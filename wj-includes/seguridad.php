<?php
/**
 * Musa Café · Seguridad
 * Sesiones, CSRF, control de intentos y autenticación del panel
 * contra el archivo wj-content/config/.htpasswd (formato de Apache, sirve también para .htaccess).
 *
 * El archivo vive en wj-content (la única carpeta escribible y fuera del repositorio).
 * Si no existe, el panel pide crear la cuenta de administrador en el primer ingreso.
 */
if (!defined('MUSA_ARRANQUE')) { http_response_code(403); exit('Acceso directo no permitido.'); }

define('MUSA_HTPASSWD', MUSA_DIR_CONFIG . '/.htpasswd');
define('MUSA_HTPASSWD_ANTERIOR', MUSA_ADMIN . '/.htpasswd');
define('MUSA_CODIGO_INSTALACION', MUSA_DIR_CONFIG . '/codigo-instalacion.php');
define('MUSA_CREDENCIALES_BLOQUEO', MUSA_DIR_CONFIG . '/credenciales.lock');

/** Trae las credenciales de la versión anterior (wj-admin/.htpasswd) si todavía no se movieron. */
function musa_htpasswd_migrar() {
    if (file_exists(MUSA_HTPASSWD) || !file_exists(MUSA_HTPASSWD_ANTERIOR)) { return; }
    $contenido = @file_get_contents(MUSA_HTPASSWD_ANTERIOR);
    if ($contenido !== false && trim($contenido) !== '' && @file_put_contents(MUSA_HTPASSWD, $contenido, LOCK_EX) !== false) {
        @chmod(MUSA_HTPASSWD, 0640);
        musa_log('Credenciales del panel movidas de wj-admin/.htpasswd a wj-content/config/.htpasswd');
    }
}

/**
 * Estado de las credenciales del panel:
 *  'sin_cuenta' = no existe el archivo (instalación recién descargada): se ofrece la configuración inicial.
 *  'ok'         = hay al menos un usuario válido.
 *  'danado'     = el archivo existe pero está vacío o no se puede leer. Falla cerrado: nunca se ofrece
 *                 crear otra cuenta encima; hay que restaurarlo o borrarlo desde el servidor.
 */
function musa_credenciales_estado() {
    musa_htpasswd_migrar();
    if (!file_exists(MUSA_HTPASSWD)) { return 'sin_cuenta'; }
    return musa_htpasswd_leer() !== array() ? 'ok' : 'danado';
}

/** ¿Ya existe (o existió) una cuenta de administrador? */
function musa_hay_administrador() {
    return musa_credenciales_estado() !== 'sin_cuenta';
}

/**
 * Código de instalación de un solo uso. Se escribe en wj-content/config/codigo-instalacion.php,
 * que la web no entrega (403): solo lo lee quien tiene acceso a los archivos del servidor
 * (Administrador de archivos de Plesk, FTP o SSH). Sin él nadie puede crear la primera cuenta.
 */
function musa_codigo_instalacion() {
    $datos = musa_leer_json(MUSA_CODIGO_INSTALACION, array());
    if (!empty($datos['codigo']) && is_string($datos['codigo'])) { return $datos['codigo']; }
    $crudo = strtoupper(bin2hex(random_bytes(8)));
    $codigo = implode('-', str_split($crudo, 4));
    musa_escribir_json(MUSA_CODIGO_INSTALACION, array(
        'codigo' => $codigo,
        'creado' => date('Y-m-d H:i:s'),
        'uso'    => 'Escribe este código en /wj-admin/ para crear la cuenta de administrador. Se borra al usarlo.',
    ));
    musa_log('Código de instalación del panel generado en wj-content/config/codigo-instalacion.php');
    return $codigo;
}

/** Compara el código de instalación sin distinguir mayúsculas, espacios ni guiones. */
function musa_codigo_instalacion_valido($recibido) {
    $normalizar = function ($v) { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $v)); };
    $esperado = $normalizar(musa_codigo_instalacion());
    return $esperado !== '' && hash_equals($esperado, $normalizar($recibido));
}

/**
 * Crea la primera cuenta de forma atómica: el bloqueo cubre la comprobación y la escritura, y el
 * archivo se abre en modo 'x' (falla si ya existe), así dos solicitudes simultáneas no se pisan.
 * Devuelve 'ok', 'existe' o 'error'.
 */
function musa_htpasswd_crear_primera($usuario, $clave) {
    $hash = password_hash((string) $clave, PASSWORD_BCRYPT);
    if ($hash === false) { return 'error'; }
    if (!is_dir(MUSA_DIR_CONFIG)) { @mkdir(MUSA_DIR_CONFIG, 0775, true); }
    $bloqueo = @fopen(MUSA_CREDENCIALES_BLOQUEO, 'c');
    if ($bloqueo === false || !@flock($bloqueo, LOCK_EX)) { return 'error'; }
    $resultado = 'error';
    if (musa_hay_administrador()) {
        $resultado = 'existe';
    } else {
        $archivo = @fopen(MUSA_HTPASSWD, 'x');
        if ($archivo !== false) {
            $contenido = "# Musa Café · credenciales del panel wj-admin\n# Generado el " . date('Y-m-d H:i:s') . " · cifrado bcrypt\n" . $usuario . ':' . $hash . "\n";
            $ok = @fwrite($archivo, $contenido) === strlen($contenido);
            @fclose($archivo);
            if ($ok) {
                @chmod(MUSA_HTPASSWD, 0640);
                @unlink(MUSA_CODIGO_INSTALACION);
                $resultado = 'ok';
            } else {
                @unlink(MUSA_HTPASSWD);
            }
        } else {
            $resultado = file_exists(MUSA_HTPASSWD) ? 'existe' : 'error';
        }
    }
    @flock($bloqueo, LOCK_UN);
    @fclose($bloqueo);
    return $resultado;
}

/**
 * Huella de la credencial vigente de un usuario. La sesión la guarda al entrar: si la contraseña
 * cambia o el archivo se restablece, las sesiones abiertas dejan de valer.
 */
function musa_credencial_version($usuario) {
    $usuarios = musa_htpasswd_leer();
    return isset($usuarios[$usuario]) ? sha1($usuario . ':' . $usuarios[$usuario]) : '';
}

/** Abre la sesión del administrador ligada a su credencial vigente. */
function musa_sesion_admin_abrir($usuario) {
    musa_sesion();
    session_regenerate_id(true);
    $_SESSION['musa_admin'] = $usuario;
    $_SESSION['musa_admin_hora'] = time();
    $_SESSION['musa_admin_version'] = musa_credencial_version($usuario);
}

/** Reglas mínimas de la contraseña del panel. Devuelve el error o '' si es válida. */
function musa_clave_debil($clave, $usuario = '') {
    $clave = (string) $clave;
    if (strlen($clave) < 10) { return 'La contraseña debe tener al menos 10 caracteres.'; }
    if (!preg_match('/[A-Za-z]/', $clave) || !preg_match('/[0-9]/', $clave)) { return 'La contraseña debe combinar letras y números.'; }
    if ($usuario !== '' && stripos($clave, (string) $usuario) !== false) { return 'La contraseña no puede contener el nombre de usuario.'; }
    return '';
}

/** Inicia la sesión con parámetros seguros. */
function musa_sesion() {
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    $seguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $parametros = array(
        'lifetime' => 0,
        'path'     => musa_url_base() === '' ? '/' : musa_url_base() . '/',
        'domain'   => '',
        'secure'   => $seguro,
        'httponly' => true,
        'samesite' => 'Lax',
    );
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($parametros);
    } else {
        session_set_cookie_params($parametros['lifetime'], $parametros['path'], $parametros['domain'], $parametros['secure'], $parametros['httponly']);
    }
    session_name('musa_sesion');
    @session_start();
}

/** Token CSRF de la sesión actual. */
function musa_token() {
    musa_sesion();
    if (empty($_SESSION['musa_token'])) {
        $_SESSION['musa_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['musa_token'];
}

/** Verifica el token CSRF recibido. */
function musa_token_valido($token) {
    musa_sesion();
    if (empty($_SESSION['musa_token']) || !is_string($token) || $token === '') { return false; }
    return hash_equals($_SESSION['musa_token'], $token);
}

/** Exige un token CSRF válido o termina con error. */
function musa_exigir_token($token, $json = false) {
    if (musa_token_valido($token)) { return; }
    if ($json) {
        musa_responder_json(array('ok' => false, 'mensaje' => 'La sesión expiró. Recarga la página e inténtalo de nuevo.'), 419);
    }
    http_response_code(419);
    exit('Token de seguridad inválido. Vuelve a cargar la página.');
}

/** Lee el archivo .htpasswd como arreglo usuario => hash. */
function musa_htpasswd_leer() {
    musa_htpasswd_migrar();
    $usuarios = array();
    if (!file_exists(MUSA_HTPASSWD)) { return $usuarios; }
    $lineas = file(MUSA_HTPASSWD, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lineas === false) { return $usuarios; }
    foreach ($lineas as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#') { continue; }
        $posicion = strpos($linea, ':');
        if ($posicion === false) { continue; }
        $usuarios[substr($linea, 0, $posicion)] = substr($linea, $posicion + 1);
    }
    return $usuarios;
}

/** Guarda un usuario en .htpasswd con cifrado bcrypt. */
function musa_htpasswd_guardar($usuario, $clave) {
    $usuario = preg_replace('/[^A-Za-z0-9._@\-]/', '', (string) $usuario);
    if ($usuario === '' || strlen((string) $clave) < 8) { return false; }
    $hash = password_hash($clave, PASSWORD_BCRYPT);
    if ($hash === false) { return false; }
    if (!is_dir(dirname(MUSA_HTPASSWD))) { @mkdir(dirname(MUSA_HTPASSWD), 0775, true); }
    $contenido = "# Musa Café · credenciales del panel wj-admin\n";
    $contenido .= "# Generado el " . date('Y-m-d H:i:s') . " · cifrado bcrypt\n";
    $contenido .= $usuario . ':' . $hash . "\n";
    $bloqueo = @fopen(MUSA_CREDENCIALES_BLOQUEO, 'c');
    if ($bloqueo !== false) { @flock($bloqueo, LOCK_EX); }
    $ok = @file_put_contents(MUSA_HTPASSWD, $contenido, LOCK_EX) !== false;
    if ($bloqueo !== false) { @flock($bloqueo, LOCK_UN); @fclose($bloqueo); }
    if ($ok) { @chmod(MUSA_HTPASSWD, 0640); }
    return $ok;
}

/** Comprueba una clave contra un hash de .htpasswd (bcrypt, APR1, SHA1, crypt o texto plano). */
function musa_htpasswd_comprobar($clave, $hash) {
    $clave = (string) $clave;
    $hash = (string) $hash;
    if ($hash === '') { return false; }

    if (strpos($hash, '$2y$') === 0 || strpos($hash, '$2a$') === 0 || strpos($hash, '$2b$') === 0) {
        return password_verify($clave, $hash);
    }
    if (strpos($hash, '$apr1$') === 0) {
        return hash_equals($hash, musa_apr1($clave, $hash));
    }
    if (strpos($hash, '{SHA}') === 0) {
        return hash_equals($hash, '{SHA}' . base64_encode(sha1($clave, true)));
    }
    if (strpos($hash, '$1$') === 0 || strpos($hash, '$5$') === 0 || strpos($hash, '$6$') === 0 || strlen($hash) === 13) {
        return hash_equals($hash, (string) crypt($clave, $hash));
    }
    return hash_equals($hash, $clave);
}

/** Implementación del algoritmo APR1-MD5 de Apache. */
function musa_apr1($clave, $hash) {
    $partes = explode('$', $hash);
    if (count($partes) < 4) { return ''; }
    $sal = substr($partes[2], 0, 8);

    $texto = $clave . '$apr1$' . $sal;
    $bin = pack('H32', md5($clave . $sal . $clave));
    for ($i = strlen($clave); $i > 0; $i -= 16) {
        $texto .= substr($bin, 0, min(16, $i));
    }
    for ($i = strlen($clave); $i > 0; $i >>= 1) {
        $texto .= ($i & 1) ? chr(0) : $clave[0];
    }
    $bin = pack('H32', md5($texto));
    for ($i = 0; $i < 1000; $i++) {
        $nuevo = ($i & 1) ? $clave : $bin;
        if ($i % 3) { $nuevo .= $sal; }
        if ($i % 7) { $nuevo .= $clave; }
        $nuevo .= ($i & 1) ? $bin : $clave;
        $bin = pack('H32', md5($nuevo));
    }

    $alfabeto = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $resultado = '';
    for ($i = 0; $i < 5; $i++) {
        $k = $i + 6;
        $j = $i + 12;
        if ($j === 16) { $j = 5; }
        $resultado = $bin[$i] . $bin[$k] . $bin[$j] . $resultado;
    }
    $resultado = chr(0) . chr(0) . $bin[11] . $resultado;
    $resultado = strtr(
        strrev(substr(base64_encode($resultado), 2)),
        'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/',
        $alfabeto
    );
    return '$apr1$' . $sal . '$' . $resultado;
}

/** Ruta del archivo de intentos fallidos. */
function musa_archivo_intentos() { return MUSA_DIR_LOGS . '/intentos.json.php'; }

/** ¿La IP está bloqueada por intentos fallidos? Devuelve segundos restantes o 0. */
function musa_bloqueo_restante($ip) {
    $intentos = musa_leer_json(musa_archivo_intentos(), array());
    if (!isset($intentos[$ip])) { return 0; }
    $registro = $intentos[$ip];
    if (($registro['fallos'] ?? 0) < 8) { return 0; }
    $restante = (int) (($registro['ultimo'] ?? 0) + 900) - time();
    return $restante > 0 ? $restante : 0;
}

/** Suma o reinicia los intentos fallidos de una IP. */
function musa_registrar_intento($ip, $fallido) {
    $intentos = musa_leer_json(musa_archivo_intentos(), array());
    foreach ($intentos as $clave => $registro) {
        if ((int) ($registro['ultimo'] ?? 0) < time() - 86400) { unset($intentos[$clave]); }
    }
    if ($fallido) {
        $actual = isset($intentos[$ip]) ? (int) $intentos[$ip]['fallos'] : 0;
        $intentos[$ip] = array('fallos' => $actual + 1, 'ultimo' => time());
    } else {
        unset($intentos[$ip]);
    }
    musa_escribir_json(musa_archivo_intentos(), $intentos);
}

/** Valida un par usuario/clave contra .htpasswd. */
function musa_credenciales_validas($usuario, $clave) {
    $usuarios = musa_htpasswd_leer();
    if ($usuarios === array()) { return false; }
    if (!isset($usuarios[$usuario])) { return false; }
    return musa_htpasswd_comprobar($clave, $usuarios[$usuario]);
}

/** Usuario autenticado por Apache (Basic Auth de .htaccess), si lo hay. */
function musa_usuario_apache() {
    if (!empty($_SERVER['PHP_AUTH_USER'])) {
        return array($_SERVER['PHP_AUTH_USER'], isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '');
    }
    $cabecera = '';
    foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $llave) {
        if (!empty($_SERVER[$llave])) { $cabecera = $_SERVER[$llave]; break; }
    }
    if ($cabecera !== '' && stripos($cabecera, 'basic ') === 0) {
        $decodificado = base64_decode(substr($cabecera, 6), true);
        if ($decodificado !== false && strpos($decodificado, ':') !== false) {
            return explode(':', $decodificado, 2);
        }
    }
    return null;
}

/** Exige sesión de administrador; si no la hay, envía al formulario de acceso. */
function musa_exigir_admin() {
    musa_sesion();

    // Sin cuenta (instalación recién descargada) o archivo dañado: acceso.php explica qué hacer.
    if (musa_credenciales_estado() !== 'ok') {
        unset($_SESSION['musa_admin'], $_SESSION['musa_admin_hora'], $_SESSION['musa_admin_version']);
        $destino = musa_url('wj-admin/acceso.php');
        if (!headers_sent()) { header('Location: ' . $destino); }
        exit('<a href="' . musa_e($destino) . '">Configurar el panel</a>');
    }

    // Credenciales enviadas por cabecera (las valide Apache o las mande el navegador):
    // pasan por el mismo bloqueo por intentos y quedan registradas, igual que el formulario.
    $apache = musa_usuario_apache();
    if ($apache !== null) {
        $ip = musa_ip();
        if (musa_bloqueo_restante($ip) > 0) {
            http_response_code(429);
            exit('Demasiados intentos fallidos. Inténtalo de nuevo en unos minutos.');
        }
        if (musa_credenciales_validas($apache[0], $apache[1])) {
            musa_registrar_intento($ip, false);
            if (empty($_SESSION['musa_admin']) || $_SESSION['musa_admin'] !== $apache[0]) {
                musa_sesion_admin_abrir($apache[0]);
                musa_log('Acceso al panel por cabecera', array('usuario' => $apache[0], 'ip' => $ip));
            }
            $_SESSION['musa_admin_hora'] = time();
            $_SESSION['musa_admin_version'] = musa_credencial_version($apache[0]);
            return $apache[0];
        }
        musa_registrar_intento($ip, true);
        musa_log('Acceso fallido al panel por cabecera', array('usuario' => $apache[0], 'ip' => $ip));
    }

    if (!empty($_SESSION['musa_admin'])) {
        $inactividad = time() - (int) ($_SESSION['musa_admin_hora'] ?? 0);
        // La sesión solo vale mientras la credencial con la que se abrió siga igual en .htpasswd.
        $version = musa_credencial_version((string) $_SESSION['musa_admin']);
        $vigente = $version !== '' && hash_equals($version, (string) ($_SESSION['musa_admin_version'] ?? ''));
        if ($inactividad < 7200 && $vigente) {
            $_SESSION['musa_admin_hora'] = time();
            return $_SESSION['musa_admin'];
        }
        unset($_SESSION['musa_admin'], $_SESSION['musa_admin_hora'], $_SESSION['musa_admin_version']);
    }

    $destino = musa_url('wj-admin/acceso.php');
    if (!headers_sent()) {
        header('Location: ' . $destino);
    }
    exit('<a href="' . musa_e($destino) . '">Iniciar sesión</a>');
}

/** ¿El visitante superó el límite de conversaciones configurado? */
function musa_limite_superado($correo, $ip) {
    $ajustes = musa_ajustes();
    $porHora = (int) musa_dato($ajustes, 'seguridad.limite_por_hora', 5);
    $porDia  = (int) musa_dato($ajustes, 'seguridad.limite_por_dia', 20);
    if ($porHora > 0 && musa_conversaciones_recientes($correo, $ip, 1) >= $porHora) { return true; }
    if ($porDia > 0 && musa_conversaciones_recientes($correo, $ip, 24) >= $porDia) { return true; }
    return false;
}

/**
 * Cabeceras de seguridad para las páginas del sistema.
 * La página pública necesita el micrófono ($microfono = true) para hablar con el avatar.
 */
function musa_cabeceras_seguridad($microfono = false) {
    if (headers_sent()) { return; }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=' . ($microfono ? '(self)' : '()'));
}
