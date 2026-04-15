<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    header('Location: ../user_login.php');
    exit();
}

$receipt = $_SESSION['receipt'] ?? null;
if (!$receipt) {
    header('Location: dashboard.php');
    exit();
}

// Get latest order status
$conn = new mysqli("localhost", "root", "", "foodhub");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$stmt = $conn->prepare("SELECT status FROM orders WHERE id = ?");
$stmt->bind_param("i", $receipt['order_id']);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $receipt['status'] = $row['status'];
}
$stmt->close();
$conn->close();

// Optionally one-time display
unset($_SESSION['receipt']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Receipt - FoodHub</title>
    <link href="https://fonts.googleapis.com/css2?family=Courier+Prime:wght@400;700&display=swap" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
    <link rel="stylesheet" href="../../css/style.css" />
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: rgba(0,0,0,0.1); padding: 20px; font-family: 'Courier Prime', monospace; }
        .receipt-container { background: white; padding: 0; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); max-width: 350px; width: 100%; }
        .receipt-header { text-align: center; padding: 20px 15px; border-bottom: 2px dashed #ccc; }
        .receipt-title { font-size: 24px; font-weight: 700; margin: 0; color: #2c3e50; }
        .receipt-subtitle { font-size: 12px; color: #7f8c8d; margin-top: 5px; }
        .receipt-body { padding: 20px 15px; }
        .receipt-row { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 8px; }
        .receipt-row-label { color: #7f8c8d; }
        .receipt-row-value { font-weight: 700; color: #2c3e50; }
        .receipt-items { border-top: 1px dashed #ccc; border-bottom: 1px dashed #ccc; padding: 15px 0; margin: 15px 0; }
        .item-row { display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 6px; }
        .item-name { flex: 1; }
        .item-qty { text-align: right; min-width: 40px; }
        .item-price { text-align: right; min-width: 50px; font-weight: 700; }
        .total-section { background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0; }
        .total-row { display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 8px; }
        .total-row.highlight { font-size: 16px; font-weight: 700; color: #e74c3c; border-top: 1px solid #ecf0f1; padding-top: 10px; margin-top: 10px; }
        .change-row { font-size: 14px; font-weight: 700; color: #27ae60; }
        .receipt-footer { text-align: center; padding: 15px; border-top: 1px dashed #ccc; font-size: 11px; color: #7f8c8d; }
        .status-badge { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; margin-top: 10px; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-serving { background: #cfe2ff; color: #084298; }
        .status-ready { background: #d1e7dd; color: #0f5132; }
        .action-buttons { text-align: center; padding: 15px; gap: 10px; display: flex; flex-direction: column; }
        .btn-small { font-size: 12px; padding: 8px 16px; }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="receipt-header">
            <h2 class="receipt-title">FoodHub</h2>
            <p class="receipt-subtitle">Order Receipt</p>
        </div>

        <div class="receipt-body">
            <div class="receipt-row">
                <span class="receipt-row-label">Order #:</span>
                <span class="receipt-row-value"><?php echo (int)$receipt['order_id']; ?></span>
            </div>
            <div class="receipt-row">
                <span class="receipt-row-label">Customer:</span>
                <span class="receipt-row-value"><?php echo htmlspecialchars($receipt['username']); ?></span>
            </div>
            <div class="receipt-row">
                <span class="receipt-row-label">Date/Time:</span>
                <span class="receipt-row-value"><?php echo date('m/d/y H:i', strtotime($receipt['order_time'])); ?></span>
            </div>

            <div class="receipt-items">
                <?php foreach ($receipt['items'] as $item): ?>
                    <div class="item-row">
                        <div class="item-name"><?php echo htmlspecialchars($item['product']['name']); ?></div>
                        <div class="item-qty">x<?php echo (int)$item['qty']; ?></div>
                        <div class="item-price">₱<?php echo number_format($item['product']['price'] * $item['qty'], 2); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="total-section">
                <div class="total-row">
                    <span>Subtotal:</span>
                    <span>₱<?php echo number_format($receipt['total'], 2); ?></span>
                </div>
                <div class="total-row highlight">
                    <span>Total:</span>
                    <span>₱<?php echo number_format($receipt['total'], 2); ?></span>
                </div>
                <div class="total-row">
                    <span>Cash Given:</span>
                    <span>₱<?php echo number_format($receipt['cash_given'], 2); ?></span>
                </div>
                <div class="total-row change-row">
                    <span>Change:</span>
                    <span>₱<?php echo number_format($receipt['change'], 2); ?></span>
                </div>
            </div>

            <div style="text-align: center;">
                <span class="status-badge status-<?php echo strtolower($receipt['status']); ?>">
                    <i class="bi bi-circle-fill me-1"></i><?php echo ucfirst($receipt['status']); ?>
                </span>
            </div>

            <div class="receipt-footer">
                <p style="margin: 0;">Thank you for your order!</p>
                <p style="margin: 5px 0; font-size: 10px;">Please wait for your order to be called.</p>
            </div>
        </div>

        <div class="action-buttons">
            <a href="dashboard.php" class="btn btn-primary btn-small"><i class="bi bi-arrow-left me-1"></i> Back to Menu</a>
            <button class="btn btn-outline-secondary btn-small" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print Receipt</button>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>