<?php
session_start();
include __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header('Location: ' . BASE_URL . '/public/login.php'); exit();
}
$receipt = $_SESSION['receipt'] ?? null;
if (!$receipt) {
    header('Location: ' . BASE_URL . '/public/user/dashboard.php'); exit();
}

// Refresh status
$stmt = $conn->prepare("SELECT status FROM orders WHERE id = ?");
$stmt->bind_param("i", $receipt['order_id']); $stmt->execute();
$row = $stmt->get_result()->fetch_assoc(); $stmt->close();
$receipt['status'] = $row['status'] ?? 'pending';
unset($_SESSION['receipt']);

$B = BASE_URL;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Receipt – FoodHub</title>
    <link href="https://fonts.googleapis.com/css2?family=Courier+Prime:wght@400;700&display=swap" rel="stylesheet"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"/>
    <style>
        body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f0f2f5;padding:20px;font-family:'Courier Prime',monospace;}
        .rc{background:#fff;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,.15);max-width:350px;width:100%;}
        .rh{text-align:center;padding:20px 15px;border-bottom:2px dashed #ccc;}
        .rh h2{font-size:24px;font-weight:700;margin:0;color:#2c3e50;}
        .rh p{font-size:12px;color:#7f8c8d;margin-top:5px;}
        .rb{padding:20px 15px;}
        .rrow{display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px;}
        .rrow-lbl{color:#7f8c8d;}
        .rrow-val{font-weight:700;color:#2c3e50;}
        .ri{border-top:1px dashed #ccc;border-bottom:1px dashed #ccc;padding:15px 0;margin:15px 0;}
        .ir{display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;}
        .ts{background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0;}
        .tr{display:flex;justify-content:space-between;font-size:14px;margin-bottom:8px;}
        .tr.hi{font-size:16px;font-weight:700;color:#e74c3c;border-top:1px solid #ecf0f1;padding-top:10px;margin-top:10px;}
        .ch{font-size:14px;font-weight:700;color:#27ae60;}
        .rf{text-align:center;padding:15px;border-top:1px dashed #ccc;font-size:11px;color:#7f8c8d;}
        .sb{display:inline-block;padding:6px 12px;border-radius:20px;font-size:11px;font-weight:700;margin-top:10px;}
        .sp{background:#fff3cd;color:#856404;}
        .ss{background:#cfe2ff;color:#084298;}
        .sr{background:#d1e7dd;color:#0f5132;}
        .abs{text-align:center;padding:15px;gap:10px;display:flex;flex-direction:column;}
    </style>
</head>
<body>
<div class="rc">
    <div class="rh">
        <h2>FoodHub</h2>
        <p>Order Receipt</p>
    </div>
    <div class="rb">
        <div class="rrow"><span class="rrow-lbl">Order #:</span><span class="rrow-val"><?= (int)$receipt['order_id'] ?></span></div>
        <div class="rrow"><span class="rrow-lbl">Customer:</span><span class="rrow-val"><?= htmlspecialchars($receipt['username']) ?></span></div>
        <div class="rrow"><span class="rrow-lbl">Date/Time:</span><span class="rrow-val"><?= date('m/d/y H:i', strtotime($receipt['order_time'])) ?></span></div>

        <div class="ri">
            <?php foreach ($receipt['items'] as $item): ?>
            <div class="ir">
                <div style="flex:1"><?= htmlspecialchars($item['product']['name']) ?></div>
                <div style="text-align:right;min-width:30px">x<?= (int)$item['qty'] ?></div>
                <div style="text-align:right;min-width:65px;font-weight:700">₱<?= number_format($item['product']['price'] * $item['qty'], 2) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="ts">
            <div class="tr"><span>Subtotal:</span><span>₱<?= number_format($receipt['total'], 2) ?></span></div>
            <div class="tr hi"><span>Total:</span><span>₱<?= number_format($receipt['total'], 2) ?></span></div>
            <div class="tr"><span>Cash Given:</span><span>₱<?= number_format($receipt['cash_given'], 2) ?></span></div>
            <div class="tr ch"><span>Change:</span><span>₱<?= number_format($receipt['change'], 2) ?></span></div>
        </div>

        <div style="text-align:center">
            <span class="sb s<?= substr($receipt['status'], 0, 1) ?>">
                <i class="bi bi-circle-fill me-1"></i><?= ucfirst($receipt['status']) ?>
            </span>
        </div>

        <div class="rf">
            <p style="margin:0">Thank you for your order!</p>
            <p style="margin:5px 0;font-size:10px">Please wait for your order to be called.</p>
        </div>
    </div>

    <div class="abs">
        <a href="<?= $B ?>/public/user/dashboard.php" class="btn btn-primary" style="font-size:.8rem;padding:.5rem 1rem"><i class="bi bi-arrow-left me-1"></i>Back to Menu</a>
        <button class="btn btn-outline-secondary" style="font-size:.8rem;padding:.5rem 1rem" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print Receipt</button>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
