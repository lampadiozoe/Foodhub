<?php
session_start();
include 'config/db.php';

$featuredProducts = [];
$result = $conn->query('SELECT * FROM products LIMIT 8');
while ($row = $result->fetch_assoc()) {
    $featuredProducts[] = $row;
}   

// Get recommended meals (excluding rice and drinks)
$recommendedMeals = [];
$result = $conn->query("SELECT * FROM products WHERE name NOT LIKE '%rice%' AND name NOT LIKE '%Rice%' AND name NOT LIKE '%softdrink%' AND name NOT LIKE '%drink%' AND name NOT LIKE '%Drink%' AND name NOT LIKE '%cola%' AND name NOT LIKE '%soda%' AND name NOT LIKE '%Coke%' AND name NOT LIKE '%Sprite%' AND name NOT LIKE '%Royal%' AND name NOT LIKE '%Sorbetes%' AND name != 'Halo-Halo' AND name != 'Kan-on' AND name != 'Unli Rice' LIMIT 4");
while ($row = $result->fetch_assoc()) {
    $recommendedMeals[] = $row;
}

// Get recommended desserts
$recommendedDesserts = [];
$result = $conn->query("SELECT * FROM products WHERE name = 'Halo-Halo' OR name = 'Sorbetes' LIMIT 2");
while ($row = $result->fetch_assoc()) {
    $recommendedDesserts[] = $row;
}   

function getProductImage($product) {
    $uploadsDir = realpath(__DIR__ . '/uploads') . DIRECTORY_SEPARATOR;
    $filename = trim($product['image']);

    if (!empty($filename) && filter_var($filename, FILTER_VALIDATE_URL)) {
        return $filename;
    }

    $filename = basename($filename);
    if (!empty($filename) && file_exists($uploadsDir . $filename)) {
        return 'uploads/' . $filename;
    }

    // High-quality AI-generated Filipino food images
    $fallback = [
        'Chicken Adobo' => 'https://images.unsplash.com/photo-1598103442097-8b74394b95c6?auto=format&fit=crop&w=800&q=80',
        'Pork Sinigang' => 'https://images.unsplash.com/photo-1576134026800-5d0c0dd7e157?auto=format&fit=crop&w=800&q=80',
        'Beef Tapa' => 'https://images.unsplash.com/photo-1607629710671-1179e2b5b9e9?auto=format&fit=crop&w=800&q=80',
        'Pancit Canton' => 'https://images.unsplash.com/photo-1621996346565-e3dbc353d2e5?auto=format&fit=crop&w=800&q=80',
        'Lumpiang Shanghai' => 'https://images.unsplash.com/photo-1603133872878-684f208fb84b?auto=format&fit=crop&w=800&q=80',
        'Halo-Halo' => 'https://images.unsplash.com/photo-1520202402948-6032c31def1a?auto=format&fit=crop&w=800&q=80',
        'Sorbetes' => 'https://images.unsplash.com/photo-1570197788417-0e82375c9371?auto=format&fit=crop&w=800&q=80',
    ];

    return $fallback[$product['name']] ?? 'https://images.unsplash.com/photo-1565299624946-b28f40a0ca4b?auto=format&fit=crop&w=800&q=80';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FoodHub - Filipino Food Ordering</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="bi bi-flag-fill" style="color:#FCD116"></i> FoodHub</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link active" href="index.php"><i class="bi bi-house-door-fill"></i> Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="#featured"><i class="bi bi-stars"></i> Featured</a></li>
                    <li class="nav-item"><a class="nav-link" href="#recommended"><i class="bi bi-heart-fill"></i> Recommended</a></li>
                    <li class="nav-item"><a class="nav-link" href="#why"><i class="bi bi-lightning-fill"></i> Why FoodHub</a></li>
                </ul>
                <div class="navbar-nav ms-3">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <?php if ($_SESSION['role'] == 'admin'): ?>
                            <a class="nav-link" href="public/admin/dashboard.php"><i class="bi bi-speedometer2"></i> Admin Dashboard</a>
                        <?php else: ?>
                            <a class="nav-link" href="public/user/dashboard.php"><i class="bi bi-cart-fill"></i> Dashboard</a>
                        <?php endif; ?>
                        <span class="navbar-text me-3">Kumusta, <?php echo htmlspecialchars($_SESSION['name']); ?> <span class="badge bg-<?php echo $_SESSION['role'] == 'admin' ? 'danger' : 'success'; ?>"><?php echo ucfirst($_SESSION['role']); ?></span></span>
                        <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
                    <?php else: ?>
                        <a class="nav-link" href="public/login.php"><i class="bi bi-box-arrow-in-right"></i> Sign in</a>
                        <a class="nav-link" href="public/user_register.php"><i class="bi bi-person-plus-fill"></i> Sign up</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="hero p-5 mb-4">
            <div class="row align-items-center">
                <div class="col-md-7">
                    <h1 class="display-4">Kain at Kainan. Eat-In Ordering Made Easy PH</h1>
                    <p class="lead">Discover authentic Filipino meals for your karenderia dining experience. Order, eat in, and savor local favorites with zero delivery process.</p>
                    <?php if (!isset($_SESSION['user_id'])): ?>
                        <button class="btn btn-primary btn-lg me-2" data-bs-toggle="modal" data-bs-target="#signupModal"><i class="bi bi-person-plus"></i> Get Started</button>
                        <button class="btn btn-outline-primary btn-lg" data-bs-toggle="modal" data-bs-target="#signinModal"><i class="bi bi-box-arrow-in-right"></i> Sign In</button>
                    <?php else: ?>
                        <a class="btn btn-primary btn-lg" href="<?php echo $_SESSION['role'] == 'admin' ? 'public/admin/dashboard.php' : 'public/user/dashboard.php'; ?>" role="button"><i class="bi bi-speedometer2"></i> Go to Dashboard</a>
                    <?php endif; ?>
                </div>
                <div class="col-md-5 text-center">
                    <img src="https://images.unsplash.com/photo-1565299624946-b28f40a0ca4b?auto=format&fit=crop&w=800&q=80" class="img-fluid rounded shadow" alt="Filipino food collage" loading="lazy">
                </div>
            </div>
        </div>

        <section id="why" class="row mt-5 fade-in">
            <div class="col-md-4">
                <div class="card feature-card p-3">
                    <div class="card-body">
                        <h5 class="card-title"><i class="bi bi-heart-fill"></i> Authentic Sarap</h5>
                        <p class="card-text">From adobo to halo-halo, we serve genuine pakain na Pinoy straight from trusted cooks.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card feature-card p-3">
                    <div class="card-body">
                        <h5 class="card-title"><i class="bi bi-shop-window"></i> Dine-In Service</h5>
                        <p class="card-text">Enjoy food hot from the kitchen while seated in our cozy karenderia, with instant pick-up and table service.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card feature-card p-3">
                    <div class="card-body">
                        <h5 class="card-title"><i class="bi bi-currency-exchange"></i> Great Value</h5>
                        <p class="card-text">All prices in Philippine Peso (₱) with transparent fees and quality portions that make every cent count.</p>
                    </div>
                </div>
            </div>
        </section>

        <div class="mt-5 fade-in" id="featured">
            <h2>Featured Dishes</h2>
            <div class="row gy-4">
                <?php foreach ($featuredProducts as $product): ?>
                    <div class="col-md-3 col-sm-6">
                        <div class="card hover-zoom">
                            <div class="position-relative">
                                <img src="<?php echo getProductImage($product); ?>" class="dish-img" alt="<?php echo htmlspecialchars($product['name']); ?>" loading="lazy" onerror="this.src='https://via.placeholder.com/800x600.png?text=FoodHub+Image';" />
                                <span class="badge sale-tag"><?php echo htmlspecialchars($product['stock'] > 5 ? 'Pinoy Favorite' : 'Best Seller'); ?></span>
                                <button class="btn btn-sm favorite-btn position-absolute top-0 end-0 m-2" data-product-id="<?php echo $product['id']; ?>" data-product-name="<?php echo htmlspecialchars($product['name']); ?>">
                                    <i class="bi bi-heart"></i>
                                </button>
                            </div>
                            <div class="card-body">
                                <h5 class="card-title"><?php echo htmlspecialchars($product['name']); ?></h5>
                                <p class="card-text"><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fs-5 fw-bold">₱<?php echo number_format($product['price'], 2); ?></span>
                                    <a href="public/login.php" class="btn btn-sm btn-primary">Order</a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mt-5 fade-in" id="recommended">
            <h2>Recommended For You</h2>
            <div class="row">
                <div class="col-md-8">
                    <h4 class="text-primary mb-3"><i class="bi bi-star-fill"></i> Main Meals</h4>
                    <div class="row gy-3">
                        <?php foreach ($recommendedMeals as $meal): ?>
                            <div class="col-md-6">
                                <div class="card h-100 hover-zoom">
                                    <div class="row g-0">
                                        <div class="col-4">
                                            <img src="<?php echo getProductImage($meal); ?>" class="img-fluid rounded-start h-100 object-fit-cover" alt="<?php echo htmlspecialchars($meal['name']); ?>" loading="lazy" onerror="this.src='https://via.placeholder.com/400x300.png?text=FoodHub+Image';" />
                                        </div>
                                        <div class="col-8">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <h6 class="card-title mb-1"><?php echo htmlspecialchars($meal['name']); ?></h6>
                                                    <button class="btn btn-sm favorite-btn p-0" data-product-id="<?php echo $meal['id']; ?>" data-product-name="<?php echo htmlspecialchars($meal['name']); ?>">
                                                        <i class="bi bi-heart text-muted"></i>
                                                    </button>
                                                </div>
                                                <p class="card-text small text-muted"><?php echo htmlspecialchars(substr($meal['description'], 0, 60)) . '...'; ?></p>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span class="fw-bold text-primary">₱<?php echo number_format($meal['price'], 2); ?></span>
                                                    <a href="public/login.php" class="btn btn-sm btn-outline-primary">Order</a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <h4 class="text-success mb-3"><i class="bi bi-cup-straw"></i> Desserts</h4>
                    <div class="row gy-3">
                        <?php foreach ($recommendedDesserts as $dessert): ?>
                            <div class="col-12">
                                <div class="card hover-zoom">
                                    <div class="position-relative">
                                        <img src="<?php echo getProductImage($dessert); ?>" class="card-img-top" alt="<?php echo htmlspecialchars($dessert['name']); ?>" loading="lazy" onerror="this.src='https://via.placeholder.com/400x300.png?text=FoodHub+Image';" />
                                        <button class="btn btn-sm favorite-btn position-absolute top-0 end-0 m-2" data-product-id="<?php echo $dessert['id']; ?>" data-product-name="<?php echo htmlspecialchars($dessert['name']); ?>">
                                            <i class="bi bi-heart"></i>
                                        </button>
                                    </div>
                                    <div class="card-body">
                                        <h6 class="card-title"><?php echo htmlspecialchars($dessert['name']); ?></h6>
                                        <p class="card-text small"><?php echo htmlspecialchars($dessert['description']); ?></p>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="fw-bold text-success">₱<?php echo number_format($dessert['price'], 2); ?></span>
                                            <a href="public/login.php" class="btn btn-sm btn-success">Order</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="bg-dark text-white text-center py-3 mt-5">
        <p>&copy; 2023 FoodHub. All rights reserved. Made with ❤️ in the Philippines.</p>
    </footer>

    <!-- Sign In Modal -->
    <div class="modal fade" id="signinModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Sign In</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="public/login.php" method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="signinEmail" class="form-label">Email</label>
                            <input type="email" class="form-control" id="signinEmail" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="signinPassword" class="form-label">Password</label>
                            <input type="password" class="form-control" id="signinPassword" name="password" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="login" class="btn btn-primary">Sign In</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Sign Up Modal -->
    <div class="modal fade" id="signupModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Create Account</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="public/user_register.php" method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="signupName" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="signupName" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="signupEmail" class="form-label">Email</label>
                            <input type="email" class="form-control" id="signupEmail" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="signupPassword" class="form-label">Password</label>
                            <input type="password" class="form-control" id="signupPassword" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label for="signupConfirm" class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" id="signupConfirm" name="confirm_password" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="register" class="btn btn-success">Sign Up</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/script.js"></script>
</body>
</html>