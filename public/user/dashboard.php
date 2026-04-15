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
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        if (isset($_SESSION['cart'][$product_id])) {
            $_SESSION['cart'][$product_id]++;
        } else {
            $_SESSION['cart'][$product_id] = 1;
        }
        $_SESSION['message'] = 'Item added to cart.';
    } else {
        $_SESSION['message'] = 'Out of stock, please pick others.';
    }
    header('Location: dashboard.php#menu');
    exit();
}

// Remove from cart
if (isset($_POST['remove_from_cart'])) {
    $product_id = (int)$_POST['product_id'];
    if (isset($_SESSION['cart'][$product_id])) {
        $_SESSION['cart'][$product_id]--;
        if ($_SESSION['cart'][$product_id] <= 0) {
            unset($_SESSION['cart'][$product_id]);
        }
    }
    header('Location: dashboard.php');
    exit();
}

// Checkout
$checkoutError = '';
if (isset($_POST['checkout'])) {
    if (empty($_SESSION['cart'])) {
        $_SESSION['message'] = 'Cart is empty.';
        header('Location: dashboard.php');
        exit();
    }

    $cashAmount = isset($_POST['cash_amount']) ? trim($_POST['cash_amount']) : '';
    $cart_items = [];
    $total = 0;

    if (!empty($_SESSION['cart'])) {
        $ids = implode(',', array_keys($_SESSION['cart']));
        $query = "SELECT * FROM products WHERE id IN ($ids)";
        $availableProducts = [];
        $result = $conn->query($query);
        while ($row = $result->fetch_assoc()) {
            $availableProducts[$row['id']] = $row;
        }

        foreach ($_SESSION['cart'] as $id => $qty) {
            if (!isset($availableProducts[$id])) {
                continue;
            }
            $product = $availableProducts[$id];
            $total += $product['price'] * $qty;
        }
    }

    if ($cashAmount === '') {
        $checkoutError = 'Please enter payment amount.';
    } elseif (!is_numeric($cashAmount) || floatval($cashAmount) < $total) {
        $checkoutError = 'Insufficient amount.';
    }

    if ($checkoutError === '') {
        foreach ($_SESSION['cart'] as $id => $qty) {
            if (!isset($availableProducts[$id])) {
                continue;
            }
            if ($availableProducts[$id]['stock'] < $qty) {
                $checkoutError = 'Insufficient stock for ' . $availableProducts[$id]['name'];
                break;
            }
            $item_total = $availableProducts[$id]['price'] * $qty;
            $cart_items[] = ['product' => $availableProducts[$id], 'qty' => $qty, 'item_total' => $item_total];
        }
    }

    if ($checkoutError === '') {
        $cashAmount = floatval($cashAmount);
        $changeAmount = $cashAmount - $total;

        // Insert order
        $stmt = $conn->prepare('INSERT INTO orders (user_id, total) VALUES (?, ?)');
        $stmt->bind_param('id', $_SESSION['user_id'], $total);
        $stmt->execute();
        $order_id = $stmt->insert_id;
        $stmt->close();

        foreach ($cart_items as $item) {
            $p = $item['product'];
            $stmt = $conn->prepare('INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('iiid', $order_id, $p['id'], $item['qty'], $p['price']);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');
            $stmt->bind_param('ii', $item['qty'], $p['id']);
            $stmt->execute();
            $stmt->close();
        }

        $_SESSION['receipt'] = [
            'order_id' => $order_id,
            'username' => $name,
            'items' => $cart_items,
            'total' => $total,
            'cash_given' => $cashAmount,
            'change' => $changeAmount,
            'order_time' => date('Y-m-d H:i:s'),
        ];

        unset($_SESSION['cart']);
        $_SESSION['show_serving_popup'] = true; // Flag to show serving popup
        header('Location: receipt.php');
        exit();
    }
}

// Load products
$products = [];
$result = $conn->query('SELECT * FROM products');
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

// Categorize products for the menu
$meals = [];
$rices = [];
$drinks = [];
$desserts = [];
foreach ($products as $product) {
    $name = strtolower($product['name']);
    if (preg_match('/rice|kan-on|kanon|unli rice/', $name)) {
        $rices[] = $product;
        continue;
    }
    if (preg_match('/drink|softdrink|cola|sprite|royal|soda|juice|tea|coffee/', $name)) {
        $drinks[] = $product;
        continue;
    }
    if (preg_match('/halo|sorbetes|dessert|cake|leche|ube|halo-halo/', $name)) {
        $desserts[] = $product;
        continue;
    }
    $meals[] = $product;
}

// Get user's orders
$user_orders = [];
$result = $conn->query("SELECT id, status, order_time FROM orders WHERE user_id = {$_SESSION['user_id']} AND status IN ('pending', 'serving', 'ready') ORDER BY order_time DESC");
while ($row = $result->fetch_assoc()) {
    $user_orders[] = $row;
}

// Get user stats
$userTotalSpent = 0;
$userFavorites = [];
$result = $conn->query("SELECT SUM(total) as total_spent FROM orders WHERE user_id = {$_SESSION['user_id']}");
if ($row = $result->fetch_assoc()) {
    $userTotalSpent = (float)($row['total_spent'] ?? 0);
}

// Get total orders count
$totalOrders = 0;
$result = $conn->query("SELECT COUNT(*) as total FROM orders WHERE user_id = {$_SESSION['user_id']}");
if ($row = $result->fetch_assoc()) {
    $totalOrders = (int)$row['total'];
}

function getProductImage($product) {
    $uploadsDir = realpath(__DIR__ . '/../../uploads') . DIRECTORY_SEPARATOR;
    $filename = trim($product['image']);

    if (!empty($filename) && filter_var($filename, FILTER_VALIDATE_URL)) {
        return $filename;
    }

    $filename = basename($filename);
    if (!empty($filename) && file_exists($uploadsDir . $filename)) {
        return '../../uploads/' . $filename;
    }

    $fallback = [
        'Chicken Adobo' => 'https://images.unsplash.com/photo-1599785209707-4dda1b54d0c5?auto=format&fit=crop&w=800&q=80',
        'Pork Sinigang' => 'https://images.unsplash.com/photo-1576134026800-5d0c0dd7e157?auto=format&fit=crop&w=800&q=80',
        'Beef Tapa' => 'https://images.unsplash.com/photo-1605475127511-12ef1dce6d3b?auto=format&fit=crop&w=800&q=80',
        'Halo-Halo' => 'https://images.unsplash.com/photo-1520202402948-6032c31def1a?auto=format&fit=crop&w=800&q=80',
    ];

    return $fallback[$product['name']] ?? 'https://via.placeholder.com/900x675.png?text=FoodHub+Dish';
}

// Cart detail
$total = 0;
$cart_items = [];
if (isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $id => $qty) {
        foreach ($products as $product) {
            if ($product['id'] == $id) {
                $cart_items[] = ['product' => $product, 'qty' => $qty];
                $total += $product['price'] * $qty;
                break;
            }
        }
    }
}

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$todayUlam = [];
if (!empty($products)) {
    $todayUlam = $products[array_rand($products)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>User Dashboard - FoodHub</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
    <link rel="stylesheet" href="../../css/style.css" />
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand" href="../../index.php"><i class="bi bi-flag-fill" style="color:#FCD116"></i> FoodHub</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarUser" aria-controls="navbarUser" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse justify-content-end" id="navbarUser">
                <div class="navbar-nav align-items-center">
                    <span class="navbar-text me-3"><i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($name); ?></span>
                    <a class="nav-link" href="#menu">Menu</a>
                    <a class="nav-link" href="../../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="text-center mb-5 fade-in">
            <h1 class="display-4 fw-bold text-gradient mb-3">🍽️ Welcome to FoodHub</h1>
            <p class="lead text-muted">Order delicious Filipino food with ease and enjoy authentic flavors</p>
        </div>

        <!-- Dashboard Stats -->
        <div class="dashboard-stats mb-5">
            <div class="stat-card orders">
                <div class="stat-card-icon">
                    <i class="bi bi-receipt"></i>
                </div>
                <div>
                    <p class="mb-1">Total Orders</p>
                    <h3><?php echo $totalOrders ?? 0; ?></h3>
                </div>
            </div>
            <div class="stat-card revenue">
                <div class="stat-card-icon">
                    <i class="bi bi-cash-stack"></i>
                </div>
                <div>
                    <p class="mb-1">Total Spent</p>
                    <h3>₱<?php echo number_format($userTotalSpent ?? 0, 2); ?></h3>
                </div>
            </div>
            <div class="stat-card users">
                <div class="stat-card-icon">
                    <i class="bi bi-star"></i>
                </div>
                <div>
                    <p class="mb-1">Favorite Items</p>
                    <h3><?php echo count($userFavorites ?? []); ?></h3>
                </div>
            </div>
            <div class="stat-card pending">
                <div class="stat-card-icon">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div>
                    <p class="mb-1">Active Orders</p>
                    <h3><?php echo count($user_orders); ?></h3>
                </div>
            </div>
        </div>

        <?php if (!empty($todayUlam)): ?>
            <div class="card mb-4 bg-gradient-warm">
                <div class="row g-0 align-items-center">
                    <div class="col-md-4 p-3">
                        <img src="<?php echo getProductImage($todayUlam); ?>" class="dish-img" alt="<?php echo htmlspecialchars($todayUlam['name']); ?>" loading="lazy" onerror="this.src='https://via.placeholder.com/900x675.png?text=FoodHub+Dish';" />
                    </div>
                    <div class="col-md-8">
                        <div class="card-body">
                            <h4 class="card-title">Today's Ulam: <?php echo htmlspecialchars($todayUlam['name']); ?></h4>
                            <p class="card-text"><?php echo nl2br(htmlspecialchars($todayUlam['description'])); ?></p>
                            <span class="badge bg-secondary">Recommended</span>
                            <span class="fs-5 fw-bold ms-3">₱<?php echo number_format($todayUlam['price'], 2); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($user_orders)): ?>
            <div class="card mb-4 fade-in">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-clock-history"></i> Your Active Orders</h5>
                </div>
                <div class="card-body">
                    <?php foreach ($user_orders as $order): ?>
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="mb-0">Order #<?php echo $order['id']; ?></h6>
                                <span class="badge <?php
                                    echo $order['status'] == 'pending' ? 'bg-warning' :
                                         ($order['status'] == 'serving' ? 'bg-info' : 'bg-success');
                                ?>">
                                    <?php echo ucfirst($order['status']); ?>
                                </span>
                            </div>
                            <div class="order-progress">
                                <div class="order-step <?php echo $order['status'] == 'pending' || $order['status'] == 'serving' || $order['status'] == 'ready' ? 'active' : ''; ?>">
                                    <i class="bi bi-clock"></i>
                                </div>
                                <div class="order-step-label">Pending</div>

                                <div class="order-step <?php echo $order['status'] == 'serving' || $order['status'] == 'ready' ? 'active' : ''; ?>">
                                    <i class="bi bi-fire"></i>
                                </div>
                                <div class="order-step-label">Serving</div>

                                <div class="order-step <?php echo $order['status'] == 'ready' ? 'completed' : ''; ?>">
                                    <i class="bi bi-check-circle"></i>
                                </div>
                                <div class="order-step-label">Ready</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const savedPos = sessionStorage.getItem('foodhubDashboardScroll');
                if (savedPos) {
                    window.scrollTo(0, parseInt(savedPos, 10));
                    sessionStorage.removeItem('foodhubDashboardScroll');
                }
            });

            window.addEventListener('beforeunload', function() {
                sessionStorage.setItem('foodhubDashboardScroll', window.scrollY);
            });
        </script>

        <div class="menu-category-nav mb-4">
            <ul class="nav nav-pills flex-column flex-sm-row gap-2" role="tablist">
                <li class="nav-item" role="presentation"><a class="nav-link active" href="#meals">🍛 Meals</a></li>
                <li class="nav-item" role="presentation"><a class="nav-link" href="#rices">🍚 Rice</a></li>
                <li class="nav-item" role="presentation"><a class="nav-link" href="#drinks">🥤 Drinks</a></li>
                <li class="nav-item" role="presentation"><a class="nav-link" href="#desserts">🍰 Desserts</a></li>
            </ul>
        </div>

        <div class="row" id="menu">
            <div class="col-lg-12">
                <section id="meals" class="menu-section mb-5">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div>
                            <h3 class="section-title">Meals / Main Foods</h3>
                            <p class="text-muted mb-0">Sarap na ulam for your karenderia dining experience.</p>
                        </div>
                        <span class="badge bg-primary align-self-start"><?php echo count($meals); ?> items</span>
                    </div>
                    <div class="row">
                        <?php if (empty($meals)): ?>
                            <div class="col-12"><div class="alert alert-secondary">No meals available right now.</div></div>
                        <?php else: ?>
                            <?php foreach ($meals as $product): ?>
                                <div class="col-md-6 col-lg-4 mb-4">
                                    <div class="dish-card">
                                        <button class="favorite-btn" onclick="toggleFavorite(<?php echo $product['id']; ?>, this)">
                                            <i class="bi bi-heart"></i>
                                        </button>
                                        <img src="<?php echo getProductImage($product); ?>" class="dish-img" alt="<?php echo htmlspecialchars($product['name']); ?>" loading="lazy" onerror="this.src='https://via.placeholder.com/900x675.png?text=FoodHub+Dish';" />
                                        <div class="card-body text-center">
                                            <h5 class="card-title mb-2"><?php echo htmlspecialchars($product['name']); ?></h5>
                                            <div class="mb-3">
                                                <span class="fs-4 fw-bold text-primary">₱<?php echo number_format($product['price'], 2); ?></span>
                                            </div>
                                            <form method="POST" class="d-grid">
                                                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>" />
                                                <?php if ($product['stock'] > 0): ?>
                                                    <button type="submit" name="add_to_cart" class="btn btn-gradient">
                                                        <i class="bi bi-cart-plus"></i> Add to Cart
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-secondary" disabled>
                                                        <i class="bi bi-x-circle"></i> Out of Stock
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <section id="rices" class="menu-section mb-5">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div>
                            <h3 class="section-title">Rice</h3>
                            <p class="text-muted mb-0">Perfect pairings for every ulam.</p>
                        </div>
                        <span class="badge bg-success align-self-start"><?php echo count($rices); ?> items</span>
                    </div>
                    <div class="row">
                        <?php if (empty($rices)): ?>
                            <div class="col-12"><div class="alert alert-secondary">No rice items available right now.</div></div>
                        <?php else: ?>
                            <?php foreach ($rices as $product): ?>
                                <div class="col-md-6 col-lg-4 mb-4">
                                    <div class="dish-card">
                                        <button class="favorite-btn" onclick="toggleFavorite(<?php echo $product['id']; ?>, this)">
                                            <i class="bi bi-heart"></i>
                                        </button>
                                        <img src="<?php echo getProductImage($product); ?>" class="dish-img" alt="<?php echo htmlspecialchars($product['name']); ?>" loading="lazy" onerror="this.src='https://via.placeholder.com/900x675.png?text=FoodHub+Dish';" />
                                        <div class="card-body text-center">
                                            <h5 class="card-title mb-2"><?php echo htmlspecialchars($product['name']); ?></h5>
                                            <div class="mb-3">
                                                <span class="fs-4 fw-bold text-primary">₱<?php echo number_format($product['price'], 2); ?></span>
                                            </div>
                                            <form method="POST" class="d-grid">
                                                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>" />
                                                <?php if ($product['stock'] > 0): ?>
                                                    <button type="submit" name="add_to_cart" class="btn btn-gradient">
                                                        <i class="bi bi-cart-plus"></i> Add to Cart
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-secondary" disabled>
                                                        <i class="bi bi-x-circle"></i> Out of Stock
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <section id="drinks" class="menu-section mb-5">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div>
                            <h3 class="section-title">Drinks / Softdrinks</h3>
                            <p class="text-muted mb-0">Refreshments that complete your meal.</p>
                        </div>
                        <span class="badge bg-info align-self-start"><?php echo count($drinks); ?> items</span>
                    </div>
                    <div class="row">
                        <?php if (empty($drinks)): ?>
                            <div class="col-12"><div class="alert alert-secondary">No drinks available right now.</div></div>
                        <?php else: ?>
                            <?php foreach ($drinks as $product): ?>
                                <div class="col-md-6 col-lg-4 mb-4">
                                    <div class="dish-card">
                                        <button class="favorite-btn" onclick="toggleFavorite(<?php echo $product['id']; ?>, this)">
                                            <i class="bi bi-heart"></i>
                                        </button>
                                        <img src="<?php echo getProductImage($product); ?>" class="dish-img" alt="<?php echo htmlspecialchars($product['name']); ?>" loading="lazy" onerror="this.src='https://via.placeholder.com/900x675.png?text=FoodHub+Dish';" />
                                        <div class="card-body text-center">
                                            <h5 class="card-title mb-2"><?php echo htmlspecialchars($product['name']); ?></h5>
                                            <div class="mb-3">
                                                <span class="fs-4 fw-bold text-primary">₱<?php echo number_format($product['price'], 2); ?></span>
                                            </div>
                                            <form method="POST" class="d-grid">
                                                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>" />
                                                <?php if ($product['stock'] > 0): ?>
                                                    <button type="submit" name="add_to_cart" class="btn btn-gradient">
                                                        <i class="bi bi-cart-plus"></i> Add to Cart
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-secondary" disabled>
                                                        <i class="bi bi-x-circle"></i> Out of Stock
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <section id="desserts" class="menu-section mb-5">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div>
                            <h3 class="section-title">Desserts</h3>
                            <p class="text-muted mb-0">Sweet favorites to finish your meal.</p>
                        </div>
                        <span class="badge bg-warning align-self-start"><?php echo count($desserts); ?> items</span>
                    </div>
                    <div class="row">
                        <?php if (empty($desserts)): ?>
                            <div class="col-12"><div class="alert alert-secondary">No desserts available right now.</div></div>
                        <?php else: ?>
                            <?php foreach ($desserts as $product): ?>
                                <div class="col-md-6 col-lg-4 mb-4">
                                    <div class="dish-card">
                                        <button class="favorite-btn" onclick="toggleFavorite(<?php echo $product['id']; ?>, this)">
                                            <i class="bi bi-heart"></i>
                                        </button>
                                        <img src="<?php echo getProductImage($product); ?>" class="dish-img" alt="<?php echo htmlspecialchars($product['name']); ?>" loading="lazy" onerror="this.src='https://via.placeholder.com/900x675.png?text=FoodHub+Dish';" />
                                        <div class="card-body text-center">
                                            <h5 class="card-title mb-2"><?php echo htmlspecialchars($product['name']); ?></h5>
                                            <div class="mb-3">
                                                <span class="fs-4 fw-bold text-primary">₱<?php echo number_format($product['price'], 2); ?></span>
                                            </div>
                                            <form method="POST" class="d-grid">
                                                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>" />
                                                <?php if ($product['stock'] > 0): ?>
                                                    <button type="submit" name="add_to_cart" class="btn btn-gradient">
                                                        <i class="bi bi-cart-plus"></i> Add to Cart
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-secondary" disabled>
                                                        <i class="bi bi-x-circle"></i> Out of Stock
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>

        <!-- Floating Cart Button -->
        <button class="floating-cart-btn" onclick="openCartModal()" id="cartButton">
            <i class="bi bi-cart3"></i>
            <span class="cart-counter" id="cartCounter"><?php echo array_sum($cart_items ?? []); ?></span>
        </button>

        <!-- Cart Modal -->
        <div class="modal fade" id="cartModal" tabindex="-1" aria-labelledby="cartModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="cartModalLabel">
                            <i class="bi bi-cart3"></i> Your Cart
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="cartModalBody">
                        <?php if (empty($cart_items)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-cart-x display-1 text-muted"></i>
                                <h4 class="text-muted mt-3">Your cart is empty</h4>
                                <p class="text-muted">Add some delicious food to get started!</p>
                            </div>
                        <?php else: ?>
                            <div class="cart-items">
                                <?php foreach ($cart_items as $item): ?>
                                    <div class="cart-item" data-product-id="<?php echo $item['product']['id']; ?>">
                                        <div class="d-flex align-items-center">
                                            <img src="<?php echo getProductImage($item['product']); ?>" alt="<?php echo htmlspecialchars($item['product']['name']); ?>" class="cart-item-img">
                                            <div class="flex-grow-1 ms-3">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($item['product']['name']); ?></h6>
                                                <p class="text-muted mb-2">₱<?php echo number_format($item['product']['price'], 2); ?> each</p>
                                                <div class="d-flex align-items-center">
                                                    <button class="btn btn-sm btn-outline-secondary qty-btn" onclick="updateQuantity(<?php echo $item['product']['id']; ?>, -1)">
                                                        <i class="bi bi-dash"></i>
                                                    </button>
                                                    <span class="qty-display mx-3" id="qty-<?php echo $item['product']['id']; ?>"><?php echo $item['qty']; ?></span>
                                                    <button class="btn btn-sm btn-outline-secondary qty-btn" onclick="updateQuantity(<?php echo $item['product']['id']; ?>, 1)">
                                                        <i class="bi bi-plus"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-danger ms-3" onclick="removeFromCart(<?php echo $item['product']['id']; ?>)">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <strong>₱<?php echo number_format($item['product']['price'] * $item['qty'], 2); ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0">Total:</h5>
                                <h4 class="mb-0 text-primary" id="cartTotal">₱<?php echo number_format($total, 2); ?></h4>
                            </div>
                            <button class="btn btn-success w-100 mb-3" onclick="proceedToCheckout()">
                                <i class="bi bi-credit-card"></i> Proceed to Checkout
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Checkout Modal -->
        <div class="modal fade" id="checkoutModal" tabindex="-1" aria-labelledby="checkoutModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="checkoutModalLabel">
                            <i class="bi bi-credit-card"></i> Checkout
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="checkout-summary mb-4">
                            <h6>Order Summary</h6>
                            <div id="checkoutItems">
                                <?php if (!empty($cart_items)): ?>
                                    <?php foreach ($cart_items as $item): ?>
                                        <div class="d-flex justify-content-between py-2">
                                            <span><?php echo htmlspecialchars($item['product']['name']); ?> x<?php echo $item['qty']; ?></span>
                                            <span>₱<?php echo number_format($item['product']['price'] * $item['qty'], 2); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <hr>
                                    <div class="d-flex justify-content-between fw-bold">
                                        <span>Total Amount:</span>
                                        <span id="checkoutTotal">₱<?php echo number_format($total, 2); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($checkoutError): ?>
                            <div class="alert alert-danger" id="checkoutError"><?php echo htmlspecialchars($checkoutError); ?></div>
                        <?php endif; ?>
                        <form method="POST" id="checkoutForm">
                            <div class="mb-3">
                                <label for="cash_amount" class="form-label fw-bold">Cash Amount (₱)</label>
                                <input type="number" step="0.01" min="<?php echo $total; ?>" name="cash_amount" id="cash_amount" class="form-control form-control-lg" placeholder="Enter cash amount" value="<?php echo isset($_POST['cash_amount']) ? htmlspecialchars($_POST['cash_amount']) : ''; ?>" required>
                                <div class="form-text">Minimum amount: ₱<?php echo number_format($total, 2); ?></div>
                            </div>
                            <div class="d-grid">
                                <button type="submit" name="checkout" id="checkoutButton" class="btn btn-success btn-lg" disabled>
                                    <i class="bi bi-check-circle"></i> Complete Order
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <!-- Food Detail Modal -->
        <div class="modal fade" id="foodDetailModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Food Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <img id="modalFoodImage" src="" alt="" class="img-fluid rounded mb-3" />
                        <h4 id="modalFoodName"></h4>
                        <p id="modalFoodDesc" class="text-muted"></p>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fs-4 fw-bold" id="modalFoodPrice"></span>
                            <form method="POST" class="d-inline">
                                <input type="hidden" id="modalProductId" name="product_id" />
                                <button type="submit" name="add_to_cart" class="btn btn-success"><i class="bi bi-cart-plus"></i> Add to Cart</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Cart functionality
        let cartItems = <?php echo json_encode($cart_items); ?>;
        let cartTotal = <?php echo $total; ?>;

        function openCartModal() {
            const cartModal = new bootstrap.Modal(document.getElementById('cartModal'));
            cartModal.show();
        }

        function proceedToCheckout() {
            const cartModal = bootstrap.Modal.getInstance(document.getElementById('cartModal'));
            cartModal.hide();

            const checkoutModal = new bootstrap.Modal(document.getElementById('checkoutModal'));
            checkoutModal.show();
        }

        function updateQuantity(productId, change) {
            const qtyElement = document.getElementById(`qty-${productId}`);
            let currentQty = parseInt(qtyElement.textContent);

            currentQty += change;
            if (currentQty < 1) currentQty = 1;

            qtyElement.textContent = currentQty;

            // Update cart via AJAX
            updateCartItem(productId, currentQty);
        }

        function removeFromCart(productId) {
            if (confirm('Remove this item from cart?')) {
                // Update cart via AJAX
                updateCartItem(productId, 0);
            }
        }

        function updateCartItem(productId, quantity) {
            fetch('update_cart.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    product_id: productId,
                    quantity: quantity
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update cart counter
                    updateCartCounter(data.cart_count);

                    // Update cart total
                    document.getElementById('cartTotal').textContent = '₱' + data.total.toLocaleString('en-PH', {minimumFractionDigits: 2});

                    if (quantity === 0) {
                        // Remove item from cart modal
                        const cartItem = document.querySelector(`.cart-item[data-product-id="${productId}"]`);
                        if (cartItem) {
                            cartItem.remove();
                        }

                        // Check if cart is empty
                        const remainingItems = document.querySelectorAll('.cart-item');
                        if (remainingItems.length === 0) {
                            location.reload(); // Reload to show empty cart message
                        }
                    }

                    showToast(data.message, 'success');
                } else {
                    showToast(data.message || 'Failed to update cart', 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Network error occurred', 'danger');
            });
        }

        function updateCartCounter(count) {
            const counter = document.getElementById('cartCounter');
            if (count > 0) {
                counter.textContent = count;
                counter.style.display = 'flex';
            } else {
                counter.textContent = '';
                counter.style.display = 'none';
            }
        }

        // Toast notification system
        function showToast(message, type = 'info') {
            const toastContainer = document.getElementById('toastContainer') || createToastContainer();
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-white bg-${type} border-0`;
            toast.setAttribute('role', 'alert');
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="bi ${type === 'success' ? 'bi-check-circle' : type === 'danger' ? 'bi-exclamation-triangle' : 'bi-info-circle'} me-2"></i>
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            toastContainer.appendChild(toast);
            const bsToast = new bootstrap.Toast(toast, { autohide: true, delay: 3000 });
            bsToast.show();

            // Remove toast after it's hidden
            toast.addEventListener('hidden.bs.toast', () => toast.remove());
        }

        function createToastContainer() {
            const container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '9999';
            document.body.appendChild(container);
            return container;
        }

        // Add to cart with toast notification
        document.addEventListener('DOMContentLoaded', function() {
            // Handle add to cart buttons
            const addToCartButtons = document.querySelectorAll('button[name="add_to_cart"]');
            addToCartButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    // Don't submit form immediately, show toast first
                    e.preventDefault();

                    const form = this.closest('form');
                    const formData = new FormData(form);

                    fetch(form.action, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (response.ok) {
                            // Update cart counter
                            const currentCount = parseInt(document.getElementById('cartCounter').textContent || '0');
                            updateCartCounter(currentCount + 1);

                            // Show success toast
                            showToast('Item added to cart!', 'success');

                            // Submit form after a short delay
                            setTimeout(() => {
                                form.submit();
                            }, 1000);
                        } else {
                            showToast('Failed to add item to cart', 'danger');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showToast('Network error occurred', 'danger');
                    });
                });
            });

            // Checkout validation
            const cashField = document.getElementById('cash_amount');
            if (cashField) {
                cashField.addEventListener('input', validateCheckout);
                validateCheckout();
            }
        });

        function validateCheckout() {
            const total = <?php echo $total; ?>;
            const cashField = document.getElementById('cash_amount');
            const checkoutButton = document.getElementById('checkoutButton');
            const cash = parseFloat(cashField.value) || 0;

            if (cash >= total && cash > 0) {
                checkoutButton.disabled = false;
            } else {
                checkoutButton.disabled = true;
            }
        }

        // Food detail modal handler
        function showFoodDetail(id, name, price, desc, image) {
            document.getElementById('modalProductId').value = id;
            document.getElementById('modalFoodName').textContent = name;
            document.getElementById('modalFoodPrice').textContent = '₱' + parseFloat(price).toLocaleString('en-PH', {minimumFractionDigits: 2});
            document.getElementById('modalFoodDesc').textContent = desc;
            document.getElementById('modalFoodImage').src = image;
            new bootstrap.Modal(document.getElementById('foodDetailModal')).show();
        }

        // Favorite functionality
        function toggleFavorite(productId, button) {
            const icon = button.querySelector('i');
            const isFavorited = button.classList.contains('favorited');

            if (isFavorited) {
                button.classList.remove('favorited');
                showToast('Removed from favorites', 'info');
            } else {
                button.classList.add('favorited');
                showToast('Added to favorites!', 'success');
            }

            // Here you could add AJAX call to save favorite status
            // For now, we'll just use local storage
            let favorites = JSON.parse(localStorage.getItem('foodhub_favorites') || '[]');
            if (isFavorited) {
                favorites = favorites.filter(id => id != productId);
            } else {
                if (!favorites.includes(productId)) {
                    favorites.push(productId);
                }
            }
            localStorage.setItem('foodhub_favorites', JSON.stringify(favorites));
        }

        // Load favorites on page load
        document.addEventListener('DOMContentLoaded', function() {
            const favorites = JSON.parse(localStorage.getItem('foodhub_favorites') || '[]');
            favorites.forEach(productId => {
                const button = document.querySelector(`.favorite-btn[onclick*="${productId}"]`);
                if (button) {
                    button.classList.add('favorited');
                }
            });
        });
    </script>
</body>
</html>