<?php
session_start();
include '../../config/db.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

// recent revenue
$result = $conn->query("SELECT id, user_id, total, status, order_time FROM orders ORDER BY order_time DESC LIMIT 100");
$orders = [];
while($r = $result->fetch_assoc()) $orders[] = $r;

$row = $conn->query("SELECT COALESCE(SUM(total),0) AS total_revenue, COUNT(*) AS total_orders FROM orders")->fetch_assoc();
$totalRevenue = (float)$row['total_revenue'];
$totalOrders = (int)$row['total_orders'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Revenue — FoodHub Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="../../css/style.css" />
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid px-3">
    <a class="navbar-brand" href="dashboard.php">FoodHub Admin</a>
    <div class="navbar-nav ms-auto">
      <a class="nav-link" href="dashboard.php">Dashboard</a>
      <a class="nav-link" href="dashboard.php#orders">Orders</a>
      <a class="nav-link" href="../../logout.php">Logout</a>
    </div>
  </div>
</nav>

<div class="page-wrapper mt-4">
  <h3 class="mb-3">Revenue History</h3>
  <div class="card mb-3">
    <div class="card-body d-flex justify-content-between align-items-center">
      <div>
        <p class="mb-0 text-muted">Total Orders</p>
        <h4><?=$totalOrders?></h4>
      </div>
      <div>
        <p class="mb-0 text-muted">Total Revenue</p>
        <h4>₱<?=number_format($totalRevenue,2)?></h4>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Recent Orders</div>
    <div class="card-body p-0">
      <table class="table mb-0">
        <thead><tr><th>Order</th><th>User ID</th><th>Total</th><th>Status</th><th>Date</th></tr></thead>
        <tbody>
          <?php if(empty($orders)): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No orders yet.</td></tr>
          <?php else: foreach($orders as $o): ?>
            <tr>
              <td>#<?=$o['id']?></td>
              <td><?=$o['user_id']?></td>
              <td>₱<?=number_format($o['total'],2)?></td>
              <td><?=htmlspecialchars(ucfirst($o['status']))?></td>
              <td class="text-muted"><?=htmlspecialchars($o['order_time'])?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
