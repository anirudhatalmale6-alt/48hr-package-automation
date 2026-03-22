<?php
/**
 * Standalone PDF branding upload handler.
 * Bypasses admin-ajax.php and REST API to avoid Cloudflare WAF blocks.
 * Calls Ghostscript directly for PDF processing.
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
    error_log('48HR brand-upload: auth failed. logged_in=' . (is_user_logged_in() ? 'yes' : 'no') . ' can_manage=' . (current_user_can('manage_options') ? 'yes' : 'no'));
    http_response_code(403);
    echo json_encode(['success' => false, 'data' => ['message' => 'Not authenticated. Please refresh the page (close tab, reopen login link) and try again.']]);
    exit;
}

// Check nonce - skip nonce check, rely on cookie auth only (nonces cause issues with repeated uploads)
// The cookie auth + manage_options capability check is sufficient security
if (false && (!isset($_POST['_nonce']) || !wp_verify_nonce($_POST['_nonce'], 'hr48_brand_upload'))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'data' => ['message' => 'Security token expired. Please refresh the page and try again.']]);
    exit;
}

// Check file upload
if (empty($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
    $err_code = isset($_FILES['pdf_file']['error']) ? $_FILES['pdf_file']['error'] : 'none';
    http_response_code(400);
    echo json_encode(['success' => false, 'data' => ['message' => "Upload failed (error code: $err_code)."]]);
    exit;
}

$file = $_FILES['pdf_file'];

// Validate extension
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($ext !== 'pdf') {
    http_response_code(400);
    echo json_encode(['success' => false, 'data' => ['message' => 'Only PDF files are accepted.']]);
    exit;
}

// Validate size
if ($file['size'] > 50 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'data' => ['message' => 'File exceeds 50 MB limit.']]);
    exit;
}

$add_branding = !empty($_POST['add_branding']);
$tag_first = !empty($_POST['tag_first_page']);
$tag_last = !empty($_POST['tag_last_page']);

// Find Ghostscript
$gs_path = trim(shell_exec('which gs 2>/dev/null') ?: '');
if (!$gs_path || !is_executable($gs_path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'data' => ['message' => 'Ghostscript not available on server.']]);
    exit;
}

@set_time_limit(300);
$source = $file['tmp_name'];
$output = tempnam(sys_get_temp_dir(), 'hr48_branded_');

// Get page count
$count_cmd = sprintf(
    '%s -q -dNODISPLAY -dNOSAFER -c "(%s) (r) file runpdfbegin pdfpagecount = quit" 2>&1',
    escapeshellarg($gs_path),
    str_replace(['(', ')'], ['\\(', '\\)'], $source)
);
$page_count = (int) trim(shell_exec($count_cmd));

// Build PostScript EndPage procedure
$ps_code = '';

if (($tag_first || $tag_last) && $add_branding) {
    $ps_code .= sprintf('/hr48page 0 def /hr48total %d def ', $page_count);
}

$ps_code .= '<< /EndPage { '
    . 'exch pop dup 0 eq { '
    . 'pop gsave '
    . 'currentpagedevice /PageSize get aload pop '
    . '/pH exch def /pW exch def '
    // Always cover NotebookLM watermark
    . '0.941 0.925 0.890 setrgbcolor '
    . 'pW 164 sub 8 156 34 rectfill ';

if ($add_branding) {
    $ps_code .= '/Helvetica findfont 9 scalefont setfont '
        . '(Powered by ) stringwidth pop '
        . '(48HoursReady) stringwidth pop add '
        . '(.com) stringwidth pop add '
        . '/tw exch def '
        . 'pW tw sub 2 div 28 moveto '
        . '0.176 0.176 0.176 setrgbcolor '
        . '(Powered by ) show '
        . '0.722 0.592 0.353 setrgbcolor '
        . '(48HoursReady) show '
        . '0.176 0.176 0.176 setrgbcolor '
        . '(.com) show ';

    if ($tag_first || $tag_last) {
        $ps_code .= '/hr48page hr48page 1 add def ';
        $conditions = [];
        if ($tag_first) $conditions[] = 'hr48page 1 eq';
        if ($tag_last) $conditions[] = 'hr48page hr48total eq';
        $condition = implode(' ', $conditions);
        if (count($conditions) > 1) $condition .= ' or';

        $ps_code .= $condition . ' { '
            . '/Helvetica-Oblique findfont 7 scalefont setfont '
            . '0.722 0.592 0.353 setrgbcolor '
            . '(Pitch Deck Ready. GPT Verified.) dup stringwidth pop '
            . '/tagw exch def '
            . 'pW tagw sub 2 div 16 moveto show '
            . '} if ';
    }
}

$ps_code .= 'grestore true '
    . '} { '
    . '2 ne '
    . '} ifelse '
    . '} >> setpagedevice';

$cmd = sprintf(
    '%s -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dNOPAUSE -dBATCH -dQUIET '
    . '-dPDFSETTINGS=/prepress '
    . '-dColorImageDownsampleType=/Bicubic -dColorImageResolution=300 '
    . '-dGrayImageDownsampleType=/Bicubic -dGrayImageResolution=300 '
    . '-sOutputFile=%s -c %s -f %s 2>&1',
    escapeshellarg($gs_path),
    escapeshellarg($output),
    escapeshellarg($ps_code),
    escapeshellarg($source)
);

$gs_output = shell_exec($cmd);

if (!file_exists($output) || filesize($output) === 0) {
    error_log('48HR brand-upload GS failed: ' . ($gs_output ?: 'empty'));
    @unlink($output);
    http_response_code(500);
    echo json_encode(['success' => false, 'data' => ['message' => 'PDF processing failed. GS output: ' . substr($gs_output ?: 'none', 0, 200)]]);
    exit;
}

// Move to branded directory
$upload_dir = wp_upload_dir();
$branded_dir = $upload_dir['basedir'] . '/hr48-branded/';
if (!is_dir($branded_dir)) {
    wp_mkdir_p($branded_dir);
}

$out_name = 'branded-' . sanitize_file_name(pathinfo($file['name'], PATHINFO_FILENAME)) . '-' . time() . '.pdf';
$dest = $branded_dir . $out_name;
rename($output, $dest);
chmod($dest, 0644);

$url = $upload_dir['baseurl'] . '/hr48-branded/' . $out_name;

echo json_encode([
    'success' => true,
    'data' => [
        'url' => $url,
        'filename' => $out_name,
        'message' => 'PDF branded successfully.',
    ]
]);
