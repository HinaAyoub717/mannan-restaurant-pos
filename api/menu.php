<?php
require 'db.php';
require_login();

// Only items that are currently available can be ordered at the POS.
$stmt = $pdo->query('SELECT id, name, category, price FROM menu_items WHERE is_available = 1 ORDER BY category, name');
respond($stmt->fetchAll());
