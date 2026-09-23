<?php
/**
 * Musa Café · Cliente de HeyGen LiveAvatar
 * -------------------------------------------------------------
 * HeyGen reemplazó su «Interactive Avatar API» por la plataforma LiveAvatar
 * (api.liveavatar.com). La API anterior dejó de funcionar el 31 de marzo de 2026.
 *
 * Flujo de una conversación (modo FULL):
 *   1. El servidor crea un token de sesión   POST /v1/sessions/token   (X-API-KEY)
 *   2. El servidor inicia la sesión          POST /v1/sessions/start   (Bearer <token>)
 *      y entrega al navegador solo la URL y el token de la sala LiveKit.
 *   3. El navegador se conecta a la sala: recibe el video del avatar y envía el micrófono.
 *      Los eventos (transcripciones, habla, comandos) viajan por los canales de datos
 *      «agent-response» y «agent-control».
 *   4. Al terminar, el servidor detiene la sesión POST /v1/sessions/stop (X-API-KEY).
 *
 * La clave de API nunca sale del servidor.
 */
if (!defined('MUSA_ARRANQUE')) { http_response_code(403); exit('Acceso directo no permitido.'); }

/** Avatar público de pruebas del modo sandbox (no consume créditos, sesiones de ~1 minuto). */
define('MUSA_HEYGEN_AVATAR_SANDBOX', 'dd73ea75-1218-4ef3-92ce-606d5f7fbc0a');

/**
 * Normaliza un identificador: los ID de 32 caracteres hexadecimales (formato del
 * antiguo panel de HeyGen) se convierten al formato UUID con guiones que espera LiveAvatar.
 */
function musa_heygen_uuid($id) {
    $id = strtolower(trim((string) $id));
    if (preg_match('/^[0-9a-f]{32}$/', $id)) {
        return substr($id, 0, 8) . '-' . substr($id, 8, 4) . '-' . substr($id, 12, 4) . '-' . substr($id, 16, 4) . '-' . substr($id, 20);
    }
    return $id;
}

/** ¿El identificador tiene formato UUID? */
function musa_heygen_uuid_valido($id) {
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', musa_heygen_uuid($id));
}

/** ¿Hay clave de API configurada? */
function musa_heygen_configurado($ajustes = null) {
    if ($ajustes === null) { $ajustes = musa_ajustes(); }
    return trim((string) musa_dato($ajustes, 'heygen.api_key', '')) !== '';
}

/**
 * Petición a la API de LiveAvatar.
 * $autenticacion: null = X-API-KEY de los ajustes; string = token de sesión (Bearer).
 * Devuelve array('ok', 'codigo', 'datos', 'mensaje', 'crudo').
 */
function musa_heygen_peticion($ruta, $metodo = 'GET', $cuerpo = null, $ajustes = null, $bearer = null, $tiempo = 30) {
    if ($ajustes === null) { $ajustes = musa_ajustes(); }
    $base = rtrim((string) musa_dato($ajustes, 'heygen.endpoint', 'https://api.liveavatar.com'), '/');
    // Solo HTTPS; se admite http://localhost o 127.0.0.1 para pruebas locales con un simulador.
    if ($base === '' || (stripos($base, 'https://') !== 0 && !preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#i', $base))) {
        $base = 'https://api.liveavatar.com';
    }

    $cabeceras = array('Accept: application/json');
    if ($bearer !== null) {
        $cabeceras[] = 'Authorization: Bearer ' . $bearer;
    } else {
        $clave = trim((string) musa_dato($ajustes, 'heygen.api_key', ''));
        if ($clave === '') {
            return array('ok' => false, 'codigo' => 0, 'datos' => null, 'mensaje' => 'Falta la clave de API de HeyGen LiveAvatar.', 'crudo' => '');
        }
        $cabeceras[] = 'X-API-KEY: ' . $clave;
    }
    $opciones = array('metodo' => $metodo, 'cabeceras' => $cabeceras, 'tiempo' => $tiempo);
    if ($cuerpo !== null) {
        $opciones['cabeceras'][] = 'Content-Type: application/json';
        $opciones['cuerpo'] = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } elseif ($metodo === 'POST') {
        $opciones['cabeceras'][] = 'Content-Type: application/json';
        $opciones['cuerpo'] = '{}';
    }

    $r = musa_http($base . $ruta, $opciones);
    $json = json_decode((string) $r['cuerpo'], true);
    $datos = is_array($json) && array_key_exists('data', $json) ? $json['data'] : null;
    $ok = $r['ok'];
    // La API responde code 100 (documentación) o 1000 (SDK) cuando todo sale bien.
    if ($ok && is_array($json) && isset($json['code']) && !in_array((int) $json['code'], array(100, 1000, 200, 0), true) && $datos === null) {
        $ok = false;
    }
    return array(
        'ok'      => $ok,
        'codigo'  => (int) $r['codigo'],
        'datos'   => $datos,
        'mensaje' => $ok ? '' : musa_heygen_error($r, $json),
        'crudo'   => substr((string) $r['cuerpo'], 0, 800),
    );
}

/** Mensaje de error legible a partir de la respuesta. */
function musa_heygen_error($r, $json) {
    $codigo = (int) $r['codigo'];
    $detalle = '';
    if (is_array($json)) {
        if (!empty($json['message']) && is_string($json['message'])) { $detalle = $json['message']; }
        if (!empty($json['detail'])) {
            if (is_string($json['detail'])) { $detalle = $json['detail']; }
            elseif (is_array($json['detail'])) {
                $partes = array();
                foreach ($json['detail'] as $d) {
                    if (is_array($d)) {
                        $campo = isset($d['loc']) ? implode('.', (array) $d['loc']) : '';
                        $partes[] = trim($campo . ' ' . (string) ($d['msg'] ?? ''));
                    }
                }
                $detalle = implode('; ', array_filter($partes));
            }
        }
    }
    if ($codigo === 0) { return 'No fue posible conectar con LiveAvatar' . ($r['error'] !== '' ? ': ' . $r['error'] : '.'); }
    if ($codigo === 401 || $codigo === 403) { return 'Clave de API rechazada (HTTP ' . $codigo . ')' . ($detalle !== '' ? ': ' . $detalle : '.'); }
    if ($codigo === 404) { return 'Recurso no encontrado (HTTP 404)' . ($detalle !== '' ? ': ' . $detalle : '.'); }
    if ($codigo === 402) { return 'La cuenta no tiene créditos suficientes (HTTP 402).'; }
    if ($codigo === 422) { return 'Datos no válidos para LiveAvatar' . ($detalle !== '' ? ': ' . $detalle : '.'); }
    if ($codigo === 429) { return 'Demasiadas solicitudes o límite de sesiones simultáneas (HTTP 429).'; }
    return 'LiveAvatar respondió HTTP ' . $codigo . ($detalle !== '' ? ': ' . $detalle : '.');
}

/* ------------------------------------------------------------------ */
/*  Contexto (personalidad, conocimiento y saludo del avatar)          */
/* ------------------------------------------------------------------ */

/** Arma la instrucción (prompt) del contexto a partir del tema configurado en el panel. */
function musa_heygen_prompt($ajustes = null) {
    if ($ajustes === null) { $ajustes = musa_ajustes(); }
    $tema = trim((string) musa_dato($ajustes, 'tema.nombre', 'Café'));
    $palabras = max(20, (int) musa_dato($ajustes, 'tema.maximo_palabras', 60));
    $partes = array();
    $partes[] = trim((string) musa_dato($ajustes, 'tema.personalidad', ''));
    $partes[] = 'TEMA DE LA CONVERSACIÓN: ' . $tema . '.';
    $partes[] = "REGLAS:\n" . trim((string) musa_dato($ajustes, 'tema.reglas', ''))
        . "\n- Cada respuesta debe tener como máximo " . $palabras . ' palabras.';
    $conocimiento = trim((string) musa_dato($ajustes, 'tema.conocimiento', ''));
    if ($conocimiento !== '') {
        $partes[] = "INFORMACIÓN DE REFERENCIA (úsala como fuente principal):\n" . $conocimiento;
    }
    return trim(implode("\n\n", array_filter($partes)));
}

/** Datos del contexto listos para enviar a la API. */
function musa_heygen_contexto_cuerpo($ajustes = null) {
    if ($ajustes === null) { $ajustes = musa_ajustes(); }
    $nombre = trim((string) musa_dato($ajustes, 'tema.titulo', 'Musa Café'));
    if (function_exists('mb_substr')) { $nombre = mb_substr($nombre, 0, 64, 'UTF-8'); }
    $cuerpo = array(
        'name'         => $nombre !== '' ? $nombre : 'Musa Café',
        'prompt'       => musa_heygen_prompt($ajustes),
        'opening_text' => trim((string) musa_dato($ajustes, 'tema.saludo', '¡Hola!')),
    );
    $enlaces = array();
    foreach ((array) musa_dato($ajustes, 'tema.enlaces', array()) as $enlace) {
        $url = trim((string) ($enlace['url'] ?? ''));
        $faq = trim((string) ($enlace['faq'] ?? ''));
        if ($url !== '' && $faq !== '' && filter_var($url, FILTER_VALIDATE_URL)) { $enlaces[] = array('url' => $url, 'faq' => $faq); }
    }
    if ($enlaces !== array()) { $cuerpo['links'] = $enlaces; }
    return $cuerpo;
}

/** Huella del contexto: si cambia, hay que volver a sincronizarlo. */
function musa_heygen_contexto_huella($ajustes = null) {
    return sha1(json_encode(musa_heygen_contexto_cuerpo($ajustes), JSON_UNESCAPED_UNICODE));
}

/**
 * Crea o actualiza el contexto en LiveAvatar y guarda su ID en los ajustes.
 * Sin $forzar, no hace nada si la huella no cambió.
 */
function musa_heygen_sincronizar_contexto($ajustes = null, $forzar = false) {
    if ($ajustes === null) { $ajustes = musa_ajustes(); }
    $huella = musa_heygen_contexto_huella($ajustes);
    $id = trim((string) musa_dato($ajustes, 'heygen.context_id', ''));

    if (!$forzar && $id !== '' && $huella === (string) musa_dato($ajustes, 'heygen.context_huella', '')) {
        return array('ok' => true, 'context_id' => $id, 'mensaje' => 'El contexto ya estaba sincronizado.');
    }

    $cuerpo = musa_heygen_contexto_cuerpo($ajustes);
    $r = null;
    if ($id !== '') {
        $r = musa_heygen_peticion('/v1/contexts/' . rawurlencode($id), 'PATCH', $cuerpo, $ajustes);
        if (!$r['ok'] && $r['codigo'] === 404) { $id = ''; }   // se borró en LiveAvatar: se crea de nuevo
    }
    if ($id === '') {
        $r = musa_heygen_peticion('/v1/contexts', 'POST', $cuerpo, $ajustes);
        if ($r['ok'] && is_array($r['datos']) && !empty($r['datos']['id'])) { $id = (string) $r['datos']['id']; }
    }
    if (!$r['ok'] || $id === '') {
        musa_log('Error al sincronizar el contexto de LiveAvatar', array('codigo' => $r['codigo'], 'respuesta' => $r['crudo']));
        return array('ok' => false, 'context_id' => '', 'mensaje' => $r['mensaje'] !== '' ? $r['mensaje'] : 'LiveAvatar no devolvió el identificador del contexto.');
    }

    // Se guarda sobre los ajustes vigentes en disco para no pisar otros cambios.
    $vigentes = musa_ajustes(true);
    musa_fijar($vigentes, 'heygen.context_id', $id);
    musa_fijar($vigentes, 'heygen.context_huella', $huella);
    musa_fijar($vigentes, 'heygen.context_fecha', date('Y-m-d H:i:s'));
    musa_guardar_ajustes($vigentes);
    musa_log('Contexto de LiveAvatar sincronizado', array('context_id' => $id));
    return array('ok' => true, 'context_id' => $id, 'mensaje' => 'Contexto sincronizado en LiveAvatar (' . $id . ').');
}

/* ------------------------------------------------------------------ */
/*  Sesiones                                                            */
/* ------------------------------------------------------------------ */

/** Cuerpo para crear el token de sesión en modo FULL. */
function musa_heygen_sesion_cuerpo($ajustes, $contextId) {
    $sandbox = !empty(musa_dato($ajustes, 'avatar.sandbox', false));
    $calidad = (string) musa_dato($ajustes, 'avatar.calidad', 'high');
    if (!in_array($calidad, array('very_high', 'high', 'medium', 'low'), true)) { $calidad = 'high'; }
    $interactividad = (string) musa_dato($ajustes, 'avatar.interactividad', 'CONVERSATIONAL');
    if (!in_array($interactividad, array('CONVERSATIONAL', 'PUSH_TO_TALK'), true)) { $interactividad = 'CONVERSATIONAL'; }

    $persona = array(
        'context_id' => $contextId,
        'language'   => (string) musa_dato($ajustes, 'avatar.idioma', 'es'),
    );
    $voz = musa_heygen_uuid(musa_dato($ajustes, 'avatar.voice_id', ''));
    if ($voz !== '' && !$sandbox) { $persona['voice_id'] = $voz; }

    return array(
        'mode'                 => 'FULL',
        'avatar_id'            => $sandbox ? MUSA_HEYGEN_AVATAR_SANDBOX : musa_heygen_uuid(musa_dato($ajustes, 'avatar.avatar_id', '')),
        'is_sandbox'           => $sandbox,
        'video_settings'       => array('quality' => $calidad, 'encoding' => 'H264'),
        'max_session_duration' => max(60, min(3600, (int) musa_dato($ajustes, 'avatar.duracion_maxima', 600))),
        'interactivity_type'   => $interactividad,
        'avatar_persona'       => $persona,
    );
}

/** Crea el token de sesión. Devuelve array('ok', 'session_id', 'session_token', 'mensaje'). */
function musa_heygen_crear_token($ajustes = null) {
    if ($ajustes === null) { $ajustes = musa_ajustes(); }
    if (!musa_heygen_configurado($ajustes)) {
        return array('ok' => false, 'mensaje' => 'Falta configurar la clave de API de HeyGen LiveAvatar en el panel.');
    }
    $contexto = musa_heygen_sincronizar_contexto($ajustes);
    if (!$contexto['ok']) { return array('ok' => false, 'mensaje' => 'Contexto: ' . $contexto['mensaje']); }

    $r = musa_heygen_peticion('/v1/sessions/token', 'POST', musa_heygen_sesion_cuerpo($ajustes, $contexto['context_id']), $ajustes);
    if (!$r['ok'] || !is_array($r['datos']) || empty($r['datos']['session_token'])) {
        musa_log('Error al crear el token de LiveAvatar', array('codigo' => $r['codigo'], 'respuesta' => $r['crudo']));
        return array('ok' => false, 'mensaje' => $r['mensaje'] !== '' ? $r['mensaje'] : 'LiveAvatar no devolvió el token de sesión.');
    }
    return array(
        'ok'            => true,
        'session_id'    => (string) ($r['datos']['session_id'] ?? ''),
        'session_token' => (string) $r['datos']['session_token'],
        'mensaje'       => '',
    );
}

/** Inicia la sesión con el token. Devuelve los datos de conexión a LiveKit. */
function musa_heygen_iniciar($sessionToken, $ajustes = null) {
    $r = musa_heygen_peticion('/v1/sessions/start', 'POST', null, $ajustes, $sessionToken, 45);
    if (!$r['ok'] || !is_array($r['datos']) || empty($r['datos']['livekit_url']) || empty($r['datos']['livekit_client_token'])) {
        musa_log('Error al iniciar la sesión de LiveAvatar', array('codigo' => $r['codigo'], 'respuesta' => $r['crudo']));
        return array('ok' => false, 'mensaje' => $r['mensaje'] !== '' ? $r['mensaje'] : 'LiveAvatar no devolvió los datos de la sala.');
    }
    return array(
        'ok'                   => true,
        'session_id'           => (string) ($r['datos']['session_id'] ?? ''),
        'livekit_url'          => (string) $r['datos']['livekit_url'],
        'livekit_client_token' => (string) $r['datos']['livekit_client_token'],
        'max_session_duration' => isset($r['datos']['max_session_duration']) ? (int) $r['datos']['max_session_duration'] : null,
    );
}

/** Mantiene viva la sesión (evita el cierre por inactividad). */
function musa_heygen_mantener($sessionId, $ajustes = null) {
    return musa_heygen_peticion('/v1/sessions/keep-alive', 'POST', array('session_id' => $sessionId), $ajustes);
}

/** Detiene una sesión. */
function musa_heygen_detener($sessionId, $motivo = 'USER_CLOSED', $ajustes = null) {
    $validos = array('UNKNOWN', 'USER_DISCONNECTED', 'USER_CLOSED', 'IDLE_TIMEOUT', 'MAX_DURATION_REACHED', 'SERVER_ERROR');
    return musa_heygen_peticion('/v1/sessions/stop', 'POST', array(
        'session_id' => $sessionId,
        'reason'     => in_array($motivo, $validos, true) ? $motivo : 'UNKNOWN',
    ), $ajustes);
}

/**
 * Transcripción oficial de la sesión guardada por LiveAvatar.
 * Devuelve lista de ['rol' => persona|avatar, 'texto', 'hora'] o null si falla.
 */
function musa_heygen_transcripcion($sessionId, $ajustes = null) {
    $r = musa_heygen_peticion('/v1/sessions/' . rawurlencode($sessionId) . '/transcript', 'GET', null, $ajustes);
    if (!$r['ok'] || !is_array($r['datos'])) { return null; }
    $lista = array();
    foreach ((array) ($r['datos']['transcript_data'] ?? array()) as $fila) {
        $texto = trim((string) ($fila['transcript'] ?? ''));
        if ($texto === '') { continue; }
        $marca = isset($fila['absolute_timestamp']) ? (int) $fila['absolute_timestamp'] : 0;
        if ($marca > 20000000000) { $marca = (int) floor($marca / 1000); }   // milisegundos
        $lista[] = array(
            'rol'    => ($fila['role'] ?? '') === 'user' ? 'persona' : 'avatar',
            'texto'  => $texto,
            'origen' => 'voz',
            'hora'   => $marca > 0 ? date('Y-m-d H:i:s', $marca) : '',
            'ref'    => 'la-' . $marca . '-' . substr(sha1($texto), 0, 8),
        );
    }
    return $lista;
}

/* ------------------------------------------------------------------ */
/*  Verificación desde el panel                                         */
/* ------------------------------------------------------------------ */

/** Verifica la clave consultando los créditos (no consume créditos). */
function musa_heygen_verificar($ajustes = null) {
    $r = musa_heygen_peticion('/v1/users/credits', 'GET', null, $ajustes);
    if (!$r['ok']) { return array('ok' => false, 'mensaje' => $r['mensaje'], 'detalle' => $r['crudo']); }
    $creditos = is_array($r['datos']) && isset($r['datos']['credits_left']) ? (string) $r['datos']['credits_left'] : '—';
    return array('ok' => true, 'mensaje' => 'Conexión correcta con LiveAvatar. Créditos disponibles: ' . $creditos . '.', 'creditos' => $creditos, 'detalle' => '');
}

/** Verifica que el avatar exista y esté activo. */
function musa_heygen_verificar_avatar($ajustes = null) {
    if ($ajustes === null) { $ajustes = musa_ajustes(); }
    $original = (string) musa_dato($ajustes, 'avatar.avatar_id', '');
    $id = musa_heygen_uuid($original);
    if (!musa_heygen_uuid_valido($id)) {
        return array('ok' => false, 'mensaje' => 'El ID del avatar «' . $original . '» no tiene formato válido (se espera un UUID de LiveAvatar).', 'detalle' => '');
    }
    $r = musa_heygen_peticion('/v1/avatars/' . rawurlencode($id), 'GET', null, $ajustes);
    if (!$r['ok'] || !is_array($r['datos'])) {
        $nota = $r['codigo'] === 404 ? ' Si el avatar viene del antiguo panel de HeyGen, búscalo migrado en app.liveavatar.com: LiveAvatar le asigna un ID nuevo.' : '';
        return array('ok' => false, 'mensaje' => 'Avatar ' . $id . ': ' . $r['mensaje'] . $nota, 'detalle' => $r['crudo']);
    }
    $d = $r['datos'];
    $estado = (string) ($d['status'] ?? '');
    $ok = $estado === '' || $estado === 'ACTIVE';
    $voz = isset($d['default_voice']['name']) ? ' · voz predeterminada: ' . $d['default_voice']['name'] : '';
    return array(
        'ok'      => $ok && empty($d['is_expired']),
        'mensaje' => 'Avatar «' . (string) ($d['name'] ?? $id) . '» · estado ' . ($estado !== '' ? $estado : 'desconocido') . $voz . (!empty($d['is_expired']) ? ' · VENCIDO' : ''),
        'preview' => (string) ($d['preview_url'] ?? ''),
        'detalle' => (string) ($d['error_message'] ?? ''),
    );
}

/** Verifica que la voz exista. */
function musa_heygen_verificar_voz($ajustes = null) {
    if ($ajustes === null) { $ajustes = musa_ajustes(); }
    $original = (string) musa_dato($ajustes, 'avatar.voice_id', '');
    if (trim($original) === '') { return array('ok' => true, 'mensaje' => 'Sin voz configurada: se usará la voz predeterminada del avatar.', 'detalle' => ''); }
    $id = musa_heygen_uuid($original);
    if (!musa_heygen_uuid_valido($id)) {
        return array('ok' => false, 'mensaje' => 'El ID de la voz «' . $original . '» no tiene formato válido (se espera un UUID de LiveAvatar).', 'detalle' => '');
    }
    $r = musa_heygen_peticion('/v1/voices/' . rawurlencode($id), 'GET', null, $ajustes);
    if (!$r['ok'] || !is_array($r['datos'])) {
        return array('ok' => false, 'mensaje' => 'Voz ' . $id . ': ' . $r['mensaje'], 'detalle' => $r['crudo']);
    }
    $d = $r['datos'];
    return array('ok' => true, 'mensaje' => 'Voz «' . (string) ($d['name'] ?? $id) . '» · idioma ' . (string) ($d['language'] ?? '—') . ' · ' . (string) ($d['gender'] ?? ''), 'detalle' => '');
}

/** Lista los avatares de la cuenta (para ayudar a encontrar el ID correcto). */
function musa_heygen_listar_avatares($ajustes = null) {
    $r = musa_heygen_peticion('/v1/avatars?page=1&page_size=50', 'GET', null, $ajustes);
    return ($r['ok'] && is_array($r['datos'])) ? (array) ($r['datos']['results'] ?? array()) : array();
}

/** Lista las voces privadas de la cuenta. */
function musa_heygen_listar_voces($ajustes = null) {
    $r = musa_heygen_peticion('/v1/voices?page=1&page_size=50&voice_type=private', 'GET', null, $ajustes);
    return ($r['ok'] && is_array($r['datos'])) ? (array) ($r['datos']['results'] ?? array()) : array();
}

/**
 * Prueba de punta a punta sin consumir créditos: sincroniza el contexto, crea un token
 * de sesión con el avatar, la voz y el idioma configurados (LiveAvatar valida todo ahí)
 * y lo cierra de inmediato sin iniciar la transmisión.
 */
function musa_heygen_prueba_sesion($ajustes = null) {
    if ($ajustes === null) { $ajustes = musa_ajustes(); }
    $token = musa_heygen_crear_token($ajustes);
    if (!$token['ok']) { return array('ok' => false, 'mensaje' => $token['mensaje'], 'detalle' => ''); }
    if ($token['session_id'] !== '') { musa_heygen_detener($token['session_id'], 'USER_CLOSED', $ajustes); }
    return array('ok' => true, 'mensaje' => 'LiveAvatar aceptó la configuración (avatar, voz, idioma y contexto) y entregó un token de sesión. La sesión de prueba se cerró sin iniciarse.', 'detalle' => 'session_id ' . $token['session_id']);
}

/* ------------------------------------------------------------------ */
/*  Cierre de conversaciones                                            */
/* ------------------------------------------------------------------ */

/**
 * Cierra una conversación: detiene la sesión en LiveAvatar y, si el navegador
 * no registró ningún mensaje, recupera la transcripción oficial de la sesión.
 */
function musa_conversacion_finalizar($id, $motivo = 'usuario', $ajustes = null, $razonApi = 'USER_CLOSED') {
    if ($ajustes === null) { $ajustes = musa_ajustes(); }
    $c = musa_conversacion_obtener($id);
    if ($c === null) { return null; }

    if ($c['session_id'] !== '' && musa_heygen_configurado($ajustes)) {
        musa_heygen_detener($c['session_id'], $razonApi, $ajustes);
        if (count($c['mensajes']) === 0) {
            $oficial = musa_heygen_transcripcion($c['session_id'], $ajustes);
            if (is_array($oficial) && $oficial !== array()) {
                musa_conversacion_agregar_mensajes($c['id'], $oficial, (int) musa_dato($ajustes, 'seguridad.maximo_mensajes', 400));
            }
        }
    }
    $final = musa_conversacion_actualizar($c['id'], array('estado' => 'finalizada', 'motivo_fin' => $motivo, 'fecha_fin' => date('Y-m-d H:i:s')));
    musa_log('Conversación finalizada', array('codigo' => $c['codigo'], 'motivo' => $motivo, 'mensajes' => count($final['mensajes'] ?? array())));
    return $final;
}

/**
 * Conversaciones que quedaron abiertas (la persona cerró la pestaña sin que llegara el aviso):
 * pasada la duración máxima más cinco minutos, se cierran. Se revisan pocas por llamada.
 */
function musa_conversaciones_cerrar_vencidas($ajustes = null, $maximo = 5) {
    if ($ajustes === null) { $ajustes = musa_ajustes(); }
    $limite = time() - (max(60, (int) musa_dato($ajustes, 'avatar.duracion_maxima', 600)) + 300);
    $cerradas = 0;
    foreach (musa_conversaciones_cargar()['conversaciones'] as $c) {
        if ($cerradas >= $maximo) { break; }
        if (!in_array($c['estado'] ?? '', array('activa', 'iniciando'), true)) { continue; }
        $marca = strtotime((string) ($c['fecha'] ?? ''));
        if ($marca === false || $marca > $limite) { continue; }
        musa_conversacion_finalizar($c['id'], 'sin_cierre', $ajustes, 'USER_DISCONNECTED');
        $cerradas++;
    }
    return $cerradas;
}
