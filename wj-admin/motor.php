<?php
/**
 * QuéDice! · Motor del avatar y APIs
 * Elige cómo funciona el avatar y configura y verifica cada API por separado:
 *  - Motor económico: IA de texto (Gemini, Hugging Face u otra compatible con OpenAI), voz (ElevenLabs,
 *    Gemini TTS o la del navegador), escucha (navegador o ElevenLabs Scribe) y los videos del avatar.
 *  - LiveAvatar (avatar en vivo de HeyGen): se configura en «HeyGen LiveAvatar».
 */
require_once __DIR__ . '/comun.php';

/** Sube un video (mp4 o webm, máx. 25 MB) a wj-content/subidas y devuelve su ruta relativa o ''. */
function musa_subir_video($campo) {
    if (!isset($_FILES[$campo]) || is_array($_FILES[$campo]['error'])) { return ''; }
    $archivo = $_FILES[$campo];
    if ((int) $archivo['error'] !== UPLOAD_ERR_OK || (int) $archivo['size'] > 25 * 1024 * 1024 || !is_uploaded_file($archivo['tmp_name'])) { return ''; }
    // El formato se comprueba por los primeros bytes, no por el nombre ni por lo que declara el navegador.
    $cabeza = (string) @file_get_contents($archivo['tmp_name'], false, null, 0, 16);
    if (substr($cabeza, 4, 4) === 'ftyp') { $extension = 'mp4'; }
    elseif (strncmp($cabeza, "\x1A\x45\xDF\xA3", 4) === 0) { $extension = 'webm'; }
    else { return ''; }
    if (!is_dir(MUSA_DIR_SUBIDAS)) { @mkdir(MUSA_DIR_SUBIDAS, 0775, true); }
    $nombre = musa_slug(pathinfo((string) $archivo['name'], PATHINFO_FILENAME));
    $nombre = substr($nombre !== '' ? $nombre : 'video', 0, 40) . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
    if (!@move_uploaded_file($archivo['tmp_name'], MUSA_DIR_SUBIDAS . '/' . $nombre)) { return ''; }
    @chmod(MUSA_DIR_SUBIDAS . '/' . $nombre, 0664);
    return 'wj-content/subidas/' . $nombre;
}

/** Guarda una clave: vacía = se conserva la actual; «borrar» = se elimina; si no, solo caracteres imprimibles. */
function musa_panel_clave(&$ajustes, $ruta, $valor, $borrar) {
    $valor = trim((string) $valor);
    if ($borrar) { musa_fijar($ajustes, $ruta, ''); }
    elseif ($valor !== '') { musa_fijar($ajustes, $ruta, substr(preg_replace('/[^\x21-\x7E]/', '', $valor), 0, 300)); }
}

/** Número dentro de un rango. */
function musa_rango($valor, $minimo, $maximo, $porDefecto) {
    return is_numeric($valor) ? max($minimo, min($maximo, (float) $valor)) : $porDefecto;
}

/** Verificación completa del motor elegido: una fila por pieza. */
function musa_motor_verificar_todo($ajustes) {
    $r = array();
    if (musa_motor($ajustes) === 'liveavatar') {
        $r['LiveAvatar · conexión y créditos'] = musa_heygen_verificar($ajustes);
        if ($r['LiveAvatar · conexión y créditos']['ok']) {
            $r['LiveAvatar · avatar'] = musa_heygen_verificar_avatar($ajustes);
            $r['LiveAvatar · voz'] = musa_heygen_verificar_voz($ajustes);
        }
        return $r;
    }
    $r['IA de texto'] = musa_ia_verificar($ajustes);
    $voz = musa_voz_proveedor($ajustes);
    if ($voz === 'elevenlabs') { $r['Voz · ElevenLabs'] = musa_elevenlabs_verificar($ajustes); }
    elseif ($voz === 'gemini') {
        $prueba = musa_voz_probar($ajustes, 'Hola.');
        unset($prueba['audio']);
        $r['Voz · Gemini TTS'] = $prueba;
    } else {
        $r['Voz · navegador'] = array('ok' => true, 'mensaje' => 'Voz del navegador: no usa APIs ni claves. Su calidad depende de las voces en español instaladas en cada equipo.', 'detalle' => '');
    }
    if ((string) musa_dato($ajustes, 'escucha.proveedor', 'navegador') === 'elevenlabs') {
        $r['Escucha · ElevenLabs Scribe'] = musa_elevenlabs_clave($ajustes) === ''
            ? array('ok' => false, 'mensaje' => 'Falta la clave de ElevenLabs para escuchar por el servidor.', 'detalle' => '')
            : musa_elevenlabs_verificar_escucha($ajustes);
    } else {
        $respaldo = musa_elevenlabs_clave($ajustes) !== '' && !empty(musa_dato($ajustes, 'escucha.respaldo', true))
            ? ' En navegadores sin reconocimiento (Firefox) se usa ElevenLabs Scribe como respaldo.'
            : ' Firefox no tiene reconocimiento de voz: allí solo se podrá escribir (o activa el respaldo con ElevenLabs).';
        $r['Escucha · navegador'] = array('ok' => true, 'mensaje' => 'Reconocimiento de voz del navegador (Chrome, Edge y Safari), sin costo.' . $respaldo, 'detalle' => '');
    }
    $faltan = array();
    foreach (array('reposo' => 'en reposo', 'hablando' => 'hablando') as $clave => $nombre) {
        if (!musa_ruta_video_valida(musa_dato($ajustes, 'animacion.' . $clave, ''))) { $faltan[] = $nombre; }
    }
    $r['Videos del avatar'] = $faltan === array()
        ? array('ok' => true, 'mensaje' => 'Los dos videos (en reposo y hablando) están disponibles.', 'detalle' => '')
        : array('ok' => false, 'mensaje' => 'Falta el video ' . implode(' y el ', $faltan) . ': se mostrará el retrato con un movimiento suave.', 'detalle' => '');
    return $r;
}

$resultados = array();
$voces = null;
$audioPrueba = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    musa_exigir_token(isset($_POST['token']) ? $_POST['token'] : '');
    $accion = musa_texto(isset($_POST['accion']) ? $_POST['accion'] : 'guardar', 30);

    if ($accion === 'guardar') {
        $nuevos = $ajustesPanel;
        $m = isset($_POST['motor']) && is_array($_POST['motor']) ? $_POST['motor'] : array();
        musa_fijar($nuevos, 'motor.tipo', ($m['tipo'] ?? '') === 'liveavatar' ? 'liveavatar' : 'economico');

        $ia = isset($_POST['ia']) && is_array($_POST['ia']) ? $_POST['ia'] : array();
        $proveedor = (string) ($ia['proveedor'] ?? 'gemini');
        if (!array_key_exists($proveedor, musa_ia_presets())) { $proveedor = 'gemini'; }
        $base = musa_ia_base_valida(musa_texto($ia['base_url'] ?? '', 300));
        $avisoBase = '';
        if ($base !== '' && strpos($base, 'https://') === 0 && !musa_ia_host_publico($base)) {
            // La clave no debe viajar a servicios internos: solo direcciones públicas (o localhost por http).
            $avisoBase = ' La dirección de la IA no se guardó: debe ser pública (o http://127.0.0.1 para un modelo local).';
            $base = '';
        }
        $baseAnterior = (string) musa_dato($ajustesPanel, 'ia.base_url', '');
        if ($proveedor !== musa_ia_proveedor($ajustesPanel) || ($proveedor === 'personalizado' && $base !== $baseAnterior)) {
            // Cambió el proveedor o la dirección: la clave anterior no se envía a otro servicio.
            musa_fijar($nuevos, 'ia.api_key', '');
        }
        musa_fijar($nuevos, 'ia.proveedor', $proveedor);
        musa_fijar($nuevos, 'ia.base_url', $base);
        $modelo = substr(preg_replace('#[^A-Za-z0-9._:/@\-]#', '', (string) ($ia['modelo'] ?? '')), 0, 120);
        musa_fijar($nuevos, 'ia.modelo', $modelo !== '' ? $modelo : musa_ia_presets()[$proveedor]['modelo']);
        musa_panel_clave($nuevos, 'ia.api_key', $ia['api_key'] ?? '', !empty($ia['borrar_clave']));
        $razonamiento = (string) ($ia['razonamiento'] ?? '');
        musa_fijar($nuevos, 'ia.razonamiento', in_array($razonamiento, array('', 'none', 'minimal', 'low', 'medium'), true) ? $razonamiento : '');
        musa_fijar($nuevos, 'ia.temperatura', musa_rango($ia['temperatura'] ?? 0.5, 0, 1.5, 0.5));
        musa_fijar($nuevos, 'ia.historial', (int) musa_rango($ia['historial'] ?? 6, 0, 12, 6));

        $v = isset($_POST['voz']) && is_array($_POST['voz']) ? $_POST['voz'] : array();
        $vozProveedor = (string) ($v['proveedor'] ?? 'elevenlabs');
        musa_fijar($nuevos, 'voz.proveedor', in_array($vozProveedor, array('elevenlabs', 'gemini', 'navegador'), true) ? $vozProveedor : 'elevenlabs');
        $el = isset($v['elevenlabs']) && is_array($v['elevenlabs']) ? $v['elevenlabs'] : array();
        musa_panel_clave($nuevos, 'voz.elevenlabs.api_key', $el['api_key'] ?? '', !empty($el['borrar_clave']));
        musa_fijar($nuevos, 'voz.elevenlabs.voice_id', substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($el['voice_id'] ?? '')), 0, 40));
        $elModelo = (string) ($el['modelo'] ?? 'eleven_flash_v2_5');
        musa_fijar($nuevos, 'voz.elevenlabs.modelo', array_key_exists($elModelo, musa_elevenlabs_modelos()) ? $elModelo : 'eleven_flash_v2_5');
        musa_fijar($nuevos, 'voz.elevenlabs.velocidad', musa_rango($el['velocidad'] ?? 1, 0.7, 1.2, 1.0));
        musa_fijar($nuevos, 'voz.elevenlabs.estabilidad', musa_rango($el['estabilidad'] ?? 0.5, 0, 1, 0.5));
        musa_fijar($nuevos, 'voz.elevenlabs.similitud', musa_rango($el['similitud'] ?? 0.75, 0, 1, 0.75));
        $ge = isset($v['gemini']) && is_array($v['gemini']) ? $v['gemini'] : array();
        musa_panel_clave($nuevos, 'voz.gemini.api_key', $ge['api_key'] ?? '', !empty($ge['borrar_clave']));
        $geModelo = (string) ($ge['modelo'] ?? '');
        musa_fijar($nuevos, 'voz.gemini.modelo', in_array($geModelo, array('gemini-3.8-flash-tts', 'gemini-3.8-flash-lite-tts'), true) ? $geModelo : 'gemini-3.8-flash-lite-tts');
        $geVoz = (string) ($ge['voz'] ?? 'Orus');
        musa_fijar($nuevos, 'voz.gemini.voz', in_array($geVoz, musa_gemini_voces(), true) ? $geVoz : 'Orus');
        musa_fijar($nuevos, 'voz.gemini.estilo', musa_texto($ge['estilo'] ?? '', 200));
        $idiomas = array('es-CO', 'es-US', 'es-MX', 'es-419', 'es-ES');
        $na = isset($v['navegador']) && is_array($v['navegador']) ? $v['navegador'] : array();
        musa_fijar($nuevos, 'voz.navegador.idioma', in_array($na['idioma'] ?? '', $idiomas, true) ? $na['idioma'] : 'es-CO');
        musa_fijar($nuevos, 'voz.navegador.velocidad', musa_rango($na['velocidad'] ?? 1, 0.5, 1.5, 1.0));

        $es = isset($_POST['escucha']) && is_array($_POST['escucha']) ? $_POST['escucha'] : array();
        musa_fijar($nuevos, 'escucha.proveedor', ($es['proveedor'] ?? '') === 'elevenlabs' ? 'elevenlabs' : 'navegador');
        musa_fijar($nuevos, 'escucha.idioma', in_array($es['idioma'] ?? '', $idiomas, true) ? $es['idioma'] : 'es-CO');
        musa_fijar($nuevos, 'escucha.respaldo', !empty($es['respaldo']));

        foreach (array('reposo', 'hablando') as $clave) {
            $subido = musa_subir_video('video_' . $clave);
            $valor = $subido !== '' ? $subido : musa_texto($_POST['animacion'][$clave] ?? '', 200);
            if ($valor === '' || musa_ruta_video_valida($valor)) { musa_fijar($nuevos, 'animacion.' . $clave, $valor); }
        }

        $se = isset($_POST['seguridad']) && is_array($_POST['seguridad']) ? $_POST['seguridad'] : array();
        musa_fijar($nuevos, 'seguridad.maximo_preguntas', (int) musa_rango($se['maximo_preguntas'] ?? 30, 1, 200, 30));
        musa_fijar($nuevos, 'seguridad.respuestas_por_hora', (int) musa_rango($se['respuestas_por_hora'] ?? 600, 0, 20000, 600));
        musa_fijar($nuevos, 'seguridad.respuestas_por_ip_hora', (int) musa_rango($se['respuestas_por_ip_hora'] ?? 120, 0, 5000, 120));
        musa_fijar($nuevos, 'seguridad.escucha_minutos_hora', (int) musa_rango($se['escucha_minutos_hora'] ?? 30, 0, 600, 30));

        musa_guardar_ajustes($nuevos);
        musa_log('Motor y APIs guardados', array('usuario' => $usuarioActual, 'motor' => musa_dato($nuevos, 'motor.tipo', '')));
        musa_panel_mensaje('Configuración guardada. Pulsa «Verificar todo» para comprobar cada API.' . $avisoBase, $avisoBase === '' ? 'exito' : 'error');
        header('Location: motor.php');
        exit;
    }

    if ($accion === 'usar_voz') {
        $nuevos = $ajustesPanel;
        musa_fijar($nuevos, 'voz.elevenlabs.voice_id', substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['voice_id'] ?? '')), 0, 40));
        musa_guardar_ajustes($nuevos);
        musa_panel_mensaje('Voz de ElevenLabs actualizada. Usa «Probar voz» para escucharla.');
        header('Location: motor.php#voz');
        exit;
    }

    if ($accion === 'cache_vaciar') {
        $n = musa_voz_cache_vaciar();
        musa_log('Caché de respuestas vaciada', array('usuario' => $usuarioActual, 'archivos' => $n));
        musa_panel_mensaje('Se borraron ' . $n . ' respuestas y audios guardados. Se generarán de nuevo al usarse.');
        header('Location: motor.php');
        exit;
    }

    @set_time_limit(120);
    if ($accion === 'verificar_todo') { $resultados = musa_motor_verificar_todo($ajustesPanel); }
    elseif ($accion === 'ia_verificar') { $resultados['IA de texto'] = musa_ia_verificar($ajustesPanel); }
    elseif ($accion === 'ia_probar') { $resultados['Respuesta de prueba'] = musa_ia_probar($ajustesPanel); }
    elseif ($accion === 'eleven_verificar') { $resultados['ElevenLabs'] = musa_elevenlabs_verificar($ajustesPanel); }
    elseif ($accion === 'escucha_verificar') { $resultados['ElevenLabs Scribe'] = musa_elevenlabs_verificar_escucha($ajustesPanel); }
    elseif ($accion === 'eleven_voces') {
        $lista = musa_elevenlabs_voces($ajustesPanel, musa_texto($_POST['buscar'] ?? '', 60));
        if ($lista['ok']) { $voces = $lista['voces']; } else { $resultados['Voces de ElevenLabs'] = array('ok' => false, 'mensaje' => $lista['mensaje'], 'detalle' => ''); }
    } elseif ($accion === 'voz_probar') {
        $prueba = musa_voz_probar($ajustesPanel);
        if (!empty($prueba['audio'])) { $audioPrueba = 'data:' . $prueba['tipo'] . ';base64,' . base64_encode($prueba['audio']); }
        unset($prueba['audio']);
        $resultados['Prueba de voz'] = $prueba;
    }

    if ($resultados !== array()) {
        $todo = true;
        foreach ($resultados as $r) { $todo = $todo && !empty($r['ok']); }
        $vigentes = musa_ajustes(true);
        musa_fijar($vigentes, 'motor.ultima_verificacion', array('accion' => $accion, 'ok' => $todo, 'fecha' => date('Y-m-d H:i:s'), 'usuario' => $usuarioActual));
        musa_guardar_ajustes($vigentes);
        musa_log('Verificación de APIs', array('accion' => $accion, 'ok' => $todo ? 'SI' : 'NO'));
    }
    $ajustesPanel = musa_ajustes(true);
}

$a = $ajustesPanel;
$motor = musa_motor($a);
$presets = musa_ia_presets();
$iaProveedor = musa_ia_proveedor($a);
$vozProveedor = musa_voz_proveedor($a);
$claveIa = (string) musa_dato($a, 'ia.api_key', '');
$claveEleven = musa_elevenlabs_clave($a);
$claveGemini = (string) musa_dato($a, 'voz.gemini.api_key', '');
$ultima = (array) musa_dato($a, 'motor.ultima_verificacion', array());
$uso = musa_uso_ia_resumen();
$videos = musa_videos_disponibles();
$idiomas = array('es-CO' => 'Español de Colombia', 'es-US' => 'Español de Estados Unidos', 'es-MX' => 'Español de México', 'es-419' => 'Español de Latinoamérica', 'es-ES' => 'Español de España');

// Costo estimado de una respuesta de ~60 palabras (≈ 350 caracteres, ≈ 20 s de voz).
$palabras = max(20, (int) musa_dato($a, 'tema.maximo_palabras', 60));
$caracteres = (int) round($palabras * 5.8);
$elModelo = (string) musa_dato($a, 'voz.elevenlabs.modelo', 'eleven_flash_v2_5');
$precioEleven = in_array($elModelo, array('eleven_multilingual_v2', 'eleven_v3'), true) ? 0.10 : 0.05;
$costoVoz = $vozProveedor === 'elevenlabs' ? $caracteres / 1000 * $precioEleven : ($vozProveedor === 'gemini' ? 0.003 : 0);
$costoMes = $uso['mes']['caracteres'] / 1000 * ($vozProveedor === 'elevenlabs' ? $precioEleven : 0);
$dolares = function ($valor) { return 'USD ' . number_format($valor, $valor < 0.1 ? 4 : 2, ',', '.'); };

musa_panel_inicio('Motor del avatar y APIs', 'motor');
musa_panel_mensaje();
?>

<?php foreach ($resultados as $titulo => $r) : ?>
  <div class="alerta <?php echo !empty($r['ok']) ? 'exito' : 'error'; ?>">
    <strong><?php echo musa_e($titulo); ?>:</strong> <?php echo musa_e($r['mensaje']); ?>
    <?php if (!empty($r['detalle'])) : ?><br><small><?php echo musa_e($r['detalle']); ?></small><?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if ($audioPrueba !== null) : ?>
  <div class="bloque-panel"><audio controls autoplay src="<?php echo musa_e($audioPrueba); ?>"></audio></div>
<?php endif; ?>

<section class="bloque-panel">
  <h2>Estado</h2>
  <div class="tarjetas-resumen">
    <div class="resumen <?php echo musa_motor_disponible($a) ? 'ok' : 'mal'; ?>">Motor<span><?php echo $motor === 'liveavatar' ? 'LiveAvatar' : 'Económico'; ?></span><?php echo musa_motor_disponible($a) ? 'listo' : 'falta configurar'; ?></div>
    <div class="resumen">Respuestas hoy<span><?php echo (int) $uso['hoy']['respuestas']; ?></span>mes: <?php echo (int) $uso['mes']['respuestas']; ?></div>
    <div class="resumen">Caracteres de voz (mes)<span><?php echo number_format($uso['mes']['caracteres'], 0, ',', '.'); ?></span><?php echo $vozProveedor === 'elevenlabs' ? '≈ ' . musa_e($dolares($costoMes)) . ' en ElevenLabs' : 'hoy: ' . number_format($uso['hoy']['caracteres'], 0, ',', '.'); ?></div>
    <div class="resumen">Respuestas en caché<span><?php echo musa_voz_cache_total(); ?></span>saludo y sugeridas</div>
  </div>
  <p class="nota">Última verificación: <?php echo $ultima === array() ? '—' : musa_e(($ultima['fecha'] ?? '') . ' · ' . (!empty($ultima['ok']) ? 'correcta' : 'con errores')); ?></p>
  <div class="fila-botones">
    <form method="post" action="motor.php" class="en-linea"><?php musa_campo_token(); ?><input type="hidden" name="accion" value="verificar_todo"><button type="submit" class="boton">Verificar todo</button></form>
    <form method="post" action="motor.php" class="en-linea confirmar" data-confirmar="¿Borrar las respuestas y audios guardados del saludo y de las preguntas sugeridas?"><?php musa_campo_token(); ?><input type="hidden" name="accion" value="cache_vaciar"><button type="submit" class="boton-linea pequeno">Vaciar caché de respuestas</button></form>
  </div>
</section>

<form method="post" action="motor.php" class="formulario-panel" enctype="multipart/form-data">
<?php musa_campo_token(); ?>
<input type="hidden" name="accion" value="guardar">

<section class="bloque-panel">
  <h2>¿Cómo funciona el avatar?</h2>
  <div class="opciones-proveedor">
    <label class="opcion <?php echo $motor === 'economico' ? 'activa' : ''; ?>">
      <input type="radio" name="motor[tipo]" value="economico" <?php echo $motor === 'economico' ? 'checked' : ''; ?>>
      <strong>Económico (recomendado)</strong>
      <span>Videos del avatar en bucle que «hablan» mientras suena la voz, respuestas de una IA de texto y voz de ElevenLabs, Gemini o del navegador.
        ≈ <?php echo musa_e($dolares($costoVoz + 0.0007)); ?> por respuesta con la configuración actual.</span>
    </label>
    <label class="opcion <?php echo $motor === 'liveavatar' ? 'activa' : ''; ?>">
      <input type="radio" name="motor[tipo]" value="liveavatar" <?php echo $motor === 'liveavatar' ? 'checked' : ''; ?>>
      <strong>Avatar en vivo (HeyGen LiveAvatar)</strong>
      <span>Video en tiempo real con los labios sincronizados. 2 créditos por minuto de sesión (≈ USD 0,20-0,25 por minuto, también mientras escucha).
        Se configura en <a href="api.php">HeyGen LiveAvatar</a>.</span>
    </label>
  </div>
  <details>
    <summary>Comparar costos (verificados en septiembre de 2026)</summary>
    <div class="tabla-envoltura"><table class="tabla compacta">
      <thead><tr><th>Opción</th><th>Cómo cobra</th><th>Conversación de 10 preguntas (~10 min)</th></tr></thead>
      <tbody>
        <tr><td>Económico · IA Gemini Flash-Lite + voz del navegador</td><td>Nivel gratuito de Gemini; pago: USD 0,25 / 1,50 por millón de tokens</td><td>≈ USD 0,01 (o gratis en el nivel gratuito)</td></tr>
        <tr><td>Económico · IA + Gemini TTS</td><td>Nivel gratuito; pago ≈ USD 0,003 por respuesta hablada</td><td>≈ USD 0,04</td></tr>
        <tr><td>Económico · IA + ElevenLabs Flash</td><td>USD 0,05 por 1 000 caracteres (Multilingual/v3: 0,10)</td><td>≈ USD 0,20 (Multilingual: ≈ 0,40)</td></tr>
        <tr><td>Escucha con ElevenLabs Scribe</td><td>USD 0,22 por hora de audio</td><td>≈ USD 0,003</td></tr>
        <tr><td>HeyGen LiveAvatar (modo FULL)</td><td>2 créditos por minuto de sesión (USD 0,10-0,13 por crédito)</td><td>≈ USD 2,00-2,50</td></tr>
        <tr><td>D-ID (referencia, no integrado)</td><td>Plan de API mensual: Launch USD 50 = 90 min de video en tiempo real; 0,5 créditos por cada 15 s que habla el avatar</td><td>≈ USD 1,70-2,20 (si habla 3-4 min) + la mensualidad</td></tr>
      </tbody>
    </table></div>
  </details>
</section>

<section class="bloque-panel" id="ia">
  <h2>1 · IA de texto (las respuestas)</h2>
  <p class="nota">Escribe las respuestas con la información de <a href="avatar.php">Avatar y tema</a>. Funciona con cualquier servicio compatible con la API de OpenAI.</p>
  <div class="rejilla">
    <label>Proveedor
      <select name="ia[proveedor]" id="ia-proveedor">
        <?php foreach ($presets as $clave => $p) : ?>
          <option value="<?php echo musa_e($clave); ?>" data-modelo="<?php echo musa_e($p['modelo']); ?>" data-razonamiento="<?php echo musa_e($p['razonamiento']); ?>" <?php echo $iaProveedor === $clave ? 'selected' : ''; ?>><?php echo musa_e($p['nombre']); ?></option>
        <?php endforeach; ?>
      </select>
      <small class="tenue"><?php echo musa_e($presets[$iaProveedor]['nota']); ?></small>
    </label>
    <label>Modelo<input type="text" name="ia[modelo]" id="ia-modelo" maxlength="120" spellcheck="false" value="<?php echo musa_e(musa_ia_modelo($a)); ?>">
      <small class="tenue">Gemini: gemini-3.1-flash-lite (barato y rápido) o gemini-3.5-flash-lite. Hugging Face: «organización/modelo:cheapest».</small></label>
    <label class="campo-personalizado">Dirección base (solo «Otra API»)<input type="text" name="ia[base_url]" maxlength="300" spellcheck="false" placeholder="https://api.ejemplo.com/v1" value="<?php echo musa_e((string) musa_dato($a, 'ia.base_url', '')); ?>">
      <small class="tenue">https:// o, para un modelo en el mismo servidor, http://127.0.0.1.</small></label>
    <label>Clave de API (déjala vacía para conservar la actual)
      <input type="password" name="ia[api_key]" autocomplete="off" placeholder="<?php echo musa_e($claveIa !== '' ? musa_enmascarar_clave($claveIa) : 'sin configurar'); ?>">
      <?php if ($presets[$iaProveedor]['clave_url'] !== '') : ?><small class="tenue">Se obtiene en <a href="<?php echo musa_e($presets[$iaProveedor]['clave_url']); ?>" target="_blank" rel="noopener"><?php echo musa_e(preg_replace('#^https://#', '', $presets[$iaProveedor]['clave_url'])); ?></a></small><?php endif; ?>
    </label>
    <label>Razonamiento
      <select name="ia[razonamiento]" id="ia-razonamiento">
        <?php foreach (array('' => 'No enviar (lo decide el modelo)', 'none' => 'Ninguno (Gemini 2.5)', 'minimal' => 'Mínimo (más rápido)', 'low' => 'Bajo', 'medium' => 'Medio (más lento)') as $valor => $nombre) : ?>
          <option value="<?php echo musa_e($valor); ?>" <?php echo (string) musa_dato($a, 'ia.razonamiento', '') === $valor ? 'selected' : ''; ?>><?php echo musa_e($nombre); ?></option>
        <?php endforeach; ?>
      </select>
      <small class="tenue">Para conversar, mínimo o bajo: la respuesta llega antes.</small></label>
    <label>Creatividad (temperatura 0 a 1,5)<input type="number" name="ia[temperatura]" min="0" max="1.5" step="0.1" value="<?php echo musa_e((string) musa_dato($a, 'ia.temperatura', 0.5)); ?>"></label>
    <label>Turnos anteriores que recuerda<input type="number" name="ia[historial]" min="0" max="12" value="<?php echo (int) musa_dato($a, 'ia.historial', 6); ?>"></label>
    <?php musa_casilla('ia[borrar_clave]', false, 'Borrar la clave guardada'); ?>
  </div>
  <p class="nota">Si cambias de proveedor, la clave anterior se borra al guardar (es de otro servicio).</p>
</section>

<section class="bloque-panel" id="voz">
  <h2>2 · Voz del avatar</h2>
  <div class="opciones-proveedor">
    <?php foreach (array(
        'elevenlabs' => array('ElevenLabs', 'Voces muy naturales en español. Flash v2.5: USD 0,05 por 1 000 caracteres (≈ USD 0,018 por respuesta).'),
        'gemini'     => array('Gemini TTS', 'Voz de Google con nivel gratuito; pago ≈ USD 0,003 por respuesta. Detecta el idioma sola.'),
        'navegador'  => array('Voz del navegador', 'Gratis y sin claves. Suena distinta en cada equipo; en quioscos con Windows o Android suele ser aceptable.'),
    ) as $valor => $info) : ?>
      <label class="opcion <?php echo $vozProveedor === $valor ? 'activa' : ''; ?>">
        <input type="radio" name="voz[proveedor]" value="<?php echo musa_e($valor); ?>" <?php echo $vozProveedor === $valor ? 'checked' : ''; ?>>
        <strong><?php echo musa_e($info[0]); ?></strong><span><?php echo musa_e($info[1]); ?></span>
      </label>
    <?php endforeach; ?>
  </div>
  <p class="nota">Si la voz del servidor falla (sin saldo, sin conexión), el sitio lee la respuesta con la voz del navegador para no dejar al visitante sin respuesta.</p>

  <h3>ElevenLabs</h3>
  <div class="rejilla">
    <label>Clave de API (déjala vacía para conservar la actual)
      <input type="password" name="voz[elevenlabs][api_key]" autocomplete="off" placeholder="<?php echo musa_e($claveEleven !== '' ? musa_enmascarar_clave($claveEleven) : 'sin configurar'); ?>">
      <small class="tenue">En <a href="https://elevenlabs.io/app/settings/api-keys" target="_blank" rel="noopener">elevenlabs.io → API Keys</a>. Permisos: Text to Speech, Speech to Text, Voices (lectura) y User (lectura, para ver el saldo).</small></label>
    <label>ID de la voz<input type="text" name="voz[elevenlabs][voice_id]" maxlength="40" spellcheck="false" value="<?php echo musa_e((string) musa_dato($a, 'voz.elevenlabs.voice_id', '')); ?>" placeholder="<?php echo musa_e(MUSA_ELEVENLABS_VOZ_EJEMPLO); ?> (ejemplo)">
      <small class="tenue">Elige una con «Ver voces de la cuenta» (abajo). Vacío = voz de ejemplo.</small></label>
    <label>Modelo
      <select name="voz[elevenlabs][modelo]">
        <?php foreach (musa_elevenlabs_modelos() as $valor => $nombre) : ?>
          <option value="<?php echo musa_e($valor); ?>" <?php echo $elModelo === $valor ? 'selected' : ''; ?>><?php echo musa_e($nombre); ?></option>
        <?php endforeach; ?>
      </select></label>
    <label>Velocidad (0,7 a 1,2)<input type="number" name="voz[elevenlabs][velocidad]" min="0.7" max="1.2" step="0.05" value="<?php echo musa_e((string) musa_dato($a, 'voz.elevenlabs.velocidad', 1)); ?>"></label>
    <label>Estabilidad (0 a 1)<input type="number" name="voz[elevenlabs][estabilidad]" min="0" max="1" step="0.05" value="<?php echo musa_e((string) musa_dato($a, 'voz.elevenlabs.estabilidad', 0.5)); ?>"></label>
    <label>Parecido a la voz original (0 a 1)<input type="number" name="voz[elevenlabs][similitud]" min="0" max="1" step="0.05" value="<?php echo musa_e((string) musa_dato($a, 'voz.elevenlabs.similitud', 0.75)); ?>"></label>
    <?php musa_casilla('voz[elevenlabs][borrar_clave]', false, 'Borrar la clave de ElevenLabs'); ?>
  </div>

  <h3>Gemini TTS</h3>
  <div class="rejilla">
    <label>Clave de API (vacía = la de la IA si es Gemini)
      <input type="password" name="voz[gemini][api_key]" autocomplete="off" placeholder="<?php echo musa_e($claveGemini !== '' ? musa_enmascarar_clave($claveGemini) : ($iaProveedor === 'gemini' && $claveIa !== '' ? 'usa la clave de la IA' : 'sin configurar')); ?>"></label>
    <label>Modelo
      <select name="voz[gemini][modelo]">
        <?php foreach (array('gemini-3.8-flash-lite-tts' => 'Gemini 3.8 Flash-Lite TTS (más barato)', 'gemini-3.8-flash-tts' => 'Gemini 3.8 Flash TTS') as $valor => $nombre) : ?>
          <option value="<?php echo musa_e($valor); ?>" <?php echo musa_dato($a, 'voz.gemini.modelo', '') === $valor ? 'selected' : ''; ?>><?php echo musa_e($nombre); ?></option>
        <?php endforeach; ?>
      </select></label>
    <label>Voz
      <select name="voz[gemini][voz]">
        <?php foreach (musa_gemini_voces() as $nombre) : ?>
          <option value="<?php echo musa_e($nombre); ?>" <?php echo musa_dato($a, 'voz.gemini.voz', 'Orus') === $nombre ? 'selected' : ''; ?>><?php echo musa_e($nombre); ?></option>
        <?php endforeach; ?>
      </select></label>
    <label>Estilo de lectura<input type="text" name="voz[gemini][estilo]" maxlength="200" value="<?php echo musa_e((string) musa_dato($a, 'voz.gemini.estilo', '')); ?>"></label>
    <?php musa_casilla('voz[gemini][borrar_clave]', false, 'Borrar la clave propia de Gemini TTS'); ?>
  </div>

  <h3>Voz del navegador (también es el respaldo)</h3>
  <div class="rejilla">
    <label>Acento preferido
      <select name="voz[navegador][idioma]">
        <?php foreach ($idiomas as $valor => $nombre) : ?>
          <option value="<?php echo musa_e($valor); ?>" <?php echo musa_dato($a, 'voz.navegador.idioma', 'es-CO') === $valor ? 'selected' : ''; ?>><?php echo musa_e($nombre); ?></option>
        <?php endforeach; ?>
      </select></label>
    <label>Velocidad (0,5 a 1,5)<input type="number" name="voz[navegador][velocidad]" min="0.5" max="1.5" step="0.05" value="<?php echo musa_e((string) musa_dato($a, 'voz.navegador.velocidad', 1)); ?>"></label>
  </div>
</section>

<section class="bloque-panel" id="escucha">
  <h2>3 · Escucha (voz a texto)</h2>
  <div class="opciones-proveedor">
    <label class="opcion <?php echo musa_dato($a, 'escucha.proveedor', 'navegador') !== 'elevenlabs' ? 'activa' : ''; ?>">
      <input type="radio" name="escucha[proveedor]" value="navegador" <?php echo musa_dato($a, 'escucha.proveedor', 'navegador') !== 'elevenlabs' ? 'checked' : ''; ?>>
      <strong>Reconocimiento del navegador</strong><span>Gratis en Chrome, Edge y Safari. Si hay clave de ElevenLabs, Firefox usa Scribe como respaldo.</span>
    </label>
    <label class="opcion <?php echo musa_dato($a, 'escucha.proveedor', '') === 'elevenlabs' ? 'activa' : ''; ?>">
      <input type="radio" name="escucha[proveedor]" value="elevenlabs" <?php echo musa_dato($a, 'escucha.proveedor', '') === 'elevenlabs' ? 'checked' : ''; ?>>
      <strong>ElevenLabs Scribe</strong><span>Igual en todos los navegadores y mejor con ruido. USD 0,22 por hora de audio. Usa la clave de ElevenLabs.</span>
    </label>
  </div>
  <div class="rejilla">
    <label>Idioma que se escucha
      <select name="escucha[idioma]">
        <?php foreach ($idiomas as $valor => $nombre) : ?>
          <option value="<?php echo musa_e($valor); ?>" <?php echo musa_dato($a, 'escucha.idioma', 'es-CO') === $valor ? 'selected' : ''; ?>><?php echo musa_e($nombre); ?></option>
        <?php endforeach; ?>
      </select></label>
  </div>
  <?php musa_casilla('escucha[respaldo]', !empty(musa_dato($a, 'escucha.respaldo', true)), 'Usar ElevenLabs Scribe como respaldo en navegadores sin reconocimiento de voz (Firefox)'); ?>
  <p class="nota">Scribe cobra por duración: el servidor mide cada audio (WebM/Opus, máximo 30 s) y lo descuenta del tope de minutos por hora de abajo.
    La forma de hablar (conversación natural o «mantener presionado») y el micrófono inicial se eligen en <a href="avatar.php">Avatar y tema</a>.</p>
</section>

<section class="bloque-panel" id="videos">
  <h2>4 · Videos del avatar</h2>
  <p class="nota">Dos videos cortos del mismo personaje, en bucle, sin sonido y con el mismo encuadre: uno quieto (reposo) y otro hablando.
    El de «hablando» se muestra solo mientras suena la voz. Los incluidos se generaron a partir del retrato actual. MP4 (H.264) o WebM, máximo 25 MB.</p>
  <div class="rejilla">
    <?php foreach (array('reposo' => 'Video en reposo', 'hablando' => 'Video hablando') as $clave => $titulo) : $actual = (string) musa_dato($a, 'animacion.' . $clave, ''); ?>
      <div class="campo-imagen">
        <strong><?php echo musa_e($titulo); ?></strong>
        <?php if (musa_ruta_video_valida($actual)) : ?><video class="vista-previa-video" muted loop autoplay playsinline><?php foreach (musa_video_fuentes($actual) as $f) : ?><source src="<?php echo musa_e($f['url']); ?>" type="<?php echo musa_e($f['tipo']); ?>"><?php endforeach; ?></video><?php endif; ?>
        <select name="animacion[<?php echo musa_e($clave); ?>]">
          <option value="">— Sin video (retrato) —</option>
          <?php foreach ($videos as $ruta) : ?>
            <option value="<?php echo musa_e($ruta); ?>" <?php echo $actual === $ruta ? 'selected' : ''; ?>><?php echo musa_e($ruta); ?></option>
          <?php endforeach; ?>
        </select>
        <input type="file" name="video_<?php echo musa_e($clave); ?>" accept="video/mp4,video/webm">
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="bloque-panel">
  <h2>Topes de uso</h2>
  <div class="rejilla">
    <label>Preguntas por conversación<input type="number" name="seguridad[maximo_preguntas]" min="1" max="200" value="<?php echo (int) musa_dato($a, 'seguridad.maximo_preguntas', 30); ?>"></label>
    <label>Respuestas por hora (todas las personas, 0 = sin tope)<input type="number" name="seguridad[respuestas_por_hora]" min="0" max="20000" value="<?php echo (int) musa_dato($a, 'seguridad.respuestas_por_hora', 600); ?>">
      <small class="tenue">Limita el gasto si alguien abre muchas conversaciones a la vez. Las preguntas sugeridas en caché no cuentan.</small></label>
    <label>Respuestas por hora desde un mismo origen (IP, 0 = sin tope)<input type="number" name="seguridad[respuestas_por_ip_hora]" min="0" max="5000" value="<?php echo (int) musa_dato($a, 'seguridad.respuestas_por_ip_hora', 120); ?>">
      <small class="tenue">Evita que una sola persona agote el cupo de todas. Súbelo si varios quioscos salen a Internet con la misma IP.</small></label>
    <label>Minutos de audio por hora para ElevenLabs Scribe (0 = sin tope)<input type="number" name="seguridad[escucha_minutos_hora]" min="0" max="600" value="<?php echo (int) musa_dato($a, 'seguridad.escucha_minutos_hora', 30); ?>">
      <small class="tenue">30 minutos ≈ USD 0,11 por hora como máximo.</small></label>
  </div>
</section>

<div class="acciones-panel"><button type="submit" class="boton">Guardar</button></div>
</form>

<section class="bloque-panel">
  <h2>Verificar cada API</h2>
  <p class="nota">Guarda primero los cambios. Las verificaciones no consumen saldo, salvo «Probar respuesta», «Probar voz» y la prueba de Scribe (una fracción de centavo).</p>
  <div class="verificaciones">
    <?php foreach (array(
        array('IA de texto', array('ia_verificar' => 'Verificar clave y modelo', 'ia_probar' => 'Probar respuesta'), musa_ia_configurada($a)),
        array('ElevenLabs', array('eleven_verificar' => 'Verificar saldo y voz', 'eleven_voces' => 'Ver voces de la cuenta', 'escucha_verificar' => 'Probar Scribe (escucha)'), $claveEleven !== ''),
        array('Voz elegida', array('voz_probar' => 'Probar voz'), $vozProveedor !== 'navegador' && musa_voz_configurada($a)),
    ) as $grupo) : ?>
      <div class="verificacion">
        <h3><?php echo musa_e($grupo[0]); ?></h3>
        <div class="fila-botones">
          <?php foreach ($grupo[1] as $accion => $texto) : ?>
            <form method="post" action="motor.php<?php echo $accion === 'eleven_voces' ? '#voces' : ''; ?>" class="en-linea"><?php musa_campo_token(); ?><input type="hidden" name="accion" value="<?php echo musa_e($accion); ?>"><button type="submit" class="boton-linea pequeno" <?php echo $grupo[2] ? '' : 'disabled'; ?>><?php echo musa_e($texto); ?></button></form>
          <?php endforeach; ?>
        </div>
        <?php if (!$grupo[2]) : ?><small class="tenue">Falta configurar la clave.</small><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php if ($voces !== null) : ?>
<section class="bloque-panel" id="voces">
  <h2>Voces de la cuenta de ElevenLabs</h2>
  <form method="post" action="motor.php#voces" class="fila-botones"><?php musa_campo_token(); ?><input type="hidden" name="accion" value="eleven_voces">
    <input type="search" name="buscar" maxlength="60" placeholder="Buscar (por ejemplo: spanish, colombian)"><button type="submit" class="boton-linea pequeno">Buscar</button></form>
  <p class="nota">Para usar voces latinoamericanas de la biblioteca de ElevenLabs, agrégalas primero a «My Voices» en elevenlabs.io; después aparecen aquí.</p>
  <?php if ($voces === array()) : ?>
    <p class="tenue">No se encontraron voces.</p>
  <?php else : ?>
    <div class="tabla-envoltura"><table class="tabla compacta">
      <thead><tr><th>Nombre</th><th>Características</th><th>Muestra</th><th>ID</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($voces as $voz) : ?>
        <tr<?php echo $voz['id'] === musa_elevenlabs_voz($a) ? ' class="fila-actual"' : ''; ?>>
          <td><?php echo musa_e($voz['nombre']); ?><br><small class="tenue"><?php echo musa_e($voz['categoria']); ?></small></td>
          <td><?php echo musa_e($voz['etiquetas']); ?></td>
          <td><?php if ($voz['muestra'] !== '') : ?><audio controls preload="none" src="<?php echo musa_e($voz['muestra']); ?>"></audio><?php endif; ?></td>
          <td><code><?php echo musa_e($voz['id']); ?></code></td>
          <td><form method="post" action="motor.php" class="en-linea"><?php musa_campo_token(); ?><input type="hidden" name="accion" value="usar_voz"><input type="hidden" name="voice_id" value="<?php echo musa_e($voz['id']); ?>"><button type="submit" class="boton-linea pequeno">Usar esta voz</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php musa_panel_fin(); ?>
