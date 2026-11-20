<?php
require 'db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['success' => false, 'message' => 'Invalid request'], 405);

$input    = json_input();
$token    = (string)($input['token'] ?? '');
$password = (string)($input['password'] ?? '');

if (!preg_match('/^[a-f0-9]{64}$/', $token)) respond(['success' => false, 'message' => 'This reset link is invalid or has expired'], 400);
if (strlen($password) < 8) respond(['success' => false, 'message' => 'Password must be at least 8 characters'], 400);

$pdo->beginTransaction();
$stmt = $pdo->prepare('SELECT user_id FROM password_resets WHERE token_hash = ? AND expires_at > NOW() FOR UPDATE');
$stmt->execute([hash('sha256', $token)]);
$row = $stmt->fetch();
if (!$row) {
    $pdo->rollBack();
    respond(['success' => false, 'message' => 'This reset link is invalid or has expired'], 400);
}

$pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
$pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$row['user_id']]);   // a link works only once
$pdo->commit();
respond(['success' => true, 'message' => 'Password updated. You can now sign in.']);