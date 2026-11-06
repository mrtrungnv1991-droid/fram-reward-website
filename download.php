<?php
require_once __DIR__ . '/config.php';
if (empty($_SESSION['rw_user'])) {
    header('Location: index.php');
    exit;
}
$file = isset($_GET['file']) ? basename((string)$_GET['file']) : '';
$allowed = array('rewards_worker_setup_v2.0.0.zip');
if (!in_array($file, $allowed, true) || !is_file(__DIR__ . '/downloads/' . $file)) {
    http_response_code(404);
    echo 'File not found';
    exit;
}
$path = __DIR__ . '/downloads/' . $file;
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
