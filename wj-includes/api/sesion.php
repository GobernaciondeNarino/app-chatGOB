<?php
/**
 * QuéDice! · Inicia una conversación con el avatar
 * POST JSON: { token, nombre, correo, telefono, ciudad, autorizacion, sitio_web }
 *
 * Valida el formulario de inicio (si está activo), crea el registro de la conversación,
 * pide a LiveAvatar el token de sesión e inicia la sesión desde el servidor.
 * Al navegador solo llegan la URL y el token de la sala LiveKit: la clave de API no sale de aquí.
 */
require_once __DIR__ . '/comun.php';

$datos = musa_api_preparar();
@set_time_limit(90);

$ajustes = musa_ajustes();
$ip = musa_ip();

// Campo trampa antirrobots.
if (!empty($datos['sitio_web'])) {
    musa_responder_json(array('ok' => false, 'mensaje' => 'No fue posible iniciar la conversación.'), 400);
}

$form = musa_dato($ajustes, 'formulario', array());
$campos = array('nombre' => '', 'correo' => '', 'telefono' => '', 'ciudad' => '', 'autorizacion' => false);
$errores = array();

if (!empty($form['activo'])) {
    $campos['nombre'] = preg_replace('/\s+/u', ' ', musa_texto(isset($datos['nombre']) ? $datos['nombre'] : '', 120));
    if (mb_strlen($campos['nombre'], 'UTF-8') < 3) { $errores['nombre'] = 'Escribe tu nombre.'; }
    elseif (!musa_nombre_valido($campos['nombre'])) { $errores['nombre'] = 'Escribe tu nombre solo con letras.'; }

    if (!empty($form['pedir_correo'])) {
        $campos['correo'] = musa_texto(isset($datos['correo']) ? $datos['correo'] : '', 160);
        if ($campos['correo'] !== '' && !musa_correo_valido($campos['correo'])) { $errores['correo'] = 'El correo no es válido.'; }
        elseif ($campos['correo'] === '' && !empty($form['correo_obligatorio'])) { $errores['correo'] = 'Escribe tu correo.'; }
    }
    if (!empty($form['pedir_telefono'])) {
        $campos['telefono'] = musa_texto(isset($datos['telefono']) ? $datos['telefono'] : '', 40);
        if ($campos['telefono'] !== '' && !preg_match('/^[0-9+()\s\-]{7,40}$/', $campos['telefono'])) { $errores['telefono'] = 'El teléfono no es válido.'; }
        elseif ($campos['telefono'] === '' && !empty($form['telefono_obligatorio'])) { $errores['telefono'] = 'Escribe tu teléfono.'; }
    }
    if (!empty($form['pedir_ciudad'])) {
        $campos['ciudad'] = musa_texto(isset($datos['ciudad']) ? $datos['ciudad'] : '', 80);
        if ($campos['ciudad'] === '' && !empty($form['ciudad_obligatoria'])) { $errores['ciudad'] = 'Elige tu municipio.'; }
        elseif ($campos['ciudad'] !== '' && empty($form['ciudad_lista']) && !musa_nombre_valido($campos['ciudad'])) { $errores['ciudad'] = 'Escribe el municipio solo con letras.'; }
        elseif ($campos['ciudad'] !== '' && !empty($form['ciudad_lista'])) {
            // Con la lista activa solo valen los municipios de Nariño (y «Otro municipio» si se permite).
            $validos = musa_municipios_narino();
            if (!empty($form['ciudad_otro'])) { $validos[] = 'Otro municipio'; }
            if (!in_array($campos['ciudad'], $validos, true)) { $errores['ciudad'] = 'Elige un municipio de la lista.'; }
        }
    }
    $campos['autorizacion'] = !empty($datos['autorizacion']);
    if (!empty(musa_dato($ajustes, 'seguridad.exigir_aceptacion', true)) && !$campos['autorizacion']) {
        $errores['autorizacion'] = 'Debes autorizar el tratamiento de datos para continuar.';
    }
}

if ($errores !== array()) {
    musa_responder_json(array('ok' => false, 'mensaje' => 'Revisa los datos del formulario.', 'errores' => $errores), 422);
}

// Consulta rápida antes de tocar nada; el límite definitivo se comprueba dentro de la transacción.
if (musa_limite_superado($ip)) {
    musa_responder_json(array('ok' => false, 'mensaje' => 'Has iniciado varias conversaciones seguidas. Inténtalo de nuevo más tarde.'), 429);
}

if (!musa_heygen_configurado($ajustes)) {
    musa_log('Conversación rechazada: falta la clave de LiveAvatar');
    musa_responder_json(array('ok' => false, 'mensaje' => 'El anfitrión no está disponible en este momento.'), 503);
}

if (musa_conversaciones_lleno()) {
    musa_log('Conversación rechazada: el archivo de conversaciones llegó a su tamaño máximo; hay que archivar las antiguas');
    musa_responder_json(array('ok' => false, 'mensaje' => 'El anfitrión no está disponible en este momento.'), 503);
}

// Sesiones abandonadas (pestaña cerrada sin aviso): se cierran para liberar cupo y créditos.
// Sin pedir la transcripción aquí (el visitante espera); el panel puede recuperarla después.
musa_conversaciones_cerrar_vencidas($ajustes, 2, false);

$navegador = musa_texto(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 250);
$conversacion = musa_conversacion_crear(array_merge($campos, array(
    'tema'      => (string) musa_dato($ajustes, 'tema.nombre', 'Café'),
    'avatar_id' => musa_heygen_uuid(musa_dato($ajustes, 'avatar.avatar_id', '')),
    'ip'        => $ip,
    'navegador' => $navegador,
)));
if (isset($conversacion['error'])) {
    if ($conversacion['error'] === 'limite') {
        musa_responder_json(array('ok' => false, 'mensaje' => 'Has iniciado varias conversaciones seguidas. Inténtalo de nuevo más tarde.'), 429);
    }
    musa_log('Conversación rechazada: se alcanzó el cupo global de conversaciones');
    musa_responder_json(array('ok' => false, 'mensaje' => 'El anfitrión está atendiendo a muchas personas. Inténtalo de nuevo en unos minutos.'), 503);
}

$token = musa_heygen_crear_token($ajustes);
$sala = $token['ok'] ? musa_heygen_iniciar($token['session_token'], $ajustes) : $token;

if (!$sala['ok']) {
    musa_conversacion_actualizar($conversacion['id'], array('estado' => 'error', 'motivo_fin' => $sala['mensaje'], 'session_id' => $token['session_id'] ?? '', 'fecha_fin' => date('Y-m-d H:i:s')));
    musa_responder_json(array('ok' => false, 'mensaje' => 'No fue posible conectar con el anfitrión. Inténtalo de nuevo en unos minutos.'), 502);
}

$sessionId = $sala['session_id'] !== '' ? $sala['session_id'] : $token['session_id'];
musa_conversacion_actualizar($conversacion['id'], array('session_id' => $sessionId, 'estado' => 'activa'));
musa_log('Conversación iniciada', array('codigo' => $conversacion['codigo'], 'session_id' => $sessionId));

musa_responder_json(array(
    'ok'          => true,
    'codigo'      => $conversacion['codigo'],
    'clave'       => $conversacion['clave'],
    'session_id'  => $sessionId,
    'livekit_url' => $sala['livekit_url'],
    'livekit_token' => $sala['livekit_client_token'],
    'duracion'    => $sala['max_session_duration'] !== null ? $sala['max_session_duration'] : (int) musa_dato($ajustes, 'avatar.duracion_maxima', 600),
));
