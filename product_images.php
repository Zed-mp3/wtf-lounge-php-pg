<?php
/**
 * Serves a product image stored in the product_images table.
 * Put this file in the same folder as your public pages (index.php, menu page, etc.).
 * Adjust the require path below if your config folder is elsewhere.
 */
require_once __DIR__ . '/config/db.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare('SELECT mime_type, data FROM product_images WHERE product_id = ?');
$stmt->execute([$id]);
$stmt->bindColumn(1, $mime);
$stmt->bindColumn(2, $data, PDO::PARAM_LOB);

if (!$stmt->fetch(PDO::FETCH_BOUND)) {
    http_response_code(404);
    exit;
}

if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}

if (is_resource($data)) {
    $data = stream_get_contents($data);
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');
echo $data;