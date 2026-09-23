<?php
/**
 * QuéDice! · Configuración y verificación de la API de HeyGen LiveAvatar
 */
require_once __DIR__ . '/comun.php';

$resultados = array();
$avatares = null;
$voces = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    musa_exigir_token(isset($_POST['token']) ? $_POST['token'] : '');
    $accion = musa_texto(isset($_POST['accion']) ? $_POST['accion'] : 'guardar', 20);

    if ($accion === 'guardar') {
        $nuevos = $ajustesPanel;
        $clave = trim((string) ($_POST['heygen']['api_key'] ?? ''));
        if (!empty($_POST['borrar_clave'])) {
            musa_fijar($nuevos, 'heygen.api_key', '');
        } elseif ($clave !== '') {
            musa_fijar($nuevos, 'heygen.api_key', preg_replace('/[^A-Za-z0-9_\-.:]/', '', $clave));
            // Clave nueva: puede ser otra cuenta, así que el contexto se crea de nuevo.
            musa_fijar($nuevos, 'heygen.context_id', '');
            musa_fijar($nuevos, 'heygen.context_huella', '');
        }
        // Solo api.liveavatar.com (o un subdominio) o, para pruebas, localhost: la clave viaja en cada petición.
        musa_fijar($nuevos, 'heygen.endpoint', musa_heygen_endpoint_valido(musa_texto($_POST['heygen']['endpoint'] ?? '', 200)));
        musa_guardar_ajustes($nuevos);
        musa_log('Configuración de la API de HeyGen guardada', array('usuario' => $usuarioActual));
        musa_panel_mensaje('Configuración de la API guardada. Pulsa «Verificar todo» para comprobarla.');
        header('Location: api.php');
        exit;
    }

    @set_time_limit(120);
    if ($accion === 'verificar') {
        $resultados['Conexión y créditos'] = musa_heygen_verificar($ajustesPanel);
        if ($resultados['Conexión y créditos']['ok']) {
            $resultados['Avatar'] = musa_heygen_verificar_avatar($ajustesPanel);
            $resultados['Voz'] = musa_heygen_verificar_voz($ajustesPanel);
        }
    } elseif ($accion === 'contexto') {
        $s = musa_heygen_sincronizar_contexto($ajustesPanel, true);
        $resultados['Contexto'] = array('ok' => $s['ok'], 'mensaje' => $s['mensaje'], 'detalle' => '');
    } elseif ($accion === 'prueba') {
        $resultados['Prueba de sesión'] = musa_heygen_prueba_sesion($ajustesPanel);
    } elseif ($accion === 'listar') {
        $avatares = musa_heygen_listar_avatares($ajustesPanel);
        $voces = musa_heygen_listar_voces($ajustesPanel);
    }

    if ($resultados !== array()) {
        $todo = true;
        foreach ($resultados as $r) { $todo = $todo && !empty($r['ok']); }
        $vigentes = musa_ajustes(true);
        musa_fijar($vigentes, 'heygen.ultima_verificacion', array(
            'accion'  => $accion,
            'ok'      => $todo,
            'fecha'   => date('Y-m-d H:i:s'),
            'usuario' => $usuarioActual,
        ));
        musa_guardar_ajustes($vigentes);
        musa_log('Verificación de la API de HeyGen', array('accion' => $accion, 'ok' => $todo ? 'SI' : 'NO'));
    }
    $ajustesPanel = musa_ajustes(true);
}

$clave = (string) musa_dato($ajustesPanel, 'heygen.api_key', '');
$ultima = (array) musa_dato($ajustesPanel, 'heygen.ultima_verificacion', array());
$avatarActual = musa_heygen_uuid(musa_dato($ajustesPanel, 'avatar.avatar_id', ''));
$vozActual = musa_heygen_uuid(musa_dato($ajustesPanel, 'avatar.voice_id', ''));

musa_panel_inicio('API de HeyGen LiveAvatar', 'api');
musa_panel_mensaje();
?>

<?php foreach ($resultados as $titulo => $r) : ?>
  <div class="alerta <?php echo !empty($r['ok']) ? 'exito' : 'error'; ?>">
    <strong><?php echo musa_e($titulo); ?>:</strong> <?php echo musa_e($r['mensaje']); ?>
    <?php if (!empty($r['detalle'])) : ?><br><small><?php echo musa_e($r['detalle']); ?></small><?php endif; ?>
  </div>
<?php endforeach; ?>

<section class="bloque-panel">
  <h2>Estado del servicio</h2>
  <p class="nota">
    HeyGen atiende los avatares en tiempo real desde <strong>LiveAvatar</strong> (<code>api.liveavatar.com</code>),
    la plataforma que reemplaza a su antigua «Interactive Avatar». La clave, el avatar y la voz deben ser de LiveAvatar:
    según HeyGen, los avatares creados en HeyGen no son compatibles directamente y se migran con otro ID.
    Pulsa <strong>Verificar todo</strong> para confirmar que el avatar configurado existe en tu cuenta.
  </p>
  <table class="tabla compacta">
    <tbody>
      <tr><td>Clave de API</td><td><?php echo $clave === '' ? '<strong class="texto-mal">sin configurar</strong>' : '<code>' . musa_e(musa_enmascarar_clave($clave)) . '</code>'; ?></td></tr>
      <tr><td>Avatar configurado</td><td><code><?php echo musa_e($avatarActual !== '' ? $avatarActual : '—'); ?></code><?php echo $avatarActual !== '' && !musa_heygen_uuid_valido($avatarActual) ? ' <strong class="texto-mal">formato no válido</strong>' : ''; ?></td></tr>
      <tr><td>Voz configurada</td><td><code><?php echo musa_e($vozActual !== '' ? $vozActual : 'predeterminada del avatar'); ?></code></td></tr>
      <tr><td>Contexto (tema)</td><td><code><?php echo musa_e(musa_dato($ajustesPanel, 'heygen.context_id', '') !== '' ? musa_dato($ajustesPanel, 'heygen.context_id', '') : 'sin crear'); ?></code></td></tr>
      <tr><td>Modo sandbox</td><td><?php echo !empty(musa_dato($ajustesPanel, 'avatar.sandbox', false)) ? 'Activado (sin créditos, avatar de prueba)' : 'Desactivado'; ?></td></tr>
      <tr><td>Última verificación</td><td><?php echo $ultima === array() ? '—' : musa_e(($ultima['fecha'] ?? '') . ' · ' . ($ultima['accion'] ?? '') . ' · ' . (!empty($ultima['ok']) ? 'correcta' : 'con errores')); ?></td></tr>
    </tbody>
  </table>
  <div class="fila-botones">
    <?php foreach (array(
        'verificar' => array('Verificar todo', 'Consulta los créditos y comprueba que el avatar y la voz existan. No consume créditos.', false),
        'contexto'  => array('Sincronizar contexto', 'Crea o actualiza en LiveAvatar el tema, la personalidad y el saludo.', false),
        'prueba'    => array('Prueba de sesión', 'Pide un token de sesión con la configuración completa y lo cierra sin transmitir. No consume créditos.', false),
        'listar'    => array('Ver mis avatares y voces', 'Lista los avatares y voces privadas de la cuenta para copiar sus ID.', false),
    ) as $accion => $info) : ?>
      <form method="post" action="api.php" class="en-linea" title="<?php echo musa_e($info[1]); ?>">
        <?php musa_campo_token(); ?>
        <input type="hidden" name="accion" value="<?php echo musa_e($accion); ?>">
        <button type="submit" class="boton-linea pequeno" <?php echo $clave === '' ? 'disabled' : ''; ?>><?php echo musa_e($info[0]); ?></button>
      </form>
    <?php endforeach; ?>
  </div>
  <p class="nota">Para ver el avatar en vivo, abre el <a href="<?php echo musa_e(musa_url('')); ?>" target="_blank" rel="noopener">sitio público</a> e inicia una conversación (esto sí consume créditos, salvo en modo sandbox).</p>
</section>

<?php if ($avatares !== null || $voces !== null) : ?>
<section class="bloque-panel">
  <h2>Avatares de la cuenta</h2>
  <?php if (empty($avatares)) : ?>
    <p class="tenue">No se encontraron avatares propios (o la clave no tiene permiso para listarlos).</p>
  <?php else : ?>
    <div class="tabla-envoltura"><table class="tabla compacta">
      <thead><tr><th>Vista</th><th>Nombre</th><th>ID</th><th>Estado</th><th>Voz predeterminada</th></tr></thead>
      <tbody>
      <?php foreach ($avatares as $av) : ?>
        <tr<?php echo ($av['id'] ?? '') === $avatarActual ? ' class="fila-actual"' : ''; ?>>
          <td><?php if (!empty($av['preview_url']) && preg_match('#^https://#', $av['preview_url'])) : ?><img src="<?php echo musa_e($av['preview_url']); ?>" alt="" class="miniatura" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?></td>
          <td><?php echo musa_e($av['name'] ?? ''); ?></td>
          <td><code><?php echo musa_e($av['id'] ?? ''); ?></code></td>
          <td><?php echo musa_e($av['status'] ?? ''); ?></td>
          <td><?php echo musa_e(($av['default_voice']['name'] ?? '') . (isset($av['default_voice']['id']) ? ' · ' . $av['default_voice']['id'] : '')); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <h2>Voces privadas de la cuenta</h2>
  <?php if (empty($voces)) : ?>
    <p class="tenue">No se encontraron voces privadas.</p>
  <?php else : ?>
    <div class="tabla-envoltura"><table class="tabla compacta">
      <thead><tr><th>Nombre</th><th>ID</th><th>Idioma</th><th>Género</th></tr></thead>
      <tbody>
      <?php foreach ($voces as $voz) : ?>
        <tr<?php echo ($voz['id'] ?? '') === $vozActual ? ' class="fila-actual"' : ''; ?>>
          <td><?php echo musa_e($voz['name'] ?? ''); ?></td>
          <td><code><?php echo musa_e($voz['id'] ?? ''); ?></code></td>
          <td><?php echo musa_e($voz['language'] ?? ''); ?></td>
          <td><?php echo musa_e($voz['gender'] ?? ''); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <p class="nota">Copia el ID que necesites en <a href="avatar.php">Avatar y tema</a>.</p>
</section>
<?php endif; ?>

<form method="post" action="api.php" class="formulario-panel">
<?php musa_campo_token(); ?>
<input type="hidden" name="accion" value="guardar">
<section class="bloque-panel">
  <h2>Credenciales</h2>
  <div class="rejilla">
    <label class="ancho-total">Clave de API de LiveAvatar (déjala vacía para conservar la actual)
      <input type="password" name="heygen[api_key]" autocomplete="off" placeholder="<?php echo musa_e(musa_enmascarar_clave($clave)); ?>">
    </label>
    <?php musa_casilla('borrar_clave', false, 'Borrar la clave guardada'); ?>
    <label>Endpoint (solo https://api.liveavatar.com o localhost para pruebas)<input type="text" name="heygen[endpoint]" value="<?php echo musa_e(musa_dato($ajustesPanel, 'heygen.endpoint', 'https://api.liveavatar.com')); ?>"></label>
  </div>
  <p class="nota">La clave se obtiene en <a href="https://app.liveavatar.com" target="_blank" rel="noopener">app.liveavatar.com</a> → Developers.
    Se guarda en <code>wj-content/config/ajustes.json.php</code> (fuera del repositorio) y nunca llega al navegador de los visitantes.</p>
</section>
<div class="acciones-panel"><button type="submit" class="boton">Guardar credenciales</button></div>
</form>

<?php musa_panel_fin(); ?>
