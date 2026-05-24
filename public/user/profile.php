<?php
session_start();
include '../../config/db.php';

function ensureUserProfileColumns($conn) {
    $extra = [
        'phone'         => 'VARCHAR(25) NULL',
        'address'       => 'TEXT NULL',
        'profile_image' => 'VARCHAR(255) NULL',
        'bio'           => 'TEXT NULL',
    ];
    foreach ($extra as $column => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM users LIKE '$column'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN $column $definition");
        }
    }
}

function getUserInitials($name) {
    $parts = preg_split('/\s+/', trim($name ?? ''));
    if (!$parts || empty($parts[0])) return 'U';
    $initials = strtoupper(substr($parts[0], 0, 1));
    if (isset($parts[1])) $initials .= strtoupper(substr($parts[1], 0, 1));
    return $initials;
}

function getProfileImageUrl($image) {
    if (empty($image)) return '';
    if (filter_var($image, FILTER_VALIDATE_URL)) return $image;
    $uploadsDir = realpath(__DIR__.'/../../uploads').DIRECTORY_SEPARATOR;
    $basename = basename($image);
    if (file_exists($uploadsDir.$basename)) return '/uploads/'.rawurlencode($basename);
    return '';
}

function safeUploadProfileImage($file) {
    if (empty($file) || empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    $info = getimagesize($file['tmp_name']);
    if (!$info || !isset($allowed[$info['mime']])) return null;
    $uploadDir = realpath(__DIR__.'/../../uploads');
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $ext      = $allowed[$info['mime']];
    $filename = 'profile_'.$_SESSION['user_id'].'_'.time().'.'.$ext;
    $target   = $uploadDir.DIRECTORY_SEPARATOR.$filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) return null;
    return $filename;
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header('Location: ../user_login.php');
    exit();
}

ensureUserProfileColumns($conn);

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $name    = trim($_POST['name']    ?? '');
    $email   = trim($_POST['email']   ?? '');
    $phone   = trim($_POST['phone']   ?? '');
    $address = trim($_POST['address'] ?? '');
    $bio     = trim($_POST['bio']     ?? '');

    if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please provide a valid name and email.';
    } else {
        $profileImage = null;
        if (!empty($_FILES['profile_image']['name'])) {
            $uploadResult = safeUploadProfileImage($_FILES['profile_image']);
            if ($uploadResult === null) {
                $error = 'Please upload a valid image (jpg, png, webp, gif).';
            } else {
                $profileImage = $uploadResult;
            }
        }
        if ($error === '') {
            if ($profileImage !== null) {
                $stmt = $conn->prepare('UPDATE users SET name=?,email=?,phone=?,address=?,bio=?,profile_image=? WHERE id=?');
                $stmt->bind_param('ssssssi', $name, $email, $phone, $address, $bio, $profileImage, $_SESSION['user_id']);
            } else {
                $stmt = $conn->prepare('UPDATE users SET name=?,email=?,phone=?,address=?,bio=? WHERE id=?');
                $stmt->bind_param('sssssi', $name, $email, $phone, $address, $bio, $_SESSION['user_id']);
            }
            if ($stmt->execute()) {
                $_SESSION['name']    = $name;
                $_SESSION['message'] = 'Profile saved successfully!';
                header('Location: profile.php');
                exit();
            }
            $error = 'Unable to update profile. Please try again.';
            $stmt->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    session_destroy();
    header('Location: ../user_login.php');
    exit();
}

$stmt = $conn->prepare('SELECT name,email,phone,address,bio,profile_image FROM users WHERE id=?');
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($name, $email, $phone, $address, $bio, $profileImage);
$stmt->fetch();
$stmt->close();

/* Null-safe all values so htmlspecialchars() never gets null */
$name     = $name     ?? '';
$email    = $email    ?? '';
$phone    = $phone    ?? '';
$address  = $address  ?? '';
$bio      = $bio      ?? '';

$profileImageUrl = getProfileImageUrl($profileImage);
$flashMsg = $_SESSION['message'] ?? ''; unset($_SESSION['message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>My Profile — FoodHub</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.css"/>
    <style>
        :root {
            --green-dark:  #1a3a1a;
            --green-mid:   #2d5a2d;
            --green-light: #4a8c4a;
            --green-pale:  #e8f5e8;
            --accent:      #ff6b35;
            --accent-soft: #fff2ec;
            --text:        #1c2b1c;
            --muted:       #6b7c6b;
            --border:      #d4e4d4;
            --surface:     #f5faf5;
            --white:       #ffffff;
            --radius-sm:   8px;
            --radius-md:   14px;
            --radius-lg:   22px;
            --radius-xl:   32px;
            --shadow-sm:   0 2px 8px rgba(26,58,26,.08);
            --shadow-md:   0 8px 28px rgba(26,58,26,.12);
            --shadow-lg:   0 20px 60px rgba(26,58,26,.16);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--surface);
            color: var(--text);
            min-height: 100vh;
        }

        /* ── Navbar ─────────────────────────────────────────────────────── */
        .top-nav {
            background: var(--green-dark);
            padding: .9rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 20px rgba(0,0,0,.25);
        }
        .nav-brand {
            font-family: 'Playfair Display', serif;
            color: #fff;
            font-size: 1.35rem;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: .5rem;
        }
        .nav-links { display: flex; align-items: center; gap: .25rem; }
        .nav-links a, .nav-links button {
            color: rgba(255,255,255,.75);
            text-decoration: none;
            font-size: .875rem;
            font-weight: 500;
            padding: .45rem .9rem;
            border-radius: 30px;
            border: none;
            background: none;
            cursor: pointer;
            transition: all .2s;
        }
        .nav-links a:hover, .nav-links button:hover { color: #fff; background: rgba(255,255,255,.1); }
        .nav-links a.active { color: #fff; background: rgba(255,255,255,.15); }

        /* ── Page layout ────────────────────────────────────────────────── */
        .page-body {
            max-width: 960px;
            margin: 2.5rem auto;
            padding: 0 1.25rem 4rem;
        }

        /* ── Hero strip ─────────────────────────────────────────────────── */
        .hero-strip {
            background: linear-gradient(135deg, var(--green-dark) 0%, var(--green-mid) 60%, var(--green-light) 100%);
            border-radius: var(--radius-xl);
            padding: 2.5rem 2.5rem 5rem;
            position: relative;
            overflow: hidden;
            margin-bottom: -4rem;
        }
        .hero-strip::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Ccircle cx='30' cy='30' r='20'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }
        .hero-title {
            font-family: 'Playfair Display', serif;
            color: #fff;
            font-size: 1.7rem;
            position: relative;
        }
        .hero-sub { color: rgba(255,255,255,.65); font-size: .875rem; margin-top: .25rem; position: relative; }

        /* ── Main card ──────────────────────────────────────────────────── */
        .main-card {
            background: var(--white);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-lg);
            padding: 2rem 2rem 2.5rem;
            position: relative;
        }

        /* ── Avatar section ─────────────────────────────────────────────── */
        .avatar-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1rem 1rem 1.5rem;
            border-right: 1px solid var(--border);
        }
        @media (max-width: 767px) {
            .avatar-section { border-right: none; border-bottom: 1px solid var(--border); padding-bottom: 1.5rem; margin-bottom: 1.5rem; }
        }
        .avatar-ring {
            position: relative;
            display: inline-block;
            margin-bottom: 1rem;
        }
        .avatar-ring::before {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--green-light), var(--accent));
            z-index: 0;
        }
        .profile-avatar-large {
            width: 110px; height: 110px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--white);
            position: relative;
            z-index: 1;
            display: block;
        }
        .profile-avatar-fallback {
            width: 110px; height: 110px;
            border-radius: 50%;
            display: grid; place-items: center;
            font-size: 2.2rem; font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, var(--green-light), var(--green-dark));
            border: 4px solid var(--white);
            position: relative;
            z-index: 1;
        }
        .edit-btn {
            position: absolute;
            bottom: 2px; right: 2px;
            z-index: 10;
            width: 32px; height: 32px;
            border-radius: 50%;
            background: var(--accent);
            color: #fff;
            border: 2px solid var(--white);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            box-shadow: 0 3px 10px rgba(255,107,53,.4);
            transition: transform .2s, box-shadow .2s;
            pointer-events: all;
        }
        .edit-btn:hover { transform: scale(1.15); box-shadow: 0 5px 16px rgba(255,107,53,.5); }
        .edit-btn i { font-size: .8rem; pointer-events: none; }

        .user-name {
            font-family: 'Playfair Display', serif;
            font-size: 1.25rem;
            color: var(--text);
            margin-bottom: .25rem;
        }
        .user-hint { font-size: .75rem; color: var(--muted); }

        /* ── Info chips ─────────────────────────────────────────────────── */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .75rem;
        }
        @media (max-width: 500px) { .info-grid { grid-template-columns: 1fr; } }
        .info-chip {
            background: var(--green-pale);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: .75rem 1rem;
        }
        .info-chip-label {
            font-size: .7rem;
            font-weight: 600;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--green-light);
            margin-bottom: .2rem;
        }
        .info-chip-value {
            font-size: .875rem;
            color: var(--text);
            font-weight: 500;
        }
        .info-chip-value.empty { color: var(--muted); font-style: italic; }

        /* ── Divider ────────────────────────────────────────────────────── */
        .section-divider {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin: 2rem 0 1.5rem;
        }
        .section-divider-line { flex: 1; height: 1px; background: var(--border); }
        .section-divider-label {
            font-family: 'Playfair Display', serif;
            font-size: .95rem;
            color: var(--muted);
            white-space: nowrap;
        }

        /* ── Form fields ────────────────────────────────────────────────── */
        .form-label {
            font-size: .78rem;
            font-weight: 600;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: var(--green-mid);
            margin-bottom: .4rem;
        }
        .form-control, .form-control:focus {
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: 'Outfit', sans-serif;
            font-size: .9rem;
            padding: .65rem .9rem;
            background: var(--white);
            color: var(--text);
            box-shadow: none;
            transition: border-color .2s, box-shadow .2s;
        }
        .form-control:focus {
            border-color: var(--green-light);
            box-shadow: 0 0 0 3px rgba(74,140,74,.12);
        }
        textarea.form-control { resize: vertical; min-height: 90px; }

        /* ── Buttons ────────────────────────────────────────────────────── */
        .btn-save {
            background: linear-gradient(135deg, var(--green-mid), var(--green-dark));
            color: #fff;
            border: none;
            border-radius: 30px;
            padding: .65rem 2rem;
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            font-size: .9rem;
            cursor: pointer;
            transition: transform .2s, box-shadow .2s;
            box-shadow: 0 4px 16px rgba(26,58,26,.3);
        }
        .btn-save:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(26,58,26,.35); color: #fff; }
        .btn-back {
            background: var(--white);
            color: var(--muted);
            border: 1.5px solid var(--border);
            border-radius: 30px;
            padding: .65rem 1.5rem;
            font-family: 'Outfit', sans-serif;
            font-weight: 500;
            font-size: .9rem;
            text-decoration: none;
            transition: all .2s;
        }
        .btn-back:hover { border-color: var(--green-light); color: var(--green-mid); }

        /* ── Alerts ─────────────────────────────────────────────────────── */
        .alert-custom {
            border-radius: var(--radius-md);
            padding: .85rem 1.1rem;
            font-size: .875rem;
            font-weight: 500;
            border: none;
            display: flex;
            align-items: center;
            gap: .6rem;
        }
        .alert-success-custom { background: #e6f9ec; color: #1a6b35; }
        .alert-danger-custom  { background: #fde8e8; color: #9b1c1c; }

        /* ── Cropper modal ──────────────────────────────────────────────── */
        .cropper-modal-img { max-width: 100%; max-height: 58vh; display: block; margin: 0 auto; }
        .modal-content { border: none; border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-lg); }
        .modal-header { background: var(--green-dark); color: #fff; border: none; padding: 1.1rem 1.5rem; }
        .modal-header .btn-close { filter: invert(1); opacity: .7; }
        .modal-footer { border-top: 1px solid var(--border); padding: 1rem 1.5rem; }
        .btn-crop {
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 30px;
            padding: .55rem 1.5rem;
            font-weight: 600;
            font-size: .875rem;
            cursor: pointer;
            transition: transform .2s, box-shadow .2s;
        }
        .btn-crop:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(255,107,53,.4); }

        /* ── Toast ──────────────────────────────────────────────────────── */
        #toastContainer { z-index: 99999; }
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="top-nav">
    <a class="nav-brand" href="dashboard.php">🍴 FoodHub</a>
    <div class="nav-links">
        <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Dashboard</a>
        <a href="profile.php" class="active"><i class="bi bi-person me-1"></i>Profile</a>
        <form method="POST" class="d-inline">
            <input type="hidden" name="logout" value="1"/>
            <button type="submit"><i class="bi bi-box-arrow-right me-1"></i>Logout</button>
        </form>
    </div>
</nav>

<!-- Toast container -->
<div id="toastContainer" class="toast-container position-fixed top-0 end-0 p-3"></div>

<div class="page-body">

    <!-- Hero strip -->
    <div class="hero-strip">
        <div class="hero-title">My Profile</div>
        <div class="hero-sub">Manage your personal info and account details</div>
    </div>

    <!-- Main card -->
    <div class="main-card">

        <?php if ($flashMsg): ?>
        <div class="alert-custom alert-success-custom mb-4">
            <i class="bi bi-check-circle-fill"></i> <?=htmlspecialchars($flashMsg)?>
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert-custom alert-danger-custom mb-4">
            <i class="bi bi-exclamation-triangle-fill"></i> <?=htmlspecialchars($error)?>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="save_profile" value="1"/>

            <div class="row g-0">
                <!-- Avatar column -->
                <div class="col-md-3 avatar-section">
                    <div class="avatar-ring">
                        <?php if ($profileImageUrl): ?>
                            <img id="profilePreview"
                                 src="<?=htmlspecialchars($profileImageUrl)?>"
                                 alt="Profile photo"
                                 class="profile-avatar-large"/>
                        <?php else: ?>
                            <div id="profilePreviewFallback" class="profile-avatar-fallback">
                                <?=htmlspecialchars(getUserInitials($name))?>
                            </div>
                        <?php endif; ?>
                        <label for="profile_image_input" class="edit-btn" title="Change photo">
                            <i class="bi bi-pencil-fill"></i>
                        </label>
                    </div>
                    <div class="user-name"><?=htmlspecialchars($name ?: 'Your Name')?></div>
                    <div class="user-hint">Tap ✏️ to update photo</div>
                </div>

                <!-- Info column -->
                <div class="col-md-9 ps-md-4 pt-2">
                    <div class="info-grid">
                        <div class="info-chip">
                            <div class="info-chip-label"><i class="bi bi-envelope me-1"></i>Email</div>
                            <div class="info-chip-value <?=empty($email)?'empty':''?>">
                                <?=htmlspecialchars($email ?: 'Not set')?>
                            </div>
                        </div>
                        <div class="info-chip">
                            <div class="info-chip-label"><i class="bi bi-phone me-1"></i>Phone</div>
                            <div class="info-chip-value <?=empty($phone)?'empty':''?>">
                                <?=htmlspecialchars($phone ?: 'Not set')?>
                            </div>
                        </div>
                        <div class="info-chip">
                            <div class="info-chip-label"><i class="bi bi-geo-alt me-1"></i>Address</div>
                            <div class="info-chip-value <?=empty($address)?'empty':''?>">
                                <?=htmlspecialchars($address ?: 'Not set')?>
                            </div>
                        </div>
                        <div class="info-chip">
                            <div class="info-chip-label"><i class="bi bi-chat-quote me-1"></i>Bio</div>
                            <div class="info-chip-value <?=empty($bio)?'empty':''?>">
                                <?=htmlspecialchars($bio ?: 'No bio yet')?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section divider -->
            <div class="section-divider">
                <div class="section-divider-line"></div>
                <div class="section-divider-label">Edit Details</div>
                <div class="section-divider-line"></div>
            </div>

            <!-- Edit fields -->
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="inp_name">Full Name</label>
                    <input type="text" class="form-control" id="inp_name" name="name"
                           value="<?=htmlspecialchars($name)?>" required placeholder="e.g. Juan dela Cruz"/>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="inp_email">Email Address</label>
                    <input type="email" class="form-control" id="inp_email" name="email"
                           value="<?=htmlspecialchars($email)?>" required placeholder="you@email.com"/>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="inp_phone">Phone</label>
                    <input type="text" class="form-control" id="inp_phone" name="phone"
                           value="<?=htmlspecialchars($phone)?>" placeholder="+63 9xx xxx xxxx"/>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="inp_address">Address</label>
                    <input type="text" class="form-control" id="inp_address" name="address"
                           value="<?=htmlspecialchars($address)?>" placeholder="City, Province"/>
                </div>
                <div class="col-12">
                    <label class="form-label" for="inp_bio">Bio</label>
                    <textarea class="form-control" id="inp_bio" name="bio"
                              placeholder="Write a short intro about yourself…"><?=htmlspecialchars($bio)?></textarea>
                </div>
            </div>

            <!-- Hidden file input -->
            <input type="file" id="profile_image_input" name="profile_image"
                   accept="image/*" class="d-none"/>

            <!-- Actions -->
            <div class="d-flex justify-content-end align-items-center gap-2 mt-4">
                <a href="dashboard.php" class="btn-back">
                    <i class="bi bi-arrow-left me-1"></i>Dashboard
                </a>
                <button type="submit" class="btn-save">
                    <i class="bi bi-check-lg me-1"></i>Save Profile
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Cropper Modal -->
<div class="modal fade" id="cropperModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" style="font-family:'Playfair Display',serif">
                    <i class="bi bi-crop me-2"></i>Crop Profile Picture
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3 text-center" style="background:#f5faf5;">
                <img id="cropperImage" class="cropper-modal-img" src="" alt="Crop preview"/>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-back" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-crop" id="cropUploadBtn">
                    <i class="bi bi-cloud-upload me-1"></i>Crop &amp; Upload
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.js"></script>
<script>
function showToast(message, type) {
    type = type || 'info';
    var bg = {success:'#1a6b35', danger:'#9b1c1c', info:'#1a3a6b', warning:'#7c4d00'};
    var container = document.getElementById('toastContainer');
    var toast = document.createElement('div');
    toast.style.cssText = 'background:' + (bg[type]||bg.info) + ';color:#fff;padding:.75rem 1.1rem;border-radius:10px;font-family:Outfit,sans-serif;font-size:.875rem;display:flex;align-items:center;gap:.5rem;box-shadow:0 8px 24px rgba(0,0,0,.18);margin-bottom:.5rem;';
    toast.innerHTML = message;
    container.appendChild(toast);
    setTimeout(function() { toast.style.opacity='0'; toast.style.transition='opacity .4s'; setTimeout(function(){ toast.remove(); }, 400); }, 3200);
}

var _cropper   = null;
var _cropModal = null;

document.getElementById('profile_image_input').addEventListener('change', function() {
    var file = this.files && this.files[0];
    if (!file) return;
    if (!file.type.startsWith('image/')) { showToast('Please select an image file.', 'danger'); return; }

    var reader = new FileReader();
    reader.onload = function(e) {
        var modalImg = document.getElementById('cropperImage');
        modalImg.src = e.target.result;
        if (_cropper) { _cropper.destroy(); _cropper = null; }
        var modalEl = document.getElementById('cropperModal');
        _cropModal  = bootstrap.Modal.getOrCreateInstance(modalEl);
        _cropModal.show();
        modalEl.addEventListener('shown.bs.modal', function onShown() {
            modalEl.removeEventListener('shown.bs.modal', onShown);
            _cropper = new Cropper(modalImg, { aspectRatio:1, viewMode:1, autoCropArea:.9, movable:true, zoomable:true, responsive:true, dragMode:'move' });
        });
        modalEl.addEventListener('hidden.bs.modal', function onHidden() {
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
            if (_cropper) { _cropper.destroy(); _cropper = null; }
        });
    };
    reader.readAsDataURL(file);
});

document.getElementById('cropUploadBtn').addEventListener('click', function() {
    if (!_cropper) { showToast('Cropper not ready — try again.', 'warning'); return; }
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading…';

    _cropper.getCroppedCanvas({width:420, height:420, imageSmoothingQuality:'high'}).toBlob(function(blob) {
        var fd = new FormData();
        fd.append('profile_image', blob, 'profile_crop.png');
        fetch('upload_profile_image.php', {method:'POST', body:fd})
            .then(function(r){ return r.text(); })
            .then(function(text) {
                var data;
                try { data = JSON.parse(text); } catch(e) { showToast('Server error — check upload_profile_image.php', 'danger'); return; }
                if (data && data.success) {
                    var preview  = document.getElementById('profilePreview');
                    var fallback = document.getElementById('profilePreviewFallback');
                    var newSrc   = data.url + '?t=' + Date.now();
                    if (preview) {
                        preview.src = newSrc;
                    } else if (fallback) {
                        var img = document.createElement('img');
                        img.id = 'profilePreview'; img.className = 'profile-avatar-large'; img.src = newSrc;
                        fallback.parentNode.replaceChild(img, fallback);
                    }
                    showToast('✅ Profile picture updated!', 'success');
                    if (_cropModal) _cropModal.hide();
                } else {
                    showToast((data && data.message) || 'Upload failed.', 'danger');
                }
            })
            .catch(function(err){ console.error(err); showToast('Network error — try again.', 'danger'); })
            .finally(function(){ btn.disabled=false; btn.innerHTML='<i class="bi bi-cloud-upload me-1"></i>Crop & Upload'; });
    }, 'image/png');
});

<?php if ($flashMsg): ?>
document.addEventListener('DOMContentLoaded', function() {
    showToast('✅ <?=addslashes(htmlspecialchars($flashMsg))?>', 'success');
});
<?php endif; ?>
</script>
</body>
</html>