<?php
session_start();
include '../config/db.php';

$errors = [];
$success = '';

if (isset($_POST['register'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($name) || empty($email) || empty($password) || empty($confirm_password)) {
        $errors[] = 'All fields are required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please use a valid email address.';
    }
    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }

    if (empty($errors)) {
        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = 'Email already exists.';
        }
        $stmt->close();
    }

    if (empty($errors)) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $role = 'user';
        $stmt = $conn->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('ssss', $name, $email, $hashed, $role);
        if ($stmt->execute()) {
            $success = 'Registration successful! You can now <a href="user_login.php">login</a>.';
            $name = $email = '';
        } else {
            $errors[] = 'Database error: ' . $stmt->error;
        }
        $stmt->close();
    }
}

function old($field) {
    return isset($_POST[$field]) ? htmlspecialchars($_POST[$field]) : '';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>User Register - FoodHub</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="../css/style.css" />
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="../index.php">FoodHub</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../index.php">Home</a>
                <a class="nav-link" href="user_login.php">User Login</a>
                <span class="badge bg-success align-self-center">User Register</span>
            </div>
        </div>
    </nav>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card p-4 fade-in">
                    <h2 class="text-center mb-4">Register (User)</h2>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul>
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" value="<?php echo old('name'); ?>" required />
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" value="<?php echo old('email'); ?>" required />
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control" required />
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" name="confirm_password" class="form-control" required />
                        </div>
                        <button type="submit" name="register" class="btn btn-primary w-100">Register as User</button>
                    </form>
                    <p class="text-center mt-3">Already registered? <a href="user_login.php">Login</a></p>
                    <p class="text-center text-muted small mt-2">   </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>