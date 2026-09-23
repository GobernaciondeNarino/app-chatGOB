<?php
/**
 * QuéDice! · Acciones sobre las conversaciones
 * Marcar casillas (AJAX), enviar el resumen por correo, notas, cerrar,
 * recuperar la transcripción de LiveAvatar, eliminar y exportar.
 */
require_once __DIR__ . '/comun.php';

$esAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

$esPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$datos = $esPost ? ($esAjax ? musa_cuerpo_json() : $_POST) : $_GET;

$accion = musa_texto(isset($datos['accion']) ? $datos['accion'] : '', 30);

/* La exportación es una descarga por GET (solo lectura); el resto exige token CSRF. */
if ($accion === 'exportar') {
    $filtros = musa_filtros_desde($_GET);
    $modo = isset($_GET['modo']) ? (string) $_GET['modo'] : 'conversaciones';
    $marca = date('Ymd-His');
    if ($modo === 'json') {
        $contenido = musa_conversaciones_json($filtros);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="conversaciones-' . $marca . '.json"');
    } else {
        $modo = $modo === 'preguntas' ? 'preguntas' : 'conversaciones';
        $contenido = musa_conversaciones_csv($filtros, $modo);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $modo . '-' . $marca . '.csv"');
    }
    header('Content-Length: ' . strlen($contenido));
    header('Cache-Control: no-store');
    echo $contenido;
    exit;
}

// Todo lo que cambia datos va solo por POST y con token CSRF.
if (!$esPost) {
    http_response_code(405);
    exit('Método no permitido.');
}
musa_exigir_token(isset($datos['token']) ? $datos['token'] : '', $esAjax);

/** Vuelve a una página conocida del panel (nunca a una dirección tomada del Referer). */
function musa_volver_panel() {
    $paginas = array('index.php', 'avatar.php', 'api.php', 'ajustes.php', 'correo.php', 'cuenta.php');
    $destino = 'index.php';
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $partes = parse_url((string) $_SERVER['HTTP_REFERER']);
        $mismoSitio = isset($partes['host']) && isset($_SERVER['HTTP_HOST']) && strcasecmp($partes['host'], preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST'])) === 0;
        $pagina = isset($partes['path']) ? basename($partes['path']) : '';
        if ($mismoSitio && in_array($pagina, $paginas, true)) {
            $consulta = isset($partes['query']) ? preg_replace('/[^A-Za-z0-9_=&%.\-]/', '', $partes['query']) : '';
            $destino = $pagina . ($consulta !== '' ? '?' . $consulta : '');
        }
    }
    header('Location: ' . $destino);
    exit;
}

/* Archivar conversaciones cerradas antiguas (no depende de una conversación en particular). */
if ($accion === 'archivar') {
    $dias = max(1, min(3650, (int) (isset($datos['dias']) ? $datos['dias'] : 90)));
    $n = musa_conversaciones_archivar($dias);
    musa_log('Conversaciones archivadas', array('dias' => $dias, 'total' => $n, 'usuario' => $usuarioActual));
    musa_panel_mensaje($n < 0 ? 'No fue posible escribir el archivo de respaldo en wj-content/datos.' : ($n === 0 ? 'No hay conversaciones cerradas con más de ' . $dias . ' días.' : $n . ' conversación(es) de más de ' . $dias . ' días se movieron a wj-content/datos/archivo-*.json.php.'), $n < 0 ? 'error' : 'exito');
    musa_volver_panel();
}

$id = musa_texto(isset($datos['id']) ? $datos['id'] : '', 60);
$c = $id !== '' ? musa_conversacion_obtener($id) : null;

if ($c === null) {
    if ($esAjax) { musa_responder_json(array('ok' => false, 'mensaje' => 'La conversación no existe.'), 404); }
    musa_panel_mensaje('La conversación no existe.', 'error');
    header('Location: index.php');
    exit;
}

switch ($accion) {

    case 'marcar':
        $campo = musa_texto(isset($datos['campo']) ? $datos['campo'] : '', 20);
        if (!in_array($campo, array('creado', 'enviado'), true)) {
            musa_responder_json(array('ok' => false, 'mensaje' => 'Campo no permitido.'), 400);
        }
        $valor = !empty($datos['valor']);
        $cambios = array($campo => $valor);
        $cambios[$campo === 'creado' ? 'fecha_creado' : 'fecha_enviado'] = $valor ? date('Y-m-d H:i:s') : '';
        $actualizado = musa_conversacion_actualizar($c['id'], $cambios);
        musa_log('Casilla actualizada', array('codigo' => $c['codigo'], 'campo' => $campo, 'valor' => $valor ? 'SI' : 'NO', 'usuario' => $usuarioActual));
        musa_responder_json(array(
            'ok' => $actualizado !== null,
            'valor' => $valor,
            'mensaje' => ($campo === 'creado' ? 'Creado' : 'Enviado') . ': ' . ($valor ? 'SÍ' : 'NO'),
        ));
        break;

    case 'enviar':
        $envio = musa_correo_enviar($c, $ajustesPanel);
        if ($envio['ok']) {
            musa_conversacion_actualizar($c['id'], array('enviado' => true, 'fecha_enviado' => date('Y-m-d H:i:s')));
        }
        musa_panel_mensaje(
            ($envio['ok'] ? 'Resumen enviado a ' . $c['correo'] . '. ' : 'No fue posible enviar el correo: ') . $envio['mensaje'],
            $envio['ok'] ? 'exito' : 'error'
        );
        break;

    case 'nota':
        musa_conversacion_actualizar($c['id'], array('notas' => musa_texto(isset($datos['notas']) ? $datos['notas'] : '', 2000)));
        musa_panel_mensaje('Nota guardada para ' . $c['codigo'] . '.');
        break;

    case 'cerrar':
        @set_time_limit(60);
        musa_conversacion_finalizar($c['id'], 'panel', $ajustesPanel, 'USER_CLOSED');
        musa_panel_mensaje('Conversación ' . $c['codigo'] . ' cerrada.');
        break;

    case 'transcripcion':
        @set_time_limit(60);
        $oficial = $c['session_id'] !== '' ? musa_heygen_transcripcion($c['session_id'], $ajustesPanel) : null;
        if ($oficial === null || $oficial === array()) {
            musa_panel_mensaje('LiveAvatar no devolvió la transcripción de esta sesión (todavía).', 'error');
            break;
        }
        // La transcripción oficial reemplaza lo que envió el navegador (queda verificada).
        $total = musa_conversacion_aplicar_oficial($c['id'], $oficial);
        musa_panel_mensaje('Transcripción oficial de LiveAvatar aplicada a ' . $c['codigo'] . ': ' . (int) $total . ' mensaje(s) verificados.');
        break;

    case 'eliminar':
        musa_conversacion_eliminar($c['id']);
        musa_log('Conversación eliminada', array('codigo' => $c['codigo'], 'usuario' => $usuarioActual));
        musa_panel_mensaje('Conversación ' . $c['codigo'] . ' eliminada.');
        break;

    default:
        musa_panel_mensaje('Acción no reconocida.', 'error');
}

musa_volver_panel();
