<?php
require 'db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['success' => false, 'message' => 'Invalid request'], 405);

$email = trim(json_input()['email'] ?? '');

// Same answer whether or not the email exists, so nobody can discover which emails are registered.
$reply = ['success' => true, 'message' => 'If that email is registered, a reset link has been sent.'];

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
if ($user = $stmt->fetch()) {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user['id']]);
    $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at)
                   VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))')
        ->execute([$user['id'], hash('sha256', $token)]);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base   = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
    $link   = "$scheme://{$_SERVER['HTTP_HOST']}$base/reset_password.html?token=$token";

    // No mail server on XAMPP: the link is written to a private log file (storage/ is blocked from the web).
    // In production, send $link by email here instead.
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents("$dir/reset_links.log", date('c') . " $email $link\n", FILE_APPEND);
}
respond($reply);