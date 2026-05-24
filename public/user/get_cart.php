<?php
session_start();
include '../../config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit;
}

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT c.product_id,c.quantity,p.id,p.name,p.price,p.image FROM cart c JOIN products p ON c.product_id=p.id WHERE c.user_id=? ORDER BY c.added_at DESC");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
$total = 0.0;
$count = 0;

$uploadsDir = realpath(__DIR__ . '/../../uploads') . DIRECTORY_SEPARATOR;

while ($row = $result->fetch_assoc()) {
    $prod = [];
    $prod['product_id'] = (int)$row['product_id'];
    $prod['id'] = (int)$row['id'];
    $prod['name'] = $row['name'];
    $prod['price'] = (float)$row['price'];
    $prod['quantity'] = (int)$row['quantity'];

    $filename = trim($row['image'] ?? '');
    if (!empty($filename) && filter_var($filename, FILTER_VALIDATE_URL)) {
        $prod['image'] = $filename;
    } else {
        $basename = basename($filename);
        if (!empty($basename) && file_exists($uploadsDir . $basename)) {
            $prod['image'] = '/uploads/' . rawurlencode($basename);
        } else {
            // small placeholder SVG
            $prod['image'] = 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="200" height="120"><rect fill="#f0f2f5" width="200" height="120"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="14" fill="#aaa">FoodHub</text></svg>');
        }
    }

    $items[] = $prod;
    $total += $prod['price'] * $prod['quantity'];
    $count += $prod['quantity'];
}
$stmt->close();

echo json_encode(['success' => true, 'items' => $items, 'total' => (float)$total, 'cart_count' => (int)$count]);
