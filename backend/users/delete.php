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
        header('Location: manage.php?error=last_admin');
        exit;
    }
}
// Bump the session version first so any open session of this user ends immediately.
cr_bump_session_version($conn, $id);
$stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
$stmt->bind_param('i', $id);
if (!$stmt->execute()) {
    // Linked records exist: deactivate instead of deleting.
    $off = $conn->prepare('UPDATE users SET is_active = 0 WHERE id = ?');
    $off->bind_param('i', $id);
    $off->execute();
    $off->close();
}
$stmt->close();
header('Location: manage.php');
exit;
?>
