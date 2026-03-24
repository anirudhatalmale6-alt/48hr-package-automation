<?php
/**
 * Standalone Video branding upload handler.
 * Bypasses admin-ajax.php and REST API to avoid Cloudflare WAF blocks.
 * Uses ffmpeg to remove NotebookLM watermark and add 48HoursReady branding.
 */

// Load WordPress
$wp_load = dirname(__FILE__) . '/../../../wp-load.php';
if (!file_exists($wp_load)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'data' => ['message' => 'WordPress not found.']]);
    exit;
}

require_once $wp_load;

// Set JSON header
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

// Check authentication
if (!is_user_logged_in() || !current_user_can('manage_options')) {
    error_log('48HR video-brand: auth failed. logged_in=' . (is_user_logged_in() ? 'yes' : 'no') . ' can_manage=' . (current_user_can('manage_options') ? 'yes' : 'no'));
    http_response_code(403);
    echo json_encode(['success' => false, 'data' => ['message' => 'Not authenticated. Please refresh the page (close tab, reopen login link) and try again.']]);
    exit;
}

// Check file upload
if (empty($_FILES['video_file']) || $_FILES['video_file']['error'] !== UPLOAD_ERR_OK) {
    $err_code = isset($_FILES['video_file']['error']) ? $_FILES['video_file']['error'] : 'none';
    http_response_code(400);
    echo json_encode(['success' => false, 'data' => ['message' => "Upload failed (error code: $err_code). Max upload size may be exceeded."]]);
    exit;
}

$file = $_FILES['video_file'];

// Validate extension
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed_ext = ['mp4', 'mov', 'webm', 'mkv', 'avi'];
if (!in_array($ext, $allowed_ext)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'data' => ['message' => 'Only video files (MP4, MOV, WebM, MKV, AVI) are accepted.']]);
    exit;
}

// Validate size (100 MB limit for videos)
if ($file['size'] > 100 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'data' => ['message' => 'File exceeds 100 MB limit.']]);
    exit;
}

$add_branding = !empty($_POST['add_branding']);

// Find ffmpeg and ffprobe - check local bin first, then system
$plugin_dir = dirname(__FILE__);
$ffmpeg_path = '';
$ffprobe_path = '';

// Check for bundled static binary
if (is_file($plugin_dir . '/bin/ffmpeg') && is_executable($plugin_dir . '/bin/ffmpeg')) {
    $ffmpeg_path = $plugin_dir . '/bin/ffmpeg';
} else if (is_file($plugin_dir . '/bin/ffmpeg')) {
    // Try to make executable
    @chmod($plugin_dir . '/bin/ffmpeg', 0755);
    if (is_executable($plugin_dir . '/bin/ffmpeg')) {
        $ffmpeg_path = $plugin_dir . '/bin/ffmpeg';
    }
}
if (!$ffmpeg_path) {
    $ffmpeg_path = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
}

if (is_file($plugin_dir . '/bin/ffprobe') && is_executable($plugin_dir . '/bin/ffprobe')) {
    $ffprobe_path = $plugin_dir . '/bin/ffprobe';
} else if (is_file($plugin_dir . '/bin/ffprobe')) {
    @chmod($plugin_dir . '/bin/ffprobe', 0755);
    if (is_executable($plugin_dir . '/bin/ffprobe')) {
        $ffprobe_path = $plugin_dir . '/bin/ffprobe';
    }
}
if (!$ffprobe_path) {
    $ffprobe_path = trim(shell_exec('which ffprobe 2>/dev/null') ?: '');
}
if (!$ffmpeg_path || !is_executable($ffmpeg_path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'data' => ['message' => 'ffmpeg not available on server.']]);
    exit;
}

@set_time_limit(600);
$source = $file['tmp_name'];
$output = tempnam(sys_get_temp_dir(), 'hr48_video_') . '.mp4';

// Get video dimensions using ffprobe
$width = 1280;
$height = 720;
if ($ffprobe_path && is_executable($ffprobe_path)) {
    $probe_cmd = sprintf(
        '%s -v quiet -print_format json -show_streams %s 2>&1',
        escapeshellarg($ffprobe_path),
        escapeshellarg($source)
    );
    $probe_output = shell_exec($probe_cmd);
    $probe_data = json_decode($probe_output, true);
    if ($probe_data && isset($probe_data['streams'])) {
        foreach ($probe_data['streams'] as $stream) {
            if (isset($stream['codec_type']) && $stream['codec_type'] === 'video') {
                $width = (int) $stream['width'];
                $height = (int) $stream['height'];
                break;
            }
        }
    }
}

// Build ffmpeg video filter
// NotebookLM watermark is at bottom-right corner
// Cover it with a white rectangle, then add branding text at bottom center
$watermark_box_w = (int) round($width * 0.19);  // ~19% of width
$watermark_box_h = (int) round($height * 0.083); // ~8.3% of height
$watermark_box_x = $width - $watermark_box_w;
$watermark_box_y = $height - $watermark_box_h;

$vf_parts = [];

// Always cover NotebookLM watermark with white box
$vf_parts[] = sprintf(
    'drawbox=x=%d:y=%d:w=%d:h=%d:color=white:t=fill',
    $watermark_box_x, $watermark_box_y, $watermark_box_w, $watermark_box_h
);

// Add branding text if enabled
if ($add_branding) {
    $font_size = max(14, (int) round($height * 0.022));
    // Try to find a good font
    $font_paths = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
    ];
    $font = '';
    foreach ($font_paths as $fp) {
        if (file_exists($fp)) {
            $font = $fp;
            break;
        }
    }

    $font_opt = $font ? ':fontfile=' . $font : '';
    $vf_parts[] = sprintf(
        "drawtext=text='Powered by 48HoursReady.com':fontsize=%d:fontcolor=0x2D2D2D:x=(w-text_w)/2:y=h-%d%s",
        $font_size,
        (int) round($height * 0.039),
        $font_opt
    );
}

$vf = implode(',', $vf_parts);

$cmd = sprintf(
    '%s -i %s -vf %s -c:v libx264 -crf 18 -preset medium -c:a copy -y %s 2>&1',
    escapeshellarg($ffmpeg_path),
    escapeshellarg($source),
    escapeshellarg($vf),
    escapeshellarg($output)
);

error_log('48HR video-brand cmd: ' . $cmd);
$ffmpeg_output = shell_exec($cmd);

if (!file_exists($output) || filesize($output) === 0) {
    error_log('48HR video-brand ffmpeg failed: ' . ($ffmpeg_output ?: 'empty'));
    @unlink($output);
    http_response_code(500);
    echo json_encode(['success' => false, 'data' => ['message' => 'Video processing failed. ffmpeg output: ' . substr($ffmpeg_output ?: 'none', -300)]]);
    exit;
}

// Move to branded directory
$upload_dir = wp_upload_dir();
$branded_dir = $upload_dir['basedir'] . '/hr48-branded/';
if (!is_dir($branded_dir)) {
    wp_mkdir_p($branded_dir);
}

$out_name = 'branded-' . sanitize_file_name(pathinfo($file['name'], PATHINFO_FILENAME)) . '-' . time() . '.mp4';
$dest = $branded_dir . $out_name;
rename($output, $dest);
chmod($dest, 0644);

$url = $upload_dir['baseurl'] . '/hr48-branded/' . $out_name;

echo json_encode([
    'success' => true,
    'data' => [
        'url' => $url,
        'filename' => $out_name,
        'message' => 'Video branded successfully.',
    ]
]);
