<?php
/**
 * Musa Café · Mantiene viva la sesión del avatar mientras la persona sigue en la página
 * POST JSON: { token, codigo, clave }
 */
require_once __DIR__ . '/comun.php';

$datos = musa_api_preparar();
$c = musa_api_conversacion($datos);

if ($c['session_id'] === '' || $c['estado'] !== 'activa') {
    musa_responder_json(array('ok' => false, 'mensaje' => 'La conversación no está activa.'), 409);
}
$r = musa_heygen_mantener($c['session_id']);
musa_conversacion_actualizar($c['id'], array());
musa_responder_json(array('ok' => (bool) $r['ok']), $r['ok'] ? 200 : 502);
