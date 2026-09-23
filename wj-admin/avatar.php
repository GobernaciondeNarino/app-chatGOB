<?php
/**
 * Musa Café · Avatar y tema de conversación
 * Configura el avatar de HeyGen (ID, voz, idioma, calidad) y de qué habla:
 * tema, personalidad, saludo, conocimiento, reglas y preguntas sugeridas.
 * Al guardar, el contexto se sincroniza con LiveAvatar.
 */
require_once __DIR__ . '/comun.php';

$sincronizacion = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    musa_exigir_token(isset($_POST['token']) ? $_POST['token'] : '');
    $nuevos = $ajustesPanel;
    $a = isset($_POST['avatar']) && is_array($_POST['avatar']) ? $_POST['avatar'] : array();
    $t = isset($_POST['tema']) && is_array($_POST['tema']) ? $_POST['tema'] : array();

    musa_fijar($nuevos, 'avatar.nombre', musa_texto($a['nombre'] ?? '', 60));
    foreach (array('avatar_id', 'voice_id') as $campo) {
        $valor = strtolower(preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($a[$campo] ?? '')));
        musa_fijar($nuevos, 'avatar.' . $campo, substr($valor, 0, 64));
    }
    $idioma = strtolower(musa_texto($a['idioma'] ?? 'es', 8));
    musa_fijar($nuevos, 'avatar.idioma', preg_match('/^[a-z]{2,3}$/', $idioma) ? $idioma : 'es');
    $calidad = (string) ($a['calidad'] ?? 'high');
    musa_fijar($nuevos, 'avatar.calidad', in_array($calidad, array('very_high', 'high', 'medium', 'low'), true) ? $calidad : 'high');
    $interactividad = (string) ($a['interactividad'] ?? 'CONVERSATIONAL');
    musa_fijar($nuevos, 'avatar.interactividad', $interactividad === 'PUSH_TO_TALK' ? 'PUSH_TO_TALK' : 'CONVERSATIONAL');
    musa_fijar($nuevos, 'avatar.duracion_maxima', max(60, min(3600, (int) ($a['duracion_maxima'] ?? 600))));
    musa_fijar($nuevos, 'avatar.sandbox', !empty($a['sandbox']));
    musa_fijar($nuevos, 'avatar.microfono_inicial', !empty($a['microfono_inicial']));
    musa_fijar($nuevos, 'avatar.permitir_escribir', !empty($a['permitir_escribir']));

    musa_fijar($nuevos, 'tema.nombre', musa_texto($t['nombre'] ?? 'Café', 80));
    musa_fijar($nuevos, 'tema.titulo', musa_texto($t['titulo'] ?? '', 64));
    musa_fijar($nuevos, 'tema.personalidad', musa_texto($t['personalidad'] ?? '', 3000));
    musa_fijar($nuevos, 'tema.saludo', musa_texto($t['saludo'] ?? '', 600));
    musa_fijar($nuevos, 'tema.conocimiento', musa_texto($t['conocimiento'] ?? '', 30000));
    musa_fijar($nuevos, 'tema.reglas', musa_texto($t['reglas'] ?? '', 3000));
    musa_fijar($nuevos, 'tema.maximo_palabras', max(20, min(250, (int) ($t['maximo_palabras'] ?? 60))));

    $sugerencias = array();
    foreach ((array) ($t['sugerencias'] ?? array()) as $texto) {
        $texto = musa_texto($texto, 140);
        if ($texto !== '') { $sugerencias[] = $texto; }
    }
    musa_fijar($nuevos, 'tema.sugerencias', array_slice($sugerencias, 0, 8));

    $enlaces = array();
    foreach ((array) ($t['enlaces'] ?? array()) as $enlace) {
        $url = musa_texto($enlace['url'] ?? '', 300);
        $faq = musa_texto($enlace['faq'] ?? '', 1000);
        if ($url !== '' && $faq !== '' && filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url)) {
            $enlaces[] = array('url' => $url, 'faq' => $faq);
        }
    }
    musa_fijar($nuevos, 'tema.enlaces', array_slice($enlaces, 0, 20));

    musa_guardar_ajustes($nuevos);
    musa_log('Avatar y tema guardados', array('usuario' => $usuarioActual));

    if (musa_heygen_configurado($nuevos)) {
        $s = musa_heygen_sincronizar_contexto(musa_ajustes(true));
        musa_panel_mensaje('Cambios guardados. ' . $s['mensaje'], $s['ok'] ? 'exito' : 'error');
    } else {
        musa_panel_mensaje('Cambios guardados. El contexto se sincronizará con LiveAvatar cuando configures la clave de API.');
    }
    header('Location: avatar.php');
    exit;
}

$ajustesPanel = musa_ajustes(true);
$a = musa_dato($ajustesPanel, 'avatar', array());
$t = musa_dato($ajustesPanel, 'tema', array());
$sugerencias = musa_sugerencias($ajustesPanel);
$enlaces = (array) musa_dato($t, 'enlaces', array());
$contextId = (string) musa_dato($ajustesPanel, 'heygen.context_id', '');
$sincronizado = $contextId !== '' && musa_heygen_contexto_huella($ajustesPanel) === (string) musa_dato($ajustesPanel, 'heygen.context_huella', '');

musa_panel_inicio('Avatar y tema', 'avatar');
musa_panel_mensaje();
?>

<form method="post" action="avatar.php" class="formulario-panel">
<?php musa_campo_token(); ?>

<section class="bloque-panel">
  <h2>Avatar de HeyGen (LiveAvatar)</h2>
  <p class="nota">Los ID se copian desde <a href="https://app.liveavatar.com" target="_blank" rel="noopener">app.liveavatar.com</a>.
    Si pegas un ID de 32 caracteres sin guiones, el sistema lo convierte al formato UUID. Revisa que exista en
    <a href="api.php">API HeyGen → Verificar avatar y voz</a>.</p>
  <div class="rejilla">
    <label>Nombre del personaje<input type="text" name="avatar[nombre]" maxlength="60" value="<?php echo musa_e(musa_dato($a, 'nombre', '')); ?>"></label>
    <label>ID del avatar<input type="text" name="avatar[avatar_id]" maxlength="64" value="<?php echo musa_e(musa_dato($a, 'avatar_id', '')); ?>" spellcheck="false">
      <small class="tenue">Se envía como <code><?php echo musa_e(musa_heygen_uuid(musa_dato($a, 'avatar_id', ''))); ?></code></small></label>
    <label>ID de la voz<input type="text" name="avatar[voice_id]" maxlength="64" value="<?php echo musa_e(musa_dato($a, 'voice_id', '')); ?>" spellcheck="false">
      <small class="tenue">Vacío = voz predeterminada del avatar. Se envía como <code><?php echo musa_e(musa_heygen_uuid(musa_dato($a, 'voice_id', ''))); ?></code></small></label>
    <label>Idioma de la conversación
      <select name="avatar[idioma]">
        <?php foreach (array('es' => 'Español', 'en' => 'Inglés', 'pt' => 'Portugués', 'fr' => 'Francés') as $codigo => $nombre) : ?>
          <option value="<?php echo musa_e($codigo); ?>" <?php echo musa_dato($a, 'idioma', 'es') === $codigo ? 'selected' : ''; ?>><?php echo musa_e($nombre); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Calidad de video
      <select name="avatar[calidad]">
        <?php foreach (array('very_high' => 'Muy alta (1080p)', 'high' => 'Alta (720p)', 'medium' => 'Media (480p)', 'low' => 'Baja (360p)') as $valor => $nombre) : ?>
          <option value="<?php echo musa_e($valor); ?>" <?php echo musa_dato($a, 'calidad', 'high') === $valor ? 'selected' : ''; ?>><?php echo musa_e($nombre); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Forma de hablar
      <select name="avatar[interactividad]">
        <option value="CONVERSATIONAL" <?php echo musa_dato($a, 'interactividad', '') !== 'PUSH_TO_TALK' ? 'selected' : ''; ?>>Conversación natural (detecta cuando la persona habla)</option>
        <option value="PUSH_TO_TALK" <?php echo musa_dato($a, 'interactividad', '') === 'PUSH_TO_TALK' ? 'selected' : ''; ?>>Mantener presionado para hablar (lugares ruidosos)</option>
      </select>
    </label>
    <label>Duración máxima de cada conversación (segundos)<input type="number" name="avatar[duracion_maxima]" min="60" max="3600" step="30" value="<?php echo (int) musa_dato($a, 'duracion_maxima', 600); ?>"></label>
    <?php musa_casilla('avatar[microfono_inicial]', !empty($a['microfono_inicial']), 'Encender el micrófono al iniciar'); ?>
    <?php musa_casilla('avatar[permitir_escribir]', !empty($a['permitir_escribir']), 'Permitir escribir preguntas'); ?>
    <?php musa_casilla('avatar[sandbox]', !empty($a['sandbox']), 'Modo sandbox (pruebas sin créditos: avatar genérico «Wayne», sesiones de ~1 minuto)'); ?>
  </div>
  <p class="nota">La imagen que se ve mientras el avatar se conecta se cambia en <a href="ajustes.php">Apariencia → Imágenes</a>.</p>
</section>

<section class="bloque-panel">
  <h2>Tema de la conversación</h2>
  <p class="nota">Esto es lo que el avatar sabe y cómo habla. Se envía a LiveAvatar como «contexto».
    Estado: <strong><?php echo $contextId === '' ? 'sin sincronizar' : ($sincronizado ? 'sincronizado' : 'con cambios pendientes'); ?></strong>
    <?php if ($contextId !== '') : ?> · <code><?php echo musa_e($contextId); ?></code> · <?php echo musa_e(musa_dato($ajustesPanel, 'heygen.context_fecha', '')); ?><?php endif; ?></p>
  <div class="rejilla">
    <label>Tema<input type="text" name="tema[nombre]" maxlength="80" value="<?php echo musa_e(musa_dato($t, 'nombre', '')); ?>"></label>
    <label>Nombre del contexto en LiveAvatar (máx. 64)<input type="text" name="tema[titulo]" maxlength="64" value="<?php echo musa_e(musa_dato($t, 'titulo', '')); ?>"></label>
    <label>Máximo de palabras por respuesta<input type="number" name="tema[maximo_palabras]" min="20" max="250" value="<?php echo (int) musa_dato($t, 'maximo_palabras', 60); ?>"></label>
    <label class="ancho-total">Saludo inicial (lo primero que dice el avatar)
      <textarea name="tema[saludo]" rows="2" maxlength="600"><?php echo musa_e(musa_dato($t, 'saludo', '')); ?></textarea></label>
    <label class="ancho-total">Personalidad
      <textarea name="tema[personalidad]" rows="4"><?php echo musa_e(musa_dato($t, 'personalidad', '')); ?></textarea></label>
    <label class="ancho-total">Información que debe conocer (base de conocimiento)
      <textarea name="tema[conocimiento]" rows="16" class="monoespaciado"><?php echo musa_e(musa_dato($t, 'conocimiento', '')); ?></textarea>
      <small class="tenue">Escribe datos concretos, uno por línea. El avatar los usa como fuente principal para responder.</small></label>
    <label class="ancho-total">Reglas
      <textarea name="tema[reglas]" rows="6"><?php echo musa_e(musa_dato($t, 'reglas', '')); ?></textarea></label>
  </div>
</section>

<section class="bloque-panel">
  <h2>Preguntas sugeridas</h2>
  <p class="nota">Aparecen como botones debajo de la transcripción. Deja una casilla vacía para quitarla (máximo 8).</p>
  <div class="lista-simple" id="lista-sugerencias">
    <?php foreach (array_pad($sugerencias, max(count($sugerencias) + 1, 4), '') as $i => $texto) : ?>
      <input type="text" name="tema[sugerencias][]" maxlength="140" value="<?php echo musa_e($texto); ?>" placeholder="Pregunta sugerida <?php echo $i + 1; ?>">
    <?php endforeach; ?>
  </div>
  <button type="button" class="boton-linea pequeno" id="agregar-sugerencia">Añadir pregunta</button>
</section>

<section class="bloque-panel">
  <h2>Páginas de referencia (opcional)</h2>
  <p class="nota">LiveAvatar puede consultar páginas web para responder. Escribe la dirección y qué información encuentra el avatar en ella.</p>
  <div id="lista-enlaces">
    <?php foreach (array_merge($enlaces, array(array('url' => '', 'faq' => ''))) as $i => $enlace) : ?>
      <div class="enlace-fila">
        <label>Dirección<input type="url" name="tema[enlaces][<?php echo (int) $i; ?>][url]" value="<?php echo musa_e($enlace['url'] ?? ''); ?>" placeholder="https://"></label>
        <label>¿Qué encuentra allí?<input type="text" name="tema[enlaces][<?php echo (int) $i; ?>][faq]" value="<?php echo musa_e($enlace['faq'] ?? ''); ?>" maxlength="1000"></label>
      </div>
    <?php endforeach; ?>
  </div>
  <button type="button" class="boton-linea pequeno" id="agregar-enlace" data-siguiente="<?php echo count($enlaces) + 1; ?>">Añadir página</button>
</section>

<div class="acciones-panel">
  <button type="submit" class="boton">Guardar y sincronizar con LiveAvatar</button>
</div>
</form>

<section class="bloque-panel">
  <h2>Vista previa de la instrucción que recibe el avatar</h2>
  <pre class="bloque codigo-copiar"><?php echo musa_e(musa_heygen_prompt($ajustesPanel)); ?></pre>
</section>

<?php musa_panel_fin(); ?>
