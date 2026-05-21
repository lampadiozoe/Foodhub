<?php
session_start();
session_destroy();
// Redirect back to the project root login page
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
header('Location: ' . $base . '/public/login.php');
exit();
