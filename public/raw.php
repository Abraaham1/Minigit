<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/engine.php';

require_login();
$repo = require_repo($conn);
$id = (int)$repo['id'];

$ref = get_str('ref');
if ($ref === '') {
    $ref = $repo['default_branch'];
}
$path = clean_path(get_str('path'));
$res = resolve_ref($conn, $id, $ref);
if ($path === false || $path === '' || !$res) {
    http_response_code(404);
    exit('Not found');
}
$commit = read_commit($conn, $id, $res['hash']);
$node = tree_at_path($conn, $id, $commit['tree_hash'], $path);
if (!$node || $node['type'] !== 'blob') {
    http_response_code(404);
    exit('Not found');
}
$content = read_blob($conn, $id, $node['hash']);

// Always plain text (never HTML), so a stored file can never run as a page
header('X-Content-Type-Options: nosniff');
if (is_binary($content)) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode(basename($path)) . '"');
} else {
    header('Content-Type: text/plain; charset=utf-8');
}
header('Content-Length: ' . strlen($content));
echo $content;
