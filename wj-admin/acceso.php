<?php
/**
 * QuéDice! · Acceso al panel
 * - Instalación recién descargada (sin cuenta): pide el código de instalación de un solo uso
 *   (wj-content/config/codigo-instalacion.php, solo legible desde el servidor) y crea la cuenta.
 * - Con cuenta: valida contra wj-content/config/.htpasswd (el mismo formato de Apache).
 * - Archivo de credenciales dañado: falla cerrado y explica cómo restaurarlo.
 */
require_once dirname(__DIR__) . '/wj-includes/arranque.php';

musa_cabeceras_seguridad();
musa_sesion();

$estado = musa_credenciales_estado();
if (!empty($_SESSION['musa_admin']) && $estado === 'ok') {
    header('Location: index.php');
    exit;
}

$instalar = $estado === 'sin_cuenta';
$danado = $estado === 'danado';
if ($instalar) { musa_codigo_instalacion(); }   // lo deja escrito en el servidor si aún no existe
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
    } elseif ($danado) {
        $error = 'El archivo de credenciales está dañado. Revisa las instrucciones de abajo.';
    } elseif ($instalar && !musa_codigo_instalacion_valido(isset($_POST['codigo']) ? $_POST['codigo'] : '')) {
        // Un código equivocado cuenta como intento fallido: 8 seguidos bloquean la IP 15 minutos.
        musa_registrar_intento($ip, true);
        musa_log('Código de instalación incorrecto', array('ip' => $ip));
        $error = 'El código de instalación no es correcto. Cópialo de wj-content/config/codigo-instalacion.php.';
        $bloqueo = musa_bloqueo_restante($ip);
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
        } else {
            $creada = musa_htpasswd_crear_primera($limpio, $clave);
            if ($creada === 'ok') {
                musa_registrar_intento($ip, false);
                musa_sesion_admin_abrir($limpio);
                musa_log('Cuenta de administrador creada en la primera configuración', array('usuario' => $limpio, 'ip' => $ip));
                header('Location: api.php');
                exit;
            }
            if ($creada === 'existe') {
                // Otra persona la creó mientras tanto: no se sobrescribe.
                $instalar = false;
                $error = 'La cuenta de administrador ya fue creada. Ingresa con ella.';
            } else {
                $error = 'No fue posible guardar la cuenta. Revisa que wj-content tenga permiso de escritura.';
            }
        }
    } elseif (musa_credenciales_validas($usuario, $clave)) {
        musa_sesion_admin_abrir($usuario);
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

$marca = (string) musa_dato(musa_ajustes(), 'marca.nombre', 'QuéDice!');
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo $instalar ? 'Configuración inicial' : 'Acceso'; ?> · Panel <?php echo musa_e($marca); ?></title>
<link rel="icon" href="<?php echo musa_e(musa_url('wj-includes/images/quedice/icono-quedice.png')); ?>">
<link rel="stylesheet" href="<?php echo musa_e(musa_recurso('wj-includes/css/admin.css')); ?>">
</head>
<body class="pantalla-acceso">
<form class="tarjeta-acceso" method="post" action="acceso.php">
  <div class="acceso-marca">
    <img src="<?php echo musa_e(musa_url('wj-includes/images/quedice/logo-quedice.png')); ?>" alt="">
  </div>
  <?php if ($danado) : ?>
    <h1>Credenciales dañadas</h1>
    <p>El archivo <code>wj-content/config/.htpasswd</code> existe pero está vacío o no se puede leer,
      así que el panel queda cerrado por seguridad. Restáuralo desde una copia de seguridad o, desde el
      Administrador de archivos de Plesk, bórralo para volver a la configuración inicial.</p>
  <?php elseif ($instalar) : ?>
    <h1>Configuración inicial</h1>
    <p>Crea la cuenta de administrador del panel. Para comprobar que tienes acceso al servidor, abre
      en el Administrador de archivos de Plesk el archivo <code>wj-content/config/codigo-instalacion.php</code>
      y copia aquí el código que contiene.</p>
  <?php else : ?>
    <h1>Panel de administración</h1>
    <p><?php echo musa_e($marca); ?> · <?php echo musa_e(musa_dato(musa_ajustes(), 'marca.entidad', 'Gobernación de Nariño')); ?></p>
  <?php endif; ?>
  <?php if ($error !== '') : ?><div class="alerta error" role="alert"><?php echo musa_e($error); ?></div><?php endif; ?>
  <?php if (!$danado) : ?>
  <?php if ($instalar) : ?>
    <label for="codigo">Código de instalación</label>
    <input type="text" id="codigo" name="codigo" autocomplete="off" spellcheck="false" required autofocus placeholder="XXXX-XXXX-XXXX-XXXX">
  <?php endif; ?>
  <label for="usuario">Usuario</label>
  <input type="text" id="usuario" name="usuario" value="<?php echo musa_e($usuario); ?>" autocomplete="username" required <?php echo $instalar ? '' : 'autofocus'; ?> maxlength="60">
  <label for="clave">Contraseña</label>
  <input type="password" id="clave" name="clave" autocomplete="<?php echo $instalar ? 'new-password' : 'current-password'; ?>" required <?php echo $instalar ? 'minlength="10"' : ''; ?>>
  <?php if ($instalar) : ?>
    <small class="tenue">Mínimo 10 caracteres, con letras y números.</small>
    <label for="repetir">Repetir la contraseña</label>
    <input type="password" id="repetir" name="repetir" autocomplete="new-password" required minlength="10">
  <?php endif; ?>
  <input type="hidden" name="token" value="<?php echo musa_e(musa_token()); ?>">
  <button type="submit" class="boton" <?php echo $bloqueo > 0 ? 'disabled' : ''; ?>><?php echo $instalar ? 'Crear cuenta y entrar' : 'Entrar'; ?></button>
  <?php endif; ?>
</form>
</body>
</html>
