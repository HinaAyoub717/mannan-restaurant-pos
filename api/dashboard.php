<?php
require 'db.php';
require_role(['Admin', 'Manager']);   // revenue figures: owner and manager only

date_default_timezone_set('Asia/Karachi');

// Date filter: ?range=today | week | month
$days = ['today' => 0, 'week' => 6, 'month' => 29][$_GET['range'] ?? 'today'] ?? 0;
$from = date('Y-m-d 00:00:00', strtotime("-$days days"));

function rows(PDO $pdo, string $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
function one(PDO $pdo, string $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

$paid = "payment_status = 'Paid' AND status <> 'Cancelled' AND created_at >= ?";

$totalSales  = one($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE $paid", [$from]);
$paidOrders  = one($pdo, "SELECT COUNT(*) FROM orders WHERE $paid", [$from]);
$totalOrders = one($pdo, "SELECT COUNT(*) FROM orders WHERE status <> 'Cancelled' AND created_at >= ?", [$from]);

$byType = rows($pdo, "SELECT order_type, COUNT(*) AS count FROM orders
                      WHERE status <> 'Cancelled' AND created_at >= ? GROUP BY order_type", [$from]);

// Live kitchen queue (not filtered by date)
$queue = array_map('intval', array_column(
    rows($pdo, "SELECT status, COUNT(*) AS count FROM orders
                WHERE status IN ('Confirmed', 'Preparing', 'Ready') GROUP BY status"),
    'count', 'status'
));

$tableSummary = rows($pdo, 'SELECT status, COUNT(*) AS count FROM dining_tables GROUP BY status');

$topItems = rows($pdo, "SELECT m.name, SUM(oi.quantity) AS qty, SUM(oi.quantity * oi.price) AS revenue
                        FROM order_items oi
                        JOIN orders o ON o.id = oi.order_id
                        JOIN menu_items m ON m.id = oi.menu_item_id
                        WHERE o.status <> 'Cancelled' AND o.created_at >= ?
                        GROUP BY m.id, m.name ORDER BY qty DESC LIMIT 5", [$from]);

$recent = rows($pdo, "SELECT order_number, order_type, payment_method, total_amount, created_at
                      FROM orders WHERE $paid ORDER BY id DESC LIMIT 8", [$from]);

// Cashier-wise totals (staff / shift summary)
$staff = rows($pdo, "SELECT u.name, COUNT(*) AS orders, SUM(o.total_amount) AS total
                     FROM orders o JOIN users u ON u.id = o.created_by
                     WHERE o.payment_status = 'Paid' AND o.status <> 'Cancelled' AND o.created_at >= ?
                     GROUP BY u.id, u.name ORDER BY total DESC", [$from]);

respond([
    'range'        => $_GET['range'] ?? 'today',
    'totalSales'   => (float)$totalSales,
    'paidOrders'   => (int)$paidOrders,
    'totalOrders'  => (int)$totalOrders,
    'activeOrders' => array_sum($queue),
    'queue'        => $queue,
    'byType'       => $byType,
    'tableSummary' => $tableSummary,
    'topItems'     => $topItems,
    'recent'       => $recent,
    'staff'        => $staff,
]);
