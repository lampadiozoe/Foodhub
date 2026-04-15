<?php
session_start();
require_once '../../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $product_id = $data['product_id'] ?? null;
    $quantity = $data['quantity'] ?? null;

    if (!$product_id || $quantity === null) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }

    try {
        if ($quantity == 0) {
            // Remove item from cart
            unset($_SESSION['cart'][$product_id]);
        } else {
            // Update quantity
            if (!isset($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }
            $_SESSION['cart'][$product_id] = $quantity;
        }

        // Calculate new total and count
        $total = 0;
        $count = 0;

        if (!empty($_SESSION['cart'])) {
            foreach ($_SESSION['cart'] as $pid => $qty) {
                $stmt = $pdo->prepare("SELECT price FROM products WHERE id = ?");
                $stmt->execute([$pid]);
                $product = $stmt->fetch();

                if ($product) {
                    $total += $product['price'] * $qty;
                    $count += $qty;
                }
            }
        }

        echo json_encode([
            'success' => true,
            'message' => $quantity == 0 ? 'Item removed from cart' : 'Cart updated successfully',
            'total' => $total,
            'cart_count' => $count
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>