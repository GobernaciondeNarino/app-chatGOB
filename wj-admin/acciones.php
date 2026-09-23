<?php
/**
 * QuéDice! · Acciones sobre las conversaciones
 * Marcar casillas (AJAX), enviar el resumen por correo, notas, cerrar,
 * recuperar la transcripción de LiveAvatar, eliminar y exportar.
 */
require_once __DIR__ . '/comun.php';

$esAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

$datos = $esAjax ? musa_cuerpo_json() : $_POST;
if ($datos === array() && $_SERVER['REQUEST_METHOD'] === 'GET') { $datos = $_GET; }

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

musa_exigir_token(isset($datos['token']) ? $datos['token'] : '', $esAjax);

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
        if ($oficial === null) {
            musa_panel_mensaje('LiveAvatar no devolvió la transcripción de esta sesión.', 'error');
            break;
        }
        // Solo se agregan los textos que todavía no están (comparando rol y texto).
        $existentes = array();
        foreach ($c['mensajes'] as $m) { $existentes[$m['rol'] . '|' . mb_strtolower(trim((string) $m['texto']), 'UTF-8')] = true; }
        $nuevos = array();
        foreach ($oficial as $m) {
            if (!isset($existentes[$m['rol'] . '|' . mb_strtolower(trim($m['texto']), 'UTF-8')])) { $nuevos[] = $m; }
        }
        if ($nuevos !== array()) {
            musa_conversacion_agregar_mensajes($c['id'], $nuevos, 2000);
            musa_conversaciones_transaccion(function ($datos) use ($c) {
                foreach ($datos['conversaciones'] as $i => $fila) {
                    if (!musa_conversacion_coincide($fila, $c['id'])) { continue; }
                    usort($fila['mensajes'], function ($a, $b) { return strcmp((string) $a['hora'], (string) $b['hora']); });
                    $datos['conversaciones'][$i] = $fila;
                    return array('datos' => $datos);
                }
                return array();
            });
        }
        musa_panel_mensaje(count($nuevos) . ' mensaje(s) recuperados de LiveAvatar para ' . $c['codigo'] . '.');
        break;

    case 'eliminar':
        musa_conversacion_eliminar($c['id']);
        musa_log('Conversación eliminada', array('codigo' => $c['codigo'], 'usuario' => $usuarioActual));
        musa_panel_mensaje('Conversación ' . $c['codigo'] . ' eliminada.');
        break;

    default:
        musa_panel_mensaje('Acción no reconocida.', 'error');
}

$destino = 'index.php';
if (!empty($_SERVER['HTTP_REFERER'])) {
    $partes = parse_url($_SERVER['HTTP_REFERER']);
    if (isset($partes['path']) && strpos($partes['path'], '/wj-admin/') !== false) {
        $destino = basename($partes['path']) . (isset($partes['query']) ? '?' . $partes['query'] : '');
    }
}
header('Location: ' . $destino);
exit;
