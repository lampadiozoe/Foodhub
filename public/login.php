<?php
session_start();
include __DIR__ . '/../config/db.php';

$error      = '';
$targetRole = (isset($_GET['role']) && in_array($_GET['role'], ['admin', 'user'])) ? $_GET['role'] : '';

if (isset($_POST['login'])) {
    $email    = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $conn->prepare("SELECT id, name, password, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            if ($targetRole && $user['role'] !== $targetRole) {
                $error = 'Please use the correct login portal for your role.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name']    = $user['name'];
                $_SESSION['role']    = $user['role'];

                header('Location: ' . BASE_URL . ($user['role'] === 'admin' ? '/public/admin/dashboard.php' : '/public/user/dashboard.php'));
                exit();
            }
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
$B = BASE_URL;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login – FoodHub</title>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;800;900&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= $B ?>/css/style.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container">
        <a class="navbar-brand" href="<?= $B ?>/index.php"><div class="brand-icon">🍴</div> FoodHub</a>
        <a class="nav-link text-white" href="<?= $B ?>/index.php">Home</a>
    </div>
</nav>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card p-4 fade-in">
                <h2 class="text-center mb-1">
                    <?= $targetRole === 'admin' ? '🛡️ Admin Login' : ($targetRole === 'user' ? '👤 User Login' : '🍴 Sign In') ?>
                </h2>
                <p class="text-center text-muted mb-4" style="font-size:.85rem">
                    <?= $targetRole ? ucfirst($targetRole) . ' account' : 'Welcome back!' ?>
                </p>

                <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control" required
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="you@example.com">
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required placeholder="••••••••">
                    </div>
                    <button type="submit" name="login" class="btn btn-primary w-100">Sign In</button>
                </form>

                <hr class="my-4">
                <p class="text-center" style="font-size:.875rem">
                    New user? <a href="<?= $B ?>/public/user_register.php">Create an account</a>
                </p>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
