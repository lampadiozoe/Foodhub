<?php
session_start();
include __DIR__ . '/../../config/db.php';
include __DIR__ . '/../../includes/image_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header('Location: ' . BASE_URL . '/public/login.php'); exit();
}

$name    = $_SESSION['name'];
$user_id = (int)$_SESSION['user_id'];
$B       = BASE_URL;

// ── Add to cart ───────────────────────────────────────────────────────────────
if (isset($_POST['add_to_cart'])) {
    $pid  = (int)$_POST['product_id'];
    $stmt = $conn->prepare('SELECT stock FROM products WHERE id = ? AND is_active = 1');
    $stmt->bind_param('i', $pid); $stmt->execute(); $stmt->bind_result($stk); $stmt->fetch(); $stmt->close();
    if ($stk > 0) {
        $stmt = $conn->prepare('INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE quantity = quantity + 1');
        $stmt->bind_param('ii', $user_id, $pid); $stmt->execute(); $stmt->close();
        $_SESSION['message'] = 'Item added to cart! 🛒';
    } else {
        $_SESSION['message'] = 'Out of stock, please pick another item.';
    }
    header('Location: dashboard.php#menu'); exit();
}

// ── Remove from cart ──────────────────────────────────────────────────────────
if (isset($_POST['remove_from_cart'])) {
    $pid  = (int)$_POST['product_id'];
    $stmt = $conn->prepare('DELETE FROM cart WHERE user_id = ? AND product_id = ?');
    $stmt->bind_param('ii', $user_id, $pid); $stmt->execute(); $stmt->close();
    header('Location: dashboard.php'); exit();
}

// ── Checkout ──────────────────────────────────────────────────────────────────
$checkoutError = '';
if (isset($_POST['checkout'])) {
    $stmt = $conn->prepare("SELECT c.product_id,c.quantity,p.price,p.stock,p.name FROM cart c JOIN products p ON c.product_id=p.id WHERE c.user_id=?");
    $stmt->bind_param('i', $user_id); $stmt->execute();
    $res = $stmt->get_result(); $cart_items = []; $total = 0;
    while ($row = $res->fetch_assoc()) { $cart_items[] = $row; $total += $row['price'] * $row['quantity']; }
    $stmt->close();

    if (empty($cart_items)) { $_SESSION['message'] = 'Cart is empty.'; header('Location: dashboard.php'); exit(); }

    $cashAmount = trim($_POST['cash_amount'] ?? '');
    if ($cashAmount === '') $checkoutError = 'Please enter payment amount.';
    elseif (!is_numeric($cashAmount) || floatval($cashAmount) < $total) $checkoutError = 'Insufficient amount.';

    if ($checkoutError === '') {
        foreach ($cart_items as $it) {
            if ($it['stock'] < $it['quantity']) { $checkoutError = 'Insufficient stock for ' . $it['name']; break; }
        }
    }

    if ($checkoutError === '') {
        $cashAmount   = floatval($cashAmount);
        $changeAmount = $cashAmount - $total;
        $stmt = $conn->prepare('INSERT INTO orders (user_id,total) VALUES (?,?)');
        $stmt->bind_param('id', $user_id, $total); $stmt->execute();
        $order_id = $stmt->insert_id; $stmt->close();

        $receipt_items = [];
        foreach ($cart_items as $it) {
            $iTotal = $it['price'] * $it['quantity'];
            $receipt_items[] = ['product' => ['id' => $it['product_id'], 'name' => $it['name'], 'price' => $it['price']], 'qty' => $it['quantity'], 'item_total' => $iTotal];
            $stmt = $conn->prepare('INSERT INTO order_items (order_id,product_id,quantity,price) VALUES (?,?,?,?)');
            $stmt->bind_param('iiid', $order_id, $it['product_id'], $it['quantity'], $it['price']); $stmt->execute(); $stmt->close();
            $stmt = $conn->prepare('UPDATE products SET stock=stock-? WHERE id=?');
            $stmt->bind_param('ii', $it['quantity'], $it['product_id']); $stmt->execute(); $stmt->close();
        }
        $_SESSION['receipt'] = ['order_id' => $order_id, 'username' => $name, 'items' => $receipt_items, 'total' => $total, 'cash_given' => $cashAmount, 'change' => $changeAmount, 'order_time' => date('Y-m-d H:i:s'), 'status' => 'pending'];
        $stmt = $conn->prepare('DELETE FROM cart WHERE user_id=?');
        $stmt->bind_param('i', $user_id); $stmt->execute(); $stmt->close();
        header('Location: receipt.php'); exit();
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────
$products = [];
$r = $conn->query('SELECT * FROM products WHERE is_active = 1');
while ($row = $r->fetch_assoc()) $products[] = $row;

$meals = $rices = $drinks = $desserts = [];
foreach ($products as $p) {
    $n = strtolower($p['name']);
    if (preg_match('/rice|kan-on|kanon|unli/', $n))                              $rices[]    = $p;
    elseif (preg_match('/drink|softdrink|cola|sprite|royal|soda|juice|tea|coffee/', $n)) $drinks[] = $p;
    elseif (preg_match('/halo|sorbetes|dessert|cake|leche|ube/', $n))            $desserts[] = $p;
    else                                                                          $meals[]    = $p;
}

$user_orders = [];
$r = $conn->query("SELECT id,status,order_time FROM orders WHERE user_id=$user_id AND status IN ('pending','serving','ready') ORDER BY order_time DESC");
while ($row = $r->fetch_assoc()) $user_orders[] = $row;

$order_history = [];
$r = $conn->query("SELECT id,total,status,order_time FROM orders WHERE user_id=$user_id AND status='completed' ORDER BY order_time DESC LIMIT 10");
while ($row = $r->fetch_assoc()) $order_history[] = $row;

$userTotalSpent = (float)($conn->query("SELECT SUM(total) s FROM orders WHERE user_id=$user_id")->fetch_assoc()['s'] ?? 0);
$totalOrders    = (int)$conn->query("SELECT COUNT(*) c FROM orders WHERE user_id=$user_id")->fetch_assoc()['c'];

// Load cart
$total = 0; $cart_items = [];
$stmt = $conn->prepare("SELECT c.product_id,c.quantity,p.id,p.name,p.price,p.image FROM cart c JOIN products p ON c.product_id=p.id WHERE c.user_id=? ORDER BY c.added_at DESC");
$stmt->bind_param('i', $user_id); $stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) { $cart_items[] = ['product' => $row, 'qty' => $row['quantity']]; $total += $row['price'] * $row['quantity']; }
$stmt->close();

$message   = $_SESSION['message'] ?? ''; unset($_SESSION['message']);
$todayUlam = !empty($meals) ? $meals[array_rand($meals)] : (!empty($products) ? $products[array_rand($products)] : []);
$cartCount = 0; foreach ($cart_items as $ci) $cartCount += $ci['qty'];
$firstName = explode(' ', $name)[0];
$hour      = (int)date('G');
$greeting  = $hour < 12 ? 'Magandang umaga' : ($hour < 17 ? 'Magandang hapon' : 'Magandang gabi');
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
  <link rel="stylesheet" href="<?= $B ?>/css/style.css"/>
  <style>
:root{--brand:#e8521a;--brand-2:#f97316;--teal:#0d9488;--indigo:#4f46e5;--bg:#faf9f7;--surface:#ffffff;--surface-2:#f5f3ef;--border:#e7e5e1;--text:#1c1917;--text-2:#57534e;--muted:#a8a29e;--grad-brand:linear-gradient(135deg,#e8521a 0%,#f97316 100%);--grad-warm:linear-gradient(135deg,#e8521a 0%,#f59e0b 60%,#ef4444 100%);--grad-teal:linear-gradient(135deg,#0d9488,#06b6d4);--sh-sm:0 1px 3px rgba(0,0,0,.08);--sh-md:0 4px 16px rgba(0,0,0,.09);--sh-lg:0 12px 36px rgba(0,0,0,.13);--sh-xl:0 24px 60px rgba(0,0,0,.18);--sh-brand:0 8px 28px rgba(232,82,26,.35);--r-sm:8px;--r-md:14px;--r-lg:20px;--r-xl:28px;--r-full:999px;--font-head:'Nunito',sans-serif;--font-body:'DM Sans',sans-serif;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;}
body{font-family:var(--font-body);background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden;}
h1,h2,h3,h4,h5,h6{font-family:var(--font-head);font-weight:700;color:var(--text);line-height:1.15;}
a{text-decoration:none;color:inherit;}
img{display:block;}
*{scrollbar-width:thin;scrollbar-color:var(--brand) var(--surface-2);}
::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-thumb{background:var(--brand);border-radius:99px;}
.topbar{position:sticky;top:0;z-index:200;background:rgba(28,25,23,.96);backdrop-filter:blur(20px);border-bottom:1px solid rgba(255,255,255,.07);height:58px;display:flex;align-items:center;padding:0 1.5rem;gap:1rem;}
.topbar-brand{font-family:var(--font-head);font-weight:800;font-size:1.25rem;color:#fff;display:flex;align-items:center;gap:8px;flex-shrink:0;}
.topbar-brand .bi{width:30px;height:30px;background:var(--grad-brand);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.9rem;}
.topbar-spacer{flex:1;}
.topbar-search{flex:0 1 320px;position:relative;}
.topbar-search input{width:100%;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:var(--r-full);color:#fff;font-family:var(--font-body);font-size:.8rem;padding:.45rem 1rem .45rem 2.2rem;outline:none;transition:all .2s;}
.topbar-search input::placeholder{color:rgba(255,255,255,.35);}
.topbar-search input:focus{background:rgba(255,255,255,.13);border-color:var(--brand);box-shadow:0 0 0 3px rgba(232,82,26,.2);}
.topbar-search .si{position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:rgba(255,255,255,.4);font-size:.8rem;pointer-events:none;}
.topbar-user{display:flex;align-items:center;gap:.5rem;color:rgba(255,255,255,.75);font-size:.8rem;font-weight:500;flex-shrink:0;}
.topbar-avatar{width:30px;height:30px;border-radius:50%;background:var(--grad-brand);display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800;color:#fff;font-family:var(--font-head);flex-shrink:0;}
.topbar-logout{color:rgba(255,255,255,.5)!important;font-size:.78rem;font-weight:600;padding:.3rem .75rem;border-radius:var(--r-full);border:1px solid rgba(255,255,255,.12);transition:all .2s;display:flex;align-items:center;gap:4px;}
.topbar-logout:hover{background:rgba(255,255,255,.1);color:#fff!important;}
.shell{display:grid;grid-template-columns:1fr 340px;min-height:calc(100vh - 58px);max-width:1440px;margin:0 auto;}
@media(max-width:1100px){.shell{grid-template-columns:1fr}.cart-panel{display:none}}
.main-col{padding:1.5rem 1.5rem 3rem;overflow-x:hidden;}
.hero-strip{background:var(--grad-warm);border-radius:var(--r-xl);padding:2rem 2rem 2rem 2.2rem;position:relative;overflow:hidden;margin-bottom:1.5rem;box-shadow:var(--sh-brand);}
.hero-strip::before{content:'';position:absolute;width:280px;height:280px;border-radius:50%;background:rgba(255,255,255,.08);right:-80px;top:-100px;}
.hero-strip::after{content:'🍽️';position:absolute;right:1.8rem;bottom:-8px;font-size:5.5rem;line-height:1;filter:drop-shadow(0 4px 16px rgba(0,0,0,.25));animation:hero-float 3s ease-in-out infinite;}
@keyframes hero-float{0%,100%{transform:translateY(0) rotate(-4deg)}50%{transform:translateY(-8px) rotate(4deg)}}
.hero-strip h1{font-size:clamp(1.4rem,3vw,2rem);color:#fff;font-weight:800;margin-bottom:.35rem;}
.hero-strip p{color:rgba(255,255,255,.85);font-size:.875rem;max-width:360px;}
.hero-greeting{font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.65);margin-bottom:.4rem;display:block;}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:.85rem;margin-bottom:1.5rem;}
@media(max-width:600px){.stats-row{grid-template-columns:1fr 1fr}}
.sc{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:1.1rem 1.2rem;display:flex;align-items:center;gap:.85rem;box-shadow:var(--sh-sm);transition:transform .2s,box-shadow .2s;cursor:default;}
.sc:hover{transform:translateY(-3px);box-shadow:var(--sh-md);}
.sc-icon{width:44px;height:44px;flex-shrink:0;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;}
.sc-icon.brand{background:#fff0eb;color:var(--brand);}
.sc-icon.teal{background:#ccfbf1;color:var(--teal);}
.sc-icon.indigo{background:#ede9fe;color:var(--indigo);}
.sc-label{font-size:.72rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:.2rem;}
.sc-value{font-family:var(--font-head);font-size:1.35rem;font-weight:800;color:var(--text);line-height:1;}
.active-orders-section{background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1.5px solid #86efac;border-radius:var(--r-lg);padding:1.1rem 1.3rem;margin-bottom:1.5rem;}
.ao-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:.85rem;}
.ao-header h5{font-size:.9rem;font-weight:800;color:#15803d;display:flex;align-items:center;gap:.4rem;}
.ao-item{background:#fff;border-radius:var(--r-md);padding:.8rem 1rem;margin-bottom:.5rem;border:1px solid #bbf7d0;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;}
.ao-item:last-child{margin-bottom:0;}
.ao-id{font-weight:800;font-size:.875rem;color:var(--text);min-width:70px;}
.ao-progress{display:flex;align-items:center;flex:1;min-width:160px;}
.ao-step{display:flex;flex-direction:column;align-items:center;gap:3px;flex:1;}
.ao-dot{width:28px;height:28px;border-radius:50%;background:#e2e8f0;color:#94a3b8;display:flex;align-items:center;justify-content:center;font-size:.75rem;position:relative;z-index:2;transition:all .3s;}
.ao-dot.done{background:var(--grad-brand);color:#fff;box-shadow:0 3px 10px rgba(232,82,26,.3);}
.ao-dot.pulse{background:var(--grad-teal);color:#fff;box-shadow:0 3px 10px rgba(13,148,136,.3);animation:pulse-dot 1.5s infinite;}
@keyframes pulse-dot{0%,100%{box-shadow:0 3px 10px rgba(13,148,136,.3)}50%{box-shadow:0 0 0 6px rgba(13,148,136,.12)}}
.ao-dot-label{font-size:.6rem;font-weight:700;color:var(--muted);white-space:nowrap;}
.ao-line{height:2px;flex:1;background:#e2e8f0;margin:0 2px;margin-bottom:17px;z-index:1;transition:background .3s;}
.ao-line.done{background:var(--grad-brand);}
.ao-badge{font-size:.7rem;font-weight:800;padding:.25rem .7rem;border-radius:var(--r-full);white-space:nowrap;flex-shrink:0;}
.ao-badge.pending{background:#fef3c7;color:#92400e;}
.ao-badge.serving{background:#cffafe;color:#0e7490;}
.ao-badge.ready{background:#dcfce7;color:#15803d;}
.cat-nav{position:sticky;top:58px;z-index:100;background:rgba(250,249,247,.93);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);padding:.6rem 0;margin:0 -1.5rem 1.5rem;padding-left:1.5rem;}
.cat-nav-inner{display:flex;gap:.45rem;overflow-x:auto;scrollbar-width:none;padding-right:1.5rem;}
.cat-nav-inner::-webkit-scrollbar{display:none;}
.cat-pill{white-space:nowrap;padding:.38rem 1rem;border-radius:var(--r-full);border:1.5px solid var(--border);background:var(--surface);color:var(--text-2);font-size:.8rem;font-weight:700;font-family:var(--font-head);cursor:pointer;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem;}
.cat-pill:hover{border-color:var(--brand);color:var(--brand);}
.cat-pill.active{background:var(--brand);border-color:var(--brand);color:#fff;box-shadow:0 4px 12px rgba(232,82,26,.25);}
.sec-head{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:1rem;padding-top:.25rem;}
.sec-head h3{font-size:1.05rem;font-weight:800;color:var(--text);margin:0;display:flex;align-items:center;gap:.35rem;}
.sec-head p{font-size:.75rem;color:var(--muted);margin:.2rem 0 0;}
.item-count{font-size:.68rem;font-weight:800;background:var(--surface-2);color:var(--muted);padding:.2rem .6rem;border-radius:var(--r-full);border:1px solid var(--border);}
.p-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.7rem;margin-bottom:2.5rem;}
@media(max-width:900px){.p-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:600px){.p-grid{grid-template-columns:repeat(2,1fr);gap:.5rem}}
.dish{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);overflow:visible;box-shadow:var(--sh-sm);transition:transform .2s,box-shadow .2s;position:relative;display:flex;flex-direction:column;isolation:isolate;}
.dish:hover{transform:translateY(-4px);box-shadow:var(--sh-md);}
.dish-thumb-wrap{overflow:hidden;border-radius:var(--r-lg) var(--r-lg) 0 0;cursor:zoom-in;flex-shrink:0;position:relative;}
.dish-thumb{width:100%;height:100px;object-fit:cover;object-position:center;background:var(--surface-2);transition:transform .35s;display:block;}
.dish:hover .dish-thumb{transform:scale(1.06);}
.stock-pip{position:absolute;top:7px;left:7px;font-size:.62rem;font-weight:800;padding:2px 7px;border-radius:var(--r-full);letter-spacing:.2px;z-index:2;}
.stock-pip.out{background:#fee2e2;color:#dc2626;}
.stock-pip.low{background:#fef9c3;color:#a16207;}
.dish-body{padding:.55rem .65rem .3rem;flex:1;}
.dish-name{font-family:var(--font-head);font-size:.76rem;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.2;margin-bottom:.15rem;}
.dish-price{font-family:var(--font-head);font-size:.8rem;font-weight:800;color:var(--brand);}
.dish-foot{padding:.3rem .5rem .5rem;display:flex;gap:.3rem;align-items:center;}
.btn-add{flex:1;background:var(--grad-brand);border:none;color:#fff;font-size:.68rem;font-weight:700;font-family:var(--font-head);border-radius:9px;padding:.28rem .4rem;cursor:pointer;transition:opacity .15s,transform .15s;display:flex;align-items:center;justify-content:center;gap:3px;line-height:1.3;white-space:nowrap;}
.btn-add:hover:not(:disabled){opacity:.88;transform:scale(1.03);}
.btn-add:disabled{background:#d1d5db;cursor:not-allowed;transform:none;font-size:.64rem;}
.fav-btn{width:26px;height:26px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:#d1d5db;font-size:.7rem;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .18s;flex-shrink:0;padding:0;}
.fav-btn.faved{color:#f43f5e;border-color:#fda4af;background:#fff1f3;}
.fav-btn:hover{transform:scale(1.12);}
.hover-prev{position:absolute;left:calc(100% + 10px);top:50%;transform:translateY(-50%) scale(.93);width:210px;background:var(--surface);border-radius:var(--r-lg);box-shadow:var(--sh-lg),0 0 0 1px rgba(0,0,0,.05);border:1px solid var(--border);z-index:500;pointer-events:none;opacity:0;transition:opacity .18s,transform .18s;overflow:hidden;}
.dish:hover .hover-prev{opacity:1;transform:translateY(-50%) scale(1);pointer-events:auto;}
.dish.flip-left .hover-prev{left:auto;right:calc(100% + 10px);}
.dish.flip-down .hover-prev{top:auto;bottom:0;transform:scale(.93);left:calc(100% + 10px);}
.dish.flip-down:hover .hover-prev{transform:scale(1);}
.hprev-img{width:100%;height:120px;object-fit:cover;background:var(--surface-2);}
.hprev-body{padding:.7rem .85rem .85rem;}
.hprev-name{font-family:var(--font-head);font-weight:800;font-size:.88rem;margin-bottom:.15rem;}
.hprev-price{font-family:var(--font-head);font-weight:800;font-size:1rem;color:var(--brand);margin-bottom:.3rem;}
.hprev-desc{font-size:.73rem;color:var(--muted);line-height:1.45;margin-bottom:.45rem;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;}
.hprev-stock{font-size:.66rem;font-weight:700;padding:2px 8px;border-radius:var(--r-full);display:inline-block;margin-bottom:.5rem;}
.hprev-stock.in{background:#dcfce7;color:#15803d;}
.hprev-stock.low{background:#fef3c7;color:#b45309;}
.hprev-stock.out{background:#fee2e2;color:#b91c1c;}
.hprev-actions{display:flex;gap:.3rem;}
.btn-hpa{flex:1;background:var(--grad-brand);border:none;color:#fff;font-size:.73rem;font-weight:700;font-family:var(--font-head);border-radius:9px;padding:.32rem .5rem;cursor:pointer;transition:opacity .15s,transform .15s;display:flex;align-items:center;justify-content:center;gap:4px;}
.btn-hpa:hover{opacity:.88;transform:scale(1.03);}
.btn-hpa:disabled{background:#d1d5db;cursor:not-allowed;transform:none;}
.btn-hpf{width:30px;height:30px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:#d1d5db;font-size:.75rem;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;flex-shrink:0;padding:0;}
.btn-hpf.faved{color:#f43f5e;border-color:#fda4af;background:#fff1f3;}
.today-pick{background:var(--surface);border:1.5px solid rgba(232,82,26,.18);border-radius:var(--r-xl);overflow:hidden;display:flex;margin-bottom:1.5rem;box-shadow:var(--sh-md);}
.tp-img{width:190px;flex-shrink:0;overflow:hidden;cursor:zoom-in;}
.tp-img img{width:100%;height:100%;object-fit:cover;transition:transform .4s;}
.tp-img:hover img{transform:scale(1.05);}
.tp-body{padding:1.4rem 1.6rem;flex:1;}
.tp-badge{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--brand);background:#fff0eb;border:1px solid #fcd5c0;border-radius:var(--r-full);padding:3px 10px;display:inline-block;margin-bottom:.7rem;}
.tp-name{font-size:1.2rem;font-weight:800;margin-bottom:.4rem;}
.tp-desc{font-size:.82rem;color:var(--text-2);margin-bottom:.9rem;line-height:1.5;}
.tp-price{font-family:var(--font-head);font-size:1.5rem;font-weight:800;color:var(--brand);margin-right:.85rem;}
@media(max-width:600px){.today-pick{flex-direction:column}.tp-img{width:100%;height:160px}}
.cart-panel{background:var(--surface);border-left:1px solid var(--border);display:flex;flex-direction:column;position:sticky;top:58px;height:calc(100vh - 58px);overflow:hidden;}
.cp-header{padding:1.1rem 1.3rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.cp-header h5{font-family:var(--font-head);font-size:.95rem;font-weight:800;margin:0;display:flex;align-items:center;gap:.4rem;}
.cp-badge{font-size:.68rem;font-weight:800;background:var(--brand);color:#fff;padding:.2rem .55rem;border-radius:var(--r-full);}
.cp-body{flex:1;overflow-y:auto;padding:.9rem 1.2rem;}
.cp-empty{text-align:center;padding:3rem 1rem;}
.cp-empty .ce-icon{font-size:3.5rem;margin-bottom:.75rem;}
.cp-empty p{font-size:.875rem;color:var(--muted);font-weight:500;}
.cart-row{display:flex;align-items:center;gap:.75rem;padding:.75rem 0;border-bottom:1px solid var(--border);}
.cart-row:last-child{border-bottom:none;}
.cri{width:52px;height:52px;border-radius:10px;object-fit:cover;flex-shrink:0;background:var(--surface-2);}
.cr-info{flex:1;min-width:0;}
.cr-name{font-weight:700;font-size:.8rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:.1rem;}
.cr-price{font-size:.75rem;color:var(--muted);}
.cr-controls{display:flex;align-items:center;gap:.25rem;margin-top:.3rem;}
.qty-btn{width:22px;height:22px;border-radius:6px;border:1px solid var(--border);background:var(--surface-2);color:var(--text-2);font-size:.75rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;flex-shrink:0;}
.qty-btn:hover{background:var(--brand);border-color:var(--brand);color:#fff;}
.qty-val{font-weight:800;font-size:.8rem;min-width:18px;text-align:center;}
.cr-total{font-family:var(--font-head);font-weight:800;font-size:.85rem;color:var(--brand);flex-shrink:0;}
.btn-cr-del{width:22px;height:22px;border-radius:6px;border:1px solid #fca5a5;background:#fff5f5;color:#dc2626;font-size:.7rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;margin-left:2px;}
.btn-cr-del:hover{background:#dc2626;color:#fff;}
.cp-footer{padding:1rem 1.2rem;border-top:1px solid var(--border);flex-shrink:0;background:var(--surface);}
.cp-total-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem;}
.cp-total-label{font-size:.8rem;font-weight:600;color:var(--text-2);}
.cp-total-val{font-family:var(--font-head);font-size:1.2rem;font-weight:800;color:var(--brand);}
.btn-checkout{width:100%;background:var(--grad-brand);border:none;color:#fff;font-family:var(--font-head);font-size:.875rem;font-weight:800;padding:.75rem 1rem;border-radius:var(--r-lg);cursor:pointer;transition:opacity .2s,transform .2s,box-shadow .2s;display:flex;align-items:center;justify-content:center;gap:.4rem;box-shadow:0 4px 16px rgba(232,82,26,.3);}
.btn-checkout:hover:not(:disabled){opacity:.9;transform:translateY(-2px);}
.btn-checkout:disabled{background:#d1d5db;box-shadow:none;cursor:not-allowed;}
.fab-cart{position:fixed;bottom:22px;right:22px;width:58px;height:58px;border-radius:50%;background:var(--grad-brand);border:none;color:#fff;font-size:1.3rem;box-shadow:0 8px 28px rgba(232,82,26,.45);display:none;align-items:center;justify-content:center;cursor:pointer;z-index:1050;transition:transform .25s,box-shadow .25s;animation:fab-pulse 3s ease-in-out infinite;}
.fab-cart:hover{transform:scale(1.1);}
@keyframes fab-pulse{0%,100%{box-shadow:0 8px 28px rgba(232,82,26,.45)}50%{box-shadow:0 12px 36px rgba(232,82,26,.6)}}
@media(max-width:1100px){.fab-cart{display:flex}}
.fab-badge{position:absolute;top:-4px;right:-4px;width:20px;height:20px;border-radius:50%;background:#fff;color:var(--brand);font-size:.65rem;font-weight:900;display:flex;align-items:center;justify-content:center;font-family:var(--font-head);animation:pop .3s ease;}
@keyframes pop{0%{transform:scale(.4)}70%{transform:scale(1.25)}100%{transform:scale(1)}}
.modal-content{border:none;border-radius:var(--r-xl);box-shadow:var(--sh-xl);overflow:hidden;}
.modal-header{border-bottom:1px solid var(--border);padding:1rem 1.3rem;background:var(--surface);}
.modal-title{font-family:var(--font-head);font-weight:800;font-size:1rem;}
.modal-footer{border-top:1px solid var(--border);padding:.9rem 1.3rem;background:var(--surface);}
.modal-body{padding:1.3rem;}
.form-control,.form-select{border:1.5px solid var(--border);border-radius:var(--r-md);padding:.6rem .9rem;background:var(--surface);font-family:var(--font-body);font-size:.875rem;color:var(--text);transition:all .2s;}
.form-control:focus,.form-select:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(232,82,26,.12);outline:none;}
.form-label{font-weight:700;font-size:.8rem;color:var(--text-2);margin-bottom:.35rem;display:block;}
.order-summary-box{background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r-md);padding:.9rem 1rem;margin-bottom:1rem;}
.order-summary-box h6{font-size:.8rem;font-weight:800;margin-bottom:.65rem;color:var(--text);}
.os-row{display:flex;justify-content:space-between;font-size:.82rem;padding:.25rem 0;color:var(--text-2);}
.os-row .os-qty{color:var(--muted);font-size:.75rem;}
.os-total{display:flex;justify-content:space-between;font-weight:800;padding-top:.5rem;margin-top:.3rem;border-top:1.5px dashed var(--border);font-size:.9rem;}
.change-alert{background:#f0fdf4;border:1px solid #86efac;border-radius:var(--r-md);padding:.65rem .9rem;font-size:.82rem;color:#15803d;font-weight:600;display:none;}
.change-alert strong{font-weight:800;}
.btn-do-checkout{width:100%;background:var(--grad-brand);border:none;color:#fff;font-family:var(--font-head);font-size:.9rem;font-weight:800;padding:.75rem 1rem;border-radius:var(--r-lg);cursor:pointer;transition:all .2s;box-shadow:0 4px 16px rgba(232,82,26,.3);display:flex;align-items:center;justify-content:center;gap:.4rem;}
.btn-do-checkout:disabled{background:#d1d5db;box-shadow:none;cursor:not-allowed;}
.btn-do-checkout:hover:not(:disabled){opacity:.9;transform:translateY(-2px);}
.lightbox-overlay{display:none;position:fixed;inset:0;background:rgba(10,8,6,.85);backdrop-filter:blur(6px);z-index:9999;align-items:center;justify-content:center;padding:1.5rem;animation:lb-in .2s ease;}
.lightbox-overlay.on{display:flex;}
@keyframes lb-in{from{opacity:0}to{opacity:1}}
.lb-box{background:var(--surface);border-radius:var(--r-xl);max-width:420px;width:100%;overflow:hidden;box-shadow:var(--sh-xl);animation:lb-up .22s ease;position:relative;}
@keyframes lb-up{from{transform:translateY(18px);opacity:0}to{transform:translateY(0);opacity:1}}
.lb-img{width:100%;height:230px;object-fit:cover;background:var(--surface-2);}
.lb-info{padding:1.1rem 1.3rem 1.4rem;}
.lb-name{font-family:var(--font-head);font-size:1.1rem;font-weight:800;margin-bottom:.25rem;}
.lb-price{font-family:var(--font-head);font-size:1.25rem;font-weight:800;color:var(--brand);margin-bottom:.5rem;}
.lb-desc{font-size:.82rem;color:var(--muted);line-height:1.55;margin-bottom:1rem;}
.lb-close{position:absolute;top:10px;right:10px;width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.9);border:none;font-size:.95rem;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:var(--sh-sm);transition:transform .2s;z-index:2;}
.lb-close:hover{transform:scale(1.12) rotate(90deg);}
.history-section{margin-top:1rem;}
.history-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:.9rem 1.1rem;margin-bottom:.6rem;display:flex;align-items:center;gap:.85rem;box-shadow:var(--sh-sm);transition:box-shadow .2s;}
.history-card:hover{box-shadow:var(--sh-md);}
.hc-id{font-family:var(--font-head);font-weight:800;font-size:.875rem;min-width:72px;}
.hc-date{font-size:.73rem;color:var(--muted);flex:1;}
.hc-total{font-family:var(--font-head);font-weight:800;font-size:.9rem;color:var(--teal);}
.hc-badge{font-size:.65rem;font-weight:800;background:#dcfce7;color:#15803d;padding:2px 9px;border-radius:var(--r-full);}
#toast-wrap{position:fixed;top:70px;right:16px;z-index:9998;display:flex;flex-direction:column;gap:.5rem;pointer-events:none;}
.toast-item{background:var(--text);color:#fff;padding:.65rem 1.1rem;border-radius:var(--r-lg);font-size:.82rem;font-weight:600;font-family:var(--font-body);box-shadow:var(--sh-lg);pointer-events:auto;animation:toast-in .3s ease;display:flex;align-items:center;gap:.5rem;min-width:200px;max-width:280px;}
.toast-item.success{background:#14532d;}
.toast-item.danger{background:#7f1d1d;}
.toast-item.info{background:#1e3a5f;}
@keyframes toast-in{from{transform:translateX(30px);opacity:0}to{transform:translateX(0);opacity:1}}
.toast-item.out{animation:toast-out .3s ease forwards;}
@keyframes toast-out{to{transform:translateX(30px);opacity:0}}
#empty-search{display:none;text-align:center;padding:3rem 1rem;}
.fade-in{animation:fi .45s ease both;}
@keyframes fi{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.menu-section{scroll-margin-top:110px;}
.btn-link-style{background:none;border:none;padding:0;cursor:pointer;color:var(--brand);font-weight:700;font-size:.8rem;font-family:var(--font-body);text-decoration:underline;text-underline-offset:2px;}
  </style>
</head>
<body>

<nav class="topbar">
  <a class="topbar-brand" href="<?= $B ?>/index.php">
    <span style="width:30px;height:30px;background:var(--grad-brand);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.9rem">🍴</span>
    FoodHub
  </a>
  <div class="topbar-spacer"></div>
  <div class="topbar-search d-none d-md-block">
    <i class="bi bi-search si"></i>
    <input type="text" id="topSearch" placeholder="Search dishes…" oninput="filterMenu(this.value)"/>
  </div>
  <div class="topbar-user">
    <div class="topbar-avatar"><?= strtoupper(substr($firstName, 0, 1)) ?></div>
    <span class="d-none d-sm-inline"><?= htmlspecialchars($firstName) ?></span>
  </div>
  <a class="topbar-logout" href="<?= $B ?>/logout.php"><i class="bi bi-box-arrow-right"></i><span class="d-none d-sm-inline">Logout</span></a>
</nav>

<div class="shell">
<main class="main-col">

  <?php if ($message): ?>
  <script>document.addEventListener('DOMContentLoaded',()=>showToast('<?= addslashes(htmlspecialchars($message)) ?>','info'));</script>
  <?php endif; ?>
  <?php if ($checkoutError): ?>
  <script>document.addEventListener('DOMContentLoaded',()=>setTimeout(()=>new bootstrap.Modal(document.getElementById('checkoutModal')).show(),200));</script>
  <?php endif; ?>

  <!-- HERO -->
  <div class="hero-strip fade-in">
    <span class="hero-greeting"><?= $greeting ?>, <?= htmlspecialchars($firstName) ?> 👋</span>
    <h1>Gutom ka na ba?</h1>
    <p>Fresh Filipino food, ready when you are. Pick your ulam below! 🇵🇭</p>
  </div>

  <!-- STATS -->
  <div class="stats-row fade-in">
    <div class="sc" style="cursor:pointer" onclick="document.getElementById('menu').scrollIntoView({behavior:'smooth'})">
      <div class="sc-icon brand"><i class="bi bi-receipt"></i></div>
      <div><div class="sc-label">Total Orders</div><div class="sc-value"><?= $totalOrders ?></div></div>
    </div>
    <div class="sc" style="cursor:pointer">
      <div class="sc-icon teal"><i class="bi bi-cash-stack"></i></div>
      <div><div class="sc-label">Total Spent</div><div class="sc-value">₱<?= number_format($userTotalSpent, 0) ?></div></div>
    </div>
    <div class="sc" style="cursor:pointer" onclick="showFavourites()">
      <div class="sc-icon indigo"><i class="bi bi-heart-fill"></i></div>
      <div><div class="sc-label">Favourites</div><div class="sc-value" id="favCount">0</div></div>
    </div>
  </div>

  <!-- ACTIVE ORDERS -->
  <?php if (!empty($user_orders)): ?>
  <div class="active-orders-section fade-in">
    <div class="ao-header">
      <h5><i class="bi bi-clock-history"></i> Active Orders</h5>
      <span style="font-size:.7rem;font-weight:700;color:#15803d;background:#bbf7d0;padding:3px 10px;border-radius:99px"><?= count($user_orders) ?> active</span>
    </div>
    <?php foreach ($user_orders as $order): $s = $order['status']; ?>
    <div class="ao-item">
      <div class="ao-id">#<?= $order['id'] ?></div>
      <div class="ao-progress">
        <div class="ao-step">
          <div class="ao-dot <?= in_array($s, ['pending','serving','ready']) ? 'done' : '' ?>"><i class="bi bi-clock"></i></div>
          <div class="ao-dot-label">Placed</div>
        </div>
        <div class="ao-line <?= in_array($s, ['serving','ready']) ? 'done' : '' ?>"></div>
        <div class="ao-step">
          <div class="ao-dot <?= $s === 'serving' ? 'pulse' : ($s === 'ready' ? 'done' : '') ?>"><i class="bi bi-fire"></i></div>
          <div class="ao-dot-label">Cooking</div>
        </div>
        <div class="ao-line <?= $s === 'ready' ? 'done' : '' ?>"></div>
        <div class="ao-step">
          <div class="ao-dot <?= $s === 'ready' ? 'pulse' : '' ?>"><i class="bi bi-check-circle"></i></div>
          <div class="ao-dot-label">Ready</div>
        </div>
      </div>
      <span class="ao-badge <?= $s ?>"><?= ucfirst($s) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- TODAY'S PICK -->
  <?php if (!empty($todayUlam)): ?>
  <div class="today-pick fade-in">
    <div class="tp-img" onclick="showLightbox('<?= htmlspecialchars(getProductImage($todayUlam)) ?>','<?= addslashes(htmlspecialchars($todayUlam['name'])) ?>',<?= $todayUlam['price'] ?>,'<?= addslashes(htmlspecialchars($todayUlam['description'] ?? '')) ?>')">
      <img src="<?= htmlspecialchars(getProductImage($todayUlam)) ?>" alt="<?= htmlspecialchars($todayUlam['name']) ?>" onerror="this.onerror=null;this.src='<?= IMG_PLACEHOLDER ?>';"/>
    </div>
    <div class="tp-body">
      <div class="tp-badge">⭐ Today's Pick</div>
      <div class="tp-name"><?= htmlspecialchars($todayUlam['name']) ?></div>
      <div class="tp-desc"><?= htmlspecialchars(mb_substr($todayUlam['description'] ?? 'Authentic Filipino dish, freshly prepared just for you!', 0, 120)) ?></div>
      <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
        <span class="tp-price">₱<?= number_format($todayUlam['price'], 2) ?></span>
        <?php if ($todayUlam['stock'] > 0): ?>
        <form method="POST" style="display:inline">
          <input type="hidden" name="product_id" value="<?= $todayUlam['id'] ?>"/>
          <button type="submit" name="add_to_cart" class="btn-add" style="font-size:.78rem;padding:.4rem .9rem;border-radius:10px"><i class="bi bi-cart-plus"></i> Add to Cart</button>
        </form>
        <?php else: ?>
        <span style="font-size:.75rem;color:#dc2626;font-weight:700;background:#fee2e2;padding:.25rem .75rem;border-radius:99px">Out of Stock</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- MOBILE SEARCH -->
  <div class="d-md-none mb-3" style="position:relative">
    <i class="bi bi-search" style="position:absolute;left:.85rem;top:50%;transform:translateY(-50%);color:var(--muted);font-size:.85rem;pointer-events:none"></i>
    <input type="text" class="form-control" style="padding-left:2.3rem;border-radius:99px;font-size:.82rem" placeholder="Search dishes…" oninput="filterMenu(this.value)"/>
  </div>

  <!-- CATEGORY NAV -->
  <div class="cat-nav" id="menu">
    <div class="cat-nav-inner">
      <a href="#meals"    class="cat-pill active" data-cat="meals">🍛 Meals</a>
      <a href="#rices"    class="cat-pill" data-cat="rices">🍚 Rice</a>
      <a href="#drinks"   class="cat-pill" data-cat="drinks">🥤 Drinks</a>
      <a href="#desserts" class="cat-pill" data-cat="desserts">🍰 Desserts</a>
      <?php if (!empty($order_history)): ?>
      <a href="#history" class="cat-pill" data-cat="history">📋 History</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- MENU SECTIONS -->
  <div id="menuContainer">
  <?php
  function renderSection(string $id, string $emoji, string $title, string $sub, array $items): void {
      if (empty($items)) return;
      ?>
  <section id="<?= $id ?>" class="menu-section mb-2" data-section="<?= $id ?>">
    <div class="sec-head">
      <div><h3><?= $emoji ?> <?= $title ?></h3><p><?= $sub ?></p></div>
      <span class="item-count"><?= count($items) ?> items</span>
    </div>
    <div class="p-grid">
    <?php foreach ($items as $p):
      $oos  = $p['stock'] <= 0;
      $low  = !$oos && $p['stock'] <= 5;
      $img  = htmlspecialchars(getProductImage($p));
      $pName = addslashes(htmlspecialchars($p['name']));
      $pDesc = addslashes(htmlspecialchars($p['description'] ?? 'Authentic Filipino dish, freshly prepared.'));
      $pid  = (int)$p['id'];
      $pStk = (int)$p['stock'];
      $pPrc = number_format($p['price'], 2);
    ?>
    <div class="dish menu-item" data-name="<?= strtolower(htmlspecialchars($p['name'])) ?>" data-section="<?= $id ?>" data-pid="<?= $pid ?>">

      <?php if ($oos): ?><span class="stock-pip out">Sold out</span>
      <?php elseif ($low): ?><span class="stock-pip low">Only <?= $pStk ?> left</span>
      <?php endif; ?>

      <div class="dish-thumb-wrap" onclick="showLightbox('<?= $img ?>','<?= $pName ?>',<?= $p['price'] ?>,'<?= $pDesc ?>',<?= $pid ?>,<?= $oos ? 'true' : 'false' ?>)">
        <img src="<?= $img ?>" class="dish-thumb" alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy"
             onerror="this.onerror=null;this.src='<?= IMG_PLACEHOLDER ?>';"/>
      </div>

      <div class="dish-body">
        <div class="dish-name" title="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></div>
        <div class="dish-price">₱<?= $pPrc ?></div>
      </div>

      <div class="dish-foot">
        <form method="POST" style="flex:1;display:contents">
          <input type="hidden" name="product_id" value="<?= $pid ?>"/>
          <?php if (!$oos): ?>
          <button type="submit" name="add_to_cart" class="btn-add" onclick="event.stopPropagation()"><i class="bi bi-cart-plus"></i> Add</button>
          <?php else: ?>
          <button class="btn-add" disabled><i class="bi bi-x-circle"></i> Unavailable</button>
          <?php endif; ?>
        </form>
        <button class="fav-btn" data-pid="<?= $pid ?>" onclick="event.stopPropagation();toggleFav(<?= $pid ?>,this)"><i class="bi bi-heart-fill"></i></button>
      </div>

      <!-- Hover preview -->
      <div class="hover-prev" onclick="event.stopPropagation()">
        <img src="<?= $img ?>" class="hprev-img" alt="" onerror="this.onerror=null;this.src='<?= IMG_PLACEHOLDER ?>';"/>
        <div class="hprev-body">
          <div class="hprev-name"><?= htmlspecialchars($p['name']) ?></div>
          <div class="hprev-price">₱<?= $pPrc ?></div>
          <p class="hprev-desc"><?= htmlspecialchars($p['description'] ?? 'Authentic Filipino dish, freshly prepared.') ?></p>
          <span class="hprev-stock <?= $oos ? 'out' : ($low ? 'low' : 'in') ?>">
            <?= $oos ? 'Out of Stock' : ($low ? "Only {$pStk} left" : "In Stock ({$pStk})") ?>
          </span>
          <div class="hprev-actions mt-1">
            <?php if (!$oos): ?>
            <form method="POST" style="flex:1;display:contents">
              <input type="hidden" name="product_id" value="<?= $pid ?>"/>
              <button type="submit" name="add_to_cart" class="btn-hpa"><i class="bi bi-cart-plus"></i> Add to Cart</button>
            </form>
            <?php else: ?>
            <button class="btn-hpa" disabled style="flex:1"><i class="bi bi-x-circle"></i> Unavailable</button>
            <?php endif; ?>
            <button class="btn-hpf" data-pid="<?= $pid ?>" onclick="syncFav(<?= $pid ?>,this)"><i class="bi bi-heart-fill"></i></button>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
  </section>
  <?php } ?>

  <?php
  renderSection('meals',    '🍛', 'Main Dishes', 'Sarap na ulam for your karenderia experience.', $meals);
  renderSection('rices',    '🍚', 'Rice',         'The perfect partner for every ulam.',          $rices);
  renderSection('drinks',   '🥤', 'Drinks',        'Cool refreshments to complete your meal.',    $drinks);
  renderSection('desserts', '🍰', 'Desserts',       'Sweet Filipino favorites.',                  $desserts);
  ?>

  <div id="empty-search">
    <div style="font-size:3rem">🔍</div>
    <h5 style="color:var(--muted);font-weight:700">No items found</h5>
    <p style="font-size:.82rem;color:var(--muted)">Try a different keyword</p>
  </div>

  <!-- ORDER HISTORY -->
  <?php if (!empty($order_history)): ?>
  <section id="history" class="history-section menu-section" data-section="history">
    <div class="sec-head">
      <div><h3>📋 Order History</h3><p>Your recent completed orders</p></div>
      <span class="item-count"><?= count($order_history) ?> orders</span>
    </div>
    <?php foreach ($order_history as $oh): ?>
    <div class="history-card">
      <div class="hc-id">#<?= $oh['id'] ?></div>
      <div class="hc-date"><i class="bi bi-calendar3 me-1"></i><?= date('M j, Y · g:i A', strtotime($oh['order_time'])) ?></div>
      <div class="hc-total">₱<?= number_format($oh['total'], 2) ?></div>
      <span class="hc-badge">Completed</span>
    </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  </div><!-- /#menuContainer -->
</main>

<!-- CART SIDEBAR -->
<aside class="cart-panel">
  <div class="cp-header">
    <h5><i class="bi bi-cart3" style="color:var(--brand)"></i> Your Cart
      <span class="cp-badge" id="sideCartCount" <?= $cartCount == 0 ? 'style="display:none"' : '' ?>><?= $cartCount ?></span>
    </h5>
    <?php if (!empty($cart_items)): ?>
    <button class="btn-link-style" onclick="clearCart()">Clear all</button>
    <?php endif; ?>
  </div>

  <div class="cp-body" id="sideCartBody">
    <?php if (empty($cart_items)): ?>
    <div class="cp-empty"><div class="ce-icon">🛒</div><p>Your cart is empty.<br/>Add something yummy!</p></div>
    <?php else: ?>
    <?php foreach ($cart_items as $ci): $item = $ci['product']; ?>
    <div class="cart-row" data-pid="<?= $item['id'] ?>">
      <img src="<?= htmlspecialchars(getProductImage($item)) ?>" class="cri"
           alt="<?= htmlspecialchars($item['name']) ?>"
           onerror="this.onerror=null;this.src='<?= IMG_PLACEHOLDER ?>';"/>
      <div class="cr-info">
        <div class="cr-name"><?= htmlspecialchars($item['name']) ?></div>
        <div class="cr-price">₱<?= number_format($item['price'], 2) ?> each</div>
        <div class="cr-controls">
          <button class="qty-btn" onclick="updateQty(<?= $item['id'] ?>,-1)"><i class="bi bi-dash"></i></button>
          <span class="qty-val" id="sqty-<?= $item['id'] ?>"><?= $ci['qty'] ?></span>
          <button class="qty-btn" onclick="updateQty(<?= $item['id'] ?>,1)"><i class="bi bi-plus"></i></button>
          <button class="btn-cr-del" onclick="removeItem(<?= $item['id'] ?>)"><i class="bi bi-trash"></i></button>
        </div>
      </div>
      <div class="cr-total" id="srow-total-<?= $item['id'] ?>">₱<?= number_format($item['price'] * $ci['qty'], 2) ?></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="cp-footer">
    <div class="cp-total-row">
      <span class="cp-total-label">Total</span>
      <span class="cp-total-val" id="sideTotal">₱<?= number_format($total, 2) ?></span>
    </div>
    <button class="btn-checkout" id="btnCheckout" onclick="openCheckout()" <?= empty($cart_items) ? 'disabled' : '' ?>>
      <i class="bi bi-credit-card"></i> Checkout
    </button>
  </div>
</aside>
</div><!-- /.shell -->

<!-- FLOATING CART (mobile) -->
<button class="fab-cart" onclick="openMobileCart()" id="fabCart">
  <i class="bi bi-cart3"></i>
  <span class="fab-badge" id="fabBadge" <?= $cartCount == 0 ? 'style="display:none"' : '' ?>><?= $cartCount ?></span>
</button>

<div id="toast-wrap"></div>

<!-- LIGHTBOX -->
<div class="lightbox-overlay" id="lb" onclick="closeLb(event)">
  <div class="lb-box">
    <button class="lb-close" onclick="closeLb(null,true)"><i class="bi bi-x-lg"></i></button>
    <img class="lb-img" id="lbImg" src="" alt="" onerror="this.onerror=null;this.src='<?= IMG_PLACEHOLDER ?>';"/>
    <div class="lb-info">
      <div class="lb-name" id="lbName"></div>
      <div class="lb-price" id="lbPrice"></div>
      <p class="lb-desc" id="lbDesc"></p>
      <form method="POST" id="lbForm">
        <input type="hidden" id="lbPid" name="product_id"/>
        <button type="submit" name="add_to_cart" id="lbBtn"
                style="width:100%;background:var(--grad-brand);border:none;color:#fff;font-family:var(--font-head);font-weight:800;font-size:.875rem;padding:.7rem 1rem;border-radius:var(--r-lg);cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.4rem;transition:opacity .2s">
          <i class="bi bi-cart-plus"></i> Add to Cart
        </button>
      </form>
    </div>
  </div>
</div>

<!-- MOBILE CART MODAL -->
<div class="modal fade" id="mobileCartModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-cart3 me-2" style="color:var(--brand)"></i>Your Cart</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="max-height:65vh;overflow-y:auto;padding:1rem 1.2rem">
        <?php if (empty($cart_items)): ?>
        <div class="text-center py-4"><div style="font-size:3rem">🛒</div><p class="mt-2" style="color:var(--muted);font-weight:600">Your cart is empty</p></div>
        <?php else: ?>
        <?php foreach ($cart_items as $ci): $item = $ci['product']; ?>
        <div class="cart-row" data-pid="<?= $item['id'] ?>">
          <img src="<?= htmlspecialchars(getProductImage($item)) ?>" class="cri"
               alt="<?= htmlspecialchars($item['name']) ?>"
               onerror="this.onerror=null;this.src='<?= IMG_PLACEHOLDER ?>';"/>
          <div class="cr-info">
            <div class="cr-name"><?= htmlspecialchars($item['name']) ?></div>
            <div class="cr-price">₱<?= number_format($item['price'], 2) ?> each</div>
            <div class="cr-controls">
              <button class="qty-btn" onclick="updateQty(<?= $item['id'] ?>,-1)"><i class="bi bi-dash"></i></button>
              <span class="qty-val" id="mqty-<?= $item['id'] ?>"><?= $ci['qty'] ?></span>
              <button class="qty-btn" onclick="updateQty(<?= $item['id'] ?>,1)"><i class="bi bi-plus"></i></button>
              <button class="btn-cr-del" onclick="removeItem(<?= $item['id'] ?>)"><i class="bi bi-trash"></i></button>
            </div>
          </div>
          <div class="cr-total" id="mrow-total-<?= $item['id'] ?>">₱<?= number_format($item['price'] * $ci['qty'], 2) ?></div>
        </div>
        <?php endforeach; ?>
        <hr style="border-color:var(--border)"/>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
          <span style="font-weight:700;font-size:.875rem">Total:</span>
          <span style="font-family:var(--font-head);font-weight:800;font-size:1.1rem;color:var(--brand)" id="mobileTotal">₱<?= number_format($total, 2) ?></span>
        </div>
        <button class="btn-checkout" onclick="bootstrap.Modal.getInstance(document.getElementById('mobileCartModal')).hide();setTimeout(openCheckout,300)">
          <i class="bi bi-credit-card"></i> Checkout
        </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- CHECKOUT MODAL -->
<div class="modal fade" id="checkoutModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-credit-card me-2" style="color:var(--teal)"></i>Checkout</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if ($checkoutError): ?>
        <div class="alert alert-danger mb-3" style="font-size:.82rem;border-radius:var(--r-md)"><?= htmlspecialchars($checkoutError) ?></div>
        <?php endif; ?>
        <div class="order-summary-box">
          <h6>📋 Order Summary</h6>
          <?php foreach ($cart_items as $ci): ?>
          <div class="os-row">
            <span><?= htmlspecialchars($ci['product']['name']) ?> <span class="os-qty">×<?= $ci['qty'] ?></span></span>
            <span style="font-weight:700">₱<?= number_format($ci['product']['price'] * $ci['qty'], 2) ?></span>
          </div>
          <?php endforeach; ?>
          <div class="os-total">
            <span>Total</span>
            <span style="color:var(--brand)">₱<?= number_format($total, 2) ?></span>
          </div>
        </div>
        <form method="POST" id="checkoutForm">
          <label class="form-label">Cash Amount (₱) <span style="color:#dc2626">*</span></label>
          <input type="number" step="0.01" min="<?= $total ?>" name="cash_amount" id="cashInput"
                 class="form-control mb-1" placeholder="Enter cash amount"
                 value="<?= isset($_POST['cash_amount']) ? htmlspecialchars($_POST['cash_amount']) : '' ?>" required/>
          <div style="font-size:.75rem;color:var(--muted);margin-bottom:.75rem">Minimum: ₱<?= number_format($total, 2) ?></div>
          <div class="change-alert" id="changeAlert">💰 Change: <strong id="changeAmt">₱0.00</strong></div>
          <button type="submit" name="checkout" id="checkoutBtn" class="btn-do-checkout mt-3" disabled>
            <i class="bi bi-check-circle"></i> Complete Order
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const TOTAL = <?= $total ?>;

function showLightbox(src, name, price, desc, pid, oos) {
    document.getElementById('lbImg').src  = src;
    document.getElementById('lbName').textContent  = name;
    document.getElementById('lbPrice').textContent = price ? '₱' + parseFloat(price).toLocaleString('en-PH',{minimumFractionDigits:2}) : '';
    document.getElementById('lbDesc').textContent  = desc || '';
    document.getElementById('lbPid').value = pid || '';
    const btn = document.getElementById('lbBtn');
    btn.disabled = !!oos;
    btn.style.background = oos ? '#d1d5db' : '';
    btn.innerHTML = oos ? '<i class="bi bi-x-circle"></i> Out of Stock' : '<i class="bi bi-cart-plus"></i> Add to Cart';
    document.getElementById('lb').classList.add('on');
    document.body.style.overflow = 'hidden';
}
function closeLb(e, force) {
    if (force || !e || e.target === document.getElementById('lb')) {
        document.getElementById('lb').classList.remove('on');
        document.body.style.overflow = '';
    }
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLb(null, true); });

function updateQty(pid, delta) {
    const sEl = document.getElementById(`sqty-${pid}`);
    const mEl = document.getElementById(`mqty-${pid}`);
    const cur = parseInt((sEl || mEl)?.textContent || '1');
    const newQty = Math.max(1, cur + delta);
    if (sEl) sEl.textContent = newQty;
    if (mEl) mEl.textContent = newQty;
    sendCartUpdate(pid, newQty);
}
function removeItem(pid) { sendCartUpdate(pid, 0); }
function clearCart() {
    if (!confirm('Remove all items from cart?')) return;
    fetch('update_cart.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({clear:true}) })
        .then(r => r.json()).then(d => { if (d.success) { showToast('Cart cleared','info'); setTimeout(()=>location.reload(), 500); } });
}
function sendCartUpdate(pid, qty) {
    fetch('update_cart.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({product_id:pid, quantity:qty}) })
        .then(r => r.json()).then(d => {
            if (d.success) {
                updateCounters(d.cart_count);
                const fmt = v => '₱' + parseFloat(v).toLocaleString('en-PH',{minimumFractionDigits:2});
                const sT = document.getElementById('sideTotal');
                const mT = document.getElementById('mobileTotal');
                if (sT) sT.textContent = fmt(d.total);
                if (mT) mT.textContent = fmt(d.total);
                const stEl = document.getElementById(`srow-total-${pid}`);
                const mtEl = document.getElementById(`mrow-total-${pid}`);
                if (d.item_total !== undefined) { if (stEl) stEl.textContent = fmt(d.item_total); if (mtEl) mtEl.textContent = fmt(d.item_total); }
                if (qty === 0) {
                    document.querySelectorAll(`.cart-row[data-pid="${pid}"]`).forEach(r => r.remove());
                    if (!document.querySelector('.cart-row')) {
                        const empty = `<div class="cp-empty"><div class="ce-icon">🛒</div><p>Your cart is empty.<br/>Add something yummy!</p></div>`;
                        const sb = document.getElementById('sideCartBody');
                        if (sb) sb.innerHTML = empty;
                        const btn = document.getElementById('btnCheckout');
                        if (btn) btn.disabled = true;
                    }
                }
                showToast(d.message || 'Cart updated', 'success');
            } else { showToast(d.message || 'Update failed', 'danger'); }
        }).catch(() => showToast('Network error','danger'));
}
function updateCounters(count) {
    ['sideCartCount','fabBadge'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = count > 0 ? count : '';
        el.style.display = count > 0 ? '' : 'none';
    });
}
function openCheckout() { new bootstrap.Modal(document.getElementById('checkoutModal')).show(); }
function openMobileCart() { new bootstrap.Modal(document.getElementById('mobileCartModal')).show(); }

document.addEventListener('DOMContentLoaded', () => {
    const ci = document.getElementById('cashInput');
    if (ci) {
        ci.addEventListener('input', () => {
            const cash = parseFloat(ci.value) || 0;
            const valid = cash >= TOTAL && TOTAL > 0;
            document.getElementById('checkoutBtn').disabled = !valid;
            const alert = document.getElementById('changeAlert');
            alert.style.display = valid ? 'block' : 'none';
            if (valid) document.getElementById('changeAmt').textContent = '₱' + (cash - TOTAL).toLocaleString('en-PH',{minimumFractionDigits:2});
        });
    }

    const favs = JSON.parse(localStorage.getItem('fh_favs') || '[]');
    document.getElementById('favCount').textContent = favs.length;
    favs.forEach(id => document.querySelectorAll(`[data-pid="${id}"]`).forEach(el => el.classList.add('faved')));

    const sections = document.querySelectorAll('.menu-section');
    const pills    = document.querySelectorAll('.cat-pill');
    const obs = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                pills.forEach(p => p.classList.remove('active'));
                document.querySelector(`.cat-pill[data-cat="${e.target.id}"]`)?.classList.add('active');
            }
        });
    }, {threshold:.25});
    sections.forEach(s => obs.observe(s));
    pills.forEach(p => p.addEventListener('click', function() { pills.forEach(x => x.classList.remove('active')); this.classList.add('active'); }));

    function positionPreviews() {
        document.querySelectorAll('.dish').forEach(card => {
            const r = card.getBoundingClientRect();
            card.classList.toggle('flip-left', r.right + 225 > window.innerWidth);
            card.classList.toggle('flip-down', r.top < window.innerHeight * 0.28);
        });
    }
    positionPreviews();
    window.addEventListener('resize', positionPreviews);
    document.addEventListener('scroll', positionPreviews, {passive:true});

    const sv = sessionStorage.getItem('fhScroll');
    if (sv) { window.scrollTo(0, parseInt(sv)); sessionStorage.removeItem('fhScroll'); }
});

window.addEventListener('beforeunload', () => sessionStorage.setItem('fhScroll', window.scrollY));

function toggleFav(pid, btn) { _applyFav(pid, !btn.classList.contains('faved')); }
function syncFav(pid, btn)   { _applyFav(pid, !btn.classList.contains('faved')); }
function _applyFav(pid, add) {
    let favs = JSON.parse(localStorage.getItem('fh_favs') || '[]');
    favs = add ? [...new Set([...favs, pid])] : favs.filter(id => id != pid);
    localStorage.setItem('fh_favs', JSON.stringify(favs));
    document.getElementById('favCount').textContent = favs.length;
    document.querySelectorAll(`[data-pid="${pid}"]`).forEach(el => el.classList.toggle('faved', add));
    showToast(add ? '❤️ Added to favourites!' : 'Removed from favourites', add ? 'success' : 'info');
}

function filterMenu(q) {
    q = q.trim().toLowerCase();
    const secs = document.querySelectorAll('.menu-section');
    let any = false;
    secs.forEach(sec => {
        const items = sec.querySelectorAll('.menu-item');
        let vis = false;
        items.forEach(item => {
            const m = !q || item.dataset.name.includes(q);
            item.style.display = m ? '' : 'none';
            if (m) { vis = true; any = true; }
        });
        sec.style.display = vis ? '' : 'none';
    });
    document.getElementById('empty-search').style.display = any ? 'none' : 'block';
    const a = document.getElementById('topSearch');
    const b = document.querySelector('.d-md-none input[type=text]');
    if (a && a.value !== q) a.value = q;
    if (b && b.value !== q) b.value = q;
}

function showToast(msg, type='info') {
    const wrap = document.getElementById('toast-wrap');
    const icons = {success:'check-circle-fill', danger:'exclamation-triangle-fill', info:'info-circle-fill'};
    const t = document.createElement('div');
    t.className = `toast-item ${type}`;
    t.innerHTML = `<i class="bi bi-${icons[type]||'info-circle-fill'}"></i>${msg}`;
    wrap.appendChild(t);
    setTimeout(() => { t.classList.add('out'); t.addEventListener('animationend', () => t.remove(), {once:true}); }, 3000);
}
</script>
</body>
</html>
