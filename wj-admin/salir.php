<?php
/** QuéDice! · Cierra la sesión del panel (POST con token: otra página no puede cerrarla por su cuenta). */
require_once dirname(__DIR__) . '/wj-includes/arranque.php';

musa_cabeceras_seguridad();
musa_sesion();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !musa_token_valido(isset($_POST['token']) ? $_POST['token'] : '')) {
    header('Location: index.php');
    exit;
}
$_SESSION = array();
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: acceso.php');
exit;
