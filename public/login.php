<?php
session_start();
include '../config/db.php';

$error = '';
$targetRole = isset($_GET['role']) && in_array($_GET['role'], ['admin', 'user']) ? $_GET['role'] : '';

if (isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $conn->prepare("SELECT id, name, password, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            if ($targetRole && $user['role'] !== $targetRole) {
                $error = 'Please use the correct login portal for your role.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['role'] = $user['role'];

                if ($user['role'] === 'admin') {
                    header("Location: admin/dashboard.php");
                } else {
                    header("Location: user/dashboard.php");
                }
                exit();
            }
        } else {
            $error = 'Invalid email or password.';
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - FoodHub</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="../index.php">FoodHub</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../index.php">Home</a>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card p-4 fade-in">
                    <h2 class="text-center mb-2"><?php echo $targetRole === 'admin' ? 'Admin Login' : ($targetRole === 'user' ? 'User Login' : 'Login to FoodHub'); ?></h2>
                    <p class="text-center text-muted mb-4"><?php echo $targetRole === 'admin' ? 'Admin Account' : ($targetRole === 'user' ? 'User Account' : ''); ?></p>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" placeholder="Enter your email" />
                        </div>
                        <div class="mb-4">
                            <label for="password" class="form-label">Password <span class="text-danger">*</span> </label>
                            <input type="password" class="form-control" id="password" name="password" required placeholder="Enter your password" />
                        </div>
                        <button type="submit" name="login" class="btn btn-primary w-100">Login</button>
                    </form>
                    
                    <hr class="my-4" />
                    <p class="text-center">New user? <a href="user_register.php">Create Account</a></p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../js/script.js"></script>
</body>
</html>