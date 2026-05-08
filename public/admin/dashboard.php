<?php
session_start();
include '../../config/db.php';

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
        return '../../uploads/' . rawurlencode($basename);
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
    $name=$_POST['name']; $price=(float)$_POST['price']; $stock=(int)$_POST['stock'];
    $description=trim($_POST['description']);
    $imageFile=safeUpload($_FILES['image']??null,$upload_dir);
    $image=$imageFile?:trim($_POST['image_url']??'');
    if (!empty($name)&&$price>0&&$stock>=0) {
        $stmt=$conn->prepare("INSERT INTO products (name,price,stock,description,image) VALUES (?,?,?,?,?)");
        $stmt->bind_param("sdiss",$name,$price,$stock,$description,$image);
        $stmt->execute(); $stmt->close();
        $message="✅ Product added successfully.";
    } else { $error="❌ Invalid input. Name, price > 0, and stock ≥ 0 are required."; }
}

if (isset($_POST['edit_product'])) {
    $id=(int)$_POST['id']; $name=trim($_POST['name']); $price=(float)$_POST['price'];
    $stock=(int)$_POST['stock']; $description=trim($_POST['description']);
    $existingImage=trim($_POST['existing_image']??'');
    $imageFile=safeUpload($_FILES['image']??null,$upload_dir);
    $image=$imageFile?:$existingImage;
    if (!empty($name)&&$price>0&&$stock>=0) {
        $stmt=$conn->prepare("UPDATE products SET name=?,price=?,stock=?,description=?,image=? WHERE id=?");
        $stmt->bind_param("sdissi",$name,$price,$stock,$description,$image,$id);
        $stmt->execute(); $stmt->close();
        $message="✅ Product updated successfully.";
    } else { $error="❌ Invalid input."; }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("UPDATE products SET is_active = 0 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    $message = "🗑️ Product hidden from menu.";

}


if (isset($_POST['update_order_status'])) {
    $order_id=(int)$_POST['order_id'];
    $newStatus=in_array($_POST['status'],['pending','serving','ready','completed'])?$_POST['status']:'pending';
    $stmt=$conn->prepare("UPDATE orders SET status=? WHERE id=?");
    $stmt->bind_param("si",$newStatus,$order_id); $stmt->execute(); $stmt->close();
    $message="📦 Order #$order_id → ".ucfirst($newStatus);
}

$products=[];
$result=$conn->query("SELECT * FROM products WHERE is_active = 1 ORDER BY id DESC");
while($row=$result->fetch_assoc()) $products[]=$row;

$users=[];
$result=$conn->query("SELECT id,name,email,role FROM users ORDER BY id ASC");
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
$result=$conn->query("SELECT p.id,p.name,p.image,SUM(oi.quantity) AS qty_sold FROM order_items oi JOIN products p ON oi.product_id=p.id WHERE p.is_active = 1 GROUP BY p.id,p.name,p.image ORDER BY qty_sold DESC LIMIT 5");
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
    <a class="navbar-brand" href="../../index.php">
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
    <div class="stat-card orders fade-in">
      <div class="stat-card-icon"><i class="bi bi-basket-fill"></i></div>
      <div><p>Total Orders</p><h3><?=$totalOrders?></h3></div>
    </div>
    <div class="stat-card revenue fade-in">
      <div class="stat-card-icon"><i class="bi bi-cash-stack"></i></div>
      <div><p>Revenue</p><h3>₱<?=number_format($totalRevenue,2)?></h3></div>
    </div>
    <div class="stat-card users fade-in">
      <div class="stat-card-icon"><i class="bi bi-people-fill"></i></div>
      <div><p>Total Users</p><h3><?=$totalUsers?></h3></div>
    </div>
    <div class="stat-card pending fade-in">
      <div class="stat-card-icon"><i class="bi bi-clock-history"></i></div>
      <div><p>Pending Orders</p><h3><?=$pendingOrders?></h3></div>
    </div>
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
              <tr><th>ID</th><th>Image</th><th>Name</th><th>Price</th><th>Stock</th><th>Description</th><th>Actions</th></tr>
            </thead>
            <tbody>
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
                  <?php $sc=$product['stock']<=0?'bg-danger':($product['stock']<=5?'bg-warning':'bg-success') ?>
                  <span class="badge <?=$sc?>"><?=$product['stock']?></span>
                </td>
                <td class="text-muted" style="max-width:160px;font-size:.82rem">
                  <?=htmlspecialchars(mb_substr($product['description']??'',0,55))?>
                  <?php if(strlen($product['description']??'')>55) echo '…' ?>
                </td>
                <td>
                  <div class="d-flex gap-1 flex-wrap">
                    <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editProductModal"
                      onclick="editProduct(<?=$product['id']?>,'<?=addslashes(htmlspecialchars($product['name']))?>', <?=$product['price']?>,<?=$product['stock']?>,'<?=addslashes(htmlspecialchars($product['description']??''))?>','<?=addslashes($product['image']??'')?>')">
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
            <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th></tr></thead>
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
          <table class="table align-middle">
            <thead><tr><th>Order ID</th><th>Customer</th><th>Total</th><th>Date</th><th>Status</th><th>Update</th></tr></thead>
            <tbody>
              <?php foreach($orders as $order): ?>
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
              <?php endforeach; ?>
              <?php if(empty($orders)): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">No orders yet.</td></tr>
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
              <label class="form-label">Stock <span class="text-danger">*</span></label>
              <input type="number" min="0" name="stock" class="form-control" required placeholder="0"/>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="3" placeholder="Brief description..."></textarea>
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
              <label class="form-label">Stock <span class="text-danger">*</span></label>
              <input type="number" min="0" name="stock" id="editStock" class="form-control" required/>
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
function editProduct(id,name,price,stock,description,image) {
  document.getElementById('editId').value=id;
  document.getElementById('editName').value=name;
  document.getElementById('editPrice').value=price;
  document.getElementById('editStock').value=stock;
  document.getElementById('editDescription').value=description;
  document.getElementById('editExistingImage').value=image;
  const imgEl=document.getElementById('editCurrentImg');
  imgEl.src=image?(image.startsWith('http')?image:'../../uploads/'+image):'<?=IMG_PLACEHOLDER?>';
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
        borderColor:'#ff5722',backgroundColor:'rgba(255,87,34,0.10)',
        fill:true,tension:.4,pointRadius:5,
        pointBackgroundColor:'#ff5722',pointBorderColor:'#fff',pointBorderWidth:2,
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
</script>
</body>
</html>