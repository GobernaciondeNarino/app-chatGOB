<?php
/**
 * Musa Café · Almacenamiento de conversaciones en JSON
 * Archivo: wj-content/datos/conversaciones.json.php
 *
 * Cada conversación guarda los datos de la persona (si el formulario de inicio
 * está activo), las preguntas y respuestas en orden, y las casillas
 * "Creado SÍ/NO" y "Enviado SÍ/NO" que se gestionan desde wj-admin.
 */
if (!defined('MUSA_ARRANQUE')) { http_response_code(403); exit('Acceso directo no permitido.'); }

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
    if ($puntero !== false) { @flock($puntero, LOCK_EX); }
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
        'session_id'     => '',
        'estado'         => 'iniciando',   // iniciando | activa | finalizada | error
        'motivo_fin'     => '',
        'mensajes'       => array(),       // [{rol: persona|avatar, texto, hora, origen: voz|texto}]
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

/** Crea una conversación nueva y la devuelve. */
function musa_conversacion_crear($campos) {
    $prefijo = musa_dato(musa_ajustes(), 'sistema.prefijo_codigo', 'CAFE');
    return musa_conversaciones_transaccion(function ($datos) use ($campos, $prefijo) {
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
            foreach ($c['mensajes'] as $m) { if (!empty($m['ref'])) { $vistos[$m['ref']] = true; } }
            foreach ($mensajes as $m) {
                if (count($c['mensajes']) >= $maximo) { break; }
                if (!empty($m['ref']) && isset($vistos[$m['ref']])) { continue; }
                $c['mensajes'][] = array(
                    'rol'    => $m['rol'],
                    'texto'  => $m['texto'],
                    'origen' => isset($m['origen']) ? $m['origen'] : 'voz',
                    'hora'   => isset($m['hora']) && $m['hora'] !== '' ? $m['hora'] : date('Y-m-d H:i:s'),
                    'ref'    => isset($m['ref']) ? $m['ref'] : '',
                );
                if (!empty($m['ref'])) { $vistos[$m['ref']] = true; }
            }
            if ($c['estado'] === 'iniciando') { $c['estado'] = 'activa'; }
            $c['actualizado'] = date('Y-m-d H:i:s');
            $datos['conversaciones'][$i] = $c;
            return array('datos' => $datos, 'retorno' => count($c['mensajes']));
        }
        return array('retorno' => null);
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
function musa_conversaciones_recientes($correo, $ip, $horas = 1) {
    $limite = time() - ($horas * 3600);
    $conteo = 0;
    foreach (musa_conversaciones_cargar()['conversaciones'] as $c) {
        $marca = strtotime((string) ($c['fecha'] ?? ''));
        if ($marca === false || $marca < $limite) { continue; }
        if (($correo !== '' && strcasecmp((string) ($c['correo'] ?? ''), $correo) === 0)
            || ($ip !== '' && (string) ($c['ip'] ?? '') === $ip)) {
            $conteo++;
        }
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
