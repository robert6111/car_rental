<?php
// Serves a stored document to logged-in users with document permission (enforced by the route rules).
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/uploads.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare('SELECT file_path, original_name, mime_type, title FROM documents WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doc || empty($doc['file_path'])) {
    http_response_code(404);
    exit('Document not found.');
}

cr_send_stored_file($doc['file_path'], $doc['original_name'] ?: ($doc['title'] ?: 'document'), (string)$doc['mime_type']);
