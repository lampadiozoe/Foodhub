<?php
include __DIR__ . '/../../../config/db.php';
header('Content-Type: application/json');

$metrics = $conn->query("SELECT COUNT(*) AS total_orders, COALESCE(SUM(total),0) AS total_revenue FROM orders")->fetch_assoc();
$users   = $conn->query("SELECT COUNT(*) AS total_users FROM users")->fetch_assoc();

$salesTrend = [];
$r = $conn->query("SELECT DATE(order_time) AS date, SUM(total) AS total FROM orders GROUP BY DATE(order_time) ORDER BY date DESC LIMIT 7");
while ($row = $r->fetch_assoc()) $salesTrend[] = $row;

echo json_encode([
    'totalOrders'   => $metrics['total_orders'],
    'totalRevenue'  => number_format($metrics['total_revenue'], 2),
    'totalUsers'    => $users['total_users'],
    'salesTrend'    => array_reverse($salesTrend),
]);
