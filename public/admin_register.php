<?php
// Admin self-registration is disabled to prevent unauthorized bypass.
header('Location: admin_login.php');
exit();
