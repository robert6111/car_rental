<?php
require_once __DIR__ . '/../includes/admin_only.php';
require __DIR__ . '/../includes/db.php';
stock_validate_action_token();
$id = intval($_GET['id'] ?? 0);
if (!$id || $id === (int)$_SESSION['user_id']) {
    header('Location: manage.php');
    exit;
}
$stmt = $conn->prepare('SELECT role_id FROM users WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($user && (int)$user['role_id'] === 1) {
    $count = $conn->query('SELECT COUNT(*) AS total FROM users WHERE role_id = 1 AND is_active = 1')->fetch_assoc()['total'];
    if ((int)$count <= 1) {
        die('Cannot delete the last active admin user.');
    }
}
$stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->close();
header('Location: manage.php');
exit;
?>
