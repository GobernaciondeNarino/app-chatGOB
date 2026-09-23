<?php
/**
 * QuéDice! · Cierra la conversación
 * POST JSON (también por navigator.sendBeacon): { token, codigo, clave, motivo, mensajes }
 *
 * Guarda los últimos mensajes pendientes, detiene la sesión en LiveAvatar y aplica su
 * transcripción oficial. En el motor económico solo cierra el registro.
 */
require_once __DIR__ . '/comun.php';

$datos = musa_api_preparar();
@ignore_user_abort(true);
@set_time_limit(60);
$c = musa_api_conversacion($datos);

if (in_array($c['estado'], array('finalizada', 'error'), true)) {
    musa_responder_json(array('ok' => true, 'codigo' => $c['codigo'], 'preguntas' => musa_conversacion_preguntas($c)));
}

$ajustes = musa_ajustes();
// En el motor económico el servidor ya guardó todo: lo que envíe el navegador se descarta.
$mensajes = $c['motor'] === 'economico' ? array() : musa_api_mensajes(isset($datos['mensajes']) ? $datos['mensajes'] : array());
if ($mensajes !== array()) {
    musa_conversacion_agregar_mensajes($c['id'], $mensajes, (int) musa_dato($ajustes, 'seguridad.maximo_mensajes', 400));
}

$motivos = array('usuario' => 'USER_CLOSED', 'salida' => 'USER_DISCONNECTED', 'tiempo' => 'MAX_DURATION_REACHED', 'inactividad' => 'IDLE_TIMEOUT', 'error' => 'UNKNOWN', 'servidor' => 'UNKNOWN');
$motivo = isset($datos['motivo']) && is_string($datos['motivo']) && isset($motivos[$datos['motivo']]) ? $datos['motivo'] : 'usuario';

musa_conversacion_finalizar($c['id'], $motivo, $ajustes, $motivos[$motivo]);
$final = musa_conversacion_obtener($c['id']);
musa_responder_json(array('ok' => true, 'codigo' => $c['codigo'], 'preguntas' => musa_conversacion_preguntas($final)));
