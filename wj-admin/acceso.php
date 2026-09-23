<?php
/**
 * Musa Café · Acceso al panel
 * Se usa cuando el servidor no aplica la autenticación de .htaccess
 * (por ejemplo, nginx sin Apache). Valida contra el mismo .htpasswd.
 */
require_once dirname(__DIR__) . '/wj-includes/arranque.php';

musa_cabeceras_seguridad();
musa_sesion();

if (!empty($_SESSION['musa_admin'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$ip = musa_ip();
$bloqueo = musa_bloqueo_restante($ip);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($bloqueo > 0) {
        $error = 'Demasiados intentos fallidos. Espera ' . ceil($bloqueo / 60) . ' minuto(s).';
    } elseif (!musa_token_valido(isset($_POST['token']) ? $_POST['token'] : '')) {
        $error = 'La sesión expiró. Vuelve a intentarlo.';
    } else {
        $usuario = musa_texto(isset($_POST['usuario']) ? $_POST['usuario'] : '', 60);
        $clave = isset($_POST['clave']) ? (string) $_POST['clave'] : '';
        if (musa_credenciales_validas($usuario, $clave)) {
            session_regenerate_id(true);
            $_SESSION['musa_admin'] = $usuario;
            $_SESSION['musa_admin_hora'] = time();
            musa_registrar_intento($ip, false);
            musa_log('Acceso al panel', array('usuario' => $usuario, 'ip' => $ip));
            header('Location: index.php');
            exit;
        }
        musa_registrar_intento($ip, true);
        musa_log('Acceso fallido al panel', array('usuario' => $usuario, 'ip' => $ip));
        $error = 'Usuario o contraseña incorrectos.';
        $bloqueo = musa_bloqueo_restante($ip);
    }
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Acceso · Panel Musa Café</title>
<link rel="stylesheet" href="<?php echo musa_e(musa_recurso('wj-includes/css/admin.css')); ?>">
</head>
<body class="pantalla-acceso">
<form class="tarjeta-acceso" method="post" action="acceso.php">
  <img src="<?php echo musa_e(musa_url('wj-includes/images/optimizadas/logo_musacafe.png')); ?>" alt="Musa Café">
  <h1>Panel de administración</h1>
  <p>Ingresa con las credenciales configuradas en <code>wj-admin/.htpasswd</code>.</p>
  <?php if ($error !== '') : ?><div class="alerta error"><?php echo musa_e($error); ?></div><?php endif; ?>
  <label for="usuario">Usuario</label>
  <input type="text" id="usuario" name="usuario" autocomplete="username" required autofocus>
  <label for="clave">Contraseña</label>
  <input type="password" id="clave" name="clave" autocomplete="current-password" required>
  <input type="hidden" name="token" value="<?php echo musa_e(musa_token()); ?>">
  <button type="submit" class="boton" <?php echo $bloqueo > 0 ? 'disabled' : ''; ?>>Entrar</button>
</form>
</body>
</html>
