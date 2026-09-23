<?php
/**
 * QuéDice! · Voz a texto con ElevenLabs Scribe (motor económico)
 * POST multipart: token, codigo, clave, audio (WebM/Opus o WAV, máx. 512 KB y 30 s)
 *
 * Se usa cuando el panel elige ElevenLabs para escuchar o, si está permitido como respaldo, cuando el
 * navegador no tiene reconocimiento de voz propio (Firefox). Devuelve { ok, texto }: la pregunta la
 * envía después el navegador a responder.php, así que aquí no se guarda nada en la conversación.
 * Scribe cobra por duración: se calcula la duración real del audio (no la que declara el archivo) y
 * se descuenta de un tope global por hora antes de enviarlo.
 */
require_once dirname(__DIR__) . '/arranque.php';

musa_cabeceras_seguridad();
musa_sesion();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    musa_responder_json(array('ok' => false, 'mensaje' => 'Método no permitido.'), 405);
}
musa_exigir_token(isset($_POST['token']) ? $_POST['token'] : '', true);
session_write_close();
@set_time_limit(60);

$c = musa_conversacion_publica(musa_texto(isset($_POST['codigo']) ? $_POST['codigo'] : '', 40), isset($_POST['clave']) ? (string) $_POST['clave'] : '');
if ($c === null) {
    musa_responder_json(array('ok' => false, 'mensaje' => 'La conversación no existe o la clave no es válida.'), 404);
}
$ajustes = musa_ajustes();
if ($c['motor'] !== 'economico' || in_array($c['estado'], array('finalizada', 'error'), true) || musa_conversacion_vencida($c, $ajustes)) {
    musa_responder_json(array('ok' => false, 'mensaje' => 'La conversación ya terminó.'), 409);
}
$permitida = musa_dato($ajustes, 'escucha.proveedor', 'navegador') === 'elevenlabs' || !empty(musa_dato($ajustes, 'escucha.respaldo', true));
if (musa_elevenlabs_clave($ajustes) === '' || !$permitida) {
    musa_responder_json(array('ok' => false, 'mensaje' => 'La escucha por el servidor no está disponible. Escribe tu pregunta.'), 503);
}

$archivo = isset($_FILES['audio']) && is_array($_FILES['audio']) ? $_FILES['audio'] : null;
if ($archivo === null || is_array($archivo['error']) || (int) $archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
    musa_responder_json(array('ok' => false, 'mensaje' => 'No llegó el audio.'), 400);
}
$tamano = (int) $archivo['size'];
if ($tamano < 1000 || $tamano > 512 * 1024) {
    musa_responder_json(array('ok' => false, 'mensaje' => 'El audio es demasiado corto o demasiado largo.'), 413);
}
$audio = (string) file_get_contents($archivo['tmp_name']);

// El tipo se deduce de los primeros bytes (el que declara el navegador no es confiable) y la duración,
// del contenido que decodificará Scribe.
$cabeza = substr($audio, 0, 16);
if (strncmp($cabeza, "\x1A\x45\xDF\xA3", 4) === 0) { $tipo = 'audio/webm'; }
elseif (strncmp($cabeza, 'RIFF', 4) === 0 && substr($cabeza, 8, 4) === 'WAVE') { $tipo = 'audio/wav'; }
else { musa_responder_json(array('ok' => false, 'mensaje' => 'Formato de audio no admitido.'), 415); }
$segundos = musa_audio_segundos($audio, $tipo);
if ($segundos === null) {
    musa_responder_json(array('ok' => false, 'mensaje' => 'Formato de audio no admitido.'), 415);
}
if ($segundos > 30) {
    musa_responder_json(array('ok' => false, 'mensaje' => 'La pregunta es demasiado larga. Hazla en menos de 30 segundos.'), 413);
}

// Topes: tantos audios como preguntas permitidas, uno por segundo, y minutos de audio por hora en total.
$maximo = max(1, (int) musa_dato($ajustes, 'seguridad.maximo_preguntas', 30));
$turno = musa_conversacion_reservar_pregunta($c['id'], $maximo, 1, 'transcripciones');
if ($turno !== 'ok') {
    musa_responder_json(array('ok' => false, 'mensaje' => $turno === 'cerrada' ? 'La conversación ya terminó.' : 'Espera un momento antes de volver a hablar.'), $turno === 'cerrada' ? 409 : 429);
}
if (!musa_uso_escucha_reservar($ajustes, max(1, $segundos))) {
    musa_log('Audio rechazado: se alcanzó el tope de minutos de escucha por hora');
    musa_responder_json(array('ok' => false, 'mensaje' => 'Ahora no puedo escucharte por el micrófono. Escribe tu pregunta, por favor.'), 503);
}

$r = musa_elevenlabs_transcribir($audio, $tipo, $ajustes);
if (!$r['ok']) {
    musa_responder_json(array('ok' => false, 'mensaje' => 'No fue posible entender el audio. Inténtalo de nuevo o escribe tu pregunta.'), 502);
}
musa_responder_json(array('ok' => true, 'texto' => $r['texto']));
