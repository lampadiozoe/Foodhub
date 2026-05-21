<?php
session_start();
include __DIR__ . '/config/db.php';
include __DIR__ . '/includes/image_helper.php';

// ── Queries ──────────────────────────────────────────────────────────────────
$featuredProducts = [];
$result = $conn->query('SELECT * FROM products WHERE is_active = 1 LIMIT 8');
while ($row = $result->fetch_assoc()) $featuredProducts[] = $row;

$recommendedMeals = [];
$result = $conn->query("SELECT * FROM products WHERE is_active = 1
    AND name NOT REGEXP '(rice|kan-on|kanon|unli|softdrink|drink|cola|soda|coke|sprite|royal|sorbetes|halo)'
    LIMIT 4");
while ($row = $result->fetch_assoc()) $recommendedMeals[] = $row;

$recommendedDesserts = [];
$result = $conn->query("SELECT * FROM products WHERE is_active = 1
    AND (name = 'Halo-Halo' OR name = 'Sorbetes') LIMIT 2");
while ($row = $result->fetch_assoc()) $recommendedDesserts[] = $row;

$B = BASE_URL; // shorthand for HTML
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FoodHub – Filipino Food Ordering</title>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;700;800;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= $B ?>/css/style.css">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container">
        <a class="navbar-brand" href="<?= $B ?>/index.php">
            <div class="brand-icon">🍴</div> FoodHub
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link active" href="<?= $B ?>/index.php"><i class="bi bi-house-door-fill"></i> Home</a></li>
                <li class="nav-item"><a class="nav-link" href="#featured"><i class="bi bi-stars"></i> Featured</a></li>
                <li class="nav-item"><a class="nav-link" href="#recommended"><i class="bi bi-heart-fill"></i> Recommended</a></li>
            </ul>
            <div class="navbar-nav ms-3">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                        <a class="nav-link" href="<?= $B ?>/public/admin/dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
                    <?php else: ?>
                        <a class="nav-link" href="<?= $B ?>/public/user/dashboard.php"><i class="bi bi-cart-fill"></i> Dashboard</a>
                    <?php endif; ?>
                    <span class="navbar-text me-2">
                        <?= htmlspecialchars($_SESSION['name']) ?>
                        <span class="badge bg-<?= $_SESSION['role'] === 'admin' ? 'danger' : 'success' ?>">
                            <?= ucfirst($_SESSION['role']) ?>
                        </span>
                    </span>
                    <a class="nav-link" href="<?= $B ?>/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
                <?php else: ?>
                    <a class="nav-link" href="<?= $B ?>/public/login.php"><i class="bi bi-box-arrow-in-right"></i> Sign In</a>
                    <a class="nav-link" href="<?= $B ?>/public/user_register.php"><i class="bi bi-person-plus-fill"></i> Sign Up</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<div class="page-wrapper mt-4">

    <!-- HERO -->
    <div class="hero fade-in">
        <div class="row align-items-center">
            <div class="col-md-7">
                <span class="hero-emoji">🍛</span>
                <h1>Kain at Kainan.<br><small style="font-size:.6em;opacity:.9">Eat-In Ordering Made Easy</small></h1>
                <p class="mb-4">Authentic Filipino meals for your karenderia dining experience. Order, eat in, and savor local favorites.</p>
                <?php if (!isset($_SESSION['user_id'])): ?>
                    <a class="btn btn-primary me-2" href="<?= $B ?>/public/user_register.php"><i class="bi bi-person-plus me-1"></i>Get Started</a>
                    <a class="btn btn-outline-secondary" href="<?= $B ?>/public/login.php" style="color:#fff;border-color:rgba(255,255,255,.5)"><i class="bi bi-box-arrow-in-right me-1"></i>Sign In</a>
                <?php else: ?>
                    <a class="btn btn-primary" href="<?= $_SESSION['role'] === 'admin' ? $B.'/public/admin/dashboard.php' : $B.'/public/user/dashboard.php' ?>">
                        <i class="bi bi-speedometer2 me-1"></i>Go to Dashboard
                    </a>
                <?php endif; ?>
            </div>
            <div class="col-md-5 text-center mt-4 mt-md-0">
                <img src="https://images.unsplash.com/photo-1565299624946-b28f40a0ca4b?auto=format&fit=crop&w=800&q=80"
                     class="img-fluid rounded" style="border-radius:20px;box-shadow:0 20px 60px rgba(0,0,0,.25);"
                     alt="Filipino food" loading="lazy">
            </div>
        </div>
    </div>

    <!-- WHY FOODHUB -->
    <div class="dashboard-stats mb-5">
        <div class="stat-card orders fade-in" style="cursor:default">
            <div class="stat-card-icon"><i class="bi bi-heart-fill"></i></div>
            <div><p>Authentic Sarap</p><h3 style="font-size:1rem;font-weight:700">Genuine Pinoy taste</h3></div>
        </div>
        <div class="stat-card revenue fade-in" style="cursor:default">
            <div class="stat-card-icon"><i class="bi bi-shop-window"></i></div>
            <div><p>Dine-In Service</p><h3 style="font-size:1rem;font-weight:700">Hot from the kitchen</h3></div>
        </div>
        <div class="stat-card users fade-in" style="cursor:default">
            <div class="stat-card-icon"><i class="bi bi-currency-exchange"></i></div>
            <div><p>Great Value</p><h3 style="font-size:1rem;font-weight:700">Every peso counts</h3></div>
        </div>
    </div>

    <!-- FEATURED -->
    <section id="featured" class="mb-5">
        <div class="section-header mb-3">
            <div><h3 style="font-size:1.15rem">⭐ Featured Dishes</h3><p style="font-size:.8rem">Our best sellers</p></div>
        </div>
        <div class="products-grid stagger">
            <?php foreach ($featuredProducts as $p): ?>
            <div class="dish-card">
                <div class="dish-thumb-wrap">
                    <img src="<?= htmlspecialchars(getProductImage($p)) ?>"
                         class="dish-thumb"
                         alt="<?= htmlspecialchars($p['name']) ?>"
                         loading="lazy"
                         onerror="this.onerror=null;this.src='<?= IMG_PLACEHOLDER ?>';">
                </div>
                <div class="dish-card-body">
                    <div class="dish-card-name" title="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></div>
                    <div class="dish-card-price">₱<?= number_format($p['price'], 2) ?></div>
                </div>
                <div class="dish-card-footer">
                    <a href="<?= $B ?>/public/login.php" class="btn-add"><i class="bi bi-cart-plus"></i> Order</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- RECOMMENDED -->
    <section id="recommended" class="mb-5">
        <div class="section-header mb-3">
            <div><h3 style="font-size:1.15rem">❤️ Recommended</h3></div>
        </div>
        <div class="row g-3">
            <div class="col-md-8">
                <h5 style="font-size:.9rem;font-weight:800;margin-bottom:.85rem"><i class="bi bi-star-fill" style="color:var(--gold)"></i> Main Meals</h5>
                <div class="row g-3">
                    <?php foreach ($recommendedMeals as $m): ?>
                    <div class="col-sm-6">
                        <div class="card" style="flex-direction:row;overflow:hidden;border-radius:14px">
                            <img src="<?= htmlspecialchars(getProductImage($m)) ?>"
                                 style="width:90px;height:90px;object-fit:cover;flex-shrink:0;"
                                 alt="<?= htmlspecialchars($m['name']) ?>"
                                 loading="lazy"
                                 onerror="this.onerror=null;this.src='<?= IMG_PLACEHOLDER ?>';">
                            <div class="card-body p-2">
                                <h6 style="font-size:.8rem;margin-bottom:.25rem"><?= htmlspecialchars($m['name']) ?></h6>
                                <p style="font-size:.72rem;color:var(--muted);margin-bottom:.4rem"><?= htmlspecialchars(mb_substr($m['description'] ?? '', 0, 55)) ?>…</p>
                                <div style="display:flex;justify-content:space-between;align-items:center">
                                    <span style="font-weight:800;color:var(--primary);font-size:.82rem">₱<?= number_format($m['price'], 2) ?></span>
                                    <a href="<?= $B ?>/public/login.php" class="btn btn-primary btn-sm" style="font-size:.68rem;padding:.2rem .55rem">Order</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-md-4">
                <h5 style="font-size:.9rem;font-weight:800;margin-bottom:.85rem"><i class="bi bi-cup-straw" style="color:var(--accent)"></i> Desserts</h5>
                <?php foreach ($recommendedDesserts as $d): ?>
                <div class="card mb-3">
                    <img src="<?= htmlspecialchars(getProductImage($d)) ?>"
                         style="height:120px;object-fit:cover;width:100%;"
                         alt="<?= htmlspecialchars($d['name']) ?>"
                         loading="lazy"
                         onerror="this.onerror=null;this.src='<?= IMG_PLACEHOLDER ?>';">
                    <div class="card-body p-2">
                        <h6 style="font-size:.8rem;margin-bottom:.2rem"><?= htmlspecialchars($d['name']) ?></h6>
                        <div style="display:flex;justify-content:space-between;align-items:center">
                            <span style="font-weight:800;color:var(--accent);font-size:.82rem">₱<?= number_format($d['price'], 2) ?></span>
                            <a href="<?= $B ?>/public/login.php" class="btn btn-success btn-sm" style="font-size:.68rem;padding:.2rem .55rem">Order</a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

</div><!-- /.page-wrapper -->

<footer class="text-center py-4 mt-3" style="background:rgba(13,17,23,.96);color:rgba(255,255,255,.5);font-size:.8rem">
    &copy; <?= date('Y') ?> FoodHub. Made with ❤️ in the Philippines.
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= $B ?>/js/script.js"></script>
</body>
</html>
