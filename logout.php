<?php
session_start();
session_destroy();
header('Location: public/user_login.php');
exit();
?>