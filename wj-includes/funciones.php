<?php
/**
 * Musa Café · Funciones de apoyo
 */
if (!defined('MUSA_ARRANQUE')) { http_response_code(403); exit('Acceso directo no permitido.'); }

/** Escapa texto para HTML. */
function musa_e($texto) {
    return htmlspecialchars((string) $texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Lee un valor anidado con notación de puntos: musa_dato($a, 'ia.google.api_key', ''). */
function musa_dato($arreglo, $ruta, $porDefecto = null) {
    $partes = explode('.', $ruta);
    $actual = $arreglo;
    foreach ($partes as $parte) {
        if (is_array($actual) && array_key_exists($parte, $actual)) {
            $actual = $actual[$parte];
        } else {
            return $porDefecto;
        }
    }
    return $actual;
}

/** Escribe un valor anidado con notación de puntos. */
function musa_fijar(&$arreglo, $ruta, $valor) {
    $partes = explode('.', $ruta);
    $actual = &$arreglo;
    foreach ($partes as $parte) {
        if (!isset($actual[$parte]) || !is_array($actual[$parte])) {
            if (!isset($actual[$parte])) { $actual[$parte] = array(); }
        }
        $actual = &$actual[$parte];
    }
    $actual = $valor;
}

/** Combina recursivamente los ajustes guardados sobre los predeterminados. */
function musa_combinar($base, $encima) {
    foreach ($encima as $clave => $valor) {
        if (is_array($valor) && isset($base[$clave]) && is_array($base[$clave])
            && !musa_es_lista($valor) && !musa_es_lista($base[$clave])) {
            $base[$clave] = musa_combinar($base[$clave], $valor);
        } else {
            $base[$clave] = $valor;
        }
    }
    return $base;
}

/** ¿El arreglo es una lista secuencial? */
function musa_es_lista($arreglo) {
    if (!is_array($arreglo)) { return false; }
    if ($arreglo === array()) { return true; }
    return array_keys($arreglo) === range(0, count($arreglo) - 1);
}

/** Crea las carpetas de contenido si no existen (primer arranque en Plesk). */
function musa_preparar_carpetas() {
    $carpetas = array(MUSA_CONTENT, MUSA_DIR_CONFIG, MUSA_DIR_DATOS, MUSA_DIR_SUBIDAS, MUSA_DIR_LOGS);
    foreach ($carpetas as $carpeta) {
        if (!is_dir($carpeta)) { @mkdir($carpeta, 0775, true); }
    }
    $protegidas = array(MUSA_DIR_CONFIG, MUSA_DIR_DATOS, MUSA_DIR_LOGS);
    foreach ($protegidas as $carpeta) {
        $htaccess = $carpeta . '/.htaccess';
        if (is_dir($carpeta) && !file_exists($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
        }
    }
}

/** URL base de la instalación (funciona en subcarpetas de Plesk). */
function musa_url_base() {
    static $base = null;
    if ($base !== null) { return $base; }

    $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '/index.php';
    $directorio = rtrim(dirname($script), '/');

    $archivo = isset($_SERVER['SCRIPT_FILENAME']) ? str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'])) : '';
    $raiz = str_replace('\\', '/', (string) realpath(MUSA_RAIZ));
    if ($archivo !== '' && $raiz !== '' && strpos($archivo, $raiz) === 0) {
        $relativo = trim(substr($archivo, strlen($raiz)), '/');
        $niveles = substr_count($relativo, '/');
        for ($i = 0; $i < $niveles; $i++) {
            $directorio = rtrim(dirname($directorio), '/');
            if ($directorio === '.' || $directorio === '\\') { $directorio = ''; }
        }
    }
    $base = ($directorio === '/' ) ? '' : $directorio;
    return $base;
}

/** Construye una URL pública a partir de una ruta relativa al proyecto. */
function musa_url($ruta = '') {
    $ruta = ltrim((string) $ruta, '/');
    $base = musa_url_base();
    return ($base === '' ? '' : $base) . '/' . $ruta;
}

/** URL de un recurso con marca de versión para evitar caché. */
function musa_recurso($ruta) {
    $absoluta = MUSA_RAIZ . '/' . ltrim($ruta, '/');
    $version = file_exists($absoluta) ? (string) filemtime($absoluta) : MUSA_VERSION;
    return musa_url($ruta) . '?v=' . $version;
}

/**
 * Línea que encabeza los archivos de datos con extensión .php.
 * Si el servidor no aplica .htaccess (por ejemplo nginx sin Apache),
 * al pedir el archivo por la web PHP lo ejecuta y no devuelve nada.
 */
define('MUSA_GUARDIA', "<?php http_response_code(403); exit; ?>\n");

/** Lee un JSON del disco (soporta archivos protegidos con la guardia PHP). */
function musa_leer_json($archivo, $porDefecto = array()) {
    if (!file_exists($archivo)) { return $porDefecto; }
    $crudo = @file_get_contents($archivo);
    if ($crudo === false || trim($crudo) === '') { return $porDefecto; }
    if (strncmp($crudo, '<?php', 5) === 0) {
        $salto = strpos($crudo, "\n");
        $crudo = $salto === false ? '' : substr($crudo, $salto + 1);
    }
    $datos = json_decode($crudo, true);
    return is_array($datos) ? $datos : $porDefecto;
}

/** Escribe un JSON en disco de forma atómica y con bloqueo. */
function musa_escribir_json($archivo, $datos) {
    $carpeta = dirname($archivo);
    if (!is_dir($carpeta)) { @mkdir($carpeta, 0775, true); }
    $json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { return false; }
    if (substr($archivo, -4) === '.php') { $json = MUSA_GUARDIA . $json; }
    $temporal = $archivo . '.' . getmypid() . '.tmp';
    if (@file_put_contents($temporal, $json, LOCK_EX) === false) { return false; }
    if (!@rename($temporal, $archivo)) { @unlink($temporal); return false; }
    @chmod($archivo, 0664);
    return true;
}

/** Registra un evento en el log diario. */
function musa_log($mensaje, $contexto = array()) {
    if (!is_dir(MUSA_DIR_LOGS)) { @mkdir(MUSA_DIR_LOGS, 0775, true); }
    $linea = '[' . date('Y-m-d H:i:s') . '] ' . $mensaje;
    if (!empty($contexto)) {
        $linea .= ' ' . json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $archivo = MUSA_DIR_LOGS . '/musa-' . date('Y-m') . '.log.php';
    if (!file_exists($archivo)) { @file_put_contents($archivo, MUSA_GUARDIA, LOCK_EX); }
    @file_put_contents($archivo, $linea . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** Responde en JSON y termina la ejecución. */
function musa_responder_json($datos, $codigo = 200) {
    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Lee el cuerpo JSON de una petición. */
function musa_cuerpo_json() {
    $crudo = file_get_contents('php://input');
    if ($crudo === false || trim($crudo) === '') { return array(); }
    $datos = json_decode($crudo, true);
    return is_array($datos) ? $datos : array();
}

/** Limpia una cadena enviada por el usuario. */
function musa_texto($valor, $maximo = 500) {
    $valor = is_scalar($valor) ? (string) $valor : '';
    $valor = str_replace(array("\r\n", "\r"), "\n", $valor);
    $valor = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $valor);
    $valor = trim($valor);
    if (function_exists('mb_substr')) { return mb_substr($valor, 0, $maximo, 'UTF-8'); }
    return substr($valor, 0, $maximo);
}

/** Valida un correo electrónico. */
function musa_correo_valido($correo) {
    return (bool) filter_var((string) $correo, FILTER_VALIDATE_EMAIL);
}

/** Convierte un texto en identificador seguro (slug). */
function musa_slug($texto) {
    $texto = (string) $texto;
    $desde = array('á','é','í','ó','ú','ü','ñ','Á','É','Í','Ó','Ú','Ü','Ñ','ç','Ç');
    $hasta = array('a','e','i','o','u','u','n','a','e','i','o','u','u','n','c','c');
    $texto = str_replace($desde, $hasta, $texto);
    $texto = strtolower($texto);
    $texto = preg_replace('/[^a-z0-9]+/', '-', $texto);
    return trim((string) $texto, '-');
}

/**
 * Devuelve la IP del visitante.
 * Las cabeceras X-Real-IP o CF-Connecting-IP las escribe quien hace la petición, así que
 * solo se tienen en cuenta si la conexión llega desde un proxy declarado como confiable
 * en los ajustes (seguridad.proxies_confiables). En cualquier otro caso manda REMOTE_ADDR.
 */
function musa_ip() {
    $directa = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    if (!filter_var($directa, FILTER_VALIDATE_IP)) { $directa = '0.0.0.0'; }

    $confiables = musa_dato(musa_ajustes(), 'seguridad.proxies_confiables', array());
    if (!is_array($confiables) || !in_array($directa, $confiables, true)) {
        return $directa;
    }
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR') as $llave) {
        if (empty($_SERVER[$llave])) { continue; }
        $primera = trim(explode(',', (string) $_SERVER[$llave])[0]);
        if (filter_var($primera, FILTER_VALIDATE_IP)) { return $primera; }
    }
    return $directa;
}

/** Valida que una ruta de imagen esté dentro del proyecto y sea un archivo real. */
function musa_ruta_imagen_valida($ruta) {
    $ruta = ltrim(str_replace('\\', '/', (string) $ruta), '/');
    if ($ruta === '' || strpos($ruta, '..') !== false) { return false; }
    $permitidas = array('wj-includes/images/', 'wj-content/subidas/');
    $ok = false;
    foreach ($permitidas as $prefijo) {
        if (strpos($ruta, $prefijo) === 0) { $ok = true; break; }
    }
    if (!$ok) { return false; }
    return file_exists(MUSA_RAIZ . '/' . $ruta);
}

/** Color hexadecimal válido (#RGB o #RRGGBB); devuelve null si no lo es. */
function musa_color($valor, $porDefecto = null) {
    $valor = trim((string) $valor);
    if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $valor)) { return strtoupper($valor); }
    return $porDefecto;
}

/** Convierte #RRGGBB a "r, g, b". */
function musa_color_rgb($hex, $porDefecto = '174, 29, 44') {
    $hex = musa_color($hex);
    if ($hex === null) { return $porDefecto; }
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) { $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
    return hexdec(substr($hex, 0, 2)) . ', ' . hexdec(substr($hex, 2, 2)) . ', ' . hexdec(substr($hex, 4, 2));
}

/** Petición HTTP con cURL y respaldo en streams. */
function musa_http($url, $opciones = array()) {
    $metodo = isset($opciones['metodo']) ? strtoupper($opciones['metodo']) : 'GET';
    $cabeceras = isset($opciones['cabeceras']) ? $opciones['cabeceras'] : array();
    $cuerpo = isset($opciones['cuerpo']) ? $opciones['cuerpo'] : null;
    $tiempo = isset($opciones['tiempo']) ? (int) $opciones['tiempo'] : 30;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $metodo);
        curl_setopt($ch, CURLOPT_TIMEOUT, $tiempo);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (!empty($cabeceras)) { curl_setopt($ch, CURLOPT_HTTPHEADER, $cabeceras); }
        if ($cuerpo !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo); }
        $respuesta = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tipo = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        return array(
            'ok'     => ($respuesta !== false && $codigo >= 200 && $codigo < 300),
            'codigo' => $codigo,
            'cuerpo' => ($respuesta === false ? '' : $respuesta),
            'tipo'   => $tipo,
            'error'  => $error,
        );
    }

    $contexto = stream_context_create(array(
        'http' => array(
            'method'        => $metodo,
            'header'        => implode("\r\n", $cabeceras),
            'content'       => $cuerpo,
            'timeout'       => $tiempo,
            'ignore_errors' => true,
        ),
        'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
    ));
    $respuesta = @file_get_contents($url, false, $contexto);
    $codigo = 0; $tipo = '';
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $cabecera) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $cabecera, $m)) { $codigo = (int) $m[1]; }
            if (stripos($cabecera, 'content-type:') === 0) { $tipo = trim(substr($cabecera, 13)); }
        }
    }
    return array(
        'ok'     => ($respuesta !== false && $codigo >= 200 && $codigo < 300),
        'codigo' => $codigo,
        'cuerpo' => ($respuesta === false ? '' : $respuesta),
        'tipo'   => $tipo,
        'error'  => ($respuesta === false ? 'No fue posible conectar con el servicio.' : ''),
    );
}

/** Oculta una clave de API dejando visibles los últimos caracteres. */
function musa_enmascarar_clave($clave) {
    $clave = (string) $clave;
    $largo = strlen($clave);
    if ($largo === 0) { return ''; }
    if ($largo <= 8) { return str_repeat('•', $largo); }
    return substr($clave, 0, 4) . str_repeat('•', 12) . substr($clave, -4);
}

/** Tamaño legible de un archivo. */
function musa_peso($bytes) {
    $bytes = (float) $bytes;
    if ($bytes <= 0) { return '0 KB'; }
    if ($bytes < 1024 * 1024) { return round($bytes / 1024) . ' KB'; }
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

/**
 * Igual que musa_http(), pero reintenta cuando el servicio responde
 * 429 (cupo) o 5xx (saturación temporal).
 */
function musa_http_reintento($url, $opciones = array(), $intentos = 3, $espera = 3) {
    $respuesta = null;
    for ($i = 0; $i < max(1, (int) $intentos); $i++) {
        $respuesta = musa_http($url, $opciones);
        if ($respuesta['ok']) { return $respuesta; }
        $codigo = (int) $respuesta['codigo'];
        $reintentable = ($codigo === 429 || $codigo === 0 || ($codigo >= 500 && $codigo < 600));
        if (!$reintentable || $i === $intentos - 1) { return $respuesta; }
        sleep($espera * ($i + 1));
    }
    return $respuesta;
}

/**
 * Límite sencillo de uso por IP guardado en wj-content/logs.
 * Devuelve true si se superó el límite en la última hora.
 */
function musa_limite_uso($clave, $ip, $maximo) {
    if ($maximo <= 0) { return false; }
    $archivo = MUSA_DIR_LOGS . '/uso-' . musa_slug($clave) . '.json.php';
    $datos = musa_leer_json($archivo, array());
    $ahora = time();
    foreach ($datos as $llave => $marcas) {
        $datos[$llave] = array_values(array_filter((array) $marcas, function ($m) use ($ahora) {
            return (int) $m > $ahora - 3600;
        }));
        if ($datos[$llave] === array()) { unset($datos[$llave]); }
    }
    $propias = isset($datos[$ip]) ? $datos[$ip] : array();
    if (count($propias) >= $maximo) {
        musa_escribir_json($archivo, $datos);
        return true;
    }
    $propias[] = $ahora;
    $datos[$ip] = $propias;
    musa_escribir_json($archivo, $datos);
    return false;
}
