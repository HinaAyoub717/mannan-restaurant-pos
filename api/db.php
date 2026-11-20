<?php
// Shared setup: session, JSON headers, database connection, helper functions.
// Every other API file starts with: require 'db.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json');

$DB_HOST = 'localhost';
$DB_NAME = 'mannan_restaurant';
$DB_USER = 'root';   // XAMPP default
$DB_PASS = '';       // XAMPP default

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    respond(['success' => false, 'message' => 'Database connection failed'], 500);
}

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function json_input() {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

// Must be logged in. Returns the session user.
function require_login() {
    if (empty($_SESSION['user'])) {
        respond(['success' => false, 'message' => 'Please log in again'], 401);
    }
    return $_SESSION['user'];
}

// Must be logged in AND have one of the given roles.
function require_role(array $roles) {
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        respond(['success' => false, 'message' => 'You do not have permission for this action'], 403);
    }
    return $user;
}