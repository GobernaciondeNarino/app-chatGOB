<?php
/**
 * QuéDice! · Base común de la API pública
 * Valida método, cuerpo JSON y token CSRF, y libera la sesión
 * antes de hablar con LiveAvatar para no bloquear otras peticiones.
 */
require_once dirname(__DIR__) . '/arranque.php';

/** Prepara una petición POST de la API y devuelve el cuerpo ya validado. */
function musa_api_preparar() {
    musa_cabeceras_seguridad();
    musa_sesion();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        musa_responder_json(array('ok' => false, 'mensaje' => 'Método no permitido.'), 405);
    }
    // navigator.sendBeacon() envía el JSON como text/plain: se acepta igual.
    $datos = musa_cuerpo_json();
    musa_exigir_token(isset($datos['token']) ? $datos['token'] : '', true);
    session_write_close();
    return $datos;
}

/** Conversación indicada por código y clave, o termina con 404. */
function musa_api_conversacion($datos) {
    $c = musa_conversacion_publica(
        musa_texto(isset($datos['codigo']) ? $datos['codigo'] : '', 40),
        isset($datos['clave']) ? (string) $datos['clave'] : ''
    );
    if ($c === null) {
        musa_responder_json(array('ok' => false, 'mensaje' => 'La conversación no existe o la clave no es válida.'), 404);
    }
    return $c;
}

/** Normaliza la lista de mensajes que envía el navegador. */
function musa_api_mensajes($lista, $maximo = 30) {
    $limpios = array();
    if (!is_array($lista)) { return $limpios; }
    foreach (array_slice($lista, 0, $maximo) as $m) {
        if (!is_array($m)) { continue; }
        $rol = isset($m['rol']) && is_string($m['rol']) ? $m['rol'] : '';
        if (!in_array($rol, array('persona', 'avatar'), true)) { continue; }
        // Topes del servidor (el maxlength del navegador no protege nada): una pregunta cabe en
        // 1 000 caracteres y una respuesta del avatar (máx. 250 palabras) en 2 000.
        $texto = musa_texto(isset($m['texto']) ? $m['texto'] : '', $rol === 'persona' ? 1000 : 2000);
        if ($texto === '') { continue; }
        $origen = isset($m['origen']) && $m['origen'] === 'texto' ? 'texto' : 'voz';
        $ref = preg_replace('/[^A-Za-z0-9_.:\-]/', '', (string) (isset($m['ref']) && is_scalar($m['ref']) ? $m['ref'] : ''));
        // Todo lo que llega del navegador queda marcado: el texto del avatar solo se da por
        // verificado cuando coincide con la transcripción oficial de LiveAvatar.
        $limpios[] = array('rol' => $rol, 'texto' => $texto, 'origen' => $origen, 'fuente' => 'navegador', 'ref' => substr($ref, 0, 80), 'hora' => date('Y-m-d H:i:s'));
    }
    return $limpios;
}
