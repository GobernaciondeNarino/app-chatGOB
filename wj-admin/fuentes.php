<?php
/**
 * QuéDice! · Fuentes de conocimiento (páginas web y PDF)
 * El motor económico consulta estas fuentes en cada pregunta (búsqueda por palabras clave y, con
 * Gemini, semántica). LiveAvatar recibe las páginas activas como enlaces de su contexto.
 * El procesamiento se hace por pasos cortos que lanza admin.js (acción «paso») mientras haya fuentes
 * pendientes, para que ninguna petición dure demasiado.
 */
require_once __DIR__ . '/comun.php';

/** Bytes de un valor de php.ini como «20M». */
function musa_ini_bytes($valor) {
    $valor = trim((string) $valor);
    if ($valor === '') { return 0; }
    $numero = (float) $valor;
    $unidad = strtolower(substr($valor, -1));
    if ($unidad === 'g') { $numero *= 1073741824; } elseif ($unidad === 'm') { $numero *= 1048576; } elseif ($unidad === 'k') { $numero *= 1024; }
    return (int) $numero;
}

$limiteSubida = MUSA_FUENTES_MAX_PDF;
foreach (array('upload_max_filesize', 'post_max_size') as $directiva) {
    $b = musa_ini_bytes(ini_get($directiva));
    if ($b > 0) { $limiteSubida = min($limiteSubida, $b); }
}

$busqueda = null;
$prueba = null;
$resultados = array();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Un archivo mayor que post_max_size deja $_POST vacío: se explica en vez de mostrar «token inválido».
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        musa_panel_mensaje('El archivo supera el máximo que acepta el servidor (' . musa_peso($limiteSubida) . '). Súbelo más liviano o pide en Plesk que aumenten «post_max_size» y «upload_max_filesize».', 'error');
        header('Location: fuentes.php');
        exit;
    }
    $esPaso = ($_POST['accion'] ?? '') === 'paso';
    musa_exigir_token(isset($_POST['token']) ? $_POST['token'] : '', $esPaso);
    $accion = musa_texto(isset($_POST['accion']) ? $_POST['accion'] : '', 30);
    $id = preg_replace('/[^a-z0-9]/', '', (string) ($_POST['id'] ?? ''));

    if ($accion === 'paso') {
        session_write_close();
        musa_responder_json(musa_fuentes_paso($ajustesPanel));
    }

    if ($accion === 'agregar_pagina') {
        $r = musa_fuentes_agregar_pagina(musa_texto($_POST['url'] ?? '', 600), $_POST['descripcion'] ?? '');
        if (strpos((string) $r, 'f') === 0 && musa_fuentes_obtener($r) !== null) {
            musa_log('Fuente agregada (página)', array('usuario' => $usuarioActual));
            musa_panel_mensaje('Página agregada. Se está leyendo: espera a que diga «Lista».');
        } else {
            musa_panel_mensaje((string) $r, 'error');
        }
        header('Location: fuentes.php');
        exit;
    }

    if ($accion === 'agregar_pdf') {
        $r = musa_fuentes_agregar_pdf($_FILES['pdf'] ?? null, $_POST['descripcion'] ?? '');
        if (strpos((string) $r, 'f') === 0 && musa_fuentes_obtener($r) !== null) {
            musa_log('Fuente agregada (PDF)', array('usuario' => $usuarioActual));
            musa_panel_mensaje('PDF agregado. Se está leyendo: espera a que diga «Lista».');
        } else {
            musa_panel_mensaje((string) $r, 'error');
        }
        header('Location: fuentes.php');
        exit;
    }

    if ($accion === 'releer' && $id !== '') {
        musa_fuentes_releer($id);
        musa_panel_mensaje('La fuente se leerá de nuevo.');
        header('Location: fuentes.php');
        exit;
    }

    if ($accion === 'releer_paginas') {
        $n = 0;
        foreach (musa_fuentes_lista() as $f) {
            if (($f['tipo'] ?? '') === 'pagina' && in_array($f['estado'] ?? '', array('lista', 'error'), true)) { musa_fuentes_releer($f['id']); $n++; }
        }
        musa_panel_mensaje($n > 0 ? 'Se descargarán de nuevo ' . $n . ' páginas.' : 'No hay páginas para actualizar.');
        header('Location: fuentes.php');
        exit;
    }

    if ($accion === 'alternar' && $id !== '') {
        musa_fuentes_alternar($id);
        header('Location: fuentes.php');
        exit;
    }

    if ($accion === 'eliminar' && $id !== '') {
        musa_fuentes_eliminar($id);
        musa_log('Fuente eliminada', array('usuario' => $usuarioActual, 'id' => $id));
        musa_panel_mensaje('Fuente eliminada.');
        header('Location: fuentes.php');
        exit;
    }

    if ($accion === 'ajustes') {
        $nuevos = $ajustesPanel;
        $fu = isset($_POST['fuentes']) && is_array($_POST['fuentes']) ? $_POST['fuentes'] : array();
        musa_fijar($nuevos, 'fuentes.busqueda', ($fu['busqueda'] ?? '') === 'palabras' ? 'palabras' : 'auto');
        musa_fijar($nuevos, 'fuentes.fragmentos', max(1, min(8, (int) ($fu['fragmentos'] ?? 4))));
        $lectura = (string) ($fu['lectura_pdf'] ?? 'auto');
        musa_fijar($nuevos, 'fuentes.lectura_pdf', in_array($lectura, array('auto', 'gemini', 'local'), true) ? $lectura : 'auto');
        $modeloLectura = (string) ($fu['modelo_lectura'] ?? '');
        musa_fijar($nuevos, 'fuentes.modelo_lectura', array_key_exists($modeloLectura, musa_fuentes_modelos_lectura()) ? $modeloLectura : 'gemini-3.1-flash-lite');
        $modeloVectores = (string) ($fu['modelo_vectores'] ?? '');
        musa_fijar($nuevos, 'fuentes.modelo_vectores', array_key_exists($modeloVectores, musa_fuentes_modelos_vectores()) ? $modeloVectores : 'gemini-embedding-2');
        $clave = trim((string) ($fu['gemini_api_key'] ?? ''));
        if (!empty($fu['borrar_clave'])) { musa_fijar($nuevos, 'fuentes.gemini_api_key', ''); }
        elseif ($clave !== '') { musa_fijar($nuevos, 'fuentes.gemini_api_key', substr(preg_replace('/[^\x21-\x7E]/', '', $clave), 0, 300)); }
        musa_guardar_ajustes($nuevos);
        $vigentes = musa_ajustes(true);
        $n = musa_fuentes_revectorizar($vigentes);
        musa_fuentes_corpus_reconstruir();
        musa_log('Ajustes de fuentes guardados', array('usuario' => $usuarioActual));
        musa_panel_mensaje('Configuración guardada.' . ($n > 0 ? ' Se calculará la búsqueda semántica de ' . $n . ' fuentes.' : ''));
        header('Location: fuentes.php');
        exit;
    }

    @set_time_limit(120);
    $pregunta = musa_texto($_POST['pregunta'] ?? '', 300);
    if ($accion === 'buscar' && $pregunta !== '') {
        $busqueda = musa_fuentes_buscar($pregunta, $ajustesPanel);
        $busqueda['pregunta'] = $pregunta;
    } elseif ($accion === 'responder' && $pregunta !== '') {
        $busqueda = musa_fuentes_buscar($pregunta, $ajustesPanel);
        $busqueda['pregunta'] = $pregunta;
        $prueba = musa_ia_configurada($ajustesPanel)
            ? musa_ia_probar($ajustesPanel, $pregunta)
            : array('ok' => false, 'mensaje' => 'Configura la IA de texto en «Motor y APIs» para probar respuestas.', 'detalle' => '');
    } elseif ($accion === 'verificar') {
        $resultados['Fuentes de conocimiento'] = musa_fuentes_verificar($ajustesPanel);
    }
}

$lista = musa_fuentes_lista();
$resumen = musa_fuentes_resumen($lista);
$a = $ajustesPanel;
$claveGemini = musa_fuentes_gemini_clave($a);
$clavePropia = (string) musa_dato($a, 'fuentes.gemini_api_key', '');
$semantica = musa_fuentes_semantica($a);
$verTexto = isset($_GET['ver']) ? preg_replace('/[^a-z0-9]/', '', (string) $_GET['ver']) : '';
$estados = array('lista' => array('Lista', 'estado-finalizada'), 'pendiente' => array('En cola', 'estado-iniciando'), 'leyendo' => array('Leyendo', 'estado-iniciando'),
    'vectorizando' => array('Indexando', 'estado-iniciando'), 'error' => array('Con error', 'estado-error'));

musa_panel_inicio('Fuentes de conocimiento', 'fuentes');
musa_panel_mensaje();
?>

<?php foreach ($resultados as $titulo => $r) : ?>
  <div class="alerta <?php echo !empty($r['ok']) ? 'exito' : 'error'; ?>"><strong><?php echo musa_e($titulo); ?>:</strong> <?php echo musa_e($r['mensaje']); ?></div>
<?php endforeach; ?>

<div id="fuentes-progreso" class="alerta progreso-fuentes" data-pendientes="<?php echo $resumen['pendientes'] > 0 ? '1' : '0'; ?>" data-token="<?php echo musa_e(musa_token()); ?>" <?php echo $resumen['pendientes'] > 0 ? '' : 'hidden'; ?> role="status">
  <span class="giro-panel" aria-hidden="true"></span> <span id="fuentes-progreso-texto">Procesando <?php echo (int) $resumen['pendientes']; ?> fuente(s)… No cierres esta página.</span>
</div>

<section class="bloque-panel">
  <h2>¿Qué hace esto?</h2>
  <p class="nota">Agrega páginas web y documentos PDF con información oficial (por ejemplo, fichas técnicas, informes o
    guías del café). El servidor los lee una vez y los parte en fragmentos. En cada pregunta, el asistente
    recibe solo los fragmentos que tienen que ver con ella y responde con esa información. No se «reentrena» ningún
    modelo: si cambias o quitas una fuente, el cambio vale desde la siguiente pregunta.</p>
  <p class="nota"><strong>Motor económico:</strong> consulta páginas y PDF. <strong>LiveAvatar:</strong> recibe las páginas activas como
    enlaces de su contexto (con la descripción «qué encuentra allí»); los PDF solo los usa el motor económico.</p>
  <div class="tarjetas-resumen">
    <div class="resumen <?php echo $resumen['activas'] > 0 ? 'ok' : ''; ?>">Fuentes en uso<span><?php echo (int) $resumen['activas']; ?></span>de <?php echo (int) $resumen['total']; ?> (máximo <?php echo MUSA_FUENTES_MAX; ?>)</div>
    <div class="resumen">Fragmentos<span><?php echo number_format($resumen['fragmentos'], 0, ',', '.'); ?></span>máximo <?php echo number_format(MUSA_FUENTES_MAX_FRAGMENTOS, 0, ',', '.'); ?></div>
    <div class="resumen <?php echo $semantica ? 'ok' : 'aviso'; ?>">Búsqueda<span><?php echo $semantica ? 'Semántica' : 'Palabras'; ?></span><?php echo $semantica ? 'Gemini + palabras clave' : ($claveGemini === '' ? 'sin clave de Gemini' : 'solo palabras clave'); ?></div>
    <div class="resumen <?php echo $resumen['errores'] > 0 ? 'mal' : ''; ?>">Con error<span><?php echo (int) $resumen['errores']; ?></span>revisa la tabla</div>
  </div>
  <div class="fila-botones">
    <form method="post" action="fuentes.php" class="en-linea"><?php musa_campo_token(); ?><input type="hidden" name="accion" value="verificar"><button type="submit" class="boton-linea pequeno">Verificar</button></form>
    <form method="post" action="fuentes.php" class="en-linea"><?php musa_campo_token(); ?><input type="hidden" name="accion" value="releer_paginas"><button type="submit" class="boton-linea pequeno" title="Descarga otra vez todas las páginas para tomar sus cambios">Actualizar todas las páginas</button></form>
  </div>
</section>

<div class="rejilla">
  <form method="post" action="fuentes.php" class="bloque-panel formulario-panel">
    <?php musa_campo_token(); ?>
    <input type="hidden" name="accion" value="agregar_pagina">
    <h2>Agregar una página web</h2>
    <label>Dirección<input type="url" name="url" required maxlength="600" placeholder="https://"></label>
    <label>¿Qué información encuentra allí? <small class="tenue">(opcional; LiveAvatar la necesita para usar el enlace)</small>
      <input type="text" name="descripcion" maxlength="1000" placeholder="Por ejemplo: precios y variedades del café de Nariño"></label>
    <p class="nota">Solo páginas públicas (http o https). Si la dirección es un PDF, se lee como PDF.</p>
    <div class="acciones-panel"><button type="submit" class="boton">Agregar página</button></div>
  </form>

  <form method="post" action="fuentes.php" class="bloque-panel formulario-panel" enctype="multipart/form-data">
    <?php musa_campo_token(); ?>
    <input type="hidden" name="accion" value="agregar_pdf">
    <h2>Subir un PDF</h2>
    <label>Documento PDF (máximo <?php echo musa_e(musa_peso($limiteSubida)); ?>)<input type="file" name="pdf" accept="application/pdf,.pdf" required></label>
    <label>¿Qué contiene? <small class="tenue">(opcional)</small><input type="text" name="descripcion" maxlength="1000" placeholder="Por ejemplo: guía de buenas prácticas de beneficio del café"></label>
    <p class="nota">Los PDF digitales se leen en el servidor sin costo. Los escaneados (fotos de páginas) se leen con Gemini, si hay clave.</p>
    <div class="acciones-panel"><button type="submit" class="boton">Subir PDF</button></div>
  </form>
</div>

<section class="bloque-panel">
  <h2>Fuentes</h2>
  <?php if ($lista === array()) : ?>
    <p class="tenue">Todavía no hay fuentes. El asistente responde con la información de referencia de <a href="avatar.php">Avatar y tema</a>.</p>
  <?php else : ?>
  <div class="tabla-envoltura"><table class="tabla compacta">
    <thead><tr><th>Fuente</th><th>Tipo</th><th>Estado</th><th>Contenido</th><th>Actualizada</th><th>Acciones</th></tr></thead>
    <tbody>
    <?php foreach ($lista as $f) : $f = array_merge(musa_fuentes_nueva($f['tipo'], array()), $f); $estado = $estados[$f['estado']] ?? array($f['estado'], ''); ?>
      <tr<?php echo empty($f['activa']) ? ' class="fila-inactiva"' : ''; ?>>
        <td>
          <strong><?php echo musa_e(musa_fuentes_titulo($f)); ?></strong>
          <?php if ($f['tipo'] === 'pagina' && musa_url_externa($f['url']) !== '') : ?><br><a href="<?php echo musa_e($f['url']); ?>" target="_blank" rel="noopener noreferrer" class="enlace-fuente"><?php echo musa_e(mb_substr($f['url'], 0, 90, 'UTF-8')); ?></a><?php endif; ?>
          <?php if ($f['descripcion'] !== '' && $f['descripcion'] !== musa_fuentes_titulo($f)) : ?><br><small class="tenue"><?php echo musa_e($f['descripcion']); ?></small><?php endif; ?>
        </td>
        <td><?php echo $f['tipo'] === 'pdf' ? 'PDF' : 'Página'; ?><?php echo $f['metodo'] !== '' ? '<br><small class="tenue">' . musa_e($f['metodo']) . '</small>' : ''; ?></td>
        <td>
          <span class="etiqueta <?php echo musa_e($estado[1]); ?>"><?php echo musa_e(empty($f['activa']) && $f['estado'] === 'lista' ? 'Inactiva' : $estado[0]); ?></span>
          <?php if ($f['progreso'] !== '') : ?><br><small class="tenue"><?php echo musa_e($f['progreso']); ?></small><?php endif; ?>
          <?php if ($f['error'] !== '') : ?><br><small class="texto-mal"><?php echo musa_e($f['error']); ?></small><?php endif; ?>
          <?php if ($f['aviso'] !== '') : ?><br><small class="tenue"><?php echo musa_e($f['aviso']); ?></small><?php endif; ?>
        </td>
        <td>
          <?php if ((int) $f['fragmentos'] > 0) : ?>
            <?php echo $f['paginas'] > 0 ? (int) $f['paginas'] . ((int) $f['paginas'] === 1 ? ' página · ' : ' páginas · ') : ''; ?><?php echo number_format((int) $f['caracteres'], 0, ',', '.'); ?> caracteres<br>
            <small class="tenue"><?php echo (int) $f['fragmentos']; ?> fragmentos<?php echo (int) $f['vectores'] > 0 ? ' · ' . (int) $f['vectores'] . ' con búsqueda semántica' : ''; ?></small>
          <?php else : ?>—<?php endif; ?>
        </td>
        <td><?php echo musa_e($f['actualizada'] !== '' ? $f['actualizada'] : $f['fecha']); ?></td>
        <td class="acciones-fila">
          <?php if ((int) $f['fragmentos'] > 0) : ?><a class="boton-linea pequeno" href="fuentes.php?ver=<?php echo musa_e($f['id']); ?>#texto">Ver texto</a><?php endif; ?>
          <form method="post" action="fuentes.php" class="en-linea"><?php musa_campo_token(); ?><input type="hidden" name="accion" value="releer"><input type="hidden" name="id" value="<?php echo musa_e($f['id']); ?>"><button type="submit" class="boton-linea pequeno"><?php echo $f['tipo'] === 'pagina' ? 'Descargar de nuevo' : 'Leer de nuevo'; ?></button></form>
          <form method="post" action="fuentes.php" class="en-linea"><?php musa_campo_token(); ?><input type="hidden" name="accion" value="alternar"><input type="hidden" name="id" value="<?php echo musa_e($f['id']); ?>"><button type="submit" class="boton-linea pequeno"><?php echo empty($f['activa']) ? 'Activar' : 'Desactivar'; ?></button></form>
          <form method="post" action="fuentes.php" class="en-linea confirmar" data-confirmar="¿Eliminar esta fuente? El asistente dejará de consultarla."><?php musa_campo_token(); ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?php echo musa_e($f['id']); ?>"><button type="submit" class="boton-linea pequeno peligro">Eliminar</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<?php if ($verTexto !== '' && ($fuenteVer = musa_fuentes_obtener($verTexto)) !== null) : $datosVer = musa_leer_json(musa_fuentes_archivo($verTexto, 'datos'), array()); ?>
<section class="bloque-panel" id="texto">
  <h2>Texto leído: <?php echo musa_e(musa_fuentes_titulo($fuenteVer)); ?></h2>
  <p class="nota">Revisa que el texto se haya leído bien: es lo que el asistente puede citar. Se muestran los primeros 30 000 caracteres.</p>
  <pre class="bloque texto-fuente"><?php echo musa_e(mb_substr((string) ($datosVer['texto'] ?? ''), 0, 30000, 'UTF-8')); ?></pre>
  <a class="boton-linea pequeno" href="fuentes.php">Cerrar</a>
</section>
<?php endif; ?>

<section class="bloque-panel" id="probar">
  <h2>Probar la búsqueda</h2>
  <p class="nota">Escribe una pregunta como la haría un visitante para ver qué fragmentos recibe el asistente y, si quieres, su respuesta.</p>
  <form method="post" action="fuentes.php#probar" class="fila-botones">
    <?php musa_campo_token(); ?>
    <input type="text" name="pregunta" maxlength="300" required class="campo-pregunta" placeholder="¿Qué variedades de café se cultivan en Nariño?" value="<?php echo musa_e($busqueda['pregunta'] ?? ''); ?>">
    <button type="submit" name="accion" value="buscar" class="boton-linea pequeno">Buscar fragmentos</button>
    <button type="submit" name="accion" value="responder" class="boton-linea pequeno">Buscar y responder</button>
  </form>
  <?php if ($prueba !== null) : ?>
    <div class="alerta <?php echo !empty($prueba['ok']) ? 'exito' : 'error'; ?>"><strong>Respuesta:</strong> <?php echo musa_e($prueba['mensaje']); ?><?php if (!empty($prueba['texto'])) : ?><br><?php echo musa_e($prueba['texto']); ?><?php endif; ?></div>
  <?php endif; ?>
  <?php if ($busqueda !== null) : ?>
    <?php if ($busqueda['aviso'] !== '') : ?><div class="alerta"><?php echo musa_e($busqueda['aviso']); ?></div><?php endif; ?>
    <?php if ($busqueda['fragmentos'] === array()) : ?>
      <p class="tenue">Ningún fragmento coincide con esa pregunta<?php echo $resumen['fragmentos'] === 0 ? ' (todavía no hay fuentes listas)' : ''; ?>.</p>
    <?php else : ?>
      <p class="nota">Búsqueda: <?php echo musa_e($busqueda['metodo']); ?>. Fragmentos que recibiría el asistente:</p>
      <ol class="fragmentos-encontrados">
      <?php foreach ($busqueda['fragmentos'] as $fr) : ?>
        <li>
          <strong><?php echo musa_e($fr['fuente']); ?></strong>
          <small class="tenue">· puntaje <?php echo musa_e(number_format($fr['puntaje'], 2, ',', '.')); ?><?php echo $fr['coseno'] !== null ? ' · semántica ' . musa_e(number_format($fr['coseno'], 2, ',', '.')) : ''; ?> · palabras <?php echo musa_e(number_format($fr['palabras'], 2, ',', '.')); ?></small>
          <div class="bloque texto-fuente corto"><?php echo musa_e($fr['texto']); ?></div>
        </li>
      <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  <?php endif; ?>
</section>

<form method="post" action="fuentes.php" class="formulario-panel">
<?php musa_campo_token(); ?>
<input type="hidden" name="accion" value="ajustes">
<section class="bloque-panel">
  <h2>Configuración</h2>
  <div class="rejilla">
    <label>Búsqueda
      <select name="fuentes[busqueda]">
        <option value="auto" <?php echo musa_dato($a, 'fuentes.busqueda', 'auto') !== 'palabras' ? 'selected' : ''; ?>>Semántica con Gemini (entiende sinónimos) + palabras clave</option>
        <option value="palabras" <?php echo musa_dato($a, 'fuentes.busqueda', 'auto') === 'palabras' ? 'selected' : ''; ?>>Solo palabras clave (no envía nada a Gemini)</option>
      </select>
      <small class="tenue">La semántica encuentra «¿qué tan alto se siembra?» aunque el documento diga «altitud». Sin clave de Gemini se usa solo la de palabras clave.</small></label>
    <label>Fragmentos por pregunta (1 a 8)<input type="number" name="fuentes[fragmentos]" min="1" max="8" value="<?php echo (int) musa_dato($a, 'fuentes.fragmentos', 4); ?>">
      <small class="tenue">Más fragmentos dan más contexto pero la respuesta tarda y cuesta un poco más.</small></label>
    <label>Lectura de PDF
      <select name="fuentes[lectura_pdf]">
        <option value="auto" <?php echo musa_dato($a, 'fuentes.lectura_pdf', 'auto') === 'auto' ? 'selected' : ''; ?>>Automática: en el servidor y con Gemini si no tiene texto (escaneados)</option>
        <option value="gemini" <?php echo musa_dato($a, 'fuentes.lectura_pdf', 'auto') === 'gemini' ? 'selected' : ''; ?>>Siempre con Gemini (mejor con tablas y diseños complejos)</option>
        <option value="local" <?php echo musa_dato($a, 'fuentes.lectura_pdf', 'auto') === 'local' ? 'selected' : ''; ?>>Solo en el servidor (no envía documentos a Gemini)</option>
      </select></label>
    <label>Modelo para leer PDF
      <select name="fuentes[modelo_lectura]">
        <?php foreach (musa_fuentes_modelos_lectura() as $valor => $nombre) : ?>
          <option value="<?php echo musa_e($valor); ?>" <?php echo musa_fuentes_modelo_lectura($a) === $valor ? 'selected' : ''; ?>><?php echo musa_e($nombre); ?></option>
        <?php endforeach; ?>
      </select></label>
    <label>Modelo de búsqueda semántica
      <select name="fuentes[modelo_vectores]">
        <?php foreach (musa_fuentes_modelos_vectores() as $valor => $nombre) : ?>
          <option value="<?php echo musa_e($valor); ?>" <?php echo musa_fuentes_modelo_vectores($a) === $valor ? 'selected' : ''; ?>><?php echo musa_e($nombre); ?></option>
        <?php endforeach; ?>
      </select>
      <small class="tenue">Si lo cambias, la búsqueda semántica se calcula de nuevo.</small></label>
    <label>Clave de Gemini (déjala vacía para conservar la actual)
      <input type="password" name="fuentes[gemini_api_key]" autocomplete="off" placeholder="<?php echo musa_e($clavePropia !== '' ? musa_enmascarar_clave($clavePropia) : ($claveGemini !== '' ? 'usa la clave de Gemini de Motor y APIs' : 'sin configurar')); ?>">
      <small class="tenue">Vacía = la de la IA de texto (si es Gemini) o la de la voz de Gemini. Se obtiene en <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a>.</small></label>
    <?php musa_casilla('fuentes[borrar_clave]', false, 'Borrar la clave propia de Fuentes'); ?>
  </div>
  <p class="nota"><strong>Costos (septiembre de 2026):</strong> leer un PDF digital y buscar por palabras clave no cuesta nada.
    Gemini Embeddings 2: nivel gratuito; de pago, USD 0,20 por millón de tokens (indexar un documento de 100 páginas ≈ USD 0,01; cada pregunta, una fracción de centavo).
    Leer un PDF escaneado con Gemini 3.1 Flash-Lite: ≈ 258 tokens de entrada por página más el texto transcrito (≈ USD 0,10 por 100 páginas en el nivel de pago).
    Los fragmentos agregan ≈ 1 000 tokens a cada pregunta (≈ USD 0,0003 con Flash-Lite).
    <strong>Privacidad:</strong> en el nivel gratuito de Gemini, Google puede usar lo que se envía para mejorar sus productos; para documentos internos, activa el nivel de pago o usa «Solo palabras clave» y «Solo en el servidor».</p>
</section>
<div class="acciones-panel"><button type="submit" class="boton">Guardar configuración</button></div>
</form>

<?php musa_panel_fin(); ?>
