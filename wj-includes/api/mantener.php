<?php
/**
 * QuéDice! · Mantiene viva la sesión del avatar mientras la persona sigue en la página
 * POST JSON: { token, codigo, clave }
 *
 * Como máximo un keep-alive cada 25 s por conversación (reservado de forma atómica): una avalancha
 * de peticiones no se traduce en llamadas a LiveAvatar con la clave de la entidad.
 * En el motor económico solo renueva el latido de la conversación (no hay sesión externa).
 */
require_once __DIR__ . '/comun.php';

$datos = musa_api_preparar();
$c = musa_api_conversacion($datos);

$economico = $c['motor'] === 'economico';
if (($c['session_id'] === '' && !$economico) || $c['estado'] !== 'activa') {
    musa_responder_json(array('ok' => false, 'mensaje' => 'La conversación no está activa.'), 409);
}
$ajustes = musa_ajustes();
if (musa_conversacion_vencida($c, $ajustes)) {
    musa_conversacion_finalizar($c['id'], 'tiempo', $ajustes, 'MAX_DURATION_REACHED');
    musa_responder_json(array('ok' => false, 'mensaje' => 'La conversación terminó.'), 409);
}
if (!musa_conversacion_reservar_mantener($c['id'])) {
    musa_responder_json(array('ok' => true, 'omitido' => true), 429);
}
if ($economico) {
    musa_responder_json(array('ok' => true));
}
$r = musa_heygen_mantener($c['session_id'], $ajustes);
musa_responder_json(array('ok' => (bool) $r['ok']), $r['ok'] ? 200 : 502);
