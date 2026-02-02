<?php
require_once('autoclean.php');
// File: upload.php - Update untuk handle curl error
error_reporting(0); // Matikan error reporting
ini_set('display_errors', 0);
set_time_limit(300); // Set timeout 5 menit
ini_set('max_execution_time', 300);
ini_set('upload_max_filesize', '10M');
ini_set('post_max_size', '10M');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Konfigurasi
$max_size = 5 * 1024 * 1024; // 5MB
$allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/svg+xml'];
$upload_dir = 'uploads/';

// Log untuk debugging
function logError($message) {
    $log = date('Y-m-d H:i:s') . " - " . $message . "\n";
    file_put_contents('upload_errors.log', $log, FILE_APPEND);
}

// Response helper
function sendResponse($success, $message, $data = []) {
    $response = [
        'success' => $success,
        'message' => $message,
        'data' => $data
    ];
    
    // Jika request dari curl, cukup return URL saja untuk kompatibilitas 0x0.st
    if (isset($_SERVER['HTTP_USER_AGENT']) && 
        (strpos($_SERVER['HTTP_USER_AGENT'], 'curl') !== false || 
         strpos($_SERVER['HTTP_USER_AGENT'], 'Wget') !== false)) {
        
        if ($success && isset($data['url'])) {
            echo $data['url'];
        } else {
            echo "Error: " . $message;
        }
    } else {
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
    }
    exit;
}

// Cek request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method. Use POST.');
}

// Cek jika ada file
if (!isset($_FILES['file'])) {
    sendResponse(false, 'No file uploaded.');
}

$file = $_FILES['file'];

// Cek error upload
if ($file['error'] !== UPLOAD_ERR_OK) {
    $error_messages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive in php.ini',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive in HTML form',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'PHP extension stopped the file upload'
    ];
    
    $error_msg = $error_messages[$file['error']] ?? 'Unknown upload error';
    logError("Upload error: " . $error_msg);
    sendResponse(false, 'Upload error: ' . $error_msg);
}

// Cek ukuran file
if ($file['size'] > $max_size) {
    sendResponse(false, 'File too large. Maximum size is 5MB.');
}

if ($file['size'] == 0) {
    sendResponse(false, 'File is empty.');
}

// Cek tipe file
$finfo = @finfo_open(FILEINFO_MIME_TYPE);
if (!$finfo) {
    // Fallback jika finfo tidak tersedia
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_ext = [
    'jpg', 'jpeg',
    'png',
    'gif',
    'webp',
    'bmp',
    'tif', 'tiff',
    'ico',
    'heic', 'heif',
    'avif'
];

    
    if (!in_array($ext, $allowed_ext)) {
        sendResponse(false, 'Invalid file type. Only image files are allowed.');
    }
    
    $mime = mime_content_type($file['tmp_name']);
} else {
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
}

// Validasi MIME type
if (!in_array($mime, $allowed_types)) {
    logError("Invalid MIME type: " . $mime . " for file: " . $file['name']);
    sendResponse(false, 'Invalid file type. Only image files are allowed (JPEG, PNG, GIF, WEBP, BMP, SVG).');
}

// Buat folder uploads jika belum ada
if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true)) {
        logError("Failed to create upload directory: " . $upload_dir);
        sendResponse(false, 'Server error: Cannot create upload directory.');
    }
}

// Cek permission folder
if (!is_writable($upload_dir)) {
    logError("Upload directory not writable: " . $upload_dir);
    sendResponse(false, 'Server error: Upload directory not writable.');
}

// Generate nama file unik
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$filename = 'capsec_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$filepath = $upload_dir . $filename;

// Pindahkan file
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    logError("Failed to move uploaded file: " . $file['tmp_name'] . " to " . $filepath);
    sendResponse(false, 'Failed to save file. Please try again.');
}

// Set permission file
chmod($filepath, 0644);

// Generate URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');

// Jika di root, hapus path
if ($path === '/') {
    $url = $protocol . $host . '/' . $filepath;
} else {
    $url = $protocol . $host . $path . '/' . $filepath;
}

// Hapus double slashes
$url = preg_replace('/([^:])(\/{2,})/', '$1/', $url);

// Log success
logError("Upload successful: " . $filename . " (" . $file['size'] . " bytes)");

// Kirim response
sendResponse(true, 'File uploaded successfully!', [
    'url' => $url,
    'filename' => $filename,
    'size' => round($file['size'] / 1024, 2) . ' KB',
    'type' => $mime,
    'delete_after' => '3 hours'
]);
?>