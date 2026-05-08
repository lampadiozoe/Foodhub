<?php
session_start();
include '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    header('Location: ../user_login.php');
    exit();
}

$name = $_SESSION['name'];

// Add to cart
if (isset($_POST['add_to_cart'])) {
    $product_id = (int)$_POST['product_id'];
    $stmt = $conn->prepare('SELECT stock FROM products WHERE id = ?');
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $stmt->bind_result($stock);
    $stmt->fetch();
    $stmt->close();

    if ($stock > 0) {
        $user_id = $_SESSION['user_id'];
        $stmt = $conn->prepare('INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE quantity = quantity + 1');
        $stmt->bind_param('ii', $user_id, $product_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['message'] = 'Item added to cart! 🛒';
    } else {
        $_SESSION['message'] = 'Out of stock, please pick another item.';
    }
    header('Location: dashboard.php#menu');
    exit();
}

// Remove from cart
if (isset($_POST['remove_from_cart'])) {
    $product_id = (int)$_POST['product_id'];
    $user_id    = $_SESSION['user_id'];
    $stmt = $conn->prepare('DELETE FROM cart WHERE user_id = ? AND product_id = ?');
    $stmt->bind_param('ii', $user_id, $product_id);
    $stmt->execute();
    $stmt->close();
    header('Location: dashboard.php');
    exit();
}

// Checkout
$checkoutError = '';
if (isset($_POST['checkout'])) {
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT c.product_id,c.quantity,p.price,p.stock,p.name FROM cart c JOIN products p ON c.product_id=p.id WHERE c.user_id=?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $cart_items = []; $total = 0;
    while ($row = $result->fetch_assoc()) { $cart_items[] = $row; $total += $row['price'] * $row['quantity']; }
    $stmt->close();

    if (empty($cart_items)) { $_SESSION['message'] = 'Cart is empty.'; header('Location: dashboard.php'); exit(); }

    $cashAmount = isset($_POST['cash_amount']) ? trim($_POST['cash_amount']) : '';
    if ($cashAmount === '') $checkoutError = 'Please enter payment amount.';
    elseif (!is_numeric($cashAmount) || floatval($cashAmount) < $total) $checkoutError = 'Insufficient amount.';

    if ($checkoutError === '') {
        foreach ($cart_items as $item) {
            if ($item['stock'] < $item['quantity']) { $checkoutError = 'Insufficient stock for '.$item['name']; break; }
        }
    }

    if ($checkoutError === '') {
        $cashAmount   = floatval($cashAmount);
        $changeAmount = $cashAmount - $total;
        $stmt = $conn->prepare('INSERT INTO orders (user_id,total) VALUES (?,?)');
        $stmt->bind_param('id', $user_id, $total);
        $stmt->execute();
        $order_id = $stmt->insert_id;
        $stmt->close();

        $receipt_items = [];
        foreach ($cart_items as $item) {
            $item_total    = $item['price'] * $item['quantity'];
            $receipt_items[] = ['product'=>['id'=>$item['product_id'],'name'=>$item['name'],'price'=>$item['price']],'qty'=>$item['quantity'],'item_total'=>$item_total];
            $stmt = $conn->prepare('INSERT INTO order_items (order_id,product_id,quantity,price) VALUES (?,?,?,?)');
            $stmt->bind_param('iiid', $order_id, $item['product_id'], $item['quantity'], $item['price']);
            $stmt->execute(); $stmt->close();
            $stmt = $conn->prepare('UPDATE products SET stock=stock-? WHERE id=?');
            $stmt->bind_param('ii', $item['quantity'], $item['product_id']);
            $stmt->execute(); $stmt->close();
        }
        $_SESSION['receipt'] = ['order_id'=>$order_id,'username'=>$name,'items'=>$receipt_items,'total'=>$total,'cash_given'=>$cashAmount,'change'=>$changeAmount,'order_time'=>date('Y-m-d H:i:s'),'status'=>'pending'];
        $stmt = $conn->prepare('DELETE FROM cart WHERE user_id=?');
        $stmt->bind_param('i', $user_id); $stmt->execute(); $stmt->close();
        $_SESSION['show_serving_popup'] = true;
        header('Location: receipt.php'); exit();
    }
}

// Load products
$products = [];
$result   = $conn->query('SELECT * FROM products');
while ($row = $result->fetch_assoc()) $products[] = $row;

// Categorise
$meals = $rices = $drinks = $desserts = [];
foreach ($products as $p) {
    $n = strtolower($p['name']);
    if (preg_match('/rice|kan-on|kanon|unli rice/', $n))                                $rices[]    = $p;
    elseif (preg_match('/drink|softdrink|cola|sprite|royal|soda|juice|tea|coffee/', $n)) $drinks[]   = $p;
    elseif (preg_match('/halo|sorbetes|dessert|cake|leche|ube|halo-halo/', $n))          $desserts[]  = $p;
    else                                                                                  $meals[]    = $p;
}

// Active orders
$user_orders = [];
$result = $conn->query("SELECT id,status,order_time FROM orders WHERE user_id={$_SESSION['user_id']} AND status IN ('pending','serving','ready') ORDER BY order_time DESC");
while ($row = $result->fetch_assoc()) $user_orders[] = $row;

// User stats
$userTotalSpent = 0; $userFavorites = [];
$row = $conn->query("SELECT SUM(total) as total_spent FROM orders WHERE user_id={$_SESSION['user_id']}")->fetch_assoc();
$userTotalSpent = (float)($row['total_spent'] ?? 0);
$row = $conn->query("SELECT COUNT(*) as total FROM orders WHERE user_id={$_SESSION['user_id']}")->fetch_assoc();
$totalOrders = (int)$row['total'];

define('IMG_PLACEHOLDER', 'data:image/svg+xml,'.rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300"><rect fill="#f0f2f5" width="400" height="300"/>'
    .'<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="18" fill="#aaa">FoodHub</text></svg>'
));

function getProductImage($product) {
    $uploadsDir = realpath(__DIR__.'/../../uploads').DIRECTORY_SEPARATOR;
    $filename   = trim($product['image'] ?? '');
    if (!empty($filename) && filter_var($filename, FILTER_VALIDATE_URL)) return $filename;
    $basename = basename($filename);
    if (!empty($basename) && file_exists($uploadsDir.$basename)) return '../../uploads/'.rawurlencode($basename);
    $fallback = [
        'Chicken Adobo'     => 'https://images.unsplash.com/photo-1599785209707-4dda1b54d0c5?auto=format&fit=crop&w=600&q=80',
        'Pork Sinigang'     => 'https://images.unsplash.com/photo-1576134026800-5d0c0dd7e157?auto=format&fit=crop&w=600&q=80',
        'Beef Tapa'         => 'https://images.unsplash.com/photo-1605475127511-12ef1dce6d3b?auto=format&fit=crop&w=600&q=80',
        'Pancit Canton'     => 'https://images.unsplash.com/photo-1621996346565-e3dbc353d2e5?auto=format&fit=crop&w=600&q=80',
        'Lumpiang Shanghai' => 'https://images.unsplash.com/photo-1603133872878-684f208fb84b?auto=format&fit=crop&w=600&q=80',
        'Halo-Halo'         => 'https://images.unsplash.com/photo-1520202402948-6032c31def1a?auto=format&fit=crop&w=600&q=80',
    ];
    return $fallback[$product['name']] ?? IMG_PLACEHOLDER;
}

// Load cart
$total = 0; $cart_items = [];
$stmt = $conn->prepare("SELECT c.product_id,c.quantity,p.id,p.name,p.price,p.image FROM cart c JOIN products p ON c.product_id=p.id WHERE c.user_id=? ORDER BY c.added_at DESC");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) { $cart_items[] = ['product'=>$row,'qty'=>$row['quantity']]; $total += $row['price']*$row['quantity']; }
$stmt->close();

$message   = $_SESSION['message'] ?? ''; unset($_SESSION['message']);
$todayUlam = !empty($products) ? $products[array_rand($products)] : [];
$cartCount = array_sum(array_column(array_column($cart_items,'qty'), null));
$cartCount = 0; foreach ($cart_items as $ci) $cartCount += $ci['qty'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FoodHub — Order Now</title>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"/>
  <link rel="stylesheet" href="../../css/style.css"/>
  <style>
    /* Sticky category nav backdrop */
    .menu-category-nav {
      background: rgba(240,242,245,.92);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid var(--border);
      padding: .5rem 0;
    }
    /* Today's ulam card */
    .ulam-card {
      background: linear-gradient(135deg,#fff8f5 0%,#fff 100%);
      border: 1.5px solid rgba(255,87,34,.15);
      border-radius: var(--radius-xl);
      overflow: hidden;
      box-shadow: var(--shadow-md);
      margin-bottom: 2rem;
      display: flex;
      align-items: stretch;
      gap: 0;
    }
    .ulam-img-wrap {
      width: 200px;
      flex-shrink: 0;
      overflow: hidden;
      position: relative;
    }
    .ulam-img-wrap img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      transition: transform .4s ease;
      cursor: zoom-in;
    }
    .ulam-img-wrap:hover img { transform: scale(1.05); }
    .ulam-body { padding: 1.4rem 1.6rem; flex: 1; }
    @media(max-width:600px){
      .ulam-card{flex-direction:column;}
      .ulam-img-wrap{width:100%;height:160px;}
    }
    /* Active order card */
    .active-order-card {
      background: linear-gradient(135deg,#fff 0%,#f0fff4 100%);
      border: 1.5px solid rgba(67,160,71,.2);
      border-radius: var(--radius-lg);
      padding: 1rem 1.2rem;
      margin-bottom: .75rem;
    }
    /* Search bar */
    .search-wrap { position: relative; margin-bottom: 1.2rem; }
    .search-wrap input {
      border-radius: var(--radius-full) !important;
      padding-left: 2.6rem !important;
      background: #fff;
      border: 1.5px solid var(--border);
      font-size: .875rem;
    }
    .search-wrap .search-icon {
      position: absolute; left: 1rem; top: 50%; transform: translateY(-50%);
      color: var(--muted); pointer-events: none;
    }
  </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid px-3">
    <a class="navbar-brand" href="../../index.php">
      <div class="brand-icon">🍴</div>
      FoodHub
    </a>
    <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarUser">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse justify-content-end" id="navbarUser">
      <div class="navbar-nav align-items-center gap-1">
        <span class="navbar-text me-2" style="font-size:.875rem;color:rgba(255,255,255,.7)">
          <i class="bi bi-person-circle me-1"></i><?=htmlspecialchars($name)?>
        </span>
        <a class="nav-link" href="#menu">🍛 Menu</a>
        <a class="nav-link" href="../../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
      </div>
    </div>
  </div>
</nav>

<div class="page-wrapper mt-4">

  <!-- HERO BANNER -->
  <div class="hero fade-in mb-4">
    <span class="hero-emoji">🍽️</span>
    <h1 class="fw-bold mb-2" style="font-size:clamp(1.5rem,4vw,2.4rem);">
      Kumain na, <?=htmlspecialchars(explode(' ',$name)[0])?>!
    </h1>
    <p class="mb-0" style="opacity:.88;font-size:.95rem;">Fresh Filipino food, ready when you are. Order below! 🇵🇭</p>
  </div>

  <!-- STAT CARDS -->
  <div class="dashboard-stats mb-4 stagger">
    <div class="stat-card orders">
      <div class="stat-card-icon"><i class="bi bi-receipt"></i></div>
      <div><p>Total Orders</p><h3><?=$totalOrders?></h3></div>
    </div>
    <div class="stat-card revenue">
      <div class="stat-card-icon"><i class="bi bi-cash-stack"></i></div>
      <div><p>Total Spent</p><h3>₱<?=number_format($userTotalSpent,2)?></h3></div>
    </div>
    <div class="stat-card users">
      <div class="stat-card-icon"><i class="bi bi-star-fill"></i></div>
      <div><p>Saved Faves</p><h3 id="favCount">0</h3></div>
    </div>
    <div class="stat-card pending">
      <div class="stat-card-icon"><i class="bi bi-clock-history"></i></div>
      <div><p>Active Orders</p><h3><?=count($user_orders)?></h3></div>
    </div>
  </div>

  <!-- TODAY'S ULAM -->
  <?php if (!empty($todayUlam)): ?>
  <div class="ulam-card fade-in">
    <div class="ulam-img-wrap">
      <img src="<?=htmlspecialchars(getProductImage($todayUlam))?>"
           alt="<?=htmlspecialchars($todayUlam['name'])?>"
           onclick="showImgPopup('<?=htmlspecialchars(getProductImage($todayUlam))?>','<?=addslashes(htmlspecialchars($todayUlam['name']))?>',<?=$todayUlam['price']?>,'<?=addslashes(htmlspecialchars($todayUlam['description']??''))?>')"
           onerror="this.onerror=null;this.src='<?=IMG_PLACEHOLDER?>';"/>
    </div>
    <div class="ulam-body">
      <div class="d-flex align-items-center gap-2 mb-1">
        <span class="badge" style="background:var(--grad-primary);font-size:.7rem">⭐ Today's Pick</span>
      </div>
      <h4 class="fw-bold mb-1"><?=htmlspecialchars($todayUlam['name'])?></h4>
      <p class="mb-2" style="font-size:.875rem"><?=htmlspecialchars(mb_substr($todayUlam['description']??'Authentic Filipino dish freshly prepared!',0,100))?></p>
      <div class="d-flex align-items-center gap-3 flex-wrap">
        <span style="font-size:1.4rem;font-weight:900;color:var(--primary);font-family:'Nunito',sans-serif">
          ₱<?=number_format($todayUlam['price'],2)?>
        </span>
        <form method="POST" class="d-inline">
          <input type="hidden" name="product_id" value="<?=$todayUlam['id']?>"/>
          <button type="submit" name="add_to_cart" class="btn btn-primary btn-sm">
            <i class="bi bi-cart-plus me-1"></i>Add to Cart
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ACTIVE ORDERS -->
  <?php if (!empty($user_orders)): ?>
  <div class="card mb-4 fade-in">
    <div class="card-header d-flex align-items-center justify-content-between">
      <span><i class="bi bi-clock-history me-2" style="color:var(--primary)"></i>Active Orders</span>
      <span class="badge" style="background:var(--grad-primary)"><?=count($user_orders)?></span>
    </div>
    <div class="card-body p-2">
      <?php foreach ($user_orders as $order): ?>
      <div class="active-order-card">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <strong style="font-size:.875rem">Order #<?=$order['id']?></strong>
          <?php $s=$order['status'];
          $bc=match($s){'pending'=>'bg-warning text-dark','serving'=>'bg-info text-dark','ready'=>'bg-success',default=>'bg-secondary'}; ?>
          <span class="badge <?=$bc?>"><?=ucfirst($s)?></span>
        </div>
        <div class="order-progress">
          <div class="order-step <?=in_array($s,['pending','serving','ready'])?'active':''?>"><i class="bi bi-clock"></i></div>
          <div class="order-step-label">Pending</div>
          <div class="order-step <?=in_array($s,['serving','ready'])?'active':''?>"><i class="bi bi-fire"></i></div>
          <div class="order-step-label">Serving</div>
          <div class="order-step <?=$s==='ready'?'completed':''?>"><i class="bi bi-check-circle"></i></div>
          <div class="order-step-label">Ready</div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- SESSION TOAST -->
  <?php if ($message): ?>
  <script>
    document.addEventListener('DOMContentLoaded',()=>showToast('<?=addslashes(htmlspecialchars($message))?>','info'));
  </script>
  <?php endif; ?>

  <!-- SEARCH BAR -->
  <div class="search-wrap" id="menu">
    <i class="bi bi-search search-icon"></i>
    <input type="text" class="form-control" id="menuSearch" placeholder="Search menu items…" oninput="filterMenu(this.value)"/>
  </div>

  <!-- CATEGORY NAV -->
  <div class="menu-category-nav mb-3">
    <div class="pill-wrap px-1">
      <a href="#meals"    class="cat-pill active" data-cat="meals">🍛 Meals</a>
      <a href="#rices"    class="cat-pill" data-cat="rices">🍚 Rice</a>
      <a href="#drinks"   class="cat-pill" data-cat="drinks">🥤 Drinks</a>
      <a href="#desserts" class="cat-pill" data-cat="desserts">🍰 Desserts</a>
    </div>
  </div>

  <!-- MENU SECTIONS -->
  <div id="menuContainer">

    <?php
    function renderMenuSection(string $id, string $emoji, string $title, string $sub, array $items, callable $getImg, string $ph): void {
      if (empty($items)) return;
    ?>
    <section id="<?=$id?>" class="menu-section mb-5" data-section="<?=$id?>">
      <div class="section-header mb-3">
        <div>
          <h3 class="fw-bold mb-0"><?=$emoji?> <?=$title?></h3>
          <p style="font-size:.8rem"><?=$sub?></p>
        </div>
        <span class="badge" style="background:var(--grad-primary);font-size:.72rem"><?=count($items)?> items</span>
      </div>
      <div class="products-grid stagger">
        <?php foreach ($items as $p):
          $outOfStock  = $p['stock'] <= 0;
          $lowStock    = !$outOfStock && $p['stock'] <= 5;
          $imgSrc      = htmlspecialchars($getImg($p));
          $pName       = addslashes(htmlspecialchars($p['name']));
          $pDesc       = addslashes(htmlspecialchars($p['description'] ?? 'Authentic Filipino dish, freshly prepared.'));
          $pPrice      = number_format($p['price'], 2);
          $pId         = (int)$p['id'];
          $pStock      = (int)$p['stock'];
          if ($pDesc === '') $pDesc = 'Authentic Filipino dish, freshly prepared.';
        ?>
        <div class="dish-card menu-item"
             data-name="<?=strtolower(htmlspecialchars($p['name']))?>"
             data-section="<?=$id?>"
             data-pid="<?=$pId?>">

          <?php if ($outOfStock): ?>
            <span class="stock-badge out">Out of Stock</span>
          <?php elseif ($lowStock): ?>
            <span class="stock-badge low">Only <?=$pStock?> left</span>
          <?php endif; ?>

          <!-- Thumbnail — click = full lightbox -->
          <div class="dish-thumb-wrap"
               onclick="showImgPopup('<?=$imgSrc?>','<?=$pName?>',<?=$p['price']?>,'<?=$pDesc?>',<?=$pId?>,<?=$outOfStock?'true':'false'?>)">
            <img src="<?=$imgSrc?>" class="dish-thumb" alt="<?=htmlspecialchars($p['name'])?>"
                 loading="lazy" onerror="this.onerror=null;this.src='<?=$ph?>';"/>
          </div>

          <!-- Name + price -->
          <div class="dish-card-body">
            <div class="dish-card-name" title="<?=htmlspecialchars($p['name'])?>"><?=htmlspecialchars($p['name'])?></div>
            <div class="dish-card-price">₱<?=$pPrice?></div>
          </div>

          <!-- Add to cart + fav buttons -->
          <div class="dish-card-footer">
            <form method="POST" style="flex:1;display:contents">
              <input type="hidden" name="product_id" value="<?=$pId?>"/>
              <?php if (!$outOfStock): ?>
                <button type="submit" name="add_to_cart" class="btn-add" onclick="event.stopPropagation()">
                  <i class="bi bi-cart-plus"></i> Add
                </button>
              <?php else: ?>
                <button class="btn-add" disabled><i class="bi bi-x-circle"></i> Unavailable</button>
              <?php endif; ?>
            </form>
            <button class="fav-btn-sm" title="Favourite"
                    data-pid="<?=$pId?>"
                    onclick="event.stopPropagation(); toggleFavorite(<?=$pId?>, this)">
              <i class="bi bi-heart-fill"></i>
            </button>
          </div>

          <!-- ── HOVER PREVIEW CARD ── -->
          <div class="hover-preview" onclick="event.stopPropagation()">
            <img src="<?=$imgSrc?>" class="hover-preview-img" alt="<?=htmlspecialchars($p['name'])?>"
                 onerror="this.onerror=null;this.src='<?=$ph?>';"/>
            <div class="hover-preview-body">
              <div class="hover-preview-name"><?=htmlspecialchars($p['name'])?></div>
              <div class="hover-preview-price">₱<?=$pPrice?></div>
              <p class="hover-preview-desc"><?=htmlspecialchars($p['description'] ?? 'Authentic Filipino dish, freshly prepared.')?></p>
              <span class="hover-preview-stock <?=$outOfStock?'out':($lowStock?'low':'in')?>">
                <?=$outOfStock ? 'Out of Stock' : ($lowStock ? "Only {$pStock} left" : "In Stock ({$pStock})")?>
              </span>
              <div class="hover-preview-actions">
                <?php if (!$outOfStock): ?>
                <form method="POST" style="flex:1;display:contents">
                  <input type="hidden" name="product_id" value="<?=$pId?>"/>
                  <button type="submit" name="add_to_cart" class="btn-hp-add">
                    <i class="bi bi-cart-plus"></i> Add to Cart
                  </button>
                </form>
                <?php else: ?>
                  <button class="btn-hp-add" disabled style="flex:1"><i class="bi bi-x-circle"></i> Unavailable</button>
                <?php endif; ?>
                <button class="btn-hp-fav"
                        data-pid="<?=$pId?>"
                        onclick="syncFav(<?=$pId?>, this)"
                        title="Favourite">
                  <i class="bi bi-heart-fill"></i>
                </button>
              </div>
            </div>
          </div><!-- /.hover-preview -->

        </div><!-- /.dish-card -->
        <?php endforeach; ?>
      </div>
    </section>
    <?php } ?>

    <?php
    renderMenuSection('meals',    '🍛','Meals / Main Dishes','Sarap na ulam for your karenderia dining experience.',$meals,    'getProductImage',IMG_PLACEHOLDER);
    renderMenuSection('rices',    '🍚','Rice','Perfect pairings for every ulam.',                                  $rices,    'getProductImage',IMG_PLACEHOLDER);
    renderMenuSection('drinks',   '🥤','Drinks / Softdrinks','Refreshments that complete your meal.',              $drinks,   'getProductImage',IMG_PLACEHOLDER);
    renderMenuSection('desserts', '🍰','Desserts','Sweet Filipino favorites to end your meal.',                    $desserts, 'getProductImage',IMG_PLACEHOLDER);
    ?>

    <!-- Empty state when search yields nothing -->
    <div id="emptySearch" class="text-center py-5" style="display:none">
      <div style="font-size:3rem">🔍</div>
      <h5 class="text-muted mt-2">No items found</h5>
      <p class="text-muted" style="font-size:.875rem">Try a different keyword</p>
    </div>

  </div><!-- /#menuContainer -->

</div><!-- /.page-wrapper -->

<!-- ── FLOATING CART BUTTON ── -->
<button class="floating-cart-btn" onclick="openCartModal()" id="cartButton">
  <i class="bi bi-cart3"></i>
  <span class="cart-counter" id="cartCounter" <?=$cartCount==0?'style="display:none"':''?>><?=$cartCount?></span>
</button>

<!-- ── IMAGE POPUP OVERLAY ── -->
<div class="img-popup-overlay" id="imgPopupOverlay" onclick="closeImgPopup(event)">
  <div class="img-popup-box">
    <button class="img-popup-close" onclick="closeImgPopup(null,true)"><i class="bi bi-x-lg"></i></button>
    <img id="popupImg" src="" alt="" class="img-popup-img" onerror="this.onerror=null;this.src='<?=IMG_PLACEHOLDER?>';"/>
    <div class="img-popup-info">
      <h5 id="popupName" class="fw-bold mb-1"></h5>
      <div class="popup-price" id="popupPrice"></div>
      <p class="popup-desc" id="popupDesc"></p>
      <form method="POST" class="d-grid mt-2" id="popupForm">
        <input type="hidden" id="popupProductId" name="product_id"/>
        <button type="submit" name="add_to_cart" class="btn btn-primary btn-sm" id="popupAddBtn">
          <i class="bi bi-cart-plus me-1"></i>Add to Cart
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ── CART MODAL ── -->
<div class="modal fade" id="cartModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-cart3 me-2" style="color:var(--primary)"></i>Your Cart</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="cartModalBody" style="max-height:65vh;overflow-y:auto">
        <?php if (empty($cart_items)): ?>
        <div class="text-center py-5">
          <div style="font-size:3.5rem">🛒</div>
          <h5 class="text-muted mt-2">Your cart is empty</h5>
          <p class="text-muted" style="font-size:.875rem">Add some delicious food to get started!</p>
        </div>
        <?php else: ?>
        <div class="d-flex flex-column gap-2 mb-3">
          <?php foreach ($cart_items as $item): ?>
          <div class="cart-item" data-product-id="<?=$item['product']['id']?>">
            <div class="d-flex align-items-center gap-3">
              <img src="<?=htmlspecialchars(getProductImage($item['product']))?>"
                   alt="<?=htmlspecialchars($item['product']['name'])?>"
                   class="cart-item-img"
                   onerror="this.onerror=null;this.src='<?=IMG_PLACEHOLDER?>';"/>
              <div class="flex-grow-1" style="min-width:0">
                <strong style="font-size:.875rem;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($item['product']['name'])?></strong>
                <small class="text-muted">₱<?=number_format($item['product']['price'],2)?> each</small>
                <div class="d-flex align-items-center gap-1 mt-1">
                  <button class="btn btn-sm btn-outline-secondary qty-btn" onclick="updateQuantity(<?=$item['product']['id']?>,-1)"><i class="bi bi-dash"></i></button>
                  <span class="qty-display mx-2 fw-bold" id="qty-<?=$item['product']['id']?>"><?=$item['qty']?></span>
                  <button class="btn btn-sm btn-outline-secondary qty-btn" onclick="updateQuantity(<?=$item['product']['id']?>,1)"><i class="bi bi-plus"></i></button>
                  <button class="btn btn-sm btn-outline-danger ms-2 qty-btn" onclick="removeFromCart(<?=$item['product']['id']?>)"><i class="bi bi-trash"></i></button>
                </div>
              </div>
              <div class="text-end" style="flex-shrink:0">
                <strong style="color:var(--primary)">₱<?=number_format($item['product']['price']*$item['qty'],2)?></strong>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <hr class="my-2"/>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="mb-0 fw-bold">Total:</h6>
          <h5 class="mb-0 fw-bold" style="color:var(--primary)" id="cartTotal">₱<?=number_format($total,2)?></h5>
        </div>
        <button class="btn btn-success w-100" onclick="proceedToCheckout()">
          <i class="bi bi-credit-card me-1"></i>Proceed to Checkout
        </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── CHECKOUT MODAL ── -->
<div class="modal fade" id="checkoutModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-credit-card me-2" style="color:var(--success)"></i>Checkout</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3 p-3 rounded-3" style="background:var(--surface-2);border:1px solid var(--border)">
          <h6 class="fw-bold mb-2" style="font-size:.875rem">📋 Order Summary</h6>
          <?php foreach ($cart_items as $item): ?>
          <div class="d-flex justify-content-between py-1" style="font-size:.85rem">
            <span><?=htmlspecialchars($item['product']['name'])?> <span class="text-muted">×<?=$item['qty']?></span></span>
            <span class="fw-bold">₱<?=number_format($item['product']['price']*$item['qty'],2)?></span>
          </div>
          <?php endforeach; ?>
          <hr class="my-2"/>
          <div class="d-flex justify-content-between fw-bold">
            <span>Total Amount:</span>
            <span style="color:var(--primary)" id="checkoutTotal">₱<?=number_format($total,2)?></span>
          </div>
        </div>

        <?php if ($checkoutError): ?>
        <div class="alert alert-danger mb-3" style="font-size:.875rem"><?=htmlspecialchars($checkoutError)?></div>
        <?php endif; ?>

        <form method="POST" id="checkoutForm">
          <div class="mb-3">
            <label class="form-label">Cash Amount (₱) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" min="<?=$total?>" name="cash_amount" id="cash_amount"
                   class="form-control form-control-lg" placeholder="Enter cash amount"
                   value="<?=isset($_POST['cash_amount'])?htmlspecialchars($_POST['cash_amount']):''?>" required/>
            <div class="form-text">Minimum: ₱<?=number_format($total,2)?></div>
          </div>
          <div class="alert alert-success mb-3" id="changePreview" style="display:none;font-size:.875rem">
            💰 Change: <strong id="changeAmt">₱0.00</strong>
          </div>
          <button type="submit" name="checkout" id="checkoutButton" class="btn btn-success w-100" disabled>
            <i class="bi bi-check-circle me-1"></i>Complete Order
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Image lightbox popup ──────────────────────────────────────────────────────
function showImgPopup(src, name, price, desc, productId, outOfStock) {
  document.getElementById('popupImg').src  = src;
  document.getElementById('popupName').textContent  = name;
  document.getElementById('popupPrice').textContent = price
    ? '₱' + parseFloat(price).toLocaleString('en-PH', {minimumFractionDigits:2}) : '';
  document.getElementById('popupDesc').textContent  = desc || '';
  document.getElementById('popupProductId').value   = productId || '';

  const btn = document.getElementById('popupAddBtn');
  if (outOfStock) {
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-x-circle me-1"></i>Out of Stock';
    btn.className = 'btn btn-secondary btn-sm';
  } else {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-cart-plus me-1"></i>Add to Cart';
    btn.className = 'btn btn-primary btn-sm';
  }

  document.getElementById('imgPopupOverlay').classList.add('active');
  document.body.style.overflow = 'hidden';
}

function closeImgPopup(e, force) {
  if (force || !e || e.target === document.getElementById('imgPopupOverlay')) {
    document.getElementById('imgPopupOverlay').classList.remove('active');
    document.body.style.overflow = '';
  }
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeImgPopup(null, true); });

// ── Cart modal ────────────────────────────────────────────────────────────────
function openCartModal() { new bootstrap.Modal(document.getElementById('cartModal')).show(); }

function proceedToCheckout() {
  bootstrap.Modal.getInstance(document.getElementById('cartModal'))?.hide();
  setTimeout(() => new bootstrap.Modal(document.getElementById('checkoutModal')).show(), 300);
}

function updateQuantity(productId, change) {
  const qtyEl = document.getElementById(`qty-${productId}`);
  let qty = parseInt(qtyEl.textContent) + change;
  if (qty < 1) qty = 1;
  qtyEl.textContent = qty;
  updateCartItem(productId, qty);
}

function removeFromCart(productId) {
  if (confirm('Remove this item from your cart?')) updateCartItem(productId, 0);
}

function updateCartItem(productId, quantity) {
  fetch('update_cart.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({product_id: productId, quantity})
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      updateCartCounter(data.cart_count);
      const ct = document.getElementById('cartTotal');
      if (ct) ct.textContent = '₱' + parseFloat(data.total).toLocaleString('en-PH', {minimumFractionDigits:2});
      if (quantity === 0) {
        document.querySelector(`.cart-item[data-product-id="${productId}"]`)?.remove();
        if (!document.querySelectorAll('.cart-item').length) location.reload();
      }
      showToast(data.message, 'success');
    } else {
      showToast(data.message || 'Failed to update cart', 'danger');
    }
  })
  .catch(() => showToast('Network error — please try again', 'danger'));
}

function updateCartCounter(count) {
  const c = document.getElementById('cartCounter');
  c.textContent   = count > 0 ? count : '';
  c.style.display = count > 0 ? 'flex' : 'none';
}

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(message, type = 'info') {
  let container = document.getElementById('toastContainer');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toastContainer';
    container.className = 'toast-container position-fixed top-0 end-0 p-3';
    container.style.zIndex = '9999';
    document.body.appendChild(container);
  }
  const icons = {success:'check-circle', danger:'exclamation-triangle', info:'info-circle', warning:'exclamation-circle'};
  const toast = document.createElement('div');
  toast.className = `toast align-items-center text-white bg-${type} border-0`;
  toast.setAttribute('role', 'alert');
  toast.innerHTML = `<div class="d-flex">
    <div class="toast-body"><i class="bi bi-${icons[type]||'info-circle'} me-2"></i>${message}</div>
    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
  </div>`;
  container.appendChild(toast);
  new bootstrap.Toast(toast, {autohide: true, delay: 3000}).show();
  toast.addEventListener('hidden.bs.toast', () => toast.remove());
}

// ── Checkout validation ───────────────────────────────────────────────────────
function validateCheckout() {
  const total = <?=$total?>;
  const cash  = parseFloat(document.getElementById('cash_amount')?.value) || 0;
  const btn   = document.getElementById('checkoutButton');
  const prev  = document.getElementById('changePreview');
  const chAmt = document.getElementById('changeAmt');
  const valid = cash >= total && cash > 0 && total > 0;
  if (btn) btn.disabled = !valid;
  if (valid && prev && chAmt) {
    prev.style.display = 'block';
    chAmt.textContent  = '₱' + (cash - total).toLocaleString('en-PH', {minimumFractionDigits:2});
  } else if (prev) {
    prev.style.display = 'none';
  }
}

// ── Favourites (shared between card + hover preview) ─────────────────────────
// Both the small .fav-btn-sm and the .btn-hp-fav carry data-pid.
// syncFav keeps both in sync when one is clicked.
function toggleFavorite(productId, btn) {
  const favorited = btn.classList.contains('favorited');
  _applyFav(productId, !favorited);
  showToast(favorited ? 'Removed from favourites' : '❤️ Added to favourites!', favorited ? 'info' : 'success');
}

function syncFav(productId, btn) {
  const favorited = btn.classList.contains('favorited');
  _applyFav(productId, !favorited);
  showToast(favorited ? 'Removed from favourites' : '❤️ Added to favourites!', favorited ? 'info' : 'success');
}

function _applyFav(productId, add) {
  let favs = JSON.parse(localStorage.getItem('fh_favorites') || '[]');
  favs = add ? [...new Set([...favs, productId])] : favs.filter(id => id != productId);
  localStorage.setItem('fh_favorites', JSON.stringify(favs));

  const countEl = document.getElementById('favCount');
  if (countEl) countEl.textContent = favs.length;

  // Sync ALL buttons (card + hover preview) for this product
  document.querySelectorAll(`[data-pid="${productId}"]`).forEach(el => {
    el.classList.toggle('favorited', add);
  });
}

// ── Menu search ───────────────────────────────────────────────────────────────
function filterMenu(query) {
  const q = query.trim().toLowerCase();
  const sections = document.querySelectorAll('.menu-section');
  let anyVisible = false;

  sections.forEach(sec => {
    const secItems = sec.querySelectorAll('.menu-item');
    let secVisible = false;
    secItems.forEach(item => {
      const match = !q || item.dataset.name.includes(q);
      item.style.display = match ? '' : 'none';
      if (match) { secVisible = true; anyVisible = true; }
    });
    sec.style.display = secVisible ? '' : 'none';
  });

  document.getElementById('emptySearch').style.display = anyVisible ? 'none' : 'block';
}

// ── DOMContentLoaded ──────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {

  // Checkout validation
  const cashField = document.getElementById('cash_amount');
  if (cashField) { cashField.addEventListener('input', validateCheckout); validateCheckout(); }

  // Restore favourites from localStorage
  const favs = JSON.parse(localStorage.getItem('fh_favorites') || '[]');
  const countEl = document.getElementById('favCount');
  if (countEl) countEl.textContent = favs.length;
  favs.forEach(id => {
    document.querySelectorAll(`[data-pid="${id}"]`).forEach(el => el.classList.add('favorited'));
  });

  // Category pill active-state via IntersectionObserver
  const sections = document.querySelectorAll('.menu-section');
  const pills    = document.querySelectorAll('.cat-pill');
  const observer = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        pills.forEach(p => p.classList.remove('active'));
        document.querySelector(`.cat-pill[data-cat="${e.target.id}"]`)?.classList.add('active');
      }
    });
  }, {threshold: .3});
  sections.forEach(s => observer.observe(s));

  // Category pill click highlight
  pills.forEach(pill => {
    pill.addEventListener('click', function () {
      pills.forEach(p => p.classList.remove('active'));
      this.classList.add('active');
    });
  });

  // Hover preview: flip left when card is in last 2 columns of the row
  // and flip down when near the top of viewport
  function positionPreviews() {
    document.querySelectorAll('.dish-card').forEach(card => {
      const rect  = card.getBoundingClientRect();
      const vpW   = window.innerWidth;
      const vpH   = window.innerHeight;

      // If not enough space on the right → flip left
      card.classList.toggle('flip-left',  rect.right + 230 > vpW);
      // If card is in the upper 30% of viewport → flip down
      card.classList.toggle('flip-down',  rect.top < vpH * 0.30);
    });
  }

  positionPreviews();
  window.addEventListener('resize', positionPreviews);
  document.addEventListener('scroll', positionPreviews, {passive: true});
});

// ── Scroll restore ────────────────────────────────────────────────────────────
(function () {
  const s = sessionStorage.getItem('fhScroll');
  if (s) { window.scrollTo(0, parseInt(s, 10)); sessionStorage.removeItem('fhScroll'); }
})();
window.addEventListener('beforeunload', () => sessionStorage.setItem('fhScroll', window.scrollY));
</script>
</body>
</html>