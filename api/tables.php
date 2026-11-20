<?php
require 'db.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query('SELECT id, table_number, capacity, status FROM dining_tables ORDER BY table_number');
    respond($stmt->fetchAll());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Kitchen staff cannot change table status.
    require_role(['Admin', 'Manager', 'Cashier', 'Waiter']);

    $input  = json_input();
    $id     = (int)($input['id'] ?? 0);
    $status = $input['status'] ?? '';
    $allowed = ['Available', 'Occupied', 'Reserved', 'Needs Cleaning', 'Out of Service'];

    if ($id <= 0 || !in_array($status, $allowed, true)) {
        respond(['success' => false, 'message' => 'Invalid table or status'], 400);
    }

    $stmt = $pdo->prepare('UPDATE dining_tables SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);
    respond(['success' => true]);
}

respond(['success' => false, 'message' => 'Invalid request'], 405);
