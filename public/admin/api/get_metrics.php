<?php
// api/get_metrics.php
include '../../config/db.php';
header('Content-Type: application/json');

// Fetch Metrics
$metrics = $conn->query("SELECT COUNT(*) AS total_orders, SUM(total) AS total_revenue FROM orders")->fetch_assoc();
$users = $conn->query("SELECT COUNT(*) AS total_users FROM users")->fetch_assoc();

// Sales Trend (Last 7 days)
$salesTrend = [];
$trendResult = $conn->query("SELECT DATE(order_time) AS date, SUM(total) AS total FROM orders GROUP BY DATE(order_time) ORDER BY date DESC LIMIT 7");
while($row = $trendResult->fetch_assoc()) { $salesTrend[] = $row; }

echo json_encode([
    'totalOrders' => $metrics['total_orders'],
    'totalRevenue' => number_format($metrics['total_revenue'], 2),
    'totalUsers' => $users['total_users'],
    'salesTrend' => array_reverse($salesTrend)
]);