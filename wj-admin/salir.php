<?php
/** QuéDice! · Cierra la sesión del panel. */
require_once dirname(__DIR__) . '/wj-includes/arranque.php';

musa_sesion();
$_SESSION = array();
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: acceso.php');
exit;
