<?php
/**
 * QuéDice! · Respuesta del avatar (motor económico)
 * POST JSON: { token, codigo, clave, pregunta, origen: voz|texto }
 *
 * 1. Reserva el turno (conversación activa, máximo de preguntas, una pregunta cada 2 s y tope global por hora).
 * 2. Pide la respuesta a la IA de texto con el tema del panel y los últimos turnos de la conversación.
 * 3. Convierte la respuesta en voz (ElevenLabs o Gemini) o deja que la pronuncie el navegador.
 * 4. Guarda la pregunta y la respuesta como mensajes del servidor (verificados).
 * Las respuestas a las preguntas sugeridas se guardan en caché: se contestan sin gastar en las APIs.
 */
require_once __DIR__ . '/comun.php';

$datos = musa_api_preparar();
@set_time_limit(90);
$c = musa_api_conversacion($datos);
$ajustes = musa_ajustes();

if ($c['motor'] !== 'economico') {
    musa_responder_json(array('ok' => false, 'mensaje' => 'Esta conversación usa otro motor.'), 409);
}
if (in_array($c['estado'], array('finalizada', 'error'), true) || musa_conversacion_vencida($c, $ajustes)) {
    musa_responder_json(array('ok' => false, 'mensaje' => 'La conversación ya terminó.'), 409);
}
$pregunta = preg_replace('/\s+/u', ' ', musa_texto(isset($datos['pregunta']) ? $datos['pregunta'] : '', 500));
if (mb_strlen($pregunta, 'UTF-8') < 2) {
    musa_responder_json(array('ok' => false, 'mensaje' => 'No entendí la pregunta. Inténtalo de nuevo.'), 422);
}
$origen = isset($datos['origen']) && $datos['origen'] === 'texto' ? 'texto' : 'voz';

$maximo = max(1, (int) musa_dato($ajustes, 'seguridad.maximo_preguntas', 30));
$turno = musa_conversacion_reservar_pregunta($c['id'], $maximo, 2);
if ($turno === 'maximo') {
    musa_responder_json(array('ok' => false, 'mensaje' => 'Llegaste al máximo de preguntas de esta conversación. ¡Gracias por conversar!', 'fin' => true), 429);
}
if ($turno === 'rapido') {
    musa_responder_json(array('ok' => false, 'mensaje' => 'Espera un momento antes de la siguiente pregunta.'), 429);
}
if ($turno !== 'ok') {
    musa_responder_json(array('ok' => false, 'mensaje' => 'La conversación ya terminó.'), 409);
}

// Preguntas sugeridas: respuesta en caché (depende del tema y del modelo; se invalida si cambian).
$sugerida = musa_pregunta_sugerida($pregunta, $ajustes);
$claveCache = $sugerida ? musa_respuesta_cache_clave($pregunta, $ajustes) : '';
$texto = $claveCache !== '' ? musa_respuesta_cache_leer($claveCache) : '';

if ($texto === '') {
    if (!musa_uso_ia_reservar($ajustes)) {
        musa_log('Respuesta rechazada: se alcanzó el tope global de respuestas por hora');
        musa_responder_json(array('ok' => false, 'mensaje' => 'El anfitrión está atendiendo a muchas personas. Inténtalo de nuevo en unos minutos.'), 503);
    }
    // Los últimos turnos dan contexto («¿y cómo se prepara?»); solo mensajes del servidor o de la persona.
    $turnos = max(0, min(12, (int) musa_dato($ajustes, 'ia.historial', 6)));
    $historial = array();
    foreach ((array) $c['mensajes'] as $m) {
        if (($m['rol'] ?? '') === 'persona' || musa_mensaje_verificado($m)) { $historial[] = $m; }
    }
    $historial = $turnos > 0 ? array_slice($historial, -2 * $turnos) : array();
    $r = musa_ia_responder($pregunta, $historial, $ajustes);
    if (!$r['ok']) {
        musa_responder_json(array('ok' => false, 'mensaje' => 'El anfitrión no pudo responder en este momento. Inténtalo de nuevo.'), 502);
    }
    $texto = $r['texto'];
    if ($claveCache !== '') { musa_respuesta_cache_guardar($claveCache, $texto); }
}

$ahora = date('Y-m-d H:i:s');
$ref = 'srv-' . bin2hex(random_bytes(6));
musa_conversacion_agregar_mensajes($c['id'], array(
    array('rol' => 'persona', 'texto' => $pregunta, 'origen' => $origen, 'fuente' => 'servidor', 'hora' => $ahora, 'ref' => $ref . '-p'),
    array('rol' => 'avatar', 'texto' => $texto, 'origen' => 'voz', 'fuente' => 'servidor', 'hora' => $ahora, 'ref' => $ref . '-a'),
), max(20, (int) musa_dato($ajustes, 'seguridad.maximo_mensajes', 400)));

$respuesta = musa_api_voz($texto, $ajustes, $sugerida);
$respuesta['ok'] = true;
$respuesta['restantes'] = max(0, $maximo - (int) $c['preguntas_ia'] - 1);
musa_responder_json($respuesta);
