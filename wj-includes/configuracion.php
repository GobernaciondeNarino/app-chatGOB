<?php
/**
 * Musa Café · Configuración
 * Todo lo configurable (avatar, tema, colores, imágenes, logos, formulario,
 * API de HeyGen y correo) vive en wj-content/config/ajustes.json.php
 * y se edita desde wj-admin.
 */
if (!defined('MUSA_ARRANQUE')) { http_response_code(403); exit('Acceso directo no permitido.'); }

/** Conocimiento base sobre el tema predeterminado (café). Se edita en wj-admin → Avatar y tema. */
function musa_conocimiento_cafe() {
    return "CAFÉ DE NARIÑO\n"
        . "- Nariño está en el suroccidente de Colombia, en la frontera con Ecuador. Su café se cultiva en zonas de montaña de la cordillera de los Andes, en algunos de los cultivos más altos del país.\n"
        . "- La altura, las noches frías y la cercanía a la línea del ecuador hacen que el grano madure despacio. Por eso el café de Nariño suele destacarse por su acidez alta y limpia, cuerpo medio y notas dulces, frutales, cítricas y florales.\n"
        . "- La mayoría del café nariñense lo producen familias campesinas en fincas pequeñas, con recolección manual grano a grano.\n"
        . "- El Café de Nariño cuenta con Denominación de Origen protegida en Colombia.\n"
        . "- Municipios cafeteros reconocidos: La Unión, Buesaco, San Lorenzo, Consacá, Sandoná, El Tablón de Gómez, Arboleda, San Pablo, Colón (Génova), La Florida, Samaniego y Linares, entre otros.\n\n"
        . "DEL CULTIVO A LA TAZA\n"
        . "- Variedades comunes en Colombia: Castillo, Caturra, Colombia, Cenicafé 1 y variedades especiales como Geisha o Borbón rosado en algunas fincas.\n"
        . "- Proceso lavado (el más común en Colombia): se despulpa la cereza, se fermenta, se lava y se seca al sol o en secadores. También existen procesos honey y natural.\n"
        . "- El café pergamino seco se trilla para obtener café verde, que luego se tuesta. El tueste medio conserva mejor la acidez y los aromas del café de altura.\n\n"
        . "PREPARACIÓN\n"
        . "- Proporción de referencia: 1 gramo de café por cada 15 a 17 mililitros de agua.\n"
        . "- Agua entre 90 y 96 °C, nunca hirviendo.\n"
        . "- Molienda gruesa para prensa francesa, media para filtrados (V60, Chemex, goteo) y fina para espresso.\n"
        . "- Conservar el café en un recipiente hermético, lejos de la luz, la humedad y el calor.\n\n"
        . "CATACIÓN\n"
        . "- Atributos que se evalúan: fragancia y aroma, sabor, acidez, cuerpo, dulzor, balance y sabor residual.\n"
        . "- Un café de especialidad obtiene 80 puntos o más en la escala de catación SCA.";
}

/** Ajustes predeterminados del sistema. */
function musa_ajustes_predeterminados() {
    return array(
        'marca' => array(
            'nombre'        => 'Musa Café',
            'eslogan'       => 'Conversa con nuestro anfitrión',
            'entidad'       => 'Gobernación de Nariño',
            'titulo_sitio'  => 'Musa Café · Conversa sobre café',
            'descripcion'   => 'Un anfitrión virtual que conversa contigo sobre el café de Nariño.',
            'logo'          => 'wj-includes/images/optimizadas/logo_musacafe.png',
            'fondo'         => 'wj-includes/images/optimizadas/bg.png',
            'barra'         => 'wj-includes/images/optimizadas/bg_barra.png',
            'favicon'       => 'wj-includes/images/optimizadas/logo_musacafe.png',
            'sitio_entidad' => 'https://www.narino.gov.co',
        ),
        'colores' => array(
            'fondo'             => '#AE1D2C',
            'fondo_profundo'    => '#7E0E1C',
            'tarjeta'           => '#9F1427',
            'tarjeta_borde'     => '#C3364A',
            'texto'             => '#F7EFE0',
            'texto_suave'       => '#EBC9CE',
            'acento'            => '#F2B705',
            'texto_sobre_acento'=> '#3A0A10',
            'acento_secundario' => '#12A5C4',
            'burbuja_persona'   => '#F6EDD9',
            'texto_persona'     => '#5A0B16',
            'exito'             => '#2E9E6B',
            'error'             => '#FFB3BC',
        ),
        'textos' => array(
            'titulo'            => 'Conversa sobre café',
            'subtitulo'         => 'Pregúntale a nuestro anfitrión lo que quieras saber del café de Nariño.',
            'boton_iniciar'     => 'Iniciar conversación',
            'boton_terminar'    => 'Terminar',
            'boton_interrumpir' => 'Interrumpir',
            'boton_microfono'   => 'Micrófono',
            'escribir_ayuda'    => 'Escribe tu pregunta…',
            'boton_enviar'      => 'Enviar',
            'conectando'        => 'Preparando al anfitrión…',
            'escuchando'        => 'Te escucho…',
            'hablando'          => 'Respondiendo…',
            'transcripcion'     => 'Transcripción de la conversación',
            'sugerencias'       => 'Puedes preguntar:',
            'formulario_titulo' => 'Antes de empezar, cuéntanos quién eres',
            'formulario_ayuda'  => 'Usaremos estos datos solo para hacer seguimiento a tu conversación.',
            'despedida_titulo'  => '¡Gracias por conversar!',
            'despedida_texto'   => 'Tu conversación quedó registrada. Puedes iniciar otra cuando quieras.',
            'aviso_datos'       => 'Autorizo el tratamiento de mis datos personales conforme a la Ley 1581 de 2012 y la política de la Gobernación de Nariño.',
            'aviso_microfono'   => 'Para hablar con el anfitrión, permite el uso del micrófono. También puedes escribir tus preguntas.',
            'pie'               => 'Gobernación de Nariño · Musa Café',
        ),
        'avatar' => array(
            'nombre'           => 'Anfitrión Musa Café',
            'avatar_id'        => '56aa5373edb14809a1572b36af99b94c',
            'voice_id'         => '5fab49b6cbd84b2cb0320fd28f9e49de',
            'idioma'           => 'es',
            'calidad'          => 'high',
            'interactividad'   => 'CONVERSATIONAL',
            'duracion_maxima'  => 600,
            'sandbox'          => false,
            'retrato'          => 'wj-includes/images/avatar/avatar-cafe.webp',
            'microfono_inicial'=> true,
            'permitir_escribir'=> true,
        ),
        'tema' => array(
            'nombre'       => 'Café',
            'titulo'       => 'Musa Café · Café de Nariño',
            'personalidad' => 'Eres el anfitrión de Musa Café, un espacio de la Gobernación de Nariño. Eres cálido, cercano y orgulloso de la tradición cafetera nariñense. Hablas en español de Colombia, con un tono amable y sencillo, como quien conversa con un visitante mientras le sirve una taza de café.',
            'saludo'       => '¡Hola! Bienvenido a Musa Café. Soy tu anfitrión y me encanta hablar del café de Nariño. ¿Qué te gustaría saber?',
            'conocimiento' => musa_conocimiento_cafe(),
            'reglas'       => "- Habla solo del tema configurado. Si te preguntan por otra cosa, responde con amabilidad que tu especialidad es el tema y ofrece una pregunta relacionada.\n- Responde en máximo tres frases cortas, fáciles de escuchar.\n- No inventes datos: si no sabes algo, dilo con sencillez.\n- No des opiniones políticas, médicas ni legales.\n- No pidas datos personales.",
            'maximo_palabras' => 60,
            'sugerencias'  => array(
                '¿Por qué el café de Nariño es especial?',
                '¿Cómo preparo un buen café en casa?',
                '¿Qué municipios de Nariño producen café?',
                '¿Qué es un café de especialidad?',
            ),
            'enlaces'      => array(),
        ),
        'heygen' => array(
            'api_key'        => '',
            'endpoint'       => 'https://api.liveavatar.com',
            'context_id'     => '',
            'context_huella' => '',
            'context_fecha'  => '',
        ),
        'formulario' => array(
            'activo'               => true,
            'pedir_correo'         => true,
            'correo_obligatorio'   => false,
            'pedir_telefono'       => false,
            'telefono_obligatorio' => false,
            'pedir_ciudad'         => true,
            'ciudad_obligatoria'   => false,
        ),
        'correo' => array(
            'activo'            => true,
            'metodo'            => 'mail',
            'remitente'         => 'no-responder@narino.gov.co',
            'nombre_remitente'  => 'Musa Café · Gobernación de Nariño',
            'responder_a'       => '',
            'copia_oculta'      => '',
            'asunto'            => 'Tu conversación en Musa Café',
            'incluir_conversacion' => true,
            'mensaje'           => "Hola {nombre},\n\nGracias por conversar con nuestro anfitrión sobre {tema}.\nEste es el resumen de tus preguntas y las respuestas que recibiste.\n\nCódigo de la conversación: {codigo}\n\nGobernación de Nariño",
            'smtp' => array(
                'host'      => '',
                'puerto'    => 587,
                'seguridad' => 'tls',
                'usuario'   => '',
                'clave'     => '',
            ),
        ),
        'seguridad' => array(
            'limite_por_hora'     => 6,
            'limite_por_dia'      => 30,
            'exigir_aceptacion'   => true,
            'maximo_mensajes'     => 400,
            // IPs de proxys propios (balanceador, CDN) cuyas cabeceras X-Real-IP
            // o CF-Connecting-IP sí se pueden creer. Vacío = usar solo REMOTE_ADDR.
            'proxies_confiables'  => array(),
        ),
        'sistema' => array(
            'zona_horaria'         => 'America/Bogota',
            'registros_por_pagina' => 25,
            'prefijo_codigo'       => 'CAFE',
            'efectos_3d'           => true,
        ),
    );
}

/**
 * Claves de API tomadas de wj-content/config/claves.php, si ese archivo existe.
 * Sirve para desplegar las claves sin escribirlas en el repositorio; solo se usan
 * en el primer arranque, porque después el panel es el dueño de la configuración.
 */
function musa_claves_locales() {
    $archivo = MUSA_DIR_CONFIG . '/claves.php';
    if (!file_exists($archivo)) { return array(); }
    $datos = include $archivo;
    return is_array($datos) ? $datos : array();
}

/** Devuelve los ajustes vigentes (predeterminados + guardados). */
function musa_ajustes($recargar = false) {
    static $ajustes = null;
    if ($ajustes !== null && !$recargar) { return $ajustes; }

    $predeterminados = musa_ajustes_predeterminados();
    if (!file_exists(MUSA_ARCHIVO_AJUSTES)) {
        // Primer arranque: se parte del archivo de ejemplo del repositorio
        // y, si existe, del archivo local de claves (que nunca se sube al repositorio).
        $ejemplo = musa_leer_json(MUSA_DIR_CONFIG . '/ajustes.ejemplo.json.php', array());
        $iniciales = $ejemplo !== array() ? musa_combinar($predeterminados, $ejemplo) : $predeterminados;
        $iniciales = musa_combinar($iniciales, musa_claves_locales());
        musa_escribir_json(MUSA_ARCHIVO_AJUSTES, $iniciales);
        $ajustes = $iniciales;
        return $ajustes;
    }
    $guardados = musa_leer_json(MUSA_ARCHIVO_AJUSTES, array());
    if (isset($guardados['generos']) || isset($guardados['ia'])) {
        $guardados = musa_migrar_ajustes_v1($guardados);
        musa_escribir_json(MUSA_ARCHIVO_AJUSTES, musa_combinar($predeterminados, $guardados));
    }
    $ajustes = musa_combinar($predeterminados, $guardados);
    return $ajustes;
}

/**
 * Migra los ajustes de la versión 1 (creador de canciones) a la 2 (avatar conversacional):
 * conserva la identidad, las imágenes, los colores, el correo SMTP y la seguridad, y descarta
 * los géneros musicales, las APIs de música y los textos de la experiencia anterior.
 */
function musa_migrar_ajustes_v1($viejos) {
    $nuevos = array();
    foreach (array('colores', 'seguridad') as $grupo) {
        if (isset($viejos[$grupo]) && is_array($viejos[$grupo])) { $nuevos[$grupo] = $viejos[$grupo]; }
    }
    if (isset($viejos['marca']) && is_array($viejos['marca'])) {
        $nuevos['marca'] = array_intersect_key($viejos['marca'], array_flip(array('nombre', 'entidad', 'logo', 'fondo', 'barra', 'favicon', 'sitio_entidad')));
    }
    if (isset($viejos['correo']) && is_array($viejos['correo'])) {
        $nuevos['correo'] = array_intersect_key($viejos['correo'], array_flip(array('activo', 'metodo', 'remitente', 'nombre_remitente', 'responder_a', 'copia_oculta', 'smtp')));
    }
    if (isset($viejos['sistema']) && is_array($viejos['sistema'])) {
        $nuevos['sistema'] = array_intersect_key($viejos['sistema'], array_flip(array('zona_horaria', 'registros_por_pagina', 'efectos_3d')));
    }
    unset($nuevos['seguridad']['limite_por_hora'], $nuevos['seguridad']['limite_por_dia']);
    musa_log('Ajustes migrados de la versión 1 (canciones) a la 2 (avatar conversacional)');
    return $nuevos;
}

/** Guarda los ajustes en disco y refresca la caché en memoria. */
function musa_guardar_ajustes($ajustes) {
    $ok = musa_escribir_json(MUSA_ARCHIVO_AJUSTES, $ajustes);
    if ($ok) {
        $GLOBALS['musa_ajustes'] = musa_ajustes(true);
    }
    return $ok;
}

/** Preguntas sugeridas del tema (sin vacíos). */
function musa_sugerencias($ajustes = null) {
    if ($ajustes === null) { $ajustes = musa_ajustes(); }
    $lista = array();
    foreach ((array) musa_dato($ajustes, 'tema.sugerencias', array()) as $texto) {
        $texto = trim((string) $texto);
        if ($texto !== '') { $lista[] = $texto; }
    }
    return $lista;
}
