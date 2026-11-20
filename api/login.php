<?php
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Invalid request'], 405);
}

$input    = json_input();
$email    = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

if ($email === '' || $password === '') {
    respond(['success' => false, 'message' => 'Email and password are required'], 400);
}

$stmt = $pdo->prepare('SELECT id, name, email, password, role FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
    respond(['success' => false, 'message' => 'Invalid email or password'], 401);
}

session_regenerate_id(true);
$_SESSION['user'] = [
    'id'    => (int)$user['id'],
    'name'  => $user['name'],
    'email' => $user['email'],
    'role'  => $user['role'],
];

respond(['success' => true, 'user' => $_SESSION['user']]);