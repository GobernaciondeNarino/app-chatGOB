<?php
/**
 * Musa Café · Guarda las preguntas y respuestas de la conversación
 * POST JSON: { token, codigo, clave, mensajes: [{ rol: persona|avatar, texto, origen: voz|texto, ref }] }
 */
require_once __DIR__ . '/comun.php';

$datos = musa_api_preparar();
$c = musa_api_conversacion($datos);

if (in_array($c['estado'], array('finalizada', 'error'), true)) {
    musa_responder_json(array('ok' => false, 'mensaje' => 'La conversación ya terminó.'), 409);
}

$mensajes = musa_api_mensajes(isset($datos['mensajes']) ? $datos['mensajes'] : array());
$maximo = (int) musa_dato(musa_ajustes(), 'seguridad.maximo_mensajes', 400);
$total = $mensajes === array() ? count($c['mensajes']) : musa_conversacion_agregar_mensajes($c['id'], $mensajes, max(20, $maximo));

musa_responder_json(array('ok' => true, 'total' => (int) $total));
