<?php
require 'db.php';
$user   = require_login();
$method = $_SERVER['REQUEST_METHOD'];

// ---------- helpers ----------
function lock_order(PDO $pdo, int $id) {
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) throw new RuntimeException('Order not found');
    return $order;
}

// Order is served AND paid: mark it Completed and flag its table for cleaning.
function finish_order(PDO $pdo, array $order) {
    $pdo->prepare("UPDATE orders SET status = 'Completed' WHERE id = ?")->execute([$order['id']]);
    if ($order['table_id']) {
        $pdo->prepare("UPDATE dining_tables SET status = 'Needs Cleaning' WHERE id = ? AND status = 'Occupied'")
            ->execute([$order['table_id']]);
    }
}

// ---------- GET: list orders ----------
if ($method === 'GET') {
    $sql = 'SELECT o.id, o.order_number, o.order_type, o.status, o.subtotal, o.discount, o.total_amount,
                   o.payment_status, o.payment_method, o.cancel_reason, o.created_at, t.table_number
            FROM orders o LEFT JOIN dining_tables t ON t.id = o.table_id';
    if ($user['role'] === 'Kitchen') {
        $sql .= " WHERE o.status IN ('Confirmed', 'Preparing', 'Ready')";   // kitchen sees active tickets only
    }
    $sql .= ' ORDER BY o.id DESC LIMIT 100';
    $orders = $pdo->query($sql)->fetchAll();

    $itemsByOrder = [];
    if ($orders) {
        $ids = array_column($orders, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT oi.order_id, oi.quantity, oi.price, oi.notes, m.name
             FROM order_items oi JOIN menu_items m ON m.id = oi.menu_item_id
             WHERE oi.order_id IN ($marks)"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $itemsByOrder[$row['order_id']][] = $row;
        }
    }

    foreach ($orders as &$o) {
        $o['items'] = $itemsByOrder[$o['id']] ?? [];
        if ($user['role'] === 'Kitchen') {          // kitchen must not see money figures
            $o['subtotal'] = $o['discount'] = $o['total_amount'] = 0;
            foreach ($o['items'] as &$i) { $i['price'] = 0; }
            unset($i);
        }
    }
    unset($o);
    respond($orders);
}

if ($method !== 'POST') {
    respond(['success' => false, 'message' => 'Invalid request'], 405);
}

// ---------- POST: create / status / pay / cancel ----------
$input  = json_input();
$action = $input['action'] ?? '';
$token  = null;

try {
    // ===== CREATE ORDER =====
    if ($action === 'create') {
        require_role(['Admin', 'Manager', 'Cashier', 'Waiter']);

        $type = $input['order_type'] ?? '';
        if (!in_array($type, ['Dine-In', 'Takeaway', 'Delivery'], true)) {
            throw new RuntimeException('Invalid order type');
        }

        $items = $input['items'] ?? [];
        if (!is_array($items) || count($items) === 0 || count($items) > 50) {
            throw new RuntimeException('Add at least one item to the order');
        }

        $token = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($input['client_token'] ?? ''));
        $token = $token === '' ? null : substr($token, 0, 64);

        // Duplicate submission (double click / retry): return the order that already exists.
        if ($token) {
            $stmt = $pdo->prepare('SELECT order_number FROM orders WHERE client_token = ?');
            $stmt->execute([$token]);
            if ($existing = $stmt->fetch()) {
                respond(['success' => true, 'order_number' => $existing['order_number'], 'duplicate' => true]);
            }
        }

        $discount = round((float)($input['discount'] ?? 0), 2);
        if ($discount < 0) throw new RuntimeException('Invalid discount');
        if ($discount > 0 && !in_array($user['role'], ['Admin', 'Manager', 'Cashier'], true)) {
            throw new RuntimeException('You are not allowed to apply discounts');
        }

        $pdo->beginTransaction();

        // Dine-in: the table must exist and be Available (row is locked so two staff cannot take it).
        $tableId = null;
        if ($type === 'Dine-In') {
            $tableId = (int)($input['table_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT status FROM dining_tables WHERE id = ? FOR UPDATE');
            $stmt->execute([$tableId]);
            $table = $stmt->fetch();
            if (!$table || $table['status'] !== 'Available') {
                throw new RuntimeException('Selected table is not available');
            }
        }

        // Validate items; prices ALWAYS come from the database, never from the browser.
        $qty = [];
        $notes = [];
        foreach ($items as $it) {
            if (!is_array($it)) throw new RuntimeException('Invalid item');
            $mid = (int)($it['id'] ?? 0);
            $q   = (int)($it['quantity'] ?? 0);
            if ($mid <= 0 || $q < 1) throw new RuntimeException('Invalid item or quantity');
            $qty[$mid] = ($qty[$mid] ?? 0) + $q;
            if ($qty[$mid] > 99) throw new RuntimeException('Quantity too large');
            $notes[$mid] = substr(trim((string)($it['notes'] ?? '')), 0, 255);
        }

        $ids = array_keys($qty);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, price FROM menu_items WHERE is_available = 1 AND id IN ($marks)");
        $stmt->execute($ids);
        $prices = [];
        foreach ($stmt->fetchAll() as $row) $prices[(int)$row['id']] = (float)$row['price'];
        if (count($prices) !== count($ids)) {
            throw new RuntimeException('One or more items are no longer available');
        }

        $subtotal = 0;
        foreach ($qty as $mid => $q) $subtotal += $prices[$mid] * $q;
        $subtotal = round($subtotal, 2);
        if ($discount > $subtotal) throw new RuntimeException('Discount cannot exceed the subtotal');
        $total = round($subtotal - $discount, 2);

        // Insert with a temporary number, then set the real one from the new id.
        $tmp = 'TMP' . bin2hex(random_bytes(8));
        $stmt = $pdo->prepare(
            "INSERT INTO orders (order_number, client_token, table_id, order_type, status, subtotal, discount, total_amount, created_by)
             VALUES (?, ?, ?, ?, 'Confirmed', ?, ?, ?, ?)"
        );
        $stmt->execute([$tmp, $token, $tableId, $type, $subtotal, $discount, $total, $user['id']]);
        $orderId = (int)$pdo->lastInsertId();
        $orderNumber = 'ORD-' . str_pad((string)$orderId, 5, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE orders SET order_number = ? WHERE id = ?')->execute([$orderNumber, $orderId]);

        $ins = $pdo->prepare('INSERT INTO order_items (order_id, menu_item_id, quantity, price, notes) VALUES (?, ?, ?, ?, ?)');
        foreach ($qty as $mid => $q) {
            $ins->execute([$orderId, $mid, $q, $prices[$mid], $notes[$mid]]);
        }

        if ($tableId) {
            $pdo->prepare("UPDATE dining_tables SET status = 'Occupied' WHERE id = ?")->execute([$tableId]);
        }

        $pdo->commit();
        respond(['success' => true, 'order_number' => $orderNumber]);
    }

    // ===== UPDATE KITCHEN STATUS =====
    if ($action === 'status') {
        $id  = (int)($input['id'] ?? 0);
        $new = $input['status'] ?? '';

        $pdo->beginTransaction();
        $order = lock_order($pdo, $id);

        $next = ['Confirmed' => 'Preparing', 'Preparing' => 'Ready', 'Ready' => 'Served'];
        if (($next[$order['status']] ?? null) !== $new) {
            throw new RuntimeException('Invalid status change');
        }

        $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$new, $id]);
        if ($new === 'Served' && $order['payment_status'] === 'Paid') {
            finish_order($pdo, $order);
        }
        $pdo->commit();
        respond(['success' => true]);
    }

    // ===== PAYMENT / CHECKOUT =====
    if ($action === 'pay') {
        require_role(['Admin', 'Manager', 'Cashier']);

        $methods = ['cash' => 'Cash', 'card' => 'Card', 'easypaisa' => 'EasyPaisa', 'jazzcash' => 'JazzCash'];
        $method  = $methods[strtolower(trim((string)($input['payment_method'] ?? '')))] ?? null;
        if (!$method) throw new RuntimeException('Payment method must be Cash, Card, EasyPaisa or JazzCash');

        $id = (int)($input['id'] ?? 0);
        $pdo->beginTransaction();
        $order = lock_order($pdo, $id);

        if ($order['status'] === 'Cancelled') throw new RuntimeException('This order was cancelled');
        if ($order['payment_status'] === 'Paid') throw new RuntimeException('This order is already paid');

        $pdo->prepare("UPDATE orders SET payment_status = 'Paid', payment_method = ? WHERE id = ?")->execute([$method, $id]);
        if ($order['status'] === 'Served') {
            finish_order($pdo, $order);
        }
        $pdo->commit();
        respond(['success' => true]);
    }

    // ===== CANCEL / VOID (reason required) =====
    if ($action === 'cancel') {
        require_role(['Admin', 'Manager']);

        $id     = (int)($input['id'] ?? 0);
        $reason = trim((string)($input['reason'] ?? ''));
        if (strlen($reason) < 3) throw new RuntimeException('A cancellation reason is required');
        $reason = substr($reason, 0, 255);

        $pdo->beginTransaction();
        $order = lock_order($pdo, $id);

        if (in_array($order['status'], ['Completed', 'Cancelled'], true)) {
            throw new RuntimeException('This order can no longer be cancelled');
        }
        if ($order['payment_status'] === 'Paid') {
            throw new RuntimeException('Paid orders need a refund, which is not part of this version');
        }

        $pdo->prepare("UPDATE orders SET status = 'Cancelled', cancel_reason = ? WHERE id = ?")->execute([$reason, $id]);
        if ($order['table_id']) {
            $pdo->prepare("UPDATE dining_tables SET status = 'Available' WHERE id = ? AND status = 'Occupied'")
                ->execute([$order['table_id']]);
        }
        $pdo->commit();
        respond(['success' => true]);
    }

    respond(['success' => false, 'message' => 'Unknown action'], 400);

} catch (PDOException $e) {
    // (PDOException is a kind of RuntimeException, so it must be caught first.)
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Two identical submissions arrived at the same moment: the second one hit the unique token.
    if ($token && ($e->errorInfo[1] ?? 0) == 1062) {
        $stmt = $pdo->prepare('SELECT order_number FROM orders WHERE client_token = ?');
        $stmt->execute([$token]);
        if ($existing = $stmt->fetch()) {
            respond(['success' => true, 'order_number' => $existing['order_number'], 'duplicate' => true]);
        }
    }
    respond(['success' => false, 'message' => 'Server error. Please try again.'], 500);   // never show raw SQL errors
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['success' => false, 'message' => $e->getMessage()], 400);
}