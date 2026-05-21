<?php
// ── Database ─────────────────────────────────────────────────────────────────
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "foodhub";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ── Base path helper ─────────────────────────────────────────────────────────
// Detects the web root so image/link paths work regardless of subfolder depth.
// e.g. http://localhost/foodhub/Foodhub  → BASE_URL = '/foodhub/Foodhub'
if (!defined('BASE_URL')) {
    $scriptDir  = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    // Walk up until we reach the project root (the folder that contains /public)
    // We detect this by looking for the document-root-relative path to THIS file.
    $configPath = str_replace('\\', '/', __DIR__);
    $docRoot    = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
    $projectDir = str_replace($docRoot, '', $configPath);      // e.g. /foodhub/Foodhub/config
    $projectDir = rtrim(dirname($projectDir), '/');             // e.g. /foodhub/Foodhub
    define('BASE_URL', $projectDir);                            // e.g. /foodhub/Foodhub
}

// ── Uploads URL helper ───────────────────────────────────────────────────────
if (!defined('UPLOADS_URL')) {
    define('UPLOADS_URL', BASE_URL . '/uploads/');
}

// ── Placeholder SVG ──────────────────────────────────────────────────────────
if (!defined('IMG_PLACEHOLDER')) {
    define('IMG_PLACEHOLDER', 'data:image/svg+xml,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300">'
        . '<rect fill="#f0f2f5" width="400" height="300"/>'
        . '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" '
        . 'font-family="Arial" font-size="18" fill="#aaa">FoodHub</text></svg>'
    ));
}
