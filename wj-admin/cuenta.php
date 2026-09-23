<?php
/**
 * Musa Café · Usuario y contraseña del panel (.htpasswd)
 */
require_once __DIR__ . '/comun.php';

$resultado = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    musa_exigir_token(isset($_POST['token']) ? $_POST['token'] : '');

    $usuario = preg_replace('/[^A-Za-z0-9._@\-]/', '', musa_texto(isset($_POST['usuario']) ? $_POST['usuario'] : '', 60));
    $actual  = (string) (isset($_POST['actual']) ? $_POST['actual'] : '');
    $nueva   = (string) (isset($_POST['nueva']) ? $_POST['nueva'] : '');
    $repetir = (string) (isset($_POST['repetir']) ? $_POST['repetir'] : '');

    if (!musa_credenciales_validas($usuarioActual, $actual)) {
        $resultado = array(false, 'La contraseña actual no es correcta.');
    } elseif (musa_clave_debil($nueva, $usuario) !== '') {
        $resultado = array(false, musa_clave_debil($nueva, $usuario));
    } elseif ($nueva !== $repetir) {
        $resultado = array(false, 'La confirmación no coincide.');
    } elseif ($usuario === '') {
        $resultado = array(false, 'Escribe un nombre de usuario válido.');
    } elseif (!musa_htpasswd_guardar($usuario, $nueva)) {
        $resultado = array(false, 'No fue posible escribir en wj-content/config/.htpasswd. Revisa los permisos de wj-content.');
    } else {
        musa_log('Credenciales del panel actualizadas', array('usuario' => $usuario));
        // Nueva credencial: esta sesión sigue abierta; las demás sesiones abiertas se cierran solas.
        musa_sesion_admin_abrir($usuario);
        $resultado = array(true, 'Credenciales actualizadas. Las demás sesiones abiertas del panel se cerraron. Si el servidor usa la autenticación de .htaccess, el navegador pedirá los datos nuevos al recargar.');
    }
}

$usuarios = musa_htpasswd_leer();

musa_panel_inicio('Acceso al panel', 'cuenta');
musa_panel_mensaje();
?>

<?php if ($resultado !== null) : ?>
  <div class="alerta <?php echo $resultado[0] ? 'exito' : 'error'; ?>"><?php echo musa_e($resultado[1]); ?></div>
<?php endif; ?>

<section class="bloque-panel">
  <h2>Usuario y contraseña</h2>
  <p class="nota">
    Las credenciales viven en <code>wj-content/config/.htpasswd</code> (cifradas con bcrypt, fuera del
    repositorio y bloqueadas para la web). Es el mismo formato que usa Apache, así que también puedes
    activar la protección de <code>.htaccess</code> que se explica abajo.
  </p>
  <p class="nota">Usuario configurado actualmente: <strong><?php echo musa_e(implode(', ', array_keys($usuarios))); ?></strong></p>

  <form method="post" action="cuenta.php" class="formulario-panel">
    <?php musa_campo_token(); ?>
    <div class="rejilla">
      <label>Usuario<input type="text" name="usuario" value="<?php echo musa_e($usuarioActual); ?>" autocomplete="username" required></label>
      <label>Contraseña actual<input type="password" name="actual" autocomplete="current-password" required></label>
      <label>Nueva contraseña (mínimo 10 caracteres, letras y números)<input type="password" name="nueva" autocomplete="new-password" minlength="10" required></label>
      <label>Repetir la nueva contraseña<input type="password" name="repetir" autocomplete="new-password" minlength="10" required></label>
    </div>
    <div class="acciones-panel"><button type="submit" class="boton">Actualizar credenciales</button></div>
  </form>
</section>

<section class="bloque-panel">
  <h2>Activar la autenticación de Apache (.htaccess)</h2>
  <p class="nota">
    Copia estas cuatro líneas dentro de <code>wj-admin/.htaccess</code> (quitando el <code>#</code> de las que ya están
    allí) para que el navegador pida usuario y contraseña antes de abrir el panel. La ruta ya está calculada
    para este servidor:
  </p>
  <pre class="bloque codigo-copiar">AuthType Basic
AuthName "Panel Musa Cafe"
AuthUserFile <?php echo musa_e(MUSA_HTPASSWD); ?>
Require valid-user</pre>
  <p class="nota">Si al activarlo el sitio muestra un error 500, la ruta no es válida para este servidor: vuelve a comentar las líneas y usa solo el acceso del panel.</p>
</section>

<section class="bloque-panel">
  <h2>Estado de la instalación</h2>
  <table class="tabla compacta">
    <tbody>
      <tr><td>Versión de PHP</td><td><?php echo musa_e(PHP_VERSION); ?></td></tr>
      <tr><td>cURL disponible</td><td><?php echo function_exists('curl_init') ? 'Sí' : 'No (se usa file_get_contents)'; ?></td></tr>
      <tr><td>Función mail()</td><td><?php echo function_exists('mail') ? 'Disponible' : 'No disponible'; ?></td></tr>
      <tr><td>wj-content/datos escribible</td><td><?php echo is_writable(MUSA_DIR_DATOS) ? 'Sí' : 'No'; ?></td></tr>
      <tr><td>wj-content/config escribible</td><td><?php echo is_writable(MUSA_DIR_CONFIG) ? 'Sí' : 'No'; ?></td></tr>
      <tr><td>wj-content/subidas escribible</td><td><?php echo is_writable(MUSA_DIR_SUBIDAS) ? 'Sí' : 'No'; ?></td></tr>
      <tr><td>Autenticación de Apache activa</td><td><?php echo musa_usuario_apache() !== null ? 'Sí' : 'No (se usa el formulario del panel)'; ?></td></tr>
      <tr><td>Archivo de conversaciones</td><td><?php echo musa_e(str_replace(MUSA_RAIZ . '/', '', MUSA_ARCHIVO_CONVERSACIONES)); ?> · <?php echo musa_e(musa_peso(file_exists(MUSA_ARCHIVO_CONVERSACIONES) ? filesize(MUSA_ARCHIVO_CONVERSACIONES) : 0)); ?></td></tr>
      <tr><td>Conexión saliente HTTPS (LiveAvatar)</td><td><?php echo function_exists('curl_init') || ini_get('allow_url_fopen') ? 'Disponible' : 'No disponible: activa cURL o allow_url_fopen'; ?></td></tr>
      <tr><td>Sitio servido por HTTPS</td><td><?php echo (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'Sí' : 'No: el micrófono del navegador solo funciona con HTTPS'; ?></td></tr>
    </tbody>
  </table>
</section>

<?php musa_panel_fin(); ?>
