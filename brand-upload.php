<?php
/**
 * Standalone PDF branding upload handler.
 * Bypasses admin-ajax.php and REST API to avoid Cloudflare WAF blocks.
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

// Check authentication
if (!is_user_logged_in() || !current_user_can('manage_options')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'data' => ['message' => 'Not authenticated. Please refresh the page and log in again.']]);
    exit;
}

// Check nonce
if (!isset($_POST['_nonce']) || !wp_verify_nonce($_POST['_nonce'], 'hr48_brand_upload')) {
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

// Validate
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($ext !== 'pdf') {
    http_response_code(400);
    echo json_encode(['success' => false, 'data' => ['message' => 'Only PDF files are accepted.']]);
    exit;
}

if ($file['size'] > 50 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'data' => ['message' => 'File exceeds 50 MB limit.']]);
    exit;
}

// Ensure plugin class is loaded
if (!class_exists('HR48_Package_Automation')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'data' => ['message' => 'Plugin not active.']]);
    exit;
}

$add_branding = !empty($_POST['add_branding']);
$tag_first = !empty($_POST['tag_first_page']);
$tag_last = !empty($_POST['tag_last_page']);

// Process using the plugin's method via reflection (since it's private)
$plugin = new HR48_Package_Automation();
$method = new ReflectionMethod($plugin, 'process_pdf_branding');
$method->setAccessible(true);

try {
    @set_time_limit(300);
    $output_path = $method->invoke($plugin, $file['tmp_name'], $tag_first, $tag_last, $add_branding);
} catch (Throwable $e) {
    error_log('48HR brand-upload error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'data' => ['message' => 'Processing failed: ' . $e->getMessage()]]);
    exit;
}

if (!$output_path || !file_exists($output_path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'data' => ['message' => 'Processing failed. No output file generated.']]);
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
rename($output_path, $dest);
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
