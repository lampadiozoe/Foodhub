<?php
session_start();
include '../../config/db.php';

$hasCategory = $conn->query("SHOW COLUMNS FROM products LIKE 'category'")->num_rows > 0;
$hasActive = $conn->query("SHOW COLUMNS FROM products LIKE 'is_active'")->num_rows > 0;
$hasLowStock = $conn->query("SHOW COLUMNS FROM products LIKE 'low_stock_warning'")->num_rows > 0;

if (!$hasCategory) {
    @$conn->query("ALTER TABLE products ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT 'Uncategorized' AFTER image");
    $hasCategory = $conn->query("SHOW COLUMNS FROM products LIKE 'category'")->num_rows > 0;
}
if (!$hasActive) {
    @$conn->query("ALTER TABLE products ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER image");
    $hasActive = $conn->query("SHOW COLUMNS FROM products LIKE 'is_active'")->num_rows > 0;
}
if (!$hasLowStock) {
    @$conn->query("ALTER TABLE products ADD COLUMN low_stock_warning TINYINT(1) NOT NULL DEFAULT 0 AFTER stock");
    $hasLowStock = $conn->query("SHOW COLUMNS FROM products LIKE 'low_stock_warning'")->num_rows > 0;
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

$upload_dir = realpath(__DIR__ . '/../../uploads');
if (!$upload_dir) {
    mkdir(__DIR__ . '/../../uploads', 0755, true);
    $upload_dir = realpath(__DIR__ . '/../../uploads');
}

function safeUpload($file, $upload_dir) {
    if (!isset($file) || $file['error'] != UPLOAD_ERR_OK) return null;
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    if (!array_key_exists($mime, $allowed)) return null;
    $ext         = $allowed[$mime];
    $filename    = uniqid('dish_', true) . '.' . $ext;
    $destination = $upload_dir . DIRECTORY_SEPARATOR . $filename;
    return move_uploaded_file($file['tmp_name'], $destination) ? $filename : null;
}

define('IMG_PLACEHOLDER', 'data:image/svg+xml,' . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200">'
    . '<rect fill="#f0f2f5" width="200" height="200"/>'
    . '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" '
    . 'font-family="Arial" font-size="14" fill="#aaa">No Image</text></svg>'
));

function getProductImage($product) {
    global $upload_dir;
    $filename = trim($product['image'] ?? '');
    if (!empty($filename) && filter_var($filename, FILTER_VALIDATE_URL)) return $filename;
    $basename = basename($filename);
    if (!empty($basename) && file_exists($upload_dir . DIRECTORY_SEPARATOR . $basename))
      return '/uploads/' . rawurlencode($basename);
    $fallback = [
        'Chicken Adobo'     => 'https://images.unsplash.com/photo-1599785209707-4dda1b54d0c5?auto=format&fit=crop&w=200&q=80',
        'Pork Sinigang'     => 'https://images.unsplash.com/photo-1576134026800-5d0c0dd7e157?auto=format&fit=crop&w=200&q=80',
        'Beef Tapa'         => 'https://images.unsplash.com/photo-1605475127511-12ef1dce6d3b?auto=format&fit=crop&w=200&q=80',
        'Pancit Canton'     => 'https://images.unsplash.com/photo-1621996346565-e3dbc353d2e5?auto=format&fit=crop&w=200&q=80',
        'Lumpiang Shanghai' => 'https://images.unsplash.com/photo-1603133872878-684f208fb84b?auto=format&fit=crop&w=200&q=80',
        'Halo-Halo'         => 'https://images.unsplash.com/photo-1520202402948-6032c31def1a?auto=format&fit=crop&w=200&q=80',
    ];
    return $fallback[$product['name']] ?? IMG_PLACEHOLDER;
}

if (isset($_POST['add_product'])) {
    $name = trim($_POST['name']);
    $price = (float)$_POST['price'];
    $status = trim($_POST['status'] ?? 'available');
    $description = trim($_POST['description']);
    $category = trim($_POST['category'] ?? '');
    $imageFile = safeUpload($_FILES['image'] ?? null, $upload_dir);
    $image = $imageFile ?: trim($_POST['image_url'] ?? '');

    $validStatuses = ['available', 'almost_out', 'unavailable'];
    if (!empty($name) && $price > 0 && in_array($status, $validStatuses, true)) {
        $stock = $status === 'unavailable' ? 0 : 1;
        $lowStockWarning = $status === 'almost_out' ? 1 : 0;
        $hasCategory = $conn->query("SHOW COLUMNS FROM products LIKE 'category'")->num_rows > 0;
        if ($hasCategory) {
            $stmt = $conn->prepare("INSERT INTO products (name,price,stock,low_stock_warning,description,image,category) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param("sdiisss", $name, $price, $stock, $lowStockWarning, $description, $image, $category);
        } else {
            $stmt = $conn->prepare("INSERT INTO products (name,price,stock,low_stock_warning,description,image) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param("sdiiss", $name, $price, $stock, $lowStockWarning, $description, $image);
        }
        $stmt->execute();
        $stmt->close();
        $message = "✅ Product added successfully.";
    } else {
        $error = "❌ Invalid input. Name, price > 0, and availability status are required.";
    }
}

if (isset($_POST['edit_product'])) {
    $id = (int)$_POST['id'];
    $name = trim($_POST['name']);
    $price = (float)$_POST['price'];
    $status = trim($_POST['status'] ?? 'available');
    $description = trim($_POST['description']);
    $category = trim($_POST['category'] ?? '');
    $existingImage = trim($_POST['existing_image'] ?? '');
    $imageFile = safeUpload($_FILES['image'] ?? null, $upload_dir);
    $image = $imageFile ?: $existingImage;

    $validStatuses = ['available', 'almost_out', 'unavailable'];
    if (!empty($name) && $price > 0 && in_array($status, $validStatuses, true)) {
        $stock = $status === 'unavailable' ? 0 : 1;
        $lowStockWarning = $status === 'almost_out' ? 1 : 0;
        $hasCategory = $conn->query("SHOW COLUMNS FROM products LIKE 'category'")->num_rows > 0;
        if ($hasCategory) {
            $stmt = $conn->prepare("UPDATE products SET name=?,price=?,stock=?,low_stock_warning=?,description=?,image=?,category=? WHERE id=?");
            $stmt->bind_param("sdiisssi", $name, $price, $stock, $lowStockWarning, $description, $image, $category, $id);
        } else {
            $stmt = $conn->prepare("UPDATE products SET name=?,price=?,stock=?,low_stock_warning=?,description=?,image=? WHERE id=?");
            $stmt->bind_param("sdiissi", $name, $price, $stock, $lowStockWarning, $description, $image, $id);
        }
        $stmt->execute();
        $stmt->close();
        $message = "✅ Product updated successfully.";
    } else {
        $error = "❌ Invalid input. Name, price > 0, and availability status are required.";
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($hasActive) {
        $stmt = $conn->prepare("UPDATE products SET is_active = 0 WHERE id = ?");
    } else {
        $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    $message = $hasActive ? "🗑️ Product hidden from menu." : "🗑️ Product removed.";
}


if (isset($_POST['update_order_status'])) {
  $order_id=(int)$_POST['order_id'];
  $newStatus=in_array($_POST['status'],['pending','serving','ready','completed'])?$_POST['status']:'pending';
  $stmt=$conn->prepare("UPDATE orders SET status=? WHERE id=?");
  $stmt->bind_param("si",$newStatus,$order_id); $stmt->execute(); $stmt->close();
  $message="📦 Order #$order_id → ".ucfirst($newStatus);

  // If this is an AJAX request, return JSON and stop rendering the page
  $isAjax = false;
  if ((isset($_POST['ajax']) && $_POST['ajax']=='1') || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
    $isAjax = true;
  }
  if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['success'=>true,'order_id'=>$order_id,'status'=>$newStatus,'message'=>$message]);
    exit;
  }
}

$products=[];
$productFilter = $hasActive ? "WHERE is_active = 1" : "";
if ($hasCategory) {
  $result=$conn->query("SELECT * FROM products $productFilter ORDER BY COALESCE(category,'') ASC, id DESC");
} else {
  $result=$conn->query("SELECT * FROM products $productFilter ORDER BY id DESC");
}
while($row=$result->fetch_assoc()) $products[]=$row;

// Fetch users and include last order status for quick reference
$users=[];
$result=$conn->query("SELECT u.id,u.name,u.email,u.role, (SELECT status FROM orders WHERE user_id=u.id ORDER BY order_time DESC LIMIT 1) AS last_order_status FROM users u ORDER BY u.id ASC");
while($row=$result->fetch_assoc()) $users[]=$row;

$orders=[];
$result=$conn->query("SELECT o.id,o.total,o.order_time,o.status,u.name AS user_name FROM orders o JOIN users u ON o.user_id=u.id ORDER BY o.order_time DESC");
while($row=$result->fetch_assoc()) $orders[]=$row;

$row=$conn->query("SELECT COUNT(*) AS c,COALESCE(SUM(total),0) AS r FROM orders")->fetch_assoc();
$totalOrders=(int)$row['c']; $totalRevenue=(float)$row['r'];
$row=$conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc();
$totalUsers=(int)$row['c'];
$row=$conn->query("SELECT COUNT(*) AS c FROM orders WHERE status='pending'")->fetch_assoc();
$pendingOrders=(int)$row['c'];

$topSelling=[];
$topSellingFilter = $hasActive ? "WHERE p.is_active = 1" : "";
$result=$conn->query("SELECT p.id,p.name,p.image,SUM(oi.quantity) AS qty_sold FROM order_items oi JOIN products p ON oi.product_id=p.id $topSellingFilter GROUP BY p.id,p.name,p.image ORDER BY qty_sold DESC LIMIT 5");
while($row=$result->fetch_assoc()) $topSelling[]=$row;

$salesTrend=[];
$result=$conn->query("SELECT DATE(order_time) AS order_date,SUM(total) AS day_total FROM orders GROUP BY DATE(order_time) ORDER BY DATE(order_time) ASC LIMIT 7");
while($row=$result->fetch_assoc()) $salesTrend[]=$row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Dashboard — FoodHub</title>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="../../css/style.css"/>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid px-3">
    <a class="navbar-brand" href="dashboard.php">
      <div class="brand-icon">🛡️</div>
      FoodHub Admin
    </a>
    <div class="navbar-nav ms-auto align-items-center gap-2">
      <a class="nav-link active" href="dashboard.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
      <span class="badge" style="background:var(--grad-primary);">Admin</span>
      <a class="nav-link" href="../../logout.php"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
    </div>
  </div>
</nav>

<div class="page-wrapper mt-4">

  <!-- PAGE HEADER -->
  <div class="mb-4 fade-in">
    <h1 class="fw-bold mb-1" style="font-size:1.7rem;">👋 Admin Dashboard</h1>
    <p style="font-size:.9rem;">Welcome back! Here's what's happening with FoodHub today.</p>
  </div>

  <!-- ALERTS -->
  <?php if(isset($message)): ?>
    <div class="alert alert-success fade-in mb-3"><i class="bi bi-check-circle me-2"></i><?=htmlspecialchars($message)?></div>
  <?php endif; ?>
  <?php if(isset($error)): ?>
    <div class="alert alert-danger fade-in mb-3"><i class="bi bi-exclamation-triangle me-2"></i><?=htmlspecialchars($error)?></div>
  <?php endif; ?>

  <!-- STAT CARDS -->
  <div class="dashboard-stats mb-4">
    <a href="#orders" class="stat-link">
      <div class="stat-card orders fade-in">
        <div class="stat-card-icon"><i class="bi bi-basket-fill"></i></div>
        <div><p>Total Orders</p><h3><?=$totalOrders?></h3></div>
      </div>
    </a>
    <a href="revenue.php" class="stat-link">
      <div class="stat-card revenue fade-in">
        <div class="stat-card-icon"><i class="bi bi-cash-stack"></i></div>
        <div><p>Revenue</p><h3>₱<?=number_format($totalRevenue,2)?></h3></div>
      </div>
    </a>
    <a href="#users" class="stat-link">
      <div class="stat-card users fade-in">
        <div class="stat-card-icon"><i class="bi bi-people-fill"></i></div>
        <div><p>Total Users</p><h3><?=$totalUsers?></h3></div>
      </div>
    </a>
    <a href="#orders" class="stat-link">
      <div class="stat-card pending fade-in">
        <div class="stat-card-icon"><i class="bi bi-clock-history"></i></div>
        <div><p>Pending Orders</p><h3><?=$pendingOrders?></h3></div>
      </div>
    </a>
  </div>

  <!-- CHARTS ROW -->
  <div class="row mb-4 g-3">
    <div class="col-md-7">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-graph-up-arrow me-2" style="color:var(--primary)"></i>Sales Trend — Last 7 Days</div>
        <div class="card-body"><canvas id="salesChart" height="160"></canvas></div>
      </div>
    </div>
    <div class="col-md-5">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-fire me-2" style="color:var(--primary)"></i>Top Selling Dishes</div>
        <div class="card-body p-2">
          <?php if(empty($topSelling)): ?>
            <p class="text-center text-muted py-3">No sales data yet.</p>
          <?php else: ?>
            <?php $rankClass=['gold','silver','bronze','',''];
            foreach($topSelling as $i=>$dish): ?>
            <div class="top-dish-item">
              <div class="top-dish-rank <?=$rankClass[$i]??''?>"><?=$i+1?></div>
              <img src="<?=htmlspecialchars(getProductImage($dish))?>"
                   class="admin-product-img img-popup-trigger"
                   style="width:46px;height:46px;"
                   alt="<?=htmlspecialchars($dish['name'])?>"
                   onclick="showImgPopup(this.src,'<?=addslashes(htmlspecialchars($dish['name']))?>','','')"
                   onerror="this.onerror=null;this.src='<?=IMG_PLACEHOLDER?>';" loading="lazy"/>
              <div style="min-width:0">
                <strong style="font-size:.85rem;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=htmlspecialchars($dish['name'])?></strong>
                <small class="text-muted"><?=(int)$dish['qty_sold']?> sold</small>
              </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- TABS -->
  <ul class="nav nav-tabs mb-4" id="adminTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#products" type="button">
        <i class="bi bi-box-seam me-1"></i>Products <span class="badge bg-secondary ms-1" style="font-size:.7rem"><?=count($products)?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" data-bs-toggle="tab" data-bs-target="#users" type="button">
        <i class="bi bi-people me-1"></i>Users <span class="badge bg-secondary ms-1" style="font-size:.7rem"><?=count($users)?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" data-bs-toggle="tab" data-bs-target="#orders" type="button">
        <i class="bi bi-receipt me-1"></i>Orders <span class="badge bg-secondary ms-1" style="font-size:.7rem"><?=count($orders)?></span>
      </button>
    </li>
  </ul>

  <div class="tab-content" id="adminTabsContent">

    <!-- PRODUCTS TAB -->
    <div class="tab-pane fade show active" id="products" role="tabpanel">
      <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="fw-bold mb-0" style="font-size:1rem;"><i class="bi bi-box-seam me-2" style="color:var(--primary)"></i>Manage Products</h4>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addProductModal">
          <i class="bi bi-plus-circle me-1"></i>Add New Product
        </button>
      </div>
      <!-- Scrollable table wrapper -->
      <div class="panel-scroll">
        <div class="table-wrapper">
          <table class="table align-middle">
            <thead>
              <tr><th>ID</th><th>Image</th><th>Name</th><th>Price</th><th>Status</th><th>Description</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php if($hasCategory):
                $grouped = [];
                foreach($products as $p) { $cat = trim($p['category']??'') ?: 'Uncategorized'; $grouped[$cat][] = $p; }
                foreach($grouped as $cat => $items): ?>
                  <tr class="table-secondary"><td colspan="7" style="font-weight:700"><?=htmlspecialchars($cat)?></td></tr>
                  <?php foreach($items as $product): ?>
                    <tr>
                      <td><span class="text-muted" style="font-size:.8rem">#<?=$product['id']?></span></td>
                      <td>
                        <img src="<?=htmlspecialchars(getProductImage($product))?>"
                             alt="<?=htmlspecialchars($product['name'])?>"
                             class="admin-product-img img-popup-trigger"
                             onclick="showImgPopup(this.src,'<?=addslashes(htmlspecialchars($product['name']))?>','<?=number_format($product['price'],2)?>','<?=addslashes(htmlspecialchars($product['description']??''))?>')"
                             onerror="this.onerror=null;this.src='<?=IMG_PLACEHOLDER?>';" loading="lazy"/>
                      </td>
                      <td><strong style="font-size:.875rem"><?=htmlspecialchars($product['name'])?></strong></td>
                      <td style="font-weight:700;color:var(--primary)">₱<?=number_format($product['price'],2)?></td>
                      <td>
                        <?php
                          $status = $product['stock'] <= 0 ? 'unavailable' : ($product['low_stock_warning'] ? 'almost_out' : 'available');
                          $badgeClass = $status === 'available' ? 'bg-success' : ($status === 'almost_out' ? 'bg-warning' : 'bg-danger');
                          $statusLabel = $status === 'available' ? 'Available' : ($status === 'almost_out' ? 'Almost Out' : 'Unavailable');
                        ?>
                        <span class="badge <?=$badgeClass?>"><?=$statusLabel?></span>
                      </td>
                      <td class="text-muted" style="max-width:160px;font-size:.82rem">
                        <?=htmlspecialchars(mb_substr($product['description']??'',0,55))?>
                        <?php if(strlen($product['description']??'')>55) echo '…' ?>
                      </td>
                      <td>
                        <div class="d-flex gap-1 flex-wrap">
                          <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editProductModal"
                            onclick="editProduct(<?=$product['id']?>,'<?=addslashes(htmlspecialchars($product['name']))?>', <?=$product['price']?>,'<?=addslashes($product['stock'] <= 0 ? 'unavailable' : ($product['low_stock_warning'] ? 'almost_out' : 'available'))?>','<?=addslashes(htmlspecialchars($product['description']??''))?>','<?=addslashes($product['image']??'')?>','<?=addslashes($product['category']??'')?>')">
                            <i class="bi bi-pencil"></i>
                          </button>
                          <a href="?delete=<?=$product['id']?>" class="btn btn-danger btn-sm"
                             onclick="return confirm('Delete this product?')">
                            <i class="bi bi-trash"></i>
                          </a>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              <?php else: ?>
                <?php foreach($products as $product): ?>
                <tr>
                  <td><span class="text-muted" style="font-size:.8rem">#<?=$product['id']?></span></td>
                  <td>
                    <img src="<?=htmlspecialchars(getProductImage($product))?>"
                         alt="<?=htmlspecialchars($product['name'])?>"
                         class="admin-product-img img-popup-trigger"
                         onclick="showImgPopup(this.src,'<?=addslashes(htmlspecialchars($product['name']))?>','<?=number_format($product['price'],2)?>','<?=addslashes(htmlspecialchars($product['description']??''))?>')"
                         onerror="this.onerror=null;this.src='<?=IMG_PLACEHOLDER?>';" loading="lazy"/>
                  </td>
                  <td><strong style="font-size:.875rem"><?=htmlspecialchars($product['name'])?></strong></td>
                  <td style="font-weight:700;color:var(--primary)">₱<?=number_format($product['price'],2)?></td>
                  <td>
                    <?php
                      $status = $product['stock'] <= 0 ? 'unavailable' : ($product['low_stock_warning'] ? 'almost_out' : 'available');
                      $badgeClass = $status === 'available' ? 'bg-success' : ($status === 'almost_out' ? 'bg-warning' : 'bg-danger');
                      $statusLabel = $status === 'available' ? 'Available' : ($status === 'almost_out' ? 'Almost Out' : 'Unavailable');
                    ?>
                    <span class="badge <?=$badgeClass?>"><?=$statusLabel?></span>
                  </td>
                  <td class="text-muted" style="max-width:160px;font-size:.82rem">
                    <?=htmlspecialchars(mb_substr($product['description']??'',0,55))?>
                    <?php if(strlen($product['description']??'')>55) echo '…' ?>
                  </td>
                  <td>
                    <div class="d-flex gap-1 flex-wrap">
                      <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editProductModal"
                        onclick="editProduct(<?=$product['id']?>,'<?=addslashes(htmlspecialchars($product['name']))?>', <?=$product['price']?>,'<?=addslashes($product['stock'] <= 0 ? 'unavailable' : ($product['low_stock_warning'] ? 'almost_out' : 'available'))?>','<?=addslashes(htmlspecialchars($product['description']??''))?>','<?=addslashes($product['image']??'')?>','')">
                        <i class="bi bi-pencil"></i>
                      </button>
                      <a href="?delete=<?=$product['id']?>" class="btn btn-danger btn-sm"
                         onclick="return confirm('Delete this product?')">
                        <i class="bi bi-trash"></i>
                      </a>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              <?php if(empty($products)): ?>
              <tr><td colspan="7" class="text-center text-muted py-4">No products found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- USERS TAB -->
    <div class="tab-pane fade" id="users" role="tabpanel">
      <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="fw-bold mb-0" style="font-size:1rem;"><i class="bi bi-people me-2" style="color:var(--secondary)"></i>All Users</h4>
        <span class="badge bg-secondary"><?=count($users)?> registered</span>
      </div>
      <div class="panel-scroll">
        <div class="table-wrapper">
          <table class="table align-middle">
            <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Last Order</th></tr></thead>
            <tbody>
              <?php foreach($users as $user): ?>
              <tr>
                <td><span class="text-muted" style="font-size:.8rem">#<?=$user['id']?></span></td>
                <td><i class="bi bi-person-circle me-2 text-muted"></i><?=htmlspecialchars($user['name'])?></td>
                <td style="font-size:.875rem"><?=htmlspecialchars($user['email'])?></td>
                <td>
                  <span class="badge <?=$user['role']==='admin'?'bg-danger':'bg-primary'?>">
                    <?=ucfirst($user['role'])?>
                  </span>
                </td>
                <td>
                  <?php $los = $user['last_order_status'] ?? null; if($los):
                    $bc = match($los){'pending'=>'bg-warning text-dark','serving'=>'bg-info text-dark','ready'=>'bg-success','completed'=>'bg-secondary',default=>'bg-light text-dark'};
                  ?>
                    <span class="badge <?=$bc?>"><?=ucfirst($los)?></span>
                  <?php else: ?>
                    <small class="text-muted">—</small>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if(empty($users)): ?>
              <tr><td colspan="4" class="text-center text-muted py-4">No users found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ORDERS TAB -->
    <div class="tab-pane fade" id="orders" role="tabpanel">
      <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="fw-bold mb-0" style="font-size:1rem;"><i class="bi bi-receipt me-2" style="color:var(--accent)"></i>All Orders</h4>
        <span class="badge bg-secondary"><?=count($orders)?> total</span>
      </div>
      <div class="panel-scroll">
        <div class="table-wrapper">
          <?php
            $activeOrders = array_filter($orders, fn($o)=>trim(($o['status']??''))!=='completed');
            $completedOrders = array_filter($orders, fn($o)=>trim(($o['status']??''))==='completed');
          ?>
          <h6 class="mb-2">Active Orders <small class="text-muted">(click to update status)</small></h6>
          <table class="table align-middle mb-4">
            <thead><tr><th>Order ID</th><th>Customer</th><th>Total</th><th>Date</th><th>Status</th><th>Update</th></tr></thead>
            <tbody>
              <?php if(!empty($activeOrders)): foreach($activeOrders as $order): ?>
              <tr>
                <td><strong>#<?=$order['id']?></strong></td>
                <td style="font-size:.875rem"><?=htmlspecialchars($order['user_name'])?></td>
                <td style="font-weight:700;color:var(--success)">₱<?=number_format($order['total'],2)?></td>
                <td class="text-muted" style="font-size:.8rem"><?=htmlspecialchars($order['order_time'])?></td>
                <td>
                  <?php $s=$order['status']??'pending';
                  $bc=match($s){'pending'=>'bg-warning text-dark','serving'=>'bg-info text-dark','ready'=>'bg-success','completed'=>'bg-secondary',default=>'bg-light text-dark'}; ?>
                  <span class="badge <?=$bc?>"><?=ucfirst($s)?></span>
                </td>
                <td>
                  <form method="POST" class="d-flex gap-1 align-items-center">
                    <input type="hidden" name="order_id" value="<?=$order['id']?>"/>
                    <select name="status" class="form-select form-select-sm" style="width:120px;font-size:.8rem">
                      <?php foreach(['pending','serving','ready','completed'] as $st): ?>
                        <option value="<?=$st?>" <?=($order['status']??'')===$st?'selected':''?>><?=ucfirst($st)?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" name="update_order_status" class="btn btn-sm btn-primary" style="padding:.3rem .6rem">
                      <i class="bi bi-check2"></i>
                    </button>
                  </form>
                </td>
              </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No active orders.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>

          <h6 class="mb-2">Completed Orders <small class="text-muted">(read-only)</small></h6>
          <table class="table align-middle">
            <thead><tr><th>Order ID</th><th>Customer</th><th>Total</th><th>Date</th><th>Status</th></tr></thead>
            <tbody>
              <?php if(!empty($completedOrders)): foreach($completedOrders as $order): ?>
              <tr class="table-light text-muted">
                <td><strong>#<?=$order['id']?></strong></td>
                <td style="font-size:.875rem"><?=htmlspecialchars($order['user_name'])?></td>
                <td style="font-weight:700;color:var(--success)">₱<?=number_format($order['total'],2)?></td>
                <td class="text-muted" style="font-size:.8rem"><?=htmlspecialchars($order['order_time'])?></td>
                <td><span class="badge bg-secondary">Completed</span></td>
              </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="5" class="text-center text-muted py-3">No completed orders.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div><!-- /.tab-content -->
</div><!-- /.page-wrapper -->

<!-- IMAGE POPUP OVERLAY -->
<div class="img-popup-overlay" id="imgPopupOverlay" onclick="closeImgPopup(event)">
  <div class="img-popup-box">
    <button class="img-popup-close" onclick="closeImgPopup(null,true)"><i class="bi bi-x-lg"></i></button>
    <img id="popupImg" src="" alt="" class="img-popup-img"/>
    <div class="img-popup-info">
      <h5 id="popupName"></h5>
      <div class="popup-price" id="popupPrice"></div>
      <p class="popup-desc" id="popupDesc"></p>
    </div>
  </div>
</div>

<!-- ADD PRODUCT MODAL -->
<div class="modal fade" id="addProductModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-plus-circle me-2" style="color:var(--primary)"></i>Add New Product</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" required placeholder="e.g. Chicken Adobo"/>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label">Price (₱) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" min="0.01" name="price" class="form-control" required placeholder="0.00"/>
            </div>
            <div class="col-6">
              <label class="form-label">Availability <span class="text-danger">*</span></label>
              <select name="status" class="form-select" required>
                <option value="available" selected>Available</option>
                <option value="almost_out">Almost Out</option>
                <option value="unavailable">Unavailable</option>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="3" placeholder="Brief description..."></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Category</label>
            <select name="category" class="form-select">
              <?php $cats=['Meals','Rice','Drinks','Desserts']; foreach($cats as $c): ?>
                <option value="<?=htmlspecialchars($c)?>"><?=htmlspecialchars($c)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Upload Image</label>
            <input type="file" name="image" class="form-control" accept="image/*" onchange="previewImage(this,'addPreview')"/>
            <small class="text-muted">Accepted: jpg, png, webp, gif</small>
            <div class="mt-2">
              <img id="addPreview" src="" alt="" class="admin-product-img" style="display:none;"/>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">— or — Image URL</label>
            <input type="url" name="image_url" class="form-control" placeholder="https://example.com/image.jpg"/>
            <small class="text-muted">Used only if no file is uploaded.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="add_product" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Product</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- EDIT PRODUCT MODAL -->
<div class="modal fade" id="editProductModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil me-2" style="color:var(--warning)"></i>Edit Product</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="editId"/>
          <input type="hidden" name="existing_image" id="editExistingImage"/>
          <div class="mb-3">
            <label class="form-label">Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="editName" class="form-control" required/>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label">Price (₱) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" min="0.01" name="price" id="editPrice" class="form-control" required/>
            </div>
            <div class="col-6">
              <label class="form-label">Availability <span class="text-danger">*</span></label>
              <select name="status" id="editStatus" class="form-select" required>
                <option value="available">Available</option>
                <option value="almost_out">Almost Out</option>
                <option value="unavailable">Unavailable</option>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" id="editDescription" class="form-control" rows="3"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Current Image</label><br>
            <img id="editCurrentImg" src="" alt="Current image" class="admin-product-img mb-2"
                 onerror="this.onerror=null;this.src='<?=IMG_PLACEHOLDER?>';"/>
          </div>
          <div class="mb-3">
            <label class="form-label">Category</label>
            <select name="category" id="editCategory" class="form-select">
              <?php $cats=['Meals','Rice','Drinks','Desserts']; foreach($cats as $c): ?>
                <option value="<?=htmlspecialchars($c)?>"><?=htmlspecialchars($c)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Replace Image (optional)</label>
            <input type="file" name="image" id="editImageFile" class="form-control" accept="image/*"
                   onchange="previewImage(this,'editCurrentImg')"/>
            <small class="text-muted">Leave empty to keep existing image.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="edit_product" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Update Product</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editProduct(id,name,price,status,description,image,category) {
  document.getElementById('editId').value = id;
  document.getElementById('editName').value = name;
  document.getElementById('editPrice').value = price;
  document.getElementById('editStatus').value = status || 'available';
  document.getElementById('editDescription').value = description;
  document.getElementById('editExistingImage').value = image;
  const imgEl = document.getElementById('editCurrentImg');
  imgEl.src = image ? (image.startsWith('http') ? image : '../../uploads/' + image) : '<?=IMG_PLACEHOLDER?>';
  try { if (document.getElementById('editCategory')) document.getElementById('editCategory').value = category || ''; } catch (e) {}
}

function previewImage(input,previewId) {
  const preview=document.getElementById(previewId);
  if(input.files&&input.files[0]) {
    const reader=new FileReader();
    reader.onload=e=>{preview.src=e.target.result;preview.style.display='block';};
    reader.readAsDataURL(input.files[0]);
  }
}

function showImgPopup(src,name,price,desc) {
  document.getElementById('popupImg').src=src;
  document.getElementById('popupName').textContent=name;
  document.getElementById('popupPrice').textContent=price?'₱'+price:'';
  document.getElementById('popupDesc').textContent=desc||'';
  document.getElementById('imgPopupOverlay').classList.add('active');
  document.body.style.overflow='hidden';
}

function closeImgPopup(e,force) {
  if(force||!e||e.target===document.getElementById('imgPopupOverlay')) {
    document.getElementById('imgPopupOverlay').classList.remove('active');
    document.body.style.overflow='';
  }
}

document.addEventListener('keydown',e=>{ if(e.key==='Escape') closeImgPopup(null,true); });

const salesLabels=<?=json_encode(array_map(fn($it)=>date('M j',strtotime($it['order_date'])),$salesTrend))?>;
const salesData=<?=json_encode(array_map(fn($it)=>(float)$it['day_total'],$salesTrend))?>;
const salesCtx=document.getElementById('salesChart');
if(salesCtx) {
  new Chart(salesCtx.getContext('2d'),{
    type:'line',
    data:{
      labels:salesLabels,
      datasets:[{
        label:'Sales (₱)',data:salesData,
        borderColor:'#2e7d32',backgroundColor:'rgba(46,125,50,0.15)',
        fill:true,tension:.4,pointRadius:5,
        pointBackgroundColor:'#2e7d32',pointBorderColor:'#fff',pointBorderWidth:2,
      }]
    },
    options:{
      responsive:true,
      plugins:{legend:{display:false},tooltip:{mode:'index',intersect:false}},
      scales:{
        y:{beginAtZero:true,grid:{color:'rgba(0,0,0,0.04)'},ticks:{callback:v=>'₱'+v.toLocaleString('en-PH')}},
        x:{grid:{display:false}}
      }
    }
  });
}

function activateAdminTabFromHash() {
  if (!location.hash) return;
  const tabTrigger = document.querySelector(`[data-bs-target="${location.hash}"]`);
  if (tabTrigger) new bootstrap.Tab(tabTrigger).show();
}

document.addEventListener('DOMContentLoaded', ()=>{
  activateAdminTabFromHash();
  document.querySelectorAll('.stat-link').forEach(a=>{
    a.addEventListener('click',e=>{
      const href=a.getAttribute('href');
      if (href && href.startsWith('#')) {
        const tabTrigger=document.querySelector(`[data-bs-target="${href}"]`);
        if (tabTrigger) {
          e.preventDefault();
          new bootstrap.Tab(tabTrigger).show();
          history.pushState(null,'',href);
        }
      }
    });
  });
  // Attach AJAX handlers for order update forms to prevent full page reload
  function attachOrderAjaxHandlers() {
    document.querySelectorAll('form[name], form').forEach(form => {
      // find forms that have an update_order_status submit button
      if (!form.querySelector('button[name="update_order_status"]')) return;
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const frm = e.currentTarget;
        const fd = new FormData(frm);
        // include submit indicator so server-side recognizes the update action
        fd.append('update_order_status','1');
        fd.append('ajax','1');
        try {
          const resp = await fetch(window.location.pathname, {method:'POST', body:fd});
          const ct = resp.headers.get('Content-Type') || '';
          if (resp.ok && ct.includes('application/json')) {
            const data = await resp.json();
            if (data && data.success) {
              const row = frm.closest('tr');
              if (!row) return;
              // update badge
              const badge = row.querySelector('td span.badge');
              const status = data.status;
              const clsMap = {
                pending: 'bg-warning text-dark',
                serving: 'bg-info text-dark',
                ready: 'bg-success',
                completed: 'bg-secondary'
              };
              const newCls = clsMap[status] || 'bg-light text-dark';
              if (badge) {
                badge.className = 'badge '+newCls;
                badge.textContent = status.charAt(0).toUpperCase()+status.slice(1);
              }
              // if completed, move to completed orders table (make row read-only)
              if (status === 'completed') {
                const completedTbody = document.querySelector('#orders .table-wrapper table:nth-of-type(2) tbody');
                if (completedTbody) {
                  const lastTd = row.querySelector('td:last-child');
                  if (lastTd) lastTd.remove();
                  row.classList.add('table-light','text-muted');
                  completedTbody.prepend(row);
                }
              }
              showToast(data.message || 'Order updated', 'success');
            } else {
              console.warn('Order update failed', data);
              showToast((data && data.message) || 'Failed to update order', 'danger');
            }
          } else {
            console.warn('Unexpected response for order update', resp.status, await resp.text());
            showToast('Server error — please try again', 'danger');
          }
        } catch (err) {
          console.error('Order update error', err);
        }
      });
    });
  }
  attachOrderAjaxHandlers();
});

window.addEventListener('hashchange', activateAdminTabFromHash);
</script>
</body>
</html>