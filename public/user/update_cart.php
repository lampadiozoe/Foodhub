<?php
session_start();
include '../../config/db.php';

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

    $user_id = $_SESSION['user_id'];
    $product_id = (int)$product_id;
    $quantity = (int)$quantity;

    try {
        if ($quantity == 0) {
            // Remove item from cart
            $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
            $stmt->bind_param("ii", $user_id, $product_id);
            $stmt->execute();
            $stmt->close();
        } else {
            // Validate product exists
            $stmt = $conn->prepare("SELECT stock FROM products WHERE id = ?");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if (!$result->fetch_assoc()) {
                echo json_encode(['success' => false, 'message' => 'Product not found']);
                exit;
            }
            $stmt->close();

            // Insert or update cart item
            $stmt = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)");
            $stmt->bind_param("iii", $user_id, $product_id, $quantity);
            $stmt->execute();
            $stmt->close();
        }

        // Calculate new total and count
        $total = 0;
        $count = 0;

        $stmt = $conn->prepare("SELECT c.product_id, c.quantity, p.price FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $total += $row['price'] * $row['quantity'];
            $count += $row['quantity'];
        }
        $stmt->close();

        echo json_encode([
            'success' => true,
            'message' => $quantity == 0 ? 'Item removed from cart' : 'Cart updated successfully',
            'total' => $total,
            'cart_count' => $count
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>