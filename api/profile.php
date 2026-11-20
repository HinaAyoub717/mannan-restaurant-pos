<?php
require 'db.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT name, email, role FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    respond($stmt->fetch());
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['success' => false, 'message' => 'Invalid request'], 405);

$input  = json_input();
$action = $input['action'] ?? '';

if ($action === 'update_name') {
    $name = trim((string)($input['name'] ?? ''));
    if (strlen($name) < 2 || strlen($name) > 100) respond(['success' => false, 'message' => 'Name must be 2 to 100 characters'], 400);
    $pdo->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $user['id']]);
    $_SESSION['user']['name'] = $name;
    respond(['success' => true]);
}

if ($action === 'change_password') {
    $current = (string)($input['current_password'] ?? '');
    $new     = (string)($input['new_password'] ?? '');
    if (strlen($new) < 8) respond(['success' => false, 'message' => 'New password must be at least 8 characters'], 400);

    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    if (!password_verify($current, (string)$stmt->fetchColumn())) {
        respond(['success' => false, 'message' => 'Current password is incorrect'], 400);
    }
    $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
    respond(['success' => true]);
}

respond(['success' => false, 'message' => 'Unknown action'], 400);