<?php
/**
 * Musa Café · Configuración del correo saliente
 */
require_once __DIR__ . '/comun.php';

$prueba = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    musa_exigir_token(isset($_POST['token']) ? $_POST['token'] : '');
    $accion = musa_texto(isset($_POST['accion']) ? $_POST['accion'] : 'guardar', 20);

    if ($accion === 'guardar') {
        $nuevos = $ajustesPanel;
        musa_fijar($nuevos, 'correo.activo', !empty($_POST['correo']['activo']));
        $metodo = musa_texto($_POST['correo']['metodo'] ?? 'mail', 10);
        musa_fijar($nuevos, 'correo.metodo', in_array($metodo, array('mail', 'smtp'), true) ? $metodo : 'mail');

        $remitente = musa_texto($_POST['correo']['remitente'] ?? '', 160);
        if (musa_correo_valido($remitente)) { musa_fijar($nuevos, 'correo.remitente', $remitente); }
        musa_fijar($nuevos, 'correo.nombre_remitente', musa_texto($_POST['correo']['nombre_remitente'] ?? '', 120));

        foreach (array('responder_a', 'copia_oculta') as $campo) {
            $valor = musa_texto($_POST['correo'][$campo] ?? '', 160);
            musa_fijar($nuevos, 'correo.' . $campo, ($valor === '' || musa_correo_valido($valor)) ? $valor : musa_dato($nuevos, 'correo.' . $campo, ''));
        }

        musa_fijar($nuevos, 'correo.asunto', musa_texto($_POST['correo']['asunto'] ?? '', 200));
        musa_fijar($nuevos, 'correo.mensaje', musa_texto($_POST['correo']['mensaje'] ?? '', 4000));
        musa_fijar($nuevos, 'correo.incluir_conversacion', !empty($_POST['correo']['incluir_conversacion']));

        musa_fijar($nuevos, 'correo.smtp.host', musa_texto($_POST['correo']['smtp']['host'] ?? '', 160));
        musa_fijar($nuevos, 'correo.smtp.puerto', max(1, min(65535, (int) ($_POST['correo']['smtp']['puerto'] ?? 587))));
        $seguridad = musa_texto($_POST['correo']['smtp']['seguridad'] ?? 'tls', 10);
        musa_fijar($nuevos, 'correo.smtp.seguridad', in_array($seguridad, array('tls', 'ssl', 'ninguna'), true) ? $seguridad : 'tls');
        musa_fijar($nuevos, 'correo.smtp.usuario', musa_texto($_POST['correo']['smtp']['usuario'] ?? '', 160));
        $claveSmtp = (string) ($_POST['correo']['smtp']['clave'] ?? '');
        if (!empty($_POST['borrar_smtp'])) { musa_fijar($nuevos, 'correo.smtp.clave', ''); }
        elseif (trim($claveSmtp) !== '') { musa_fijar($nuevos, 'correo.smtp.clave', musa_texto($claveSmtp, 200)); }

        musa_guardar_ajustes($nuevos);
        musa_log('Configuración de correo guardada', array('usuario' => $usuarioActual));
        musa_panel_mensaje('Configuración de correo guardada.');
        header('Location: correo.php');
        exit;
    }

    if ($accion === 'probar') {
        $destino = musa_texto($_POST['destino'] ?? '', 160);
        if (!musa_correo_valido($destino)) {
            $prueba = array('ok' => false, 'mensaje' => 'Escribe un correo válido para la prueba.');
        } else {
            $prueba = musa_correo_prueba($destino, $ajustesPanel);
        }
    }
}

musa_panel_inicio('Correo', 'correo');
musa_panel_mensaje();
?>

<?php if ($prueba !== null) : ?>
  <div class="alerta <?php echo $prueba['ok'] ? 'exito' : 'error'; ?>"><?php echo musa_e($prueba['mensaje']); ?></div>
<?php endif; ?>

<section class="bloque-panel">
  <h2>Enviar un correo de prueba</h2>
  <form method="post" action="correo.php" class="fila-botones">
    <?php musa_campo_token(); ?>
    <input type="hidden" name="accion" value="probar">
    <input type="email" name="destino" placeholder="correo@narino.gov.co" required>
    <button type="submit" class="boton-linea">Enviar prueba</button>
  </form>
  <p class="nota">En Plesk la función <code>mail()</code> funciona si el dominio tiene servicio de correo activo. Si no, usa SMTP.</p>
</section>

<form method="post" action="correo.php" class="formulario-panel">
<?php musa_campo_token(); ?>
<input type="hidden" name="accion" value="guardar">

<section class="bloque-panel">
  <h2>Envío</h2>
  <div class="rejilla">
    <label class="interruptor"><input type="checkbox" name="correo[activo]" <?php echo !empty(musa_dato($ajustesPanel, 'correo.activo', true)) ? 'checked' : ''; ?>> Permitir enviar el resumen de la conversación por correo</label>
    <label>Método
      <select name="correo[metodo]">
        <option value="mail" <?php echo musa_dato($ajustesPanel, 'correo.metodo', '') === 'mail' ? 'selected' : ''; ?>>mail() de PHP</option>
        <option value="smtp" <?php echo musa_dato($ajustesPanel, 'correo.metodo', '') === 'smtp' ? 'selected' : ''; ?>>SMTP autenticado</option>
      </select>
    </label>
    <label>Correo remitente<input type="email" name="correo[remitente]" value="<?php echo musa_e(musa_dato($ajustesPanel, 'correo.remitente', '')); ?>"></label>
    <label>Nombre del remitente<input type="text" name="correo[nombre_remitente]" value="<?php echo musa_e(musa_dato($ajustesPanel, 'correo.nombre_remitente', '')); ?>"></label>
    <label>Responder a (opcional)<input type="email" name="correo[responder_a]" value="<?php echo musa_e(musa_dato($ajustesPanel, 'correo.responder_a', '')); ?>"></label>
    <label>Copia oculta (opcional)<input type="email" name="correo[copia_oculta]" value="<?php echo musa_e(musa_dato($ajustesPanel, 'correo.copia_oculta', '')); ?>"></label>
    <label class="interruptor"><input type="checkbox" name="correo[incluir_conversacion]" <?php echo !empty(musa_dato($ajustesPanel, 'correo.incluir_conversacion', true)) ? 'checked' : ''; ?>> Incluir las preguntas y respuestas en el correo</label>
  </div>
</section>

<section class="bloque-panel">
  <h2>Servidor SMTP</h2>
  <div class="rejilla">
    <label>Host<input type="text" name="correo[smtp][host]" value="<?php echo musa_e(musa_dato($ajustesPanel, 'correo.smtp.host', '')); ?>" placeholder="smtp.narino.gov.co"></label>
    <label>Puerto<input type="number" name="correo[smtp][puerto]" min="1" max="65535" value="<?php echo (int) musa_dato($ajustesPanel, 'correo.smtp.puerto', 587); ?>"></label>
    <label>Seguridad
      <select name="correo[smtp][seguridad]">
        <?php foreach (array('tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'ninguna' => 'Sin cifrado (25)') as $valor => $etiqueta) : ?>
          <option value="<?php echo musa_e($valor); ?>" <?php echo musa_dato($ajustesPanel, 'correo.smtp.seguridad', '') === $valor ? 'selected' : ''; ?>><?php echo musa_e($etiqueta); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Usuario<input type="text" name="correo[smtp][usuario]" autocomplete="off" value="<?php echo musa_e(musa_dato($ajustesPanel, 'correo.smtp.usuario', '')); ?>"></label>
    <label>Contraseña (vacía = conservar)<input type="password" name="correo[smtp][clave]" autocomplete="new-password" placeholder="<?php echo musa_e(musa_enmascarar_clave(musa_dato($ajustesPanel, 'correo.smtp.clave', ''))); ?>"></label>
    <label class="interruptor"><input type="checkbox" name="borrar_smtp"> Borrar la contraseña guardada</label>
  </div>
</section>

<section class="bloque-panel">
  <h2>Contenido del mensaje</h2>
  <label class="ancho-total">Asunto<input type="text" name="correo[asunto]" value="<?php echo musa_e(musa_dato($ajustesPanel, 'correo.asunto', '')); ?>"></label>
  <label class="ancho-total">Mensaje
    <textarea name="correo[mensaje]" rows="8"><?php echo musa_e(musa_dato($ajustesPanel, 'correo.mensaje', '')); ?></textarea>
  </label>
  <p class="nota">Etiquetas disponibles: <code>{nombre}</code> <code>{correo}</code> <code>{ciudad}</code> <code>{tema}</code>
    <code>{codigo}</code> <code>{fecha}</code> <code>{preguntas}</code> <code>{avatar}</code> <code>{conversacion}</code> (texto completo).
    El correo se envía desde <a href="index.php">Conversaciones</a> con el botón «Enviar», y la casilla «Enviado» se marca sola.</p>
</section>

<div class="acciones-panel">
  <button type="submit" class="boton">Guardar configuración</button>
</div>
</form>

<?php musa_panel_fin(); ?>
