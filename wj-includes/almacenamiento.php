<?php
/**
 * QuéDice! · Almacenamiento de conversaciones en JSON
 * Archivo: wj-content/datos/conversaciones.json.php
 *
 * Cada conversación guarda los datos de la persona (si el formulario de inicio
 * está activo), las preguntas y respuestas en orden, y las casillas
 * "Creado SÍ/NO" y "Enviado SÍ/NO" que se gestionan desde wj-admin.
 */
if (!defined('MUSA_ARRANQUE')) { http_response_code(403); exit('Acceso directo no permitido.'); }

/** Texto máximo guardado por conversación (bytes). Una conversación real ronda 3-10 KB. */
define('MUSA_MAX_BYTES_CONVERSACION', 65536);
/** Tamaño del archivo de conversaciones a partir del cual no se aceptan conversaciones nuevas. */
define('MUSA_MAX_BYTES_ARCHIVO', 16 * 1024 * 1024);
/** Segundos máximos esperando el bloqueo del archivo antes de responder «ocupado». */
define('MUSA_ESPERA_BLOQUEO', 8);
/** Una conversación activa sin latido (mensajes o keep-alive) durante este tiempo se da por abandonada. */
define('MUSA_SIN_LATIDO', 180);

/** Estructura vacía del archivo. */
function musa_conversaciones_vacio() {
    return array('version' => 2, 'secuencia' => 0, 'actualizado' => date('c'), 'conversaciones' => array());
}

/** Carga todas las conversaciones. */
function musa_conversaciones_cargar() {
    $datos = musa_leer_json(MUSA_ARCHIVO_CONVERSACIONES, musa_conversaciones_vacio());
    if (!isset($datos['conversaciones']) || !is_array($datos['conversaciones'])) { $datos['conversaciones'] = array(); }
    if (!isset($datos['secuencia'])) { $datos['secuencia'] = count($datos['conversaciones']); }
    return $datos;
}

/** Guarda el archivo completo. */
function musa_conversaciones_guardar($datos) {
    $datos['actualizado'] = date('c');
    return musa_escribir_json(MUSA_ARCHIVO_CONVERSACIONES, $datos);
}

/**
 * Ejecuta una operación con bloqueo exclusivo para que dos visitantes
 * simultáneos no se pisen los datos. La función recibe el arreglo completo
 * y devuelve array('datos' => …, 'retorno' => …); sin 'datos' no se escribe.
 */
function musa_conversaciones_transaccion($operacion) {
    if (!is_dir(MUSA_DIR_DATOS)) { @mkdir(MUSA_DIR_DATOS, 0775, true); }
    $puntero = @fopen(MUSA_DIR_DATOS . '/conversaciones.lock', 'c+');
    if ($puntero !== false) {
        // Espera acotada: una avalancha de peticiones no deja procesos PHP colgados indefinidamente.
        $limite = microtime(true) + MUSA_ESPERA_BLOQUEO;
        while (!@flock($puntero, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $limite) {
                @fclose($puntero);
                musa_log('Archivo de conversaciones ocupado: se rechazó una operación');
                if (!headers_sent()) { header('Retry-After: 5'); }
                musa_responder_json(array('ok' => false, 'mensaje' => 'El servidor está ocupado. Inténtalo de nuevo en unos segundos.'), 503);
            }
            usleep(100000);
        }
    }
    $datos = musa_conversaciones_cargar();
    $resultado = $operacion($datos);
    if (isset($resultado['datos'])) { musa_conversaciones_guardar($resultado['datos']); }
    if ($puntero !== false) { @flock($puntero, LOCK_UN); @fclose($puntero); }
    return isset($resultado['retorno']) ? $resultado['retorno'] : null;
}

/** Campos y valores por defecto de una conversación. */
function musa_conversacion_base() {
    return array(
        'id'             => '',
        'codigo'         => '',
        'fecha'          => '',
        'fecha_fin'      => '',
        'nombre'         => '',
        'correo'         => '',
        'telefono'       => '',
        'ciudad'         => '',
        'autorizacion'   => false,
        'tema'           => '',
        'avatar_id'      => '',
        'motor'          => 'liveavatar',  // liveavatar | economico (registros anteriores: liveavatar)
        'session_id'     => '',
        'estado'         => 'iniciando',   // iniciando | activa | finalizada | error
        'motivo_fin'     => '',
        'mensajes'       => array(),       // [{rol: persona|avatar, texto, hora, origen: voz|texto, fuente: navegador|liveavatar|servidor}]
        'ultimo_mantener'=> '',
        'preguntas_ia'   => 0,             // preguntas respondidas por el motor económico
        'ultima_pregunta'=> 0,             // marca de tiempo (Unix) de la última pregunta reservada
        'transcripciones'=> 0,             // audios enviados a ElevenLabs Scribe
        'ultimo_audio'   => 0,
        'creado'         => false,
        'enviado'        => false,
        'fecha_creado'   => '',
        'fecha_enviado'  => '',
        'notas'          => '',
        'ip'             => '',
        'navegador'      => '',
        'clave'          => '',            // token de la conversación para el navegador
        'actualizado'    => '',
    );
}

/**
 * Crea una conversación nueva, comprobando los límites dentro de la misma transacción para que
 * varias solicitudes simultáneas no puedan saltárselos. Devuelve la conversación, o
 * array('error' => 'limite'|'capacidad') si no se puede crear.
 * Límites: por origen (IP o /64 de IPv6) por hora y por día, global por hora y de conversaciones
 * activas al mismo tiempo. No se cuenta por correo: no está verificado y serviría para bloquear
 * a otra persona escribiendo su dirección.
 */
function musa_conversacion_crear($campos) {
    $ajustes = musa_ajustes();
    $prefijo = musa_dato($ajustes, 'sistema.prefijo_codigo', 'CAFE');
    $limites = array(
        'hora'    => (int) musa_dato($ajustes, 'seguridad.limite_por_hora', 6),
        'dia'     => (int) musa_dato($ajustes, 'seguridad.limite_por_dia', 30),
        'global'  => (int) musa_dato($ajustes, 'seguridad.limite_global_hora', 120),
        'activas' => (int) musa_dato($ajustes, 'seguridad.maximo_activas', 15),
    );
    $grupo = musa_ip_grupo(isset($campos['ip']) ? $campos['ip'] : '');
    return musa_conversaciones_transaccion(function ($datos) use ($campos, $prefijo, $limites, $grupo) {
        $ahora = time();
        $hora = 0; $dia = 0; $global = 0; $activas = 0;
        foreach ($datos['conversaciones'] as $c) {
            $marca = strtotime((string) ($c['fecha'] ?? ''));
            if ($marca === false || $marca < $ahora - 86400) { continue; }
            $mismo = $grupo !== '' && musa_ip_grupo((string) ($c['ip'] ?? '')) === $grupo;
            if ($mismo) { $dia++; }
            if ($marca >= $ahora - 3600) { $global++; if ($mismo) { $hora++; } }
            $latido = strtotime((string) ($c['actualizado'] ?? ''));
            if (in_array($c['estado'] ?? '', array('activa', 'iniciando'), true) && $latido !== false && $latido >= $ahora - MUSA_SIN_LATIDO) { $activas++; }
        }
        if (($limites['hora'] > 0 && $hora >= $limites['hora']) || ($limites['dia'] > 0 && $dia >= $limites['dia'])) {
            return array('retorno' => array('error' => 'limite'));
        }
        if (($limites['global'] > 0 && $global >= $limites['global']) || ($limites['activas'] > 0 && $activas >= $limites['activas'])) {
            return array('retorno' => array('error' => 'capacidad'));
        }
        $secuencia = (int) $datos['secuencia'] + 1;
        $c = array_merge(musa_conversacion_base(), $campos);
        $c['id']          = 'c' . bin2hex(random_bytes(8));
        $c['codigo']      = $prefijo . '-' . date('Ymd') . '-' . str_pad((string) $secuencia, 4, '0', STR_PAD_LEFT);
        $c['fecha']       = date('Y-m-d H:i:s');
        $c['actualizado'] = $c['fecha'];
        $c['clave']       = bin2hex(random_bytes(16));
        $datos['secuencia'] = $secuencia;
        array_unshift($datos['conversaciones'], $c);
        return array('datos' => $datos, 'retorno' => $c);
    });
}

/** ¿El archivo de conversaciones llegó al tamaño máximo? (hay que archivar las antiguas) */
function musa_conversaciones_lleno() {
    clearstatcache(true, MUSA_ARCHIVO_CONVERSACIONES);
    return file_exists(MUSA_ARCHIVO_CONVERSACIONES) && filesize(MUSA_ARCHIVO_CONVERSACIONES) >= MUSA_MAX_BYTES_ARCHIVO;
}

/** Porcentaje de uso del archivo de conversaciones respecto al máximo. */
function musa_conversaciones_uso() {
    clearstatcache(true, MUSA_ARCHIVO_CONVERSACIONES);
    $bytes = file_exists(MUSA_ARCHIVO_CONVERSACIONES) ? filesize(MUSA_ARCHIVO_CONVERSACIONES) : 0;
    return (int) floor($bytes * 100 / MUSA_MAX_BYTES_ARCHIVO);
}

/**
 * Mueve a un archivo aparte (datos/archivo-AAAAMMDD-HHMMSS.json.php) las conversaciones cerradas
 * con más de $dias días. Sirve para mantener liviano el archivo principal y para aplicar la política
 * de retención de datos personales. Devuelve el número de conversaciones archivadas.
 */
function musa_conversaciones_archivar($dias) {
    $limite = time() - max(1, (int) $dias) * 86400;
    return musa_conversaciones_transaccion(function ($datos) use ($limite) {
        $quedan = array(); $salen = array();
        foreach ($datos['conversaciones'] as $c) {
            $marca = strtotime((string) ($c['fecha'] ?? ''));
            $cerrada = in_array($c['estado'] ?? '', array('finalizada', 'error'), true);
            if ($cerrada && $marca !== false && $marca < $limite) { unset($c['clave']); $salen[] = $c; } else { $quedan[] = $c; }
        }
        if ($salen === array()) { return array('retorno' => 0); }
        $archivo = MUSA_DIR_DATOS . '/archivo-' . date('Ymd-His') . '.json.php';
        if (!musa_escribir_json($archivo, array('archivado' => date('c'), 'total' => count($salen), 'conversaciones' => $salen))) {
            return array('retorno' => -1);
        }
        $datos['conversaciones'] = $quedan;
        return array('datos' => $datos, 'retorno' => count($salen));
    });
}

/** ¿El registro coincide con el identificador (id o código)? */
function musa_conversacion_coincide($c, $identificador) {
    return (isset($c['id']) && $c['id'] === $identificador) || (isset($c['codigo']) && $c['codigo'] === $identificador);
}

/** Obtiene una conversación por id o código. */
function musa_conversacion_obtener($identificador) {
    $datos = musa_conversaciones_cargar();
    foreach ($datos['conversaciones'] as $c) {
        if (musa_conversacion_coincide($c, $identificador)) { return array_merge(musa_conversacion_base(), $c); }
    }
    return null;
}

/** Obtiene una conversación validando su clave (acceso desde el navegador). */
function musa_conversacion_publica($codigo, $clave) {
    $c = musa_conversacion_obtener((string) $codigo);
    if ($c === null || !is_string($clave) || $clave === '' || !hash_equals((string) $c['clave'], $clave)) { return null; }
    return $c;
}

/** Actualiza campos. Devuelve la conversación actualizada o null. */
function musa_conversacion_actualizar($identificador, $cambios) {
    return musa_conversaciones_transaccion(function ($datos) use ($identificador, $cambios) {
        foreach ($datos['conversaciones'] as $i => $c) {
            if (!musa_conversacion_coincide($c, $identificador)) { continue; }
            $c = array_merge(musa_conversacion_base(), $c, $cambios);
            $c['actualizado'] = date('Y-m-d H:i:s');
            $datos['conversaciones'][$i] = $c;
            return array('datos' => $datos, 'retorno' => $c);
        }
        return array('retorno' => null);
    });
}

/**
 * Agrega mensajes a una conversación.
 * $mensajes: [['rol' => 'persona'|'avatar', 'texto' => '…', 'origen' => 'voz'|'texto', 'ref' => 'id-evento']]
 * Ignora duplicados por 'ref' y respeta el máximo configurado.
 * Devuelve el total de mensajes guardados o null si no existe.
 */
function musa_conversacion_agregar_mensajes($identificador, $mensajes, $maximo = 400) {
    return musa_conversaciones_transaccion(function ($datos) use ($identificador, $mensajes, $maximo) {
        foreach ($datos['conversaciones'] as $i => $c) {
            if (!musa_conversacion_coincide($c, $identificador)) { continue; }
            $c = array_merge(musa_conversacion_base(), $c);
            $vistos = array();
            $bytes = 0;
            foreach ($c['mensajes'] as $m) {
                if (!empty($m['ref'])) { $vistos[$m['ref']] = true; }
                $bytes += strlen((string) ($m['texto'] ?? ''));
            }
            $agregados = 0;
            foreach ($mensajes as $m) {
                if (count($c['mensajes']) >= $maximo) { break; }
                if (!empty($m['ref']) && isset($vistos[$m['ref']])) { continue; }
                $bytes += strlen((string) $m['texto']);
                if ($bytes > MUSA_MAX_BYTES_CONVERSACION) { break; }
                $c['mensajes'][] = array(
                    'rol'    => $m['rol'],
                    'texto'  => $m['texto'],
                    'origen' => isset($m['origen']) ? $m['origen'] : 'voz',
                    'fuente' => isset($m['fuente']) ? $m['fuente'] : 'navegador',
                    'hora'   => isset($m['hora']) && $m['hora'] !== '' ? $m['hora'] : date('Y-m-d H:i:s'),
                    'ref'    => isset($m['ref']) ? $m['ref'] : '',
                );
                if (!empty($m['ref'])) { $vistos[$m['ref']] = true; }
                $agregados++;
            }
            // Nada nuevo (duplicados o topes alcanzados): no se reescribe el archivo.
            if ($agregados === 0 && $c['estado'] !== 'iniciando') { return array('retorno' => count($c['mensajes'])); }
            if ($c['estado'] === 'iniciando') { $c['estado'] = 'activa'; }
            $c['actualizado'] = date('Y-m-d H:i:s');
            $datos['conversaciones'][$i] = $c;
            return array('datos' => $datos, 'retorno' => count($c['mensajes']));
        }
        return array('retorno' => null);
    });
}

/** Texto normalizado para comparar mensajes (minúsculas, sin puntuación ni espacios repetidos). */
function musa_texto_normalizado($texto) {
    $texto = function_exists('mb_strtolower') ? mb_strtolower((string) $texto, 'UTF-8') : strtolower((string) $texto);
    return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $texto));
}

/**
 * Aplica la transcripción oficial de LiveAvatar: sus mensajes (persona y avatar) quedan como
 * verificados y reemplazan a los que envió el navegador. De lo enviado por el navegador solo se
 * conservan las preguntas escritas que la transcripción oficial no incluya.
 */
function musa_conversacion_aplicar_oficial($identificador, $oficial) {
    return musa_conversaciones_transaccion(function ($datos) use ($identificador, $oficial) {
        foreach ($datos['conversaciones'] as $i => $c) {
            if (!musa_conversacion_coincide($c, $identificador)) { continue; }
            $c = array_merge(musa_conversacion_base(), $c);
            $nuevos = array(); $oficiales = array();
            foreach ($oficial as $m) {
                $m['fuente'] = 'liveavatar';
                $nuevos[] = $m;
                if ($m['rol'] === 'persona') { $oficiales[musa_texto_normalizado($m['texto'])] = true; }
            }
            foreach ($c['mensajes'] as $m) {
                $escrita = ($m['rol'] ?? '') === 'persona' && ($m['origen'] ?? '') === 'texto';
                if ($escrita && !isset($oficiales[musa_texto_normalizado($m['texto'] ?? '')])) { $nuevos[] = $m; }
            }
            usort($nuevos, function ($a, $b) { return strcmp((string) ($a['hora'] ?? ''), (string) ($b['hora'] ?? '')); });
            $c['mensajes'] = $nuevos;
            $c['actualizado'] = date('Y-m-d H:i:s');
            $datos['conversaciones'][$i] = $c;
            return array('datos' => $datos, 'retorno' => count($nuevos));
        }
        return array('retorno' => null);
    });
}

/**
 * ¿El mensaje del avatar está verificado? Sí cuando viene de la transcripción oficial de LiveAvatar
 * o cuando lo generó el propio servidor (motor económico); no cuando solo lo informó el navegador.
 */
function musa_mensaje_verificado($m) {
    return ($m['rol'] ?? '') !== 'avatar' || in_array($m['fuente'] ?? '', array('liveavatar', 'servidor'), true);
}

/** Quita direcciones web de un texto escrito por el visitante antes de enviarlo por correo. */
function musa_sin_enlaces($texto) {
    $texto = preg_replace('~\b(?:https?://|www\.)\S+~iu', '[enlace eliminado]', (string) $texto);
    return (string) preg_replace('~\b[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.(?:[a-z]{2,24})(?:/\S*)?\b~iu', '[enlace eliminado]', $texto);
}

/**
 * Copia de la conversación apta para el correo institucional: sin respuestas del avatar que no
 * estén verificadas y sin enlaces en ningún mensaje (evita que el correo oficial sirva para enviar
 * phishing a la dirección que el visitante haya escrito: también la respuesta de una IA se puede
 * manipular con la pregunta para que incluya una dirección).
 */
function musa_conversacion_para_correo($c) {
    $c['nombre'] = musa_sin_enlaces($c['nombre'] ?? '');
    $mensajes = array();
    foreach ((array) ($c['mensajes'] ?? array()) as $m) {
        if (!musa_mensaje_verificado($m)) { continue; }
        $m['texto'] = musa_sin_enlaces($m['texto'] ?? '');
        $mensajes[] = $m;
    }
    $c['mensajes'] = $mensajes;
    return $c;
}

/**
 * Reserva el turno del keep-alive: como máximo uno cada 25 segundos por conversación (el navegador
 * lo envía cada 60). La comprobación y la marca van en la misma transacción, así una avalancha de
 * peticiones en paralelo solo produce una llamada a LiveAvatar.
 */
function musa_conversacion_reservar_mantener($identificador) {
    return musa_conversaciones_transaccion(function ($datos) use ($identificador) {
        foreach ($datos['conversaciones'] as $i => $c) {
            if (!musa_conversacion_coincide($c, $identificador)) { continue; }
            $ultimo = strtotime((string) ($c['ultimo_mantener'] ?? ''));
            if ($ultimo !== false && $ultimo > time() - 25) { return array('retorno' => false); }
            $c['ultimo_mantener'] = date('Y-m-d H:i:s');
            $c['actualizado'] = $c['ultimo_mantener'];
            $datos['conversaciones'][$i] = $c;
            return array('datos' => $datos, 'retorno' => true);
        }
        return array('retorno' => false);
    });
}

/**
 * Reserva el turno de una pregunta del motor económico, de forma atómica: la conversación debe estar
 * activa, no superar el máximo de preguntas y dejar al menos $intervalo segundos entre preguntas.
 * $campo: 'preguntas_ia' (respuestas de la IA) o 'transcripciones' (audios para ElevenLabs Scribe).
 * Devuelve 'ok', 'cerrada', 'maximo' o 'rapido'.
 */
function musa_conversacion_reservar_pregunta($identificador, $maximo, $intervalo = 2, $campo = 'preguntas_ia') {
    $campo = $campo === 'transcripciones' ? 'transcripciones' : 'preguntas_ia';
    return musa_conversaciones_transaccion(function ($datos) use ($identificador, $maximo, $intervalo, $campo) {
        foreach ($datos['conversaciones'] as $i => $c) {
            if (!musa_conversacion_coincide($c, $identificador)) { continue; }
            $c = array_merge(musa_conversacion_base(), $c);
            if (!in_array($c['estado'], array('activa', 'iniciando'), true)) { return array('retorno' => 'cerrada'); }
            if ((int) $c[$campo] >= $maximo) { return array('retorno' => 'maximo'); }
            $marca = $campo === 'preguntas_ia' ? 'ultima_pregunta' : 'ultimo_audio';
            if ((int) ($c[$marca] ?? 0) > time() - $intervalo) { return array('retorno' => 'rapido'); }
            $c[$campo] = (int) $c[$campo] + 1;
            $c[$marca] = time();
            $c['actualizado'] = date('Y-m-d H:i:s');
            $datos['conversaciones'][$i] = $c;
            return array('datos' => $datos, 'retorno' => 'ok');
        }
        return array('retorno' => 'cerrada');
    });
}

/** Elimina una conversación. */
function musa_conversacion_eliminar($identificador) {
    return musa_conversaciones_transaccion(function ($datos) use ($identificador) {
        foreach ($datos['conversaciones'] as $i => $c) {
            if (!musa_conversacion_coincide($c, $identificador)) { continue; }
            array_splice($datos['conversaciones'], $i, 1);
            return array('datos' => $datos, 'retorno' => true);
        }
        return array('retorno' => false);
    });
}

/** Número de preguntas (mensajes de la persona). */
function musa_conversacion_preguntas($c) {
    $n = 0;
    foreach ((array) ($c['mensajes'] ?? array()) as $m) { if (($m['rol'] ?? '') === 'persona') { $n++; } }
    return $n;
}

/** Duración legible entre el inicio y el fin (o la última actualización). */
function musa_conversacion_duracion($c) {
    $inicio = strtotime((string) ($c['fecha'] ?? ''));
    $fin = strtotime((string) (($c['fecha_fin'] ?? '') !== '' ? $c['fecha_fin'] : ($c['actualizado'] ?? '')));
    if ($inicio === false || $fin === false || $fin < $inicio) { return '—'; }
    $s = $fin - $inicio;
    return sprintf('%d:%02d', floor($s / 60), $s % 60);
}

/**
 * Pares pregunta → respuesta. Las respuestas consecutivas del avatar se unen;
 * lo que el avatar dice antes de la primera pregunta queda como saludo.
 */
function musa_conversacion_pares($c) {
    $pares = array();
    $actual = null;
    foreach ((array) ($c['mensajes'] ?? array()) as $m) {
        if (($m['rol'] ?? '') === 'persona') {
            if ($actual !== null) { $pares[] = $actual; }
            $actual = array('pregunta' => (string) $m['texto'], 'respuesta' => '', 'hora' => (string) ($m['hora'] ?? ''), 'origen' => (string) ($m['origen'] ?? ''));
        } else {
            if ($actual === null) {
                $actual = array('pregunta' => '', 'respuesta' => '', 'hora' => (string) ($m['hora'] ?? ''), 'origen' => '');
            }
            $actual['respuesta'] = trim($actual['respuesta'] . ' ' . (string) $m['texto']);
        }
    }
    if ($actual !== null) { $pares[] = $actual; }
    return $pares;
}

/** Texto plano de la conversación (correo, exportaciones). */
function musa_conversacion_texto($c, $nombreAvatar = 'Anfitrión') {
    $lineas = array();
    foreach ((array) ($c['mensajes'] ?? array()) as $m) {
        $quien = ($m['rol'] ?? '') === 'persona' ? (($c['nombre'] ?? '') !== '' ? $c['nombre'] : 'Visitante') : $nombreAvatar;
        $lineas[] = $quien . ': ' . (string) $m['texto'];
    }
    return implode("\n", $lineas);
}

/**
 * Filtra, ordena y pagina las conversaciones del panel.
 * Filtros: busqueda, creado ('si'|'no'), enviado, estado, desde, hasta.
 */
function musa_conversaciones_filtrar($filtros = array(), $pagina = 1, $porPagina = 25) {
    $datos = musa_conversaciones_cargar();
    $busqueda = isset($filtros['busqueda']) ? mb_strtolower(trim((string) $filtros['busqueda']), 'UTF-8') : '';
    $creado   = (string) ($filtros['creado'] ?? '');
    $enviado  = (string) ($filtros['enviado'] ?? '');
    $estado   = (string) ($filtros['estado'] ?? '');
    $desde    = (string) ($filtros['desde'] ?? '');
    $hasta    = (string) ($filtros['hasta'] ?? '');

    $filtrados = array();
    foreach ($datos['conversaciones'] as $c) {
        $c = array_merge(musa_conversacion_base(), $c);
        if ($busqueda !== '') {
            $heno = $c['nombre'] . ' ' . $c['correo'] . ' ' . $c['codigo'] . ' ' . $c['ciudad'] . ' ' . $c['telefono'];
            foreach ($c['mensajes'] as $m) { $heno .= ' ' . (string) ($m['texto'] ?? ''); }
            if (strpos(mb_strtolower($heno, 'UTF-8'), $busqueda) === false) { continue; }
        }
        if ($creado === 'si' && empty($c['creado'])) { continue; }
        if ($creado === 'no' && !empty($c['creado'])) { continue; }
        if ($enviado === 'si' && empty($c['enviado'])) { continue; }
        if ($enviado === 'no' && !empty($c['enviado'])) { continue; }
        if ($estado !== '' && $c['estado'] !== $estado) { continue; }
        $fecha = substr($c['fecha'], 0, 10);
        if ($desde !== '' && $fecha < $desde) { continue; }
        if ($hasta !== '' && $fecha > $hasta) { continue; }
        $filtrados[] = $c;
    }

    $total = count($filtrados);
    $porPagina = max(5, (int) $porPagina);
    $paginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min(max(1, (int) $pagina), $paginas);

    return array(
        'conversaciones' => array_slice($filtrados, ($pagina - 1) * $porPagina, $porPagina),
        'total'     => $total,
        'pagina'    => $pagina,
        'paginas'   => $paginas,
        'porPagina' => $porPagina,
    );
}

/** Totales para el tablero del panel. */
function musa_conversaciones_resumen() {
    $datos = musa_conversaciones_cargar();
    $r = array('total' => 0, 'hoy' => 0, 'preguntas' => 0, 'creados' => 0, 'pendientes_creacion' => 0, 'enviados' => 0, 'pendientes_envio' => 0, 'errores' => 0, 'activas' => 0);
    $hoy = date('Y-m-d');
    foreach ($datos['conversaciones'] as $c) {
        $r['total']++;
        $r['preguntas'] += musa_conversacion_preguntas($c);
        if (!empty($c['creado'])) { $r['creados']++; } else { $r['pendientes_creacion']++; }
        if (!empty($c['enviado'])) { $r['enviados']++; } else { $r['pendientes_envio']++; }
        if (substr((string) ($c['fecha'] ?? ''), 0, 10) === $hoy) { $r['hoy']++; }
        if (($c['estado'] ?? '') === 'error') { $r['errores']++; }
        if (($c['estado'] ?? '') === 'activa') { $r['activas']++; }
    }
    return $r;
}

/** Conversaciones iniciadas desde una IP (o correo) en las últimas N horas. */
function musa_conversaciones_recientes($ip, $horas = 1) {
    $limite = time() - ($horas * 3600);
    $grupo = musa_ip_grupo($ip);
    $conteo = 0;
    foreach (musa_conversaciones_cargar()['conversaciones'] as $c) {
        $marca = strtotime((string) ($c['fecha'] ?? ''));
        if ($marca === false || $marca < $limite) { continue; }
        if ($grupo !== '' && musa_ip_grupo((string) ($c['ip'] ?? '')) === $grupo) { $conteo++; }
    }
    return $conteo;
}

/** Protege un valor para CSV (Excel no debe interpretarlo como fórmula). */
function musa_csv_valor($valor) {
    if (is_bool($valor)) { $valor = $valor ? 'SI' : 'NO'; }
    $valor = str_replace(array("\r", "\n"), ' ', (string) $valor);
    if ($valor !== '' && strpos("=+-@\t", $valor[0]) !== false) { $valor = "'" . $valor; }
    return '"' . str_replace('"', '""', $valor) . '"';
}

/**
 * Exporta a CSV (separador ; para Excel en español).
 * $modo 'conversaciones' = una fila por conversación; 'preguntas' = una fila por pregunta y respuesta.
 */
function musa_conversaciones_csv($filtros = array(), $modo = 'conversaciones') {
    $resultado = musa_conversaciones_filtrar($filtros, 1, PHP_INT_MAX);
    $lineas = array();

    if ($modo === 'preguntas') {
        $lineas[] = implode(';', array('Código', 'Fecha', 'Nombre', 'Correo', 'Municipio', 'N.º', 'Hora', 'Pregunta', 'Respuesta', 'Origen', 'Creado', 'Enviado'));
        foreach ($resultado['conversaciones'] as $c) {
            foreach (musa_conversacion_pares($c) as $n => $par) {
                $lineas[] = implode(';', array_map('musa_csv_valor', array(
                    $c['codigo'], $c['fecha'], $c['nombre'], $c['correo'], $c['ciudad'], $n + 1, $par['hora'],
                    $par['pregunta'] !== '' ? $par['pregunta'] : '(saludo)', $par['respuesta'], $par['origen'], !empty($c['creado']), !empty($c['enviado']),
                )));
            }
        }
    } else {
        $lineas[] = implode(';', array('Código', 'Fecha', 'Fin', 'Duración', 'Nombre', 'Correo', 'Teléfono', 'Municipio', 'Autoriza datos', 'Tema', 'Preguntas', 'Conversación', 'Estado', 'Creado', 'Fecha creado', 'Enviado', 'Fecha enviado', 'Notas', 'IP'));
        $avatar = musa_dato(musa_ajustes(), 'avatar.nombre', 'Anfitrión');
        foreach ($resultado['conversaciones'] as $c) {
            $lineas[] = implode(';', array_map('musa_csv_valor', array(
                $c['codigo'], $c['fecha'], $c['fecha_fin'], musa_conversacion_duracion($c), $c['nombre'], $c['correo'], $c['telefono'],
                $c['ciudad'], !empty($c['autorizacion']), $c['tema'], musa_conversacion_preguntas($c),
                str_replace("\n", ' | ', musa_conversacion_texto($c, $avatar)), $c['estado'],
                !empty($c['creado']), $c['fecha_creado'], !empty($c['enviado']), $c['fecha_enviado'], $c['notas'], $c['ip'],
            )));
        }
    }
    return "\xEF\xBB\xBF" . implode("\r\n", $lineas);
}

/** Exporta las conversaciones filtradas como JSON legible (sin claves internas). */
function musa_conversaciones_json($filtros = array()) {
    $resultado = musa_conversaciones_filtrar($filtros, 1, PHP_INT_MAX);
    $lista = array();
    foreach ($resultado['conversaciones'] as $c) {
        unset($c['clave']);
        foreach ($c['mensajes'] as &$m) { unset($m['ref']); }
        unset($m);
        $lista[] = $c;
    }
    return json_encode(array('exportado' => date('c'), 'total' => count($lista), 'conversaciones' => $lista), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
