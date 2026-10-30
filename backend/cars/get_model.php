<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$make_id = intval($_GET['make_id'] ?? 0);
$models = [];

if ($make_id > 0) {
    $stmt = $conn->prepare("SELECT id, name FROM models WHERE make_id = ? ORDER BY name ASC");
    $stmt->bind_param("i", $make_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $models[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }
    }
    $stmt->close();
}

echo json_encode($models, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
