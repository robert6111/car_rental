<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

$make_id = intval($_GET['make_id'] ?? 0);

if ($make_id > 0) {
    $stmt = $conn->prepare("SELECT id, name FROM models WHERE make_id = ? ORDER BY name ASC");
    $stmt->bind_param("i", $make_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $models = [];
    while ($row = $result->fetch_assoc()) {
        $models[] = $row;
    }

    // tell browser it’s JSON
    header('Content-Type: application/json');
    echo json_encode($models);
}
