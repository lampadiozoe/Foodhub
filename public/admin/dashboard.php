<?php
session_start();
include '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

// File upload helper
$upload_dir = realpath(__DIR__ . '/../../uploads');
if (!$upload_dir) {
    mkdir(__DIR__ . '/../../uploads', 0755, true);
    $upload_dir = realpath(__DIR__ . '/../../uploads');
}

function safeUpload($file, $upload_dir) {
    if (!isset($file) || $file['error'] != UPLOAD_ERR_OK) {
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    ];
    if (!array_key_exists($mime, $allowed)) {
        return null;
    }
    $ext = $allowed[$mime];
    $filename = uniqid('dish_', true) . '.' . $ext;
    $destination = $upload_dir . DIRECTORY_SEPARATOR . $filename;
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return $filename;
    }
    return null;
}

// Handle add product
if (isset($_POST['add_product'])) {
    $name = trim($_POST['name']);
    $price = (float)$_POST['price'];
    $stock = (int)$_POST['stock'];
    $description = trim($_POST['description']);
    $imageFile = safeUpload($_FILES['image'] ?? null, $upload_dir);
    $image = $imageFile ?: trim($_POST['image']);

    if (!empty($name) && $price > 0 && $stock >= 0) {
        $stmt = $conn->prepare("INSERT INTO products (name, price, stock, description, image) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sdiss", $name, $price, $stock, $description, $image);
        $stmt->execute();
        $stmt->close();
        $message = "Product added successfully.";
    } else {
        $error = "Invalid input.";
    }
}

// Handle edit product
if (isset($_POST['edit_product'])) {
    $id = (int)$_POST['id'];
    $name = trim($_POST['name']);
    $price = (float)$_POST['price'];
    $stock = (int)$_POST['stock'];
    $description = trim($_POST['description']);
    $existingImage = trim($_POST['existing_image'] ?? '');
    $imageFile = safeUpload($_FILES['image'] ?? null, $upload_dir);
    $image = $imageFile ?: $existingImage;

    if (!empty($name) && $price > 0 && $stock >= 0) {
        $stmt = $conn->prepare("UPDATE products SET name=?, price=?, stock=?, description=?, image=? WHERE id=?");
        $stmt->bind_param("sdissi", $name, $price, $stock, $description, $image, $id);
        $stmt->execute();
        $stmt->close();
        $message = "Product updated successfully.";
    } else {
        $error = "Invalid input.";
    }
}

// Handle delete product
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM products WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    $message = "Product deleted successfully.";
}

// Get products
$products = [];
$result = $conn->query("SELECT * FROM products");
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

// Get users
$users = [];
$result = $conn->query("SELECT id, name, email, role FROM users");
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

// Get orders (real persisted orders)
$orders = [];
$result = $conn->query("SELECT o.id, o.total, o.order_time, u.name AS user_name FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.order_time DESC");
while ($row = $result->fetch_assoc()) {
    $orders[] = $row;
}

// Dashboard quick metrics
$totalOrders = 0;
$totalRevenue = 0;
$pendingOrders = 0; // no status in schema, so using placeholder
$result = $conn->query("SELECT COUNT(*) AS total_orders, COALESCE(SUM(total), 0) AS total_revenue FROM orders");
if ($row = $result->fetch_assoc()) {
    $totalOrders = (int)$row['total_orders'];
    $totalRevenue = (float)$row['total_revenue'];
}

$result = $conn->query("SELECT COUNT(*) AS total_users FROM users");
$totalUsers = 0;
if ($row = $result->fetch_assoc()) {
    $totalUsers = (int)$row['total_users'];
}

// Top selling dishes
$topSelling = [];
$result = $conn->query("SELECT p.id, p.name, p.image, SUM(oi.quantity) AS qty_sold FROM order_items oi JOIN products p ON oi.product_id = p.id GROUP BY p.id, p.name, p.image ORDER BY qty_sold DESC LIMIT 5");
while ($row = $result->fetch_assoc()) {
    $topSelling[] = $row;
}

// Sales trend dataset for last 7 days
$salesTrend = [];
$result = $conn->query("SELECT DATE(order_time) AS order_date, SUM(total) AS day_total FROM orders GROUP BY DATE(order_time) ORDER BY DATE(order_time) ASC LIMIT 7");
while ($row = $result->fetch_assoc()) {
    $salesTrend[] = $row;
}

// Recent orders list (just top 5)
$recentOrders = array_slice($orders, 0, 5);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - FoodHub</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../../css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-danger">
        <div class="container">
            <a class="navbar-brand" href="../../index.php">FoodHub Admin</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link active" href="dashboard.php">Dashboard</a>
                <span class="navbar-text"><span class="badge bg-danger">Admin</span></span>
                <a class="nav-link" href="../../logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h1>Admin Dashboard</h1>
        <p>Welcome, Admin! Manage your FoodHub system here.</p>

        <div class="row dashboard-summary mb-4">
            <div class="col-md-3 mb-3">
                <div class="card p-3 bg-light">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-basket-fill card-icon me-3"></i>
                        <div>
                            <h5>Total Orders</h5>
                            <h3><?php echo $totalOrders; ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card p-3 bg-light">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-cash-stack card-icon me-3"></i>
                        <div>
                            <h5>Total Revenue</h5>
                            <h3>₱<?php echo number_format($totalRevenue,2); ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card p-3 bg-light">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-people-fill card-icon me-3"></i>
                        <div>
                            <h5>Total Users</h5>
                            <h3><?php echo $totalUsers; ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card p-3 bg-light">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-clock-history card-icon me-3"></i>
                        <div>
                            <h5>Pending Orders</h5>
                            <h3><?php echo $pendingOrders; ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card p-3 h-100 bg-white">
                    <h5 class="mb-3">Sales Trend</h5>
                    <canvas id="salesChart"></canvas>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card p-3 h-100 bg-white">
                    <h5 class="mb-3">Top Selling Filipino Dishes</h5>
                    <div class="list-group">
                        <?php foreach ($topSelling as $dish): ?>
                            <div class="list-group-item d-flex align-items-center">
                                <img src="../../uploads/<?php echo htmlspecialchars($dish['image']); ?>" alt="<?php echo htmlspecialchars($dish['name']); ?>" class="dish-img" style="width:80px; height:60px; object-fit:cover; border-radius:8px;" onerror="this.src='https://via.placeholder.com/90x70.png?text=No+Image';" loading="lazy" />
                                <div class="ms-3">
                                    <strong><?php echo htmlspecialchars($dish['name']); ?></strong><br>
                                    <small><?php echo (int)$dish['qty_sold']; ?> sold</small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($message)): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <ul class="nav nav-tabs" id="adminTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="products-tab" data-bs-toggle="tab" data-bs-target="#products" type="button" role="tab">Products</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="users-tab" data-bs-toggle="tab" data-bs-target="#users" type="button" role="tab">Users</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="orders-tab" data-bs-toggle="tab" data-bs-target="#orders" type="button" role="tab">Orders</button>
            </li>
        </ul>
        <div class="tab-content" id="adminTabsContent">
            <div class="tab-pane fade show active" id="products" role="tabpanel">
                <h2 class="mt-4">Manage Products</h2>
                <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addProductModal">Add New Product</button>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Price (₱)</th>
                            <th>Stock</th>
                            <th>Description</th>
                            <th>Image</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><?php echo $product['id']; ?></td>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td>₱<?php echo number_format($product['price'], 2); ?></td>
                                <td><?php echo $product['stock']; ?></td>
                                <td><?php echo htmlspecialchars(substr($product['description'], 0, 50)); ?>...</td>
                                <td><?php echo htmlspecialchars($product['image']); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editProductModal" onclick="editProduct(<?php echo $product['id']; ?>, '<?php echo addslashes($product['name']); ?>', <?php echo $product['price']; ?>, <?php echo $product['stock']; ?>, '<?php echo addslashes($product['description']); ?>', '<?php echo addslashes($product['image']); ?>')">Edit</button>
                                    <a href="?delete=<?php echo $product['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="tab-pane fade" id="users" role="tabpanel">
                <h2 class="mt-4">All Users</h2>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo ucfirst($user['role']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="tab-pane fade" id="orders" role="tabpanel">
                <h2 class="mt-4">All Orders</h2>
                <p>Orders placed by users.</p>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>User</th>
                            <th>Total (₱)</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><?php echo $order['id']; ?></td>
                                <td><?php echo htmlspecialchars($order['user_name']); ?></td>
                                <td>₱<?php echo number_format($order['total'], 2); ?></td>
                                <td><?php echo htmlspecialchars($order['order_time']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add Product Modal -->
    <div class="modal fade" id="addProductModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Product</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Name</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Price (₱)</label>
                            <input type="number" step="0.01" name="price" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Stock</label>
                            <input type="number" name="stock" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Description</label>
                            <textarea name="description" class="form-control"></textarea>
                        </div>
                        <div class="mb-3">
                            <label>Dish Image</label>
                            <input type="file" name="image" class="form-control" accept="image/*">
                            <small class="text-muted">Upload an image file for the dish (jpg/png/webp/gif).</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="add_product" class="btn btn-primary">Add Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Product Modal -->
    <div class="modal fade" id="editProductModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Product</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="editId">
                        <div class="mb-3">
                            <label>Name</label>
                            <input type="text" name="name" id="editName" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Price (₱)</label>
                            <input type="number" step="0.01" name="price" id="editPrice" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Stock</label>
                            <input type="number" name="stock" id="editStock" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Description</label>
                            <textarea name="description" id="editDescription" class="form-control"></textarea>
                        </div>
                        <input type="hidden" name="existing_image" id="editExistingImage">
                        <div class="mb-3">
                            <label>Dish Image</label>
                            <input type="file" name="image" id="editImage" class="form-control" accept="image/*">
                            <small class="text-muted">Leave empty to keep existing image.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="edit_product" class="btn btn-primary">Update Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/script.js"></script>
    <script>
        function editProduct(id, name, price, stock, description, image) {
            document.getElementById('editId').value = id;
            document.getElementById('editName').value = name;
            document.getElementById('editPrice').value = price;
            document.getElementById('editStock').value = stock;
            document.getElementById('editDescription').value = description;
            document.getElementById('editImage').value = image;
        }

        var salesLabels = <?php echo json_encode(array_map(function($it){ return date('M j', strtotime($it['order_date'])); }, $salesTrend)); ?>;
        var salesData = <?php echo json_encode(array_map(function($it){ return floatval($it['day_total']); }, $salesTrend)); ?>;

        if (document.getElementById('salesChart')) {
            var ctx = document.getElementById('salesChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: salesLabels,
                    datasets: [{
                        label: 'Sales (₱)',
                        data: salesData,
                        borderColor: 'rgba(0, 56, 168, 0.9)',
                        backgroundColor: 'rgba(0, 56, 168, 0.2)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 4,
                        pointBackgroundColor: 'rgba(206,17,38,1)'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: false },
                        tooltip: { mode: 'index', intersect: false }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        }
    </script>
</body>
</html>