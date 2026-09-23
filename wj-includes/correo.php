<?php
/**
 * QuéDice! · Envío de correo
 * Envía a la persona el resumen de su conversación.
 * Soporta la función mail() de PHP (predeterminado en Plesk) y SMTP autenticado.
 */
if (!defined('MUSA_ARRANQUE')) { http_response_code(403); exit('Acceso directo no permitido.'); }

/** Reemplaza las etiquetas {nombre}, {tema}, {codigo}, {conversacion}… en una plantilla. */
function musa_correo_plantilla($texto, $c, $ajustes = null) {
    if ($ajustes === null) { $ajustes = musa_ajustes(); }
    $reemplazos = array(
        '{nombre}'       => (string) (($c['nombre'] ?? '') !== '' ? $c['nombre'] : 'visitante'),
        '{correo}'       => (string) ($c['correo'] ?? ''),
        '{ciudad}'       => (string) ($c['ciudad'] ?? ''),
        '{codigo}'       => (string) ($c['codigo'] ?? ''),
        '{fecha}'        => (string) ($c['fecha'] ?? ''),
        '{tema}'         => (string) (($c['tema'] ?? '') !== '' ? $c['tema'] : musa_dato($ajustes, 'tema.nombre', 'café')),
        '{preguntas}'    => (string) musa_conversacion_preguntas($c),
        '{avatar}'       => (string) musa_dato($ajustes, 'avatar.nombre', 'Anfitrión'),
        '{conversacion}' => musa_conversacion_texto($c, musa_dato($ajustes, 'avatar.nombre', 'Anfitrión')),
    );
    return strtr((string) $texto, $reemplazos);
}

/** Codifica una cabecera con acentos según RFC 2047. */
function musa_correo_cabecera($texto) {
    $texto = (string) $texto;
    if (preg_match('/^[\x20-\x7E]*$/', $texto)) { return $texto; }
    return '=?UTF-8?B?' . base64_encode($texto) . '?=';
}

/** Arma el cuerpo HTML del correo con la identidad de QuéDice!. */
function musa_correo_html($c, $ajustes) {
    $colores = musa_dato($ajustes, 'colores', array());
    $fondo   = musa_color(musa_dato($colores, 'fondo', '#8F1824'), '#8F1824');
    $texto   = musa_color(musa_dato($colores, 'texto', '#FFFFFF'), '#FFFFFF');
    $acento  = musa_color(musa_dato($colores, 'acento', '#FFD500'), '#FFD500');
    $marca   = musa_dato($ajustes, 'marca.nombre', 'QuéDice!');
    $entidad = musa_dato($ajustes, 'marca.entidad', 'Gobernación de Nariño');
    $avatar  = musa_dato($ajustes, 'avatar.nombre', 'Anfitrión');

    $mensaje = nl2br(musa_e(musa_correo_plantilla(musa_dato($ajustes, 'correo.mensaje', ''), $c, $ajustes)));

    $bloque = '';
    if (!empty(musa_dato($ajustes, 'correo.incluir_conversacion', true))) {
        $filas = '';
        foreach (musa_conversacion_pares($c) as $par) {
            if ($par['pregunta'] !== '') {
                $filas .= '<p style="margin:14px 0 4px;font-weight:bold;color:' . $acento . '">' . musa_e($par['pregunta']) . '</p>';
            }
            if ($par['respuesta'] !== '') {
                $filas .= '<p style="margin:0 0 6px;line-height:1.6"><span style="opacity:.8">' . musa_e($avatar) . ':</span> ' . musa_e($par['respuesta']) . '</p>';
            }
        }
        if ($filas !== '') {
            $bloque = '<div style="margin:24px 0;padding:18px 20px;border-radius:14px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);font-size:14px">' . $filas . '</div>';
        }
    }

    return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"></head>'
        . '<body style="margin:0;padding:0;background:#f4f1ea">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1ea;padding:24px 12px">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:' . $fondo . ';color:' . $texto . ';border-radius:18px;overflow:hidden;font-family:Arial,Helvetica,sans-serif">'
        . '<tr><td style="padding:28px 30px 8px"><h1 style="margin:0;font-size:24px;letter-spacing:.5px">' . musa_e($marca) . '</h1>'
        . '<p style="margin:6px 0 0;font-size:13px;opacity:.85">' . musa_e($entidad) . '</p></td></tr>'
        . '<tr><td style="padding:12px 30px 28px;font-size:15px;line-height:1.7">' . $mensaje . $bloque
        . '<p style="margin:22px 0 0;font-size:12px;opacity:.75">Código de la conversación: ' . musa_e((string) ($c['codigo'] ?? '')) . '</p>'
        . '</td></tr></table>'
        . '<p style="font-size:11px;color:#8a7f79;margin:16px 0 0">Este mensaje fue enviado automáticamente. Por favor no respondas a este correo.</p>'
        . '</td></tr></table></body></html>';
}

/**
 * Envía a la persona el resumen de su conversación.
 * Devuelve array('ok' => bool, 'mensaje' => string).
 */
function musa_correo_enviar($c, $ajustes = null) {
    if ($ajustes === null) { $ajustes = musa_ajustes(); }

    if (empty(musa_dato($ajustes, 'correo.activo', true))) {
        return array('ok' => false, 'mensaje' => 'El envío de correo está desactivado en los ajustes.');
    }
    $destino = (string) ($c['correo'] ?? '');
    if (!musa_correo_valido($destino)) {
        return array('ok' => false, 'mensaje' => 'La conversación no tiene un correo válido.');
    }

    // El correo sale con la identidad de la Gobernación hacia una dirección que escribió el visitante:
    // solo lleva respuestas del avatar verificadas con LiveAvatar y ningún enlace escrito por él.
    $c = musa_conversacion_para_correo($c);
    $asunto = musa_correo_plantilla(musa_dato($ajustes, 'correo.asunto', 'Tu conversación en QuéDice!'), $c, $ajustes);
    $html = musa_correo_html($c, $ajustes);
    $textoPlano = musa_correo_plantilla(musa_dato($ajustes, 'correo.mensaje', ''), $c, $ajustes);
    if (!empty(musa_dato($ajustes, 'correo.incluir_conversacion', true))) {
        $textoPlano .= "\n\n" . musa_conversacion_texto($c, musa_dato($ajustes, 'avatar.nombre', 'Anfitrión'));
    }
    $adjunto = null;

    $remitente = musa_dato($ajustes, 'correo.remitente', 'no-responder@narino.gov.co');
    $nombreRemitente = musa_dato($ajustes, 'correo.nombre_remitente', 'QuéDice!');
    $metodo = musa_dato($ajustes, 'correo.metodo', 'mail');

    $mensaje = musa_correo_construir($html, $textoPlano, $adjunto, $limite);
    $cabeceras = array(
        'From: ' . musa_correo_cabecera($nombreRemitente) . ' <' . $remitente . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $limite . '"',
        'X-Mailer: QueDice ' . MUSA_VERSION,
    );
    $responder = musa_dato($ajustes, 'correo.responder_a', '');
    if (musa_correo_valido($responder)) { $cabeceras[] = 'Reply-To: ' . $responder; }
    $copia = (string) musa_dato($ajustes, 'correo.copia_oculta', '');
    if (!musa_correo_valido($copia)) { $copia = ''; }

    if ($metodo === 'smtp') {
        // En SMTP la copia oculta va solo como destinatario del sobre (RCPT TO), nunca como cabecera.
        $resultado = musa_correo_smtp($destino, $asunto, $mensaje, $cabeceras, $ajustes, $copia);
    } else {
        if ($copia !== '') { $cabeceras[] = 'Bcc: ' . $copia; }   // mail() la retira antes de entregar
        $enviado = @mail($destino, musa_correo_cabecera($asunto), $mensaje, implode("\r\n", $cabeceras), '-f' . $remitente);
        $resultado = array(
            'ok' => (bool) $enviado,
            'mensaje' => $enviado ? 'Correo entregado al servidor.' : 'La función mail() de PHP no pudo enviar el mensaje. Revisa el servicio de correo del servidor o configura SMTP.',
        );
    }

    // La bitácora no guarda la dirección del destinatario (dato personal): el código basta para ubicarlo.
    musa_log($resultado['ok'] ? 'Correo enviado' : 'Fallo al enviar correo', array(
        'codigo' => $c['codigo'] ?? '', 'metodo' => $metodo, 'detalle' => $resultado['mensaje'],
    ));
    return $resultado;
}

/** Nombre de host SMTP válido (sin esquemas como udp:// ni rutas): letras, números, puntos y guiones. */
function musa_smtp_host_valido($host) {
    return (bool) preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)*$/', (string) $host);
}

/** Construye el cuerpo MIME (texto + HTML + adjunto opcional). */
function musa_correo_construir($html, $texto, $adjunto, &$limite) {
    $limite = '=_musa_' . bin2hex(random_bytes(10));
    $limiteAlterno = '=_alt_' . bin2hex(random_bytes(10));

    $alternativo = '--' . $limiteAlterno . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($texto)) . "\r\n"
        . '--' . $limiteAlterno . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html)) . "\r\n"
        . '--' . $limiteAlterno . "--\r\n";

    if ($adjunto === null) {
        $limite = $limiteAlterno;
        return $alternativo;
    }

    $cuerpo = '--' . $limite . "\r\n"
        . 'Content-Type: multipart/alternative; boundary="' . $limiteAlterno . '"' . "\r\n\r\n"
        . $alternativo . "\r\n"
        . '--' . $limite . "\r\n"
        . 'Content-Type: ' . $adjunto['tipo'] . '; name="' . $adjunto['nombre'] . '"' . "\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . 'Content-Disposition: attachment; filename="' . $adjunto['nombre'] . '"' . "\r\n\r\n"
        . chunk_split(base64_encode($adjunto['contenido'])) . "\r\n"
        . '--' . $limite . "--\r\n";
    return $cuerpo;
}

/** Envío por SMTP con autenticación (sin dependencias externas). */
function musa_correo_smtp($destino, $asunto, $mensaje, $cabeceras, $ajustes, $copia = '') {
    $host = musa_dato($ajustes, 'correo.smtp.host', '');
    $puerto = (int) musa_dato($ajustes, 'correo.smtp.puerto', 587);
    $seguridad = musa_dato($ajustes, 'correo.smtp.seguridad', 'tls');
    $usuario = musa_dato($ajustes, 'correo.smtp.usuario', '');
    $clave = musa_dato($ajustes, 'correo.smtp.clave', '');
    $remitente = musa_dato($ajustes, 'correo.remitente', '');

    if ($host === '') { return array('ok' => false, 'mensaje' => 'Falta configurar el servidor SMTP.'); }
    if (!musa_smtp_host_valido($host) || !in_array($puerto, array(25, 465, 587, 2525), true)) {
        return array('ok' => false, 'mensaje' => 'El servidor SMTP o el puerto configurado no son válidos.');
    }

    $prefijo = ($seguridad === 'ssl') ? 'ssl://' : '';
    $conexion = @stream_socket_client($prefijo . $host . ':' . $puerto, $errorNum, $errorMsg, 20);
    if (!$conexion) {
        return array('ok' => false, 'mensaje' => 'No fue posible conectar con ' . $host . ':' . $puerto . ' (' . $errorMsg . ')');
    }
    stream_set_timeout($conexion, 20);

    $leer = function () use ($conexion) {
        $respuesta = '';
        while (($linea = fgets($conexion, 515)) !== false) {
            $respuesta .= $linea;
            if (strlen($linea) < 4 || $linea[3] === ' ') { break; }
        }
        return $respuesta;
    };
    $enviar = function ($comando) use ($conexion, $leer) {
        fwrite($conexion, $comando . "\r\n");
        return $leer();
    };
    $codigo = function ($respuesta) { return (int) substr(trim((string) $respuesta), 0, 3); };

    $bienvenida = $leer();
    if ($codigo($bienvenida) !== 220) { fclose($conexion); return array('ok' => false, 'mensaje' => 'El servidor SMTP rechazó la conexión: ' . trim($bienvenida)); }

    $dominio = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';
    $respuesta = $enviar('EHLO ' . $dominio);
    if ($codigo($respuesta) !== 250) { fclose($conexion); return array('ok' => false, 'mensaje' => 'EHLO rechazado: ' . trim($respuesta)); }

    if ($seguridad === 'tls') {
        $respuesta = $enviar('STARTTLS');
        if ($codigo($respuesta) !== 220) { fclose($conexion); return array('ok' => false, 'mensaje' => 'STARTTLS no disponible: ' . trim($respuesta)); }
        if (!@stream_socket_enable_crypto($conexion, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($conexion);
            return array('ok' => false, 'mensaje' => 'No fue posible establecer el cifrado TLS.');
        }
        $enviar('EHLO ' . $dominio);
    }

    if ($usuario !== '') {
        $respuesta = $enviar('AUTH LOGIN');
        if ($codigo($respuesta) !== 334) { fclose($conexion); return array('ok' => false, 'mensaje' => 'El servidor no aceptó AUTH LOGIN: ' . trim($respuesta)); }
        $respuesta = $enviar(base64_encode($usuario));
        if ($codigo($respuesta) !== 334) { fclose($conexion); return array('ok' => false, 'mensaje' => 'Usuario SMTP rechazado.'); }
        $respuesta = $enviar(base64_encode($clave));
        if ($codigo($respuesta) !== 235) { fclose($conexion); return array('ok' => false, 'mensaje' => 'Contraseña SMTP rechazada.'); }
    }

    $respuesta = $enviar('MAIL FROM:<' . $remitente . '>');
    if ($codigo($respuesta) !== 250) { fclose($conexion); return array('ok' => false, 'mensaje' => 'Remitente rechazado: ' . trim($respuesta)); }
    $respuesta = $enviar('RCPT TO:<' . $destino . '>');
    if ($codigo($respuesta) !== 250 && $codigo($respuesta) !== 251) { fclose($conexion); return array('ok' => false, 'mensaje' => 'Destinatario rechazado: ' . trim($respuesta)); }
    if ($copia !== '') {
        $respuesta = $enviar('RCPT TO:<' . $copia . '>');
        if ($codigo($respuesta) !== 250 && $codigo($respuesta) !== 251) { musa_log('La copia oculta fue rechazada por el servidor SMTP'); }
    }

    $respuesta = $enviar('DATA');
    if ($codigo($respuesta) !== 354) { fclose($conexion); return array('ok' => false, 'mensaje' => 'DATA rechazado: ' . trim($respuesta)); }

    $encabezado = implode("\r\n", $cabeceras) . "\r\n"
        . 'To: ' . $destino . "\r\n"
        . 'Subject: ' . musa_correo_cabecera($asunto) . "\r\n"
        . 'Date: ' . date('r') . "\r\n";
    $datos = $encabezado . "\r\n" . preg_replace('/^\./m', '..', $mensaje) . "\r\n.";
    $respuesta = $enviar($datos);
    $exito = ($codigo($respuesta) === 250);
    $enviar('QUIT');
    fclose($conexion);

    return array(
        'ok' => $exito,
        'mensaje' => $exito ? 'Correo entregado por SMTP.' : 'El servidor SMTP no aceptó el mensaje: ' . trim($respuesta),
    );
}

/** Envía un correo de prueba (para el panel). */
function musa_correo_prueba($destino, $ajustes = null) {
    if ($ajustes === null) { $ajustes = musa_ajustes(); }
    $c = array_merge(musa_conversacion_base(), array(
        'codigo'   => 'PRUEBA-' . date('Ymd-His'),
        'nombre'   => 'Equipo QuéDice!',
        'correo'   => $destino,
        'fecha'    => date('Y-m-d H:i:s'),
        'mensajes' => array(
            array('rol' => 'persona', 'texto' => '¿Funciona el correo?', 'hora' => date('Y-m-d H:i:s')),
            array('rol' => 'avatar', 'texto' => 'Sí. Si lees este mensaje, la configuración de correo funciona.', 'hora' => date('Y-m-d H:i:s')),
        ),
    ));
    return musa_correo_enviar($c, $ajustes);
}
