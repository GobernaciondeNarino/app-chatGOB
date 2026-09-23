<?php
/**
 * Musa Café · Acceso al panel
 * - Instalación recién descargada (sin cuenta): pide crear el usuario y la contraseña del administrador.
 * - Con cuenta: valida contra wj-content/config/.htpasswd (el mismo formato de Apache).
 */
require_once dirname(__DIR__) . '/wj-includes/arranque.php';

musa_cabeceras_seguridad();
musa_sesion();

if (!empty($_SESSION['musa_admin']) && musa_hay_administrador()) {
    header('Location: index.php');
    exit;
}

$instalar = !musa_hay_administrador();
$error = '';
$ip = musa_ip();
$bloqueo = musa_bloqueo_restante($ip);
$usuario = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $usuario = musa_texto(isset($_POST['usuario']) ? $_POST['usuario'] : '', 60);
    $clave = isset($_POST['clave']) ? (string) $_POST['clave'] : '';

    if ($bloqueo > 0) {
        $error = 'Demasiados intentos fallidos. Espera ' . ceil($bloqueo / 60) . ' minuto(s).';
    } elseif (!musa_token_valido(isset($_POST['token']) ? $_POST['token'] : '')) {
        $error = 'La sesión expiró. Vuelve a intentarlo.';
    } elseif ($instalar) {
        // Primera configuración: se crea la cuenta del administrador.
        $repetir = isset($_POST['repetir']) ? (string) $_POST['repetir'] : '';
        $limpio = preg_replace('/[^A-Za-z0-9._@\-]/', '', $usuario);
        $debil = musa_clave_debil($clave, $limpio);
        if ($limpio === '' || $limpio !== $usuario || strlen($limpio) < 3) {
            $error = 'El usuario debe tener al menos 3 caracteres: letras, números, punto, guion o @.';
        } elseif ($debil !== '') {
            $error = $debil;
        } elseif ($clave !== $repetir) {
            $error = 'La confirmación de la contraseña no coincide.';
        } elseif (musa_hay_administrador()) {
            // Otra persona la creó mientras tanto.
            $instalar = false;
            $error = 'La cuenta de administrador ya fue creada. Ingresa con ella.';
        } elseif (!musa_htpasswd_guardar($limpio, $clave)) {
            $error = 'No fue posible guardar la cuenta. Revisa que wj-content tenga permiso de escritura.';
        } else {
            session_regenerate_id(true);
            $_SESSION['musa_admin'] = $limpio;
            $_SESSION['musa_admin_hora'] = time();
            musa_log('Cuenta de administrador creada en la primera configuración', array('usuario' => $limpio, 'ip' => $ip));
            header('Location: api.php');
            exit;
        }
    } elseif (musa_credenciales_validas($usuario, $clave)) {
        session_regenerate_id(true);
        $_SESSION['musa_admin'] = $usuario;
        $_SESSION['musa_admin_hora'] = time();
        musa_registrar_intento($ip, false);
        musa_log('Acceso al panel', array('usuario' => $usuario, 'ip' => $ip));
        header('Location: index.php');
        exit;
    } else {
        musa_registrar_intento($ip, true);
        musa_log('Acceso fallido al panel', array('usuario' => $usuario, 'ip' => $ip));
        $error = 'Usuario o contraseña incorrectos.';
        $bloqueo = musa_bloqueo_restante($ip);
    }
}

$marca = (string) musa_dato(musa_ajustes(), 'marca.nombre', 'Musa Café');
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo $instalar ? 'Configuración inicial' : 'Acceso'; ?> · Panel <?php echo musa_e($marca); ?></title>
<link rel="icon" href="<?php echo musa_e(musa_url('wj-includes/images/optimizadas/logo_musacafe.png')); ?>">
<link rel="stylesheet" href="<?php echo musa_e(musa_recurso('wj-includes/css/admin.css')); ?>">
</head>
<body class="pantalla-acceso">
<form class="tarjeta-acceso" method="post" action="acceso.php">
  <div class="acceso-marca">
    <img src="<?php echo musa_e(musa_url('wj-includes/images/optimizadas/logo_musacafe.png')); ?>" alt="">
  </div>
  <?php if ($instalar) : ?>
    <h1>Configuración inicial</h1>
    <p>Crea la cuenta de administrador del panel. Guárdala en un lugar seguro: con ella verás las
      conversaciones y configurarás el avatar.</p>
  <?php else : ?>
    <h1>Panel de administración</h1>
    <p><?php echo musa_e($marca); ?> · <?php echo musa_e(musa_dato(musa_ajustes(), 'marca.entidad', 'Gobernación de Nariño')); ?></p>
  <?php endif; ?>
  <?php if ($error !== '') : ?><div class="alerta error" role="alert"><?php echo musa_e($error); ?></div><?php endif; ?>
  <label for="usuario">Usuario</label>
  <input type="text" id="usuario" name="usuario" value="<?php echo musa_e($usuario); ?>" autocomplete="username" required autofocus maxlength="60">
  <label for="clave">Contraseña</label>
  <input type="password" id="clave" name="clave" autocomplete="<?php echo $instalar ? 'new-password' : 'current-password'; ?>" required <?php echo $instalar ? 'minlength="10"' : ''; ?>>
  <?php if ($instalar) : ?>
    <small class="tenue">Mínimo 10 caracteres, con letras y números.</small>
    <label for="repetir">Repetir la contraseña</label>
    <input type="password" id="repetir" name="repetir" autocomplete="new-password" required minlength="10">
  <?php endif; ?>
  <input type="hidden" name="token" value="<?php echo musa_e(musa_token()); ?>">
  <button type="submit" class="boton" <?php echo $bloqueo > 0 ? 'disabled' : ''; ?>><?php echo $instalar ? 'Crear cuenta y entrar' : 'Entrar'; ?></button>
</form>
</body>
</html>
