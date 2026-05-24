<?php
session_start();
include '../../config/db.php';

header('Content-Type: application/json');

$logFile = __DIR__ . '/../../uploads/upload_debug.log';
function _log($msg) {
    global $logFile;
    @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . (is_string($msg) ? $msg : print_r($msg, true)) . "\n", FILE_APPEND);
}

_log('upload_profile_image called');
_log(['_SERVER'=>array_intersect_key($_SERVER,['REQUEST_METHOD'=>0,'HTTP_COOKIE'=>0])]);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    _log('unauthorized: no session user');
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

if (empty($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
    _log('no file or upload error: ' . (isset($_FILES['profile_image']['error']) ? $_FILES['profile_image']['error'] : 'none'));
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit();
}

$file = $_FILES['profile_image'];
_log(['file_keys'=>array_keys($file),'file_size'=>isset($file['size'])?$file['size']:null]);
$info = @getimagesize($file['tmp_name']);
_log(['getimagesize'=>$info]);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
if (!$info || !isset($allowed[$info['mime']])) {
    _log('invalid image mime: ' . ($info['mime'] ?? 'none'));
    echo json_encode(['success' => false, 'message' => 'Invalid image file type']);
    exit();
}

$uploadDir = __DIR__ . '/../../uploads';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        echo json_encode(['success' => false, 'message' => 'Unable to create upload directory']);
        exit();
    }
}

$ext = $allowed[$info['mime']];
$filename = 'profile_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
$target = $uploadDir . DIRECTORY_SEPARATOR . $filename;

if (!move_uploaded_file($file['tmp_name'], $target)) {
    _log('move_uploaded_file failed: tmp=' . ($file['tmp_name'] ?? ''));
    echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file']);
    exit();
}

_log('moved file to ' . $target);

$stmt = $conn->prepare('UPDATE users SET profile_image = ? WHERE id = ?');
$stmt->bind_param('si', $filename, $_SESSION['user_id']);
if (!$stmt->execute()) {
    _log('db update failed: ' . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
    exit();
}

_log('db update ok');
$url = '/uploads/' . rawurlencode($filename);
_log('responding with url ' . $url);
echo json_encode(['success' => true, 'url' => $url]);

?>
