<?php
/**
 * QuéDice! · Seguridad
 * Sesiones, CSRF, control de intentos y autenticación del panel
 * contra el archivo wj-content/config/.htpasswd (formato de Apache, sirve también para .htaccess).
 *
 * El archivo vive en wj-content (la única carpeta escribible y fuera del repositorio).
 * Si no existe, el panel pide crear la cuenta de administrador en el primer ingreso.
 */
if (!defined('MUSA_ARRANQUE')) { http_response_code(403); exit('Acceso directo no permitido.'); }

/*
 * Credenciales en wj-content/config/.htpasswd.php, con formato de Apache y una primera línea
 * «#<?php http_response_code(403); exit; ?>»: para Apache (AuthUserFile) y para el panel es un
 * comentario; si un servidor solo nginx lo sirviera, PHP lo ejecuta y responde 403 sin mostrar
 * los hashes. Se migran solas las ubicaciones anteriores.
 */
define('MUSA_HTPASSWD', MUSA_DIR_CONFIG . '/.htpasswd.php');
define('MUSA_HTPASSWD_GUARDA', '#<?php http_response_code(403); exit; ?>');
define('MUSA_HTPASSWD_ANTERIOR', MUSA_ADMIN . '/.htpasswd');
define('MUSA_HTPASSWD_ANTERIOR_2', MUSA_DIR_CONFIG . '/.htpasswd');
/*
 * Huella SHA-256 de la contraseña de fábrica que publicó la versión 1 del README (el repositorio es
 * público, así que esa contraseña está quemada). Nunca se acepta, ni al entrar ni al migrar.
 */
define('MUSA_CLAVE_FABRICA_SHA256', '2408ddd27c78029e3f397c2f5e3a08b2f58ca1115e129a86c8d286a3268ef095');

/** ¿Es la contraseña de fábrica publicada? */
function musa_es_clave_fabrica($clave) {
    return hash_equals(MUSA_CLAVE_FABRICA_SHA256, hash('sha256', (string) $clave));
}
define('MUSA_CODIGO_INSTALACION', MUSA_DIR_CONFIG . '/codigo-instalacion.php');
define('MUSA_CREDENCIALES_BLOQUEO', MUSA_DIR_CONFIG . '/credenciales.lock');

/** Trae las credenciales de versiones anteriores (wj-admin/.htpasswd o wj-content/config/.htpasswd). */
function musa_htpasswd_migrar() {
    if (file_exists(MUSA_HTPASSWD)) { return; }
    foreach (array(MUSA_HTPASSWD_ANTERIOR_2, MUSA_HTPASSWD_ANTERIOR) as $anterior) {
        if (!file_exists($anterior)) { continue; }
        $contenido = @file_get_contents($anterior);
        // Solo se trae si tiene al menos una línea usuario:hash; un archivo vacío o de ejemplo no
        // debe convertirse en las credenciales vigentes (dejaría el panel bloqueado).
        if ($contenido === false || !preg_match('/^[^#\s:][^:]*:\S+/m', $contenido)) { continue; }
        if (@file_put_contents(MUSA_HTPASSWD, MUSA_HTPASSWD_GUARDA . "\n" . $contenido, LOCK_EX) !== false) {
            @chmod(MUSA_HTPASSWD, 0640);
            // La copia vieja no tiene la línea de guarda y queda en una carpeta web: se borra.
            @unlink($anterior);
            musa_log('Credenciales del panel movidas a wj-content/config/.htpasswd.php', array('desde' => str_replace(MUSA_RAIZ . '/', '', $anterior)));
        }
        return;
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
            $contenido = MUSA_HTPASSWD_GUARDA . "\n# QuéDice! · credenciales del panel wj-admin\n# Generado el " . date('Y-m-d H:i:s') . " · cifrado bcrypt\n" . $usuario . ':' . $hash . "\n";
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
    // Token CSRF nuevo: el que existía antes de entrar pudo haberlo visto otra persona.
    unset($_SESSION['musa_token']);
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
    $seguro = musa_es_https();
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
    // Modo estricto: PHP no acepta identificadores de sesión que no haya creado él (fijación).
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
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

/**
 * Guarda (o renombra) un usuario en el archivo de credenciales con cifrado bcrypt, conservando a los
 * demás usuarios. $anterior = nombre actual cuando la persona cambia su usuario.
 */
function musa_htpasswd_guardar($usuario, $clave, $anterior = null) {
    $usuario = preg_replace('/[^A-Za-z0-9._@\-]/', '', (string) $usuario);
    if ($usuario === '' || strlen((string) $clave) < 8) { return false; }
    $hash = password_hash($clave, PASSWORD_BCRYPT);
    if ($hash === false) { return false; }
    return musa_htpasswd_escribir_usuario($usuario, $hash, $anterior);
}

/** Reescribe la línea de un usuario (con el hash ya calculado) bajo bloqueo, sin tocar a los demás. */
function musa_htpasswd_escribir_usuario($usuario, $hash, $anterior = null) {
    if (!is_dir(dirname(MUSA_HTPASSWD))) { @mkdir(dirname(MUSA_HTPASSWD), 0775, true); }
    $bloqueo = @fopen(MUSA_CREDENCIALES_BLOQUEO, 'c');
    if ($bloqueo !== false) { @flock($bloqueo, LOCK_EX); }
    $usuarios = musa_htpasswd_leer();
    if ($anterior !== null && $anterior !== $usuario) { unset($usuarios[$anterior]); }
    $usuarios[$usuario] = $hash;
    $contenido = MUSA_HTPASSWD_GUARDA . "\n# QuéDice! · credenciales del panel wj-admin\n";
    $contenido .= "# Actualizado el " . date('Y-m-d H:i:s') . " · cifrado bcrypt\n";
    foreach ($usuarios as $nombre => $valor) { $contenido .= $nombre . ':' . $valor . "\n"; }
    $temporal = MUSA_HTPASSWD . '.' . bin2hex(random_bytes(8)) . '.tmp.php';
    $ok = @file_put_contents($temporal, $contenido, LOCK_EX) !== false && @rename($temporal, MUSA_HTPASSWD);
    if (!$ok) { @unlink($temporal); }
    if ($bloqueo !== false) { @flock($bloqueo, LOCK_UN); @fclose($bloqueo); }
    if ($ok) { @chmod(MUSA_HTPASSWD, 0640); }
    return $ok;
}

/**
 * Comprueba una clave contra un hash. Solo se aceptan formatos con sal y costo: bcrypt ($2y$, $2a$,
 * $2b$) y APR1 ($apr1$, el de «htpasswd» sin -B). Texto plano, DES, SHA1 sin sal y demás se rechazan.
 */
function musa_htpasswd_comprobar($clave, $hash) {
    $clave = (string) $clave;
    $hash = (string) $hash;
    if (preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $hash)) {
        return password_verify($clave, $hash);
    }
    if (strpos($hash, '$apr1$') === 0) {
        return hash_equals($hash, musa_apr1($clave, $hash));
    }
    return false;
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

/**
 * Bloqueo por intentos fallidos: 8 por origen (IP o /64 de IPv6) cada 15 minutos y 40 en total
 * (ataque repartido entre muchas direcciones). Devuelve los segundos que faltan o 0.
 */
function musa_bloqueo_restante($ip, $intentos = null) {
    if ($intentos === null) { $intentos = musa_leer_json(musa_archivo_intentos(), array()); }
    $ahora = time();
    $grupo = 'ip:' . musa_ip_grupo($ip);
    $restante = 0;
    if (isset($intentos[$grupo]) && (int) ($intentos[$grupo]['fallos'] ?? 0) >= 8) {
        $restante = max($restante, (int) ($intentos[$grupo]['ultimo'] ?? 0) + 900 - $ahora);
    }
    $globales = array_values(array_filter((array) ($intentos['global'] ?? array()), function ($t) use ($ahora) { return (int) $t > $ahora - 900; }));
    if (count($globales) >= 40) {
        sort($globales);
        $restante = max($restante, (int) $globales[count($globales) - 40] + 900 - $ahora);
    }
    return $restante > 0 ? $restante : 0;
}

/** Ejecuta un cambio sobre el registro de intentos con bloqueo exclusivo (sin actualizaciones perdidas). */
function musa_intentos_transaccion($operacion) {
    if (!is_dir(MUSA_DIR_LOGS)) { @mkdir(MUSA_DIR_LOGS, 0775, true); }
    $puntero = @fopen(MUSA_DIR_LOGS . '/intentos.lock', 'c');
    if ($puntero !== false) { @flock($puntero, LOCK_EX); }
    $intentos = musa_leer_json(musa_archivo_intentos(), array());
    $ahora = time();
    foreach ($intentos as $clave => $registro) {
        if ($clave === 'global') { continue; }
        if ((int) ($registro['ultimo'] ?? 0) < $ahora - 86400) { unset($intentos[$clave]); }
    }
    $intentos['global'] = array_values(array_filter((array) ($intentos['global'] ?? array()), function ($t) use ($ahora) { return (int) $t > $ahora - 900; }));
    $resultado = $operacion($intentos);
    musa_escribir_json(musa_archivo_intentos(), $resultado['intentos']);
    if ($puntero !== false) { @flock($puntero, LOCK_UN); @fclose($puntero); }
    return $resultado['retorno'];
}

/**
 * Reserva un intento de acceso: si el origen o el total están bloqueados devuelve los segundos que
 * faltan; si no, lo cuenta como fallido ANTES de comprobar la contraseña (así las solicitudes en
 * paralelo no se cuelan) y devuelve 0. Un acierto posterior lo borra con musa_registrar_intento().
 */
function musa_reservar_intento($ip) {
    return musa_intentos_transaccion(function ($intentos) use ($ip) {
        $restante = musa_bloqueo_restante($ip, $intentos);
        if ($restante > 0) { return array('intentos' => $intentos, 'retorno' => $restante); }
        $grupo = 'ip:' . musa_ip_grupo($ip);
        $intentos[$grupo] = array('fallos' => (int) ($intentos[$grupo]['fallos'] ?? 0) + 1, 'ultimo' => time());
        $intentos['global'][] = time();
        return array('intentos' => $intentos, 'retorno' => 0);
    });
}

/** Tras un acierto borra los fallos del origen (y el intento reservado); un fallo ya quedó contado. */
function musa_registrar_intento($ip, $fallido) {
    if ($fallido) { return; }
    musa_intentos_transaccion(function ($intentos) use ($ip) {
        unset($intentos['ip:' . musa_ip_grupo($ip)]);
        array_pop($intentos['global']);
        return array('intentos' => $intentos, 'retorno' => null);
    });
}

/**
 * Valida un par usuario/clave. Con un usuario inexistente también se ejecuta la comprobación contra
 * un hash real (mismo costo), así el tiempo de respuesta no revela qué usuarios existen. Si el hash
 * guardado no es bcrypt (APR1 de htpasswd), se reemplaza por bcrypt al acertar.
 */
function musa_credenciales_validas($usuario, $clave) {
    $usuarios = musa_htpasswd_leer();
    if ($usuarios === array()) { return false; }
    if (musa_es_clave_fabrica($clave)) {
        // Nunca se acepta. Si además coincide con la cuenta guardada, el panel explica cómo
        // restablecer el acceso (quien ya conoce la contraseña publicada no gana nada con saberlo).
        $hash = is_string($usuario) && isset($usuarios[$usuario]) ? $usuarios[$usuario] : (string) reset($usuarios);
        if (musa_htpasswd_comprobar((string) $clave, $hash) && is_string($usuario) && isset($usuarios[$usuario])) {
            $GLOBALS['musa_clave_fabrica_detectada'] = true;
        }
        musa_log('Se rechazó la contraseña de fábrica publicada', array('ip' => musa_ip()));
        return false;
    }
    if (!is_string($usuario) || !isset($usuarios[$usuario])) {
        musa_htpasswd_comprobar((string) $clave, (string) reset($usuarios));
        return false;
    }
    $ok = musa_htpasswd_comprobar($clave, $usuarios[$usuario]);
    if ($ok && strpos($usuarios[$usuario], '$2') !== 0) {
        $nuevo = password_hash((string) $clave, PASSWORD_BCRYPT);
        if ($nuevo !== false) { musa_htpasswd_escribir_usuario($usuario, $nuevo); }
    }
    return $ok;
}

/**
 * Usuario autenticado por Apache (AuthType Basic activado en wj-admin/.htaccess). Solo se tiene en
 * cuenta si Apache ya validó la petición (REMOTE_USER); una cabecera Authorization enviada a mano
 * contra un servidor sin esa protección se ignora (no abre una vía de adivinación sin token).
 */
function musa_usuario_apache() {
    $remoto = isset($_SERVER['REMOTE_USER']) ? (string) $_SERVER['REMOTE_USER'] : (isset($_SERVER['REDIRECT_REMOTE_USER']) ? (string) $_SERVER['REDIRECT_REMOTE_USER'] : '');
    if ($remoto === '') { return null; }
    if (!empty($_SERVER['PHP_AUTH_USER']) && $_SERVER['PHP_AUTH_USER'] === $remoto) {
        return array($remoto, isset($_SERVER['PHP_AUTH_PW']) ? (string) $_SERVER['PHP_AUTH_PW'] : '');
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
        if (musa_reservar_intento($ip) > 0) {
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
        musa_log('Acceso fallido al panel por cabecera', array('ip' => $ip));
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

/** ¿El visitante superó el límite de conversaciones configurado? (consulta rápida, sin bloqueo) */
function musa_limite_superado($ip) {
    $ajustes = musa_ajustes();
    $porHora = (int) musa_dato($ajustes, 'seguridad.limite_por_hora', 6);
    $porDia  = (int) musa_dato($ajustes, 'seguridad.limite_por_dia', 30);
    if ($porHora > 0 && musa_conversaciones_recientes($ip, 1) >= $porHora) { return true; }
    if ($porDia > 0 && musa_conversaciones_recientes($ip, 24) >= $porDia) { return true; }
    return false;
}

/** ¿La petición llegó por HTTPS (directo o a través del proxy de Plesk)? */
function musa_es_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'
            && in_array(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', (array) musa_dato(musa_ajustes(), 'seguridad.proxies_confiables', array('127.0.0.1', '::1')), true));
}

/** Nonce de la página para la Content-Security-Policy (uno por petición). */
function musa_nonce() {
    static $nonce = null;
    if ($nonce === null) { $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='); }
    return $nonce;
}

/**
 * Cabeceras de seguridad.
 * $tipo 'publica' = página del avatar (micrófono, Google Fonts, sala LiveKit); 'panel' = wj-admin.
 * La CSP solo permite scripts y estilos propios o con el nonce de la página: un texto inyectado no
 * puede ejecutar código. Con LiveAvatar, connect-src admite https:/wss: porque el servidor de video
 * (LiveKit) lo asigna LiveAvatar en cada sesión y su dominio no está documentado; con el motor
 * económico queda en 'self'.
 */
function musa_cabeceras_seguridad($tipo = 'panel') {
    if (headers_sent()) { return; }
    if ($tipo === true) { $tipo = 'publica'; }   // compatibilidad con la firma anterior
    $publica = $tipo === 'publica';
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), camera=(), payment=(), usb=(), microphone=' . ($publica ? '(self)' : '()'));
    if (musa_es_https()) { header('Strict-Transport-Security: max-age=31536000'); }
    $n = musa_nonce();
    if ($publica) {
        // Motor económico: el navegador solo habla con este servidor (las APIs se llaman desde PHP).
        // LiveAvatar: la sala LiveKit que asigna en cada sesión (dominio variable).
        $conexiones = "'self'";
        if (musa_motor() === 'liveavatar') {
            $conexiones = "'self' https: wss:";
            $endpoint = (string) musa_dato(musa_ajustes(), 'heygen.endpoint', '');
            if (preg_match('#^http://(localhost|127\.0\.0\.1)#i', $endpoint)) { $conexiones .= ' ws://127.0.0.1:* ws://localhost:*'; }   // simulador local
        }
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$n'; style-src 'self' 'nonce-$n' https://fonts.googleapis.com; "
            . "font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; media-src 'self' blob:; connect-src $conexiones; "
            . "worker-src 'self' blob:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");
    } else {
        // media-src: muestras de voz de ElevenLabs (https) y la prueba de voz generada en el panel (data:).
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: https:; "
            . "media-src 'self' data: https:; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");
    }
}
