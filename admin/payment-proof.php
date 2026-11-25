<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

/* Streams a private JazzCash payment screenshot to a logged-in admin only. */
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT screenshot FROM manual_payments WHERE id = ?');
$stmt->execute([$id]);
$rel = (string) ($stmt->fetchColumn() ?: '');

$base = realpath(__DIR__ . '/../uploads/payments');
$path = $rel !== '' ? realpath(__DIR__ . '/../' . $rel) : false;
if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('Not found');
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
