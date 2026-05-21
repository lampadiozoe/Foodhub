<?php
session_start();
include __DIR__ . '/../../config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']); exit;
}

$data       = json_decode(file_get_contents('php://input'), true);
$user_id    = (int)$_SESSION['user_id'];

// Clear-all shortcut
if (!empty($data['clear'])) {
    $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
    $stmt->bind_param("i", $user_id); $stmt->execute(); $stmt->close();
    echo json_encode(['success' => true, 'message' => 'Cart cleared', 'total' => 0, 'cart_count' => 0]); exit;
}

$product_id = (int)($data['product_id'] ?? 0);
$quantity   = (int)($data['quantity']   ?? -1);

if (!$product_id || $quantity < 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']); exit;
}

try {
    if ($quantity === 0) {
        $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
        $stmt->bind_param("ii", $user_id, $product_id); $stmt->execute(); $stmt->close();
    } else {
        // Check product exists
        $stmt = $conn->prepare("SELECT id, price, stock FROM products WHERE id = ? AND is_active = 1");
        $stmt->bind_param("i", $product_id); $stmt->execute();
        $prod = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$prod) { echo json_encode(['success' => false, 'message' => 'Product not found']); exit; }

        $stmt = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)");
        $stmt->bind_param("iii", $user_id, $product_id, $quantity); $stmt->execute(); $stmt->close();
    }

    // Recalculate totals
    $total = 0; $count = 0; $itemTotal = null;
    $stmt = $conn->prepare("SELECT c.product_id, c.quantity, p.price FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
    $stmt->bind_param("i", $user_id); $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rowTotal = $row['price'] * $row['quantity'];
        $total   += $rowTotal;
        $count   += $row['quantity'];
        if ($row['product_id'] == $product_id) $itemTotal = $rowTotal;
    }
    $stmt->close();

    echo json_encode([
        'success'    => true,
        'message'    => $quantity === 0 ? 'Item removed' : 'Cart updated',
        'total'      => $total,
        'cart_count' => $count,
        'item_total' => $itemTotal,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
