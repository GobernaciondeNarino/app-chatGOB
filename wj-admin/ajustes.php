<?php
/**
 * Musa Café · Apariencia, textos y formulario de inicio
 * Todo lo que se ve en la interfaz pública se configura aquí.
 */
require_once __DIR__ . '/comun.php';

/** Sube una imagen al directorio wj-content/subidas y devuelve su ruta relativa. */
function musa_subir_imagen($campo, $indice = null) {
    if (!isset($_FILES[$campo])) { return ''; }
    $archivo = $_FILES[$campo];
    if ($indice !== null) {
        if (!isset($archivo['name'][$indice])) { return ''; }
        $archivo = array(
            'name'     => $archivo['name'][$indice],
            'type'     => $archivo['type'][$indice],
            'tmp_name' => $archivo['tmp_name'][$indice],
            'error'    => $archivo['error'][$indice],
            'size'     => $archivo['size'][$indice],
        );
    }
    if ((int) $archivo['error'] === UPLOAD_ERR_NO_FILE) { return ''; }
    if ((int) $archivo['error'] !== UPLOAD_ERR_OK) { return ''; }
    if ((int) $archivo['size'] > 5 * 1024 * 1024) { return ''; }

    $informacion = @getimagesize($archivo['tmp_name']);
    if ($informacion === false) { return ''; }
    $permitidos = array(IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp');
    if (!isset($permitidos[$informacion[2]])) { return ''; }

    if (!is_dir(MUSA_DIR_SUBIDAS)) { @mkdir(MUSA_DIR_SUBIDAS, 0775, true); }
    $nombre = musa_slug(pathinfo((string) $archivo['name'], PATHINFO_FILENAME));
    if ($nombre === '') { $nombre = 'imagen'; }
    $nombre = substr($nombre, 0, 40) . '-' . bin2hex(random_bytes(3)) . '.' . $permitidos[$informacion[2]];
    $destino = MUSA_DIR_SUBIDAS . '/' . $nombre;
    if (!@move_uploaded_file($archivo['tmp_name'], $destino)) { return ''; }
    @chmod($destino, 0664);
    return 'wj-content/subidas/' . $nombre;
}

/** Imágenes disponibles para elegir en los selectores. */
function musa_imagenes_disponibles() {
    $lista = array();
    foreach (array('wj-includes/images', 'wj-includes/images/optimizadas', 'wj-includes/images/avatar', 'wj-content/subidas') as $carpeta) {
        $ruta = MUSA_RAIZ . '/' . $carpeta;
        if (!is_dir($ruta)) { continue; }
        foreach ((array) scandir($ruta) as $archivo) {
            if ($archivo === '.' || $archivo === '..') { continue; }
            if (!preg_match('/\.(png|jpe?g|gif|webp)$/i', $archivo)) { continue; }
            $lista[] = $carpeta . '/' . $archivo;
        }
    }
    sort($lista);
    return $lista;
}

$etiquetasColor = array(
    'fondo' => 'Fondo principal', 'fondo_profundo' => 'Fondo profundo', 'tarjeta' => 'Burbuja del avatar',
    'tarjeta_borde' => 'Borde de la burbuja del avatar', 'texto' => 'Texto', 'texto_suave' => 'Texto secundario',
    'acento' => 'Acento (botones y aura)', 'texto_sobre_acento' => 'Texto sobre el acento',
    'acento_secundario' => 'Acento secundario (escuchando)', 'burbuja_persona' => 'Burbuja de la persona',
    'texto_persona' => 'Texto de la burbuja de la persona', 'exito' => 'Éxito', 'error' => 'Error',
);

$etiquetasTexto = array(
    'titulo' => 'Título', 'subtitulo' => 'Subtítulo', 'boton_iniciar' => 'Botón para iniciar',
    'boton_terminar' => 'Botón para terminar', 'boton_interrumpir' => 'Botón para interrumpir',
    'boton_microfono' => 'Botón del micrófono', 'escribir_ayuda' => 'Ayuda de la caja de texto',
    'boton_enviar' => 'Botón para enviar', 'conectando' => 'Mensaje mientras conecta',
    'escuchando' => 'Estado: escuchando', 'hablando' => 'Estado: respondiendo',
    'transcripcion' => 'Título de la transcripción', 'sugerencias' => 'Antes de las preguntas sugeridas',
    'formulario_titulo' => 'Título del formulario de inicio', 'formulario_ayuda' => 'Ayuda del formulario de inicio',
    'despedida_titulo' => 'Título de despedida', 'despedida_texto' => 'Texto de despedida',
    'aviso_datos' => 'Autorización de datos (Ley 1581)', 'aviso_microfono' => 'Aviso si no hay micrófono', 'pie' => 'Pie de página',
);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    musa_exigir_token(isset($_POST['token']) ? $_POST['token'] : '');
    $nuevos = $ajustesPanel;

    foreach (array('nombre', 'eslogan', 'entidad', 'titulo_sitio', 'descripcion', 'sitio_entidad') as $campo) {
        musa_fijar($nuevos, 'marca.' . $campo, musa_texto(isset($_POST['marca'][$campo]) ? $_POST['marca'][$campo] : '', 200));
    }
    $imagenes = array('marca.logo' => 'logo', 'marca.fondo' => 'fondo', 'marca.barra' => 'barra', 'marca.favicon' => 'favicon', 'avatar.retrato' => 'retrato');
    foreach ($imagenes as $ruta => $campo) {
        $subida = musa_subir_imagen('archivo_' . $campo);
        $valor = $subida !== '' ? $subida : musa_texto(isset($_POST['imagen'][$campo]) ? $_POST['imagen'][$campo] : '', 200);
        if ($valor === '' || musa_ruta_imagen_valida($valor)) { musa_fijar($nuevos, $ruta, $valor); }
    }

    foreach (array_keys($etiquetasColor) as $clave) {
        $color = musa_color(isset($_POST['colores'][$clave]) ? $_POST['colores'][$clave] : '', null);
        if ($color !== null) { musa_fijar($nuevos, 'colores.' . $clave, $color); }
    }
    foreach (array_keys($etiquetasTexto) as $clave) {
        if (isset($_POST['textos'][$clave])) { musa_fijar($nuevos, 'textos.' . $clave, musa_texto($_POST['textos'][$clave], 400)); }
    }

    $f = isset($_POST['formulario']) && is_array($_POST['formulario']) ? $_POST['formulario'] : array();
    foreach (array('activo', 'pedir_correo', 'correo_obligatorio', 'pedir_telefono', 'telefono_obligatorio', 'pedir_ciudad', 'ciudad_obligatoria') as $campo) {
        musa_fijar($nuevos, 'formulario.' . $campo, !empty($f[$campo]));
    }
    musa_fijar($nuevos, 'seguridad.exigir_aceptacion', !empty($_POST['seguridad']['exigir_aceptacion']));
    musa_fijar($nuevos, 'seguridad.limite_por_hora', max(0, min(200, (int) ($_POST['seguridad']['limite_por_hora'] ?? 6))));
    musa_fijar($nuevos, 'seguridad.limite_por_dia', max(0, min(2000, (int) ($_POST['seguridad']['limite_por_dia'] ?? 30))));
    musa_fijar($nuevos, 'seguridad.maximo_mensajes', max(20, min(2000, (int) ($_POST['seguridad']['maximo_mensajes'] ?? 400))));

    musa_fijar($nuevos, 'sistema.efectos_3d', !empty($_POST['sistema']['efectos_3d']));
    musa_fijar($nuevos, 'sistema.registros_por_pagina', max(5, min(200, (int) ($_POST['sistema']['registros_por_pagina'] ?? 25))));
    $prefijo = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($_POST['sistema']['prefijo_codigo'] ?? 'CAFE')));
    musa_fijar($nuevos, 'sistema.prefijo_codigo', $prefijo !== '' ? substr($prefijo, 0, 10) : 'CAFE');
    $zona = musa_texto($_POST['sistema']['zona_horaria'] ?? 'America/Bogota', 60);
    if (in_array($zona, timezone_identifiers_list(), true)) { musa_fijar($nuevos, 'sistema.zona_horaria', $zona); }

    musa_guardar_ajustes($nuevos);
    musa_log('Ajustes de apariencia guardados', array('usuario' => $usuarioActual));
    musa_panel_mensaje('Los cambios se guardaron y ya están visibles en la página pública.');
    header('Location: ajustes.php');
    exit;
}

$imagenes = musa_imagenes_disponibles();
$colores = musa_dato($ajustesPanel, 'colores', array());
$f = musa_dato($ajustesPanel, 'formulario', array());

musa_panel_inicio('Apariencia y formulario', 'ajustes');
musa_panel_mensaje();
?>

<form method="post" action="ajustes.php" enctype="multipart/form-data" class="formulario-panel">
<?php musa_campo_token(); ?>

<section class="bloque-panel">
  <h2>Identidad</h2>
  <div class="rejilla">
    <label>Nombre de la marca<input type="text" name="marca[nombre]" value="<?php echo musa_e(musa_dato($ajustesPanel, 'marca.nombre', '')); ?>"></label>
    <label>Eslogan<input type="text" name="marca[eslogan]" value="<?php echo musa_e(musa_dato($ajustesPanel, 'marca.eslogan', '')); ?>"></label>
    <label>Entidad<input type="text" name="marca[entidad]" value="<?php echo musa_e(musa_dato($ajustesPanel, 'marca.entidad', '')); ?>"></label>
    <label>Título del sitio (pestaña)<input type="text" name="marca[titulo_sitio]" value="<?php echo musa_e(musa_dato($ajustesPanel, 'marca.titulo_sitio', '')); ?>"></label>
    <label>Sitio de la entidad<input type="text" name="marca[sitio_entidad]" value="<?php echo musa_e(musa_dato($ajustesPanel, 'marca.sitio_entidad', '')); ?>"></label>
    <label class="ancho-total">Descripción (SEO)<input type="text" name="marca[descripcion]" value="<?php echo musa_e(musa_dato($ajustesPanel, 'marca.descripcion', '')); ?>"></label>
  </div>
</section>

<section class="bloque-panel">
  <h2>Imágenes y logos</h2>
  <div class="rejilla">
    <?php foreach (array(
        'retrato' => array('Imagen del avatar (mientras se conecta)', 'avatar.retrato'),
        'logo'    => array('Logo', 'marca.logo'),
        'fondo'   => array('Rama decorativa (esquina superior)', 'marca.fondo'),
        'barra'   => array('Imagen lateral', 'marca.barra'),
        'favicon' => array('Favicon', 'marca.favicon'),
    ) as $campo => $info) :
        $valor = (string) musa_dato($ajustesPanel, $info[1], ''); ?>
      <div class="campo-imagen">
        <strong><?php echo musa_e($info[0]); ?></strong>
        <?php if ($valor !== '' && musa_ruta_imagen_valida($valor)) : ?>
          <img src="<?php echo musa_e(musa_url($valor)); ?>" alt="" class="vista-previa">
        <?php endif; ?>
        <select name="imagen[<?php echo musa_e($campo); ?>]">
          <option value="">— sin imagen —</option>
          <?php foreach ($imagenes as $imagen) : ?>
            <option value="<?php echo musa_e($imagen); ?>" <?php echo $valor === $imagen ? 'selected' : ''; ?>><?php echo musa_e($imagen); ?></option>
          <?php endforeach; ?>
        </select>
        <input type="file" name="archivo_<?php echo musa_e($campo); ?>" accept="image/png,image/jpeg,image/gif,image/webp">
      </div>
    <?php endforeach; ?>
  </div>
  <p class="nota">Las imágenes que subas se guardan en <code>wj-content/subidas</code>. Tamaño máximo: 5 MB.
    La imagen del avatar se muestra en vertical (3:4) y se recorta al centro.</p>
</section>

<section class="bloque-panel">
  <h2>Colores</h2>
  <div class="rejilla colores">
    <?php foreach ($etiquetasColor as $clave => $etiqueta) :
        $valor = musa_color(musa_dato($colores, $clave, ''), '#000000'); ?>
      <label class="color">
        <span><?php echo musa_e($etiqueta); ?></span>
        <input type="color" name="colores[<?php echo musa_e($clave); ?>]" value="<?php echo musa_e($valor); ?>">
        <code><?php echo musa_e($valor); ?></code>
      </label>
    <?php endforeach; ?>
  </div>
  <p class="nota">Mantén buen contraste: el texto debe leerse con claridad sobre el fondo y sobre las burbujas.</p>
</section>

<section class="bloque-panel">
  <h2>Formulario de inicio</h2>
  <p class="nota">Si está activo, la persona escribe sus datos antes de conversar y así sabes con quién habló el avatar.
    Si lo desactivas, las conversaciones quedan anónimas (solo código, fecha, preguntas y respuestas).</p>
  <div class="rejilla">
    <?php musa_casilla('formulario[activo]', !empty($f['activo']), 'Pedir datos antes de conversar'); ?>
    <?php musa_casilla('seguridad[exigir_aceptacion]', !empty(musa_dato($ajustesPanel, 'seguridad.exigir_aceptacion', true)), 'Exigir autorización de tratamiento de datos'); ?>
    <span class="tenue">El nombre siempre se pide cuando el formulario está activo.</span>
    <?php musa_casilla('formulario[pedir_correo]', !empty($f['pedir_correo']), 'Pedir correo'); ?>
    <?php musa_casilla('formulario[correo_obligatorio]', !empty($f['correo_obligatorio']), 'Correo obligatorio'); ?>
    <span></span>
    <?php musa_casilla('formulario[pedir_telefono]', !empty($f['pedir_telefono']), 'Pedir teléfono'); ?>
    <?php musa_casilla('formulario[telefono_obligatorio]', !empty($f['telefono_obligatorio']), 'Teléfono obligatorio'); ?>
    <span></span>
    <?php musa_casilla('formulario[pedir_ciudad]', !empty($f['pedir_ciudad']), 'Pedir municipio'); ?>
    <?php musa_casilla('formulario[ciudad_obligatoria]', !empty($f['ciudad_obligatoria']), 'Municipio obligatorio'); ?>
  </div>
</section>

<section class="bloque-panel">
  <h2>Textos de la interfaz</h2>
  <div class="rejilla">
    <?php foreach ($etiquetasTexto as $clave => $etiqueta) :
        $valor = (string) musa_dato($ajustesPanel, 'textos.' . $clave, ''); ?>
      <label class="<?php echo strlen($valor) > 60 ? 'ancho-total' : ''; ?>">
        <?php echo musa_e($etiqueta); ?>
        <?php if (strlen($valor) > 60) : ?>
          <textarea name="textos[<?php echo musa_e($clave); ?>]" rows="2"><?php echo musa_e($valor); ?></textarea>
        <?php else : ?>
          <input type="text" name="textos[<?php echo musa_e($clave); ?>]" value="<?php echo musa_e($valor); ?>">
        <?php endif; ?>
      </label>
    <?php endforeach; ?>
  </div>
</section>

<section class="bloque-panel">
  <h2>Sistema y límites</h2>
  <div class="rejilla">
    <?php musa_casilla('sistema[efectos_3d]', !empty(musa_dato($ajustesPanel, 'sistema.efectos_3d', true)), 'Efectos 3D (three.js)'); ?>
    <label>Conversaciones por hora (por IP o correo)<input type="number" name="seguridad[limite_por_hora]" min="0" max="200" value="<?php echo (int) musa_dato($ajustesPanel, 'seguridad.limite_por_hora', 6); ?>"></label>
    <label>Conversaciones por día<input type="number" name="seguridad[limite_por_dia]" min="0" max="2000" value="<?php echo (int) musa_dato($ajustesPanel, 'seguridad.limite_por_dia', 30); ?>"></label>
    <label>Máximo de mensajes guardados por conversación<input type="number" name="seguridad[maximo_mensajes]" min="20" max="2000" value="<?php echo (int) musa_dato($ajustesPanel, 'seguridad.maximo_mensajes', 400); ?>"></label>
    <label>Registros por página<input type="number" name="sistema[registros_por_pagina]" min="5" max="200" value="<?php echo (int) musa_dato($ajustesPanel, 'sistema.registros_por_pagina', 25); ?>"></label>
    <label>Prefijo del código<input type="text" name="sistema[prefijo_codigo]" maxlength="10" value="<?php echo musa_e(musa_dato($ajustesPanel, 'sistema.prefijo_codigo', 'CAFE')); ?>"></label>
    <label>Zona horaria
      <select name="sistema[zona_horaria]">
        <?php foreach (timezone_identifiers_list() as $zona) : ?>
          <option value="<?php echo musa_e($zona); ?>" <?php echo musa_dato($ajustesPanel, 'sistema.zona_horaria', '') === $zona ? 'selected' : ''; ?>><?php echo musa_e($zona); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
  <p class="nota">0 = sin límite. Los límites evitan que alguien gaste los créditos de LiveAvatar abriendo sesiones sin parar.</p>
</section>

<div class="acciones-panel">
  <button type="submit" class="boton">Guardar cambios</button>
</div>
</form>

<?php musa_panel_fin(); ?>
