<?php
require_once __DIR__ . '/security.php';
rw_start_session_secure();
rw_audit('logout', array());
$_SESSION = array();
if (ini_get('session.use_cookies')) {
    $pp = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $pp['path'], $pp['domain'], $pp['secure'], $pp['httponly']);
}
session_destroy();
header('Location: index.php?logged=1'); exit;
?>
