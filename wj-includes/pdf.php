<?php
/**
 * QuéDice! · Lector de texto de PDF sin dependencias
 * -------------------------------------------------------------
 * Saca el texto de los PDF «digitales» (los que se generan desde Word, LibreOffice, InDesign o un
 * navegador) sin programas externos ni APIs: recorre los objetos del archivo (también los que van
 * comprimidos dentro de flujos de objetos), sigue el árbol de páginas en orden, interpreta los
 * operadores de texto de cada página y traduce los códigos de cada fuente a Unicode con su tabla
 * ToUnicode (o con WinAnsi y la lista /Differences en las fuentes simples).
 *
 * No hace OCR: un PDF escaneado no tiene texto y se lee con Gemini (ver fuentes.php).
 * El archivo puede venir de cualquier lado, así que todo tiene topes: bytes descomprimidos, número de
 * objetos y de páginas, profundidad y tiempo.
 */
if (!defined('MUSA_ARRANQUE')) { http_response_code(403); exit('Acceso directo no permitido.'); }

define('MUSA_PDF_MAX_DESCOMPRIMIDO', 96 * 1024 * 1024);   // trabajo total (un manual de 54 páginas con diseño usa ~23 MB)
define('MUSA_PDF_MAX_FLUJO', 24 * 1024 * 1024);           // un solo flujo descomprimido (lo que ocupa la memoria a la vez)
define('MUSA_PDF_MAX_OBJETOS', 300000);
define('MUSA_PDF_MAX_PAGINAS', 3000);

/**
 * Texto de un PDF. Devuelve array('texto', 'paginas', 'incompleto' => bool, 'error').
 * Las páginas se separan con un salto de página (\f).
 */
function musa_pdf_texto($datos, $segundos = 25) {
    $r = array('texto' => '', 'paginas' => 0, 'incompleto' => false, 'error' => '');
    if (strpos(substr($datos, 0, 1024), '%PDF-') === false) { $r['error'] = 'El archivo no es un PDF.'; return $r; }
    if (preg_match('/\/Encrypt\s*\d+\s+\d+\s+R|\/Encrypt\s*<</', $datos)) { $r['error'] = 'El PDF está cifrado o protegido.'; return $r; }
    $ctx = array(
        'datos' => $datos, 'objs' => array(), 'valores' => array(), 'flujos' => array(), 'fuentes' => array(),
        'presupuesto' => MUSA_PDF_MAX_DESCOMPRIMIDO, 'fin' => microtime(true) + $segundos, 'incompleto' => false,
    );
    musa_pdf_objetos($ctx);
    if ($ctx['objs'] === array()) { $r['error'] = 'No se encontraron objetos en el PDF.'; return $r; }
    $paginas = musa_pdf_paginas($ctx);
    $textos = array();
    foreach ($paginas as $pagina) {
        if (microtime(true) > $ctx['fin']) { $ctx['incompleto'] = true; break; }
        $contenido = musa_pdf_contenido_pagina($ctx, $pagina['dic']);
        $textos[] = $contenido === '' ? '' : musa_pdf_interpretar($ctx, $contenido, $pagina['recursos'], 0);
    }
    $r['paginas'] = count($paginas);
    $r['texto'] = implode("\f", array_map('musa_pdf_limpiar', $textos));
    $r['incompleto'] = $ctx['incompleto'];
    return $r;
}

/** Ordena el texto de una página: espacios repetidos fuera y como máximo una línea en blanco seguida. */
function musa_pdf_limpiar($texto) {
    $texto = str_replace("\r", "\n", (string) $texto);
    // Controles (algunos PDF asignan U+0000 a las ligaduras) y caracteres de uso privado fuera.
    $texto = preg_replace('/[\x00-\x08\x0B\x0E-\x1F\x7F\x{E000}-\x{F8FF}\x{FFFD}]/u', '', $texto);
    $texto = preg_replace('/[ \t\x{00A0}]+/u', ' ', $texto);
    $texto = preg_replace('/ *\n */', "\n", (string) $texto);
    return trim((string) preg_replace('/\n{3,}/', "\n\n", (string) $texto));
}

/* ------------------------------------------------------------------ */
/*  Objetos del archivo                                                 */
/* ------------------------------------------------------------------ */

/** Ubica los objetos «N G obj … endobj» y los que van dentro de flujos de objetos (/ObjStm). */
function musa_pdf_objetos(&$ctx) {
    $d = $ctx['datos'];
    if (!preg_match_all('/(?<![0-9])(\d{1,7})\s+(\d{1,5})\s+obj\b/', $d, $m, PREG_OFFSET_CAPTURE)) { return; }
    $hasta = 0;
    $total = min(count($m[0]), MUSA_PDF_MAX_OBJETOS);
    for ($k = 0; $k < $total; $k++) {
        if ($m[0][$k][1] < $hasta) { continue; }   // coincidencia dentro de un flujo ya leído
        $num = (int) $m[1][$k][0];
        $ini = $m[0][$k][1] + strlen($m[0][$k][0]);
        $fin = strpos($d, 'endobj', $ini);
        if ($fin === false) { break; }
        $cuerpo = substr($d, $ini, $fin - $ini);
        $flujo = null;
        $p = strpos($cuerpo, 'stream');
        if ($p !== false && preg_match('/>>\s*$/', substr($cuerpo, 0, $p))) {
            $dicTexto = substr($cuerpo, 0, $p);
            $inicioDatos = $ini + $p + 6;
            if (substr($d, $inicioDatos, 2) === "\r\n") { $inicioDatos += 2; }
            elseif (isset($d[$inicioDatos]) && ($d[$inicioDatos] === "\n" || $d[$inicioDatos] === "\r")) { $inicioDatos++; }
            // Se prefiere /Length cuando es un número directo y cuadra con «endstream».
            $largo = preg_match('/\/Length\s+(\d+)(?!\s+\d+\s+R)/', $dicTexto, $l) ? (int) $l[1] : -1;
            $finFlujo = false;
            if ($largo >= 0 && preg_match('/\G\s*endstream/', $d, $x, 0, $inicioDatos + $largo)) { $finFlujo = $inicioDatos + $largo; }
            if ($finFlujo === false) { $finFlujo = strpos($d, 'endstream', $inicioDatos); }
            if ($finFlujo === false) { break; }
            $flujo = substr($d, $inicioDatos, $finFlujo - $inicioDatos);
            $cuerpo = $dicTexto;
            $fin = strpos($d, 'endobj', $finFlujo);
            if ($fin === false) { $fin = $finFlujo; }
        }
        $hasta = $fin + 6;
        $ctx['objs'][$num] = array('cuerpo' => $cuerpo, 'flujo' => $flujo);
    }
    // Flujos de objetos (PDF 1.5+): los objetos comprimidos solo llenan los que faltan.
    foreach (array_keys($ctx['objs']) as $num) {
        $o = $ctx['objs'][$num];
        if ($o['flujo'] === null || !preg_match('/\/Type\s*\/ObjStm\b/', $o['cuerpo'])) { continue; }
        $dic = musa_pdf_valor_objeto($ctx, $num);
        $datos = musa_pdf_flujo($ctx, $num);
        $primero = (int) musa_pdf_dic($dic, 'First', 0);
        $cantidad = min(50000, (int) musa_pdf_dic($dic, 'N', 0));
        if ($datos === null || $primero <= 0 || $cantidad <= 0) { continue; }
        // El encabezado («número desplazamiento» × N) se lee par a par y solo hasta /N pares: /First y /N
        // vienen del archivo y no deben decidir cuánta memoria se reserva.
        $pares = array();
        $fin = min($primero, strlen($datos));
        for ($pos = 0; count($pares) < $cantidad && $pos < $fin && preg_match('/\G\s*(\d+)\s+(\d+)/', $datos, $par, 0, $pos); $pos += strlen($par[0])) {
            $pares[] = array((int) $par[1], (int) $par[2]);
        }
        foreach ($pares as $j => $par) {
            $sub = $par[0];
            $desde = $primero + $par[1];
            $hastaSub = isset($pares[$j + 1]) ? $primero + $pares[$j + 1][1] : strlen($datos);
            if (!isset($ctx['objs'][$sub]) && count($ctx['objs']) < MUSA_PDF_MAX_OBJETOS) {
                $ctx['objs'][$sub] = array('cuerpo' => substr($datos, $desde, max(0, $hastaSub - $desde)), 'flujo' => null);
            }
        }
    }
}

/** Valor ya interpretado de un objeto (con caché). */
function musa_pdf_valor_objeto(&$ctx, $num) {
    if (array_key_exists($num, $ctx['valores'])) { return $ctx['valores'][$num]; }
    $ctx['valores'][$num] = null;   // evita ciclos
    if (!isset($ctx['objs'][$num])) { return null; }
    $s = $ctx['objs'][$num]['cuerpo'];
    $i = 0;
    $v = musa_pdf_valor($s, $i, strlen($s));
    $ctx['valores'][$num] = $v;
    return $v;
}

/** Sigue las referencias «N 0 R» hasta un valor directo. */
function musa_pdf_res(&$ctx, $v) {
    for ($n = 0; $n < 12 && is_array($v) && isset($v['r']); $n++) { $v = musa_pdf_valor_objeto($ctx, $v['r']); }
    return $v;
}

/** Valor de una clave de un diccionario (ya resuelto). */
function musa_pdf_dic($dic, $clave, $porDefecto = null) {
    return is_array($dic) && isset($dic['d']) && array_key_exists($clave, $dic['d']) ? $dic['d'][$clave] : $porDefecto;
}

/** Nombre (/Algo) de un valor, o ''. */
function musa_pdf_nombre_de($v) { return is_array($v) && isset($v['n']) ? $v['n'] : ''; }

/**
 * Datos descomprimidos del flujo de un objeto, o null si no se pueden leer. Los flujos de contenido de
 * las páginas se usan una sola vez y no se guardan en caché ($guardar = false) salvo que sean pequeños:
 * así la memoria depende del flujo más grande y no del documento entero.
 */
function musa_pdf_flujo(&$ctx, $num, $guardar = true) {
    if (array_key_exists($num, $ctx['flujos'])) { return $ctx['flujos'][$num]; }
    $ctx['flujos'][$num] = null;
    if (!isset($ctx['objs'][$num]) || $ctx['objs'][$num]['flujo'] === null) { return null; }
    $dic = musa_pdf_valor_objeto($ctx, $num);
    $datos = $ctx['objs'][$num]['flujo'];
    $filtros = musa_pdf_dic($dic, 'Filter');
    $lista = array();
    if (is_array($filtros) && isset($filtros['a'])) { foreach ($filtros['a'] as $f) { $lista[] = musa_pdf_nombre_de($f); } }
    elseif (musa_pdf_nombre_de($filtros) !== '') { $lista[] = musa_pdf_nombre_de($filtros); }
    foreach ($lista as $filtro) {
        if ($filtro === 'FlateDecode' || $filtro === 'Fl') { $datos = musa_pdf_inflar($ctx, $datos); }
        elseif ($filtro === 'ASCIIHexDecode' || $filtro === 'AHx') {
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', (string) strstr($datos . '>', '>', true));
            $datos = (string) hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex);
        } elseif ($filtro === 'ASCII85Decode' || $filtro === 'A85') { $datos = musa_pdf_ascii85($datos); }
        else { $datos = null; }   // imágenes (DCT, JBIG2…) u otros: no llevan texto
        if ($datos === null) { break; }
    }
    if ($guardar || ($datos !== null && strlen($datos) <= 1048576)) { $ctx['flujos'][$num] = $datos; }
    else { unset($ctx['flujos'][$num]); }
    return $datos;
}

/**
 * Descomprime zlib por partes y se detiene si el flujo pasa de MUSA_PDF_MAX_FLUJO o se agota el
 * presupuesto del documento (protege de «bombas»). La entrada se da de a 4 KB: deflate expande como
 * máximo ~1 000 veces, así que cada paso produce a lo sumo unos 4 MB antes de volver a comprobar.
 */
function musa_pdf_inflar(&$ctx, $datos) {
    $limite = min($ctx['presupuesto'], MUSA_PDF_MAX_FLUJO);
    foreach (array(ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_RAW) as $codificacion) {
        $z = @inflate_init($codificacion);
        if ($z === false) { return null; }
        $salida = '';
        $largo = strlen($datos);
        for ($pos = 0; $pos < $largo; $pos += 4096) {
            $parte = @inflate_add($z, substr($datos, $pos, 4096), ZLIB_SYNC_FLUSH);
            if ($parte === false) { break; }
            $salida .= $parte;
            if (strlen($salida) > $limite) {
                // Flujo demasiado grande (o «bomba»): se descarta y cuenta contra el presupuesto.
                $ctx['presupuesto'] = max(0, $ctx['presupuesto'] - strlen($salida));
                $ctx['incompleto'] = true;
                return null;
            }
        }
        if ($salida !== '') { $ctx['presupuesto'] -= strlen($salida); return $salida; }
    }
    return null;
}

/** Decodificador ASCII85. */
function musa_pdf_ascii85($datos) {
    $datos = preg_replace('/\s+/', '', (string) $datos);
    if (substr($datos, 0, 2) === '<~') { $datos = substr($datos, 2); }
    $fin = strpos($datos, '~>');
    if ($fin !== false) { $datos = substr($datos, 0, $fin); }
    $salida = ''; $grupo = array();
    $largo = strlen($datos);
    for ($i = 0; $i < $largo; $i++) {
        $c = $datos[$i];
        if ($c === 'z' && $grupo === array()) { $salida .= "\0\0\0\0"; continue; }
        $o = ord($c) - 33;
        if ($o < 0 || $o > 84) { continue; }
        $grupo[] = $o;
        if (count($grupo) === 5) {
            $valor = 0; foreach ($grupo as $g) { $valor = $valor * 85 + $g; }
            $salida .= pack('N', $valor & 0xFFFFFFFF); $grupo = array();
        }
    }
    if ($grupo !== array()) {
        $n = count($grupo);
        while (count($grupo) < 5) { $grupo[] = 84; }
        $valor = 0; foreach ($grupo as $g) { $valor = $valor * 85 + $g; }
        $salida .= substr(pack('N', $valor & 0xFFFFFFFF), 0, $n - 1);
    }
    return $salida;
}

/* ------------------------------------------------------------------ */
/*  Sintaxis de objetos                                                 */
/* ------------------------------------------------------------------ */

/** Salta espacios y comentarios. */
function musa_pdf_espacios($s, &$i) {
    if (preg_match('/\G(?:[\x00\t\n\f\r ]+|%[^\r\n]*)+/', $s, $m, 0, $i)) { $i += strlen($m[0]); }
}

/** Lee un nombre (/Algo) y decodifica los #xx. */
function musa_pdf_nombre($s, &$i) {
    if (!preg_match('/\G\/([^\x00\t\n\f\r \/\[\]\(\)<>{}%]*)/', $s, $m, 0, $i)) { $i++; return ''; }
    $i += strlen($m[0]);
    return strpos($m[1], '#') === false ? $m[1] : preg_replace_callback('/#([0-9A-Fa-f]{2})/', function ($x) { return chr(hexdec($x[1])); }, $m[1]);
}

/** Lee una cadena literal (…) con paréntesis anidados y secuencias de escape. */
function musa_pdf_literal($s, &$i, $n) {
    $i++;
    $profundidad = 1;
    $salida = '';
    $escapes = array('n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\');
    while ($i < $n) {
        $tramo = strcspn($s, "()\\", $i);
        $salida .= substr($s, $i, $tramo);
        $i += $tramo;
        if ($i >= $n) { break; }
        $c = $s[$i];
        if ($c === '\\') {
            $i++;
            if ($i >= $n) { break; }
            $e = $s[$i];
            if (isset($escapes[$e])) { $salida .= $escapes[$e]; $i++; }
            elseif ($e >= '0' && $e <= '7') { preg_match('/\G[0-7]{1,3}/', $s, $o, 0, $i); $salida .= chr(octdec($o[0]) & 0xFF); $i += strlen($o[0]); }
            elseif ($e === "\r") { $i++; if ($i < $n && $s[$i] === "\n") { $i++; } }
            elseif ($e === "\n") { $i++; }
            else { $salida .= $e; $i++; }
        } elseif ($c === '(') { $profundidad++; $salida .= '('; $i++; }
        else {
            $i++;
            if (--$profundidad === 0) { break; }
            $salida .= ')';
        }
    }
    return $salida;
}

/**
 * Lee un valor PDF en la posición $i. Representación:
 * diccionario ['d' => [clave => valor]], arreglo ['a' => [...]], referencia ['r' => número],
 * nombre ['n' => texto], cadena ['s' => bytes], operador o palabra clave ['k' => texto], números como int/float.
 */
function musa_pdf_valor($s, &$i, $n, $profundidad = 0) {
    musa_pdf_espacios($s, $i);
    if ($i >= $n) { return null; }
    $c = $s[$i];
    if ($profundidad > 40) { $i++; return null; }
    if ($c === '<') {
        if (isset($s[$i + 1]) && $s[$i + 1] === '<') {
            $i += 2;
            $dic = array();
            while ($i < $n) {
                musa_pdf_espacios($s, $i);
                if ($i >= $n) { break; }
                if ($s[$i] === '>' && isset($s[$i + 1]) && $s[$i + 1] === '>') { $i += 2; break; }
                if ($s[$i] !== '/') { $i++; continue; }
                $clave = musa_pdf_nombre($s, $i);
                $antes = $i;
                $dic[$clave] = musa_pdf_valor($s, $i, $n, $profundidad + 1);
                if ($i === $antes) { $i++; }
            }
            return array('d' => $dic);
        }
        $fin = strpos($s, '>', $i + 1);
        if ($fin === false) { $fin = $n; }
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $i + 1, $fin - $i - 1));
        $i = $fin + 1;
        return array('s' => (string) hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex));
    }
    if ($c === '[') {
        $i++;
        $arreglo = array();
        while ($i < $n) {
            musa_pdf_espacios($s, $i);
            if ($i >= $n) { break; }
            if ($s[$i] === ']') { $i++; break; }
            $antes = $i;
            $arreglo[] = musa_pdf_valor($s, $i, $n, $profundidad + 1);
            if ($i === $antes) { $i++; }
        }
        return array('a' => $arreglo);
    }
    if ($c === '(') { return array('s' => musa_pdf_literal($s, $i, $n)); }
    if ($c === '/') { return array('n' => musa_pdf_nombre($s, $i)); }
    if (preg_match('/\G[+-]?(?:\d+\.?\d*|\.\d+)/', $s, $m, 0, $i)) {
        $i += strlen($m[0]);
        if (ctype_digit($m[0]) && preg_match('/\G\s+\d+\s+R(?![A-Za-z0-9])/', $s, $r, 0, $i)) { $i += strlen($r[0]); return array('r' => (int) $m[0]); }
        return $m[0] + 0;
    }
    if (preg_match('/\G[^\x00\t\n\f\r \/\[\]\(\)<>{}%]+/', $s, $m, 0, $i)) { $i += strlen($m[0]); return array('k' => $m[0]); }
    if ($c === '>' && isset($s[$i + 1]) && $s[$i + 1] === '>') { return null; }   // fin de diccionario: lo consume quien llama
    $i++;
    return null;
}

/* ------------------------------------------------------------------ */
/*  Páginas                                                             */
/* ------------------------------------------------------------------ */

/** Páginas en orden (árbol /Pages del catálogo), cada una con sus recursos heredados. */
function musa_pdf_paginas(&$ctx) {
    $paginas = array();
    $raiz = null;
    foreach ($ctx['objs'] as $num => $o) {
        if (preg_match('/\/Type\s*\/Catalog\b/', $o['cuerpo'])) {
            $catalogo = musa_pdf_valor_objeto($ctx, $num);
            $raiz = musa_pdf_dic($catalogo, 'Pages');
            if ($raiz !== null) { break; }
        }
    }
    $vistos = array();
    if ($raiz !== null) { musa_pdf_recorrer($ctx, $raiz, null, $paginas, $vistos, 0); }
    if ($paginas === array()) {
        // Sin catálogo legible: cada objeto /Page en el orden del archivo.
        $numeros = array_keys($ctx['objs']);
        sort($numeros);
        foreach ($numeros as $num) {
            if (count($paginas) >= MUSA_PDF_MAX_PAGINAS) { break; }
            if (preg_match('/\/Type\s*\/Page\b(?!s)/', $ctx['objs'][$num]['cuerpo'])) {
                $dic = musa_pdf_valor_objeto($ctx, $num);
                $paginas[] = array('dic' => $dic, 'recursos' => musa_pdf_res($ctx, musa_pdf_dic($dic, 'Resources')));
            }
        }
    }
    return $paginas;
}

function musa_pdf_recorrer(&$ctx, $nodo, $recursos, &$paginas, &$vistos, $profundidad) {
    if ($profundidad > 30 || count($paginas) >= MUSA_PDF_MAX_PAGINAS) { return; }
    if (is_array($nodo) && isset($nodo['r'])) {
        if (isset($vistos[$nodo['r']])) { return; }
        $vistos[$nodo['r']] = true;
    }
    $dic = musa_pdf_res($ctx, $nodo);
    if (!is_array($dic) || !isset($dic['d'])) { return; }
    $propios = musa_pdf_dic($dic, 'Resources');
    if ($propios !== null) { $recursos = musa_pdf_res($ctx, $propios); }
    $hijos = musa_pdf_res($ctx, musa_pdf_dic($dic, 'Kids'));
    if (is_array($hijos) && isset($hijos['a'])) {
        foreach ($hijos['a'] as $hijo) { musa_pdf_recorrer($ctx, $hijo, $recursos, $paginas, $vistos, $profundidad + 1); }
    } elseif (musa_pdf_nombre_de(musa_pdf_dic($dic, 'Type')) !== 'Pages') {
        $paginas[] = array('dic' => $dic, 'recursos' => $recursos);
    }
}

/** Flujo de contenido de una página (si son varios, se unen). */
function musa_pdf_contenido_pagina(&$ctx, $dic) {
    $contenido = musa_pdf_dic($dic, 'Contents');
    $lista = array();
    if (is_array($contenido) && isset($contenido['r'])) {
        $resuelto = musa_pdf_valor_objeto($ctx, $contenido['r']);
        if (is_array($resuelto) && isset($resuelto['a'])) { $lista = $resuelto['a']; } else { $lista = array($contenido); }
    } elseif (is_array($contenido) && isset($contenido['a'])) { $lista = $contenido['a']; }
    $partes = array();
    foreach ($lista as $ref) {
        if (is_array($ref) && isset($ref['r'])) { $flujo = musa_pdf_flujo($ctx, $ref['r'], false); if ($flujo !== null) { $partes[] = $flujo; } }
    }
    return implode("\n", $partes);
}

/* ------------------------------------------------------------------ */
/*  Operadores de texto                                                 */
/* ------------------------------------------------------------------ */

/**
 * Interpreta un flujo de contenido y devuelve su texto. Sigue la matriz de texto y el ancho real de
 * cada glifo para saber dónde termina lo escrito: así distingue el espacio entre palabras del ajuste
 * entre letras (interletrado, kerning) y nota cuándo empieza una línea o un párrafo nuevo. Las formas
 * (XObject /Form) se recorren hasta tres niveles.
 */
function musa_pdf_interpretar(&$ctx, $contenido, $recursos, $profundidad) {
    $fuentes = musa_pdf_res($ctx, musa_pdf_dic($recursos, 'Font'));
    $formas = musa_pdf_res($ctx, musa_pdf_dic($recursos, 'XObject'));
    $e = array(
        'salida' => '', 'fuente' => null, 'tamano' => 10.0, 'interlineado' => 0.0, 'tc' => 0.0, 'tw' => 0.0, 'th' => 1.0,
        'tm' => array(1, 0, 0, 1, 0, 0), 'tlm' => array(1, 0, 0, 1, 0, 0), 'ultimaY' => null, 'finX' => null,
    );
    $operandos = array();
    $n = strlen($contenido);
    $i = 0; $contador = 0;
    $numero = function ($k) use (&$operandos) { return isset($operandos[$k]) && is_numeric($operandos[$k]) ? (float) $operandos[$k] : 0.0; };
    while ($i < $n) {
        if ((++$contador & 4095) === 0 && microtime(true) > $ctx['fin']) { $ctx['incompleto'] = true; break; }
        $v = musa_pdf_valor($contenido, $i, $n);
        if (!is_array($v) || !isset($v['k'])) {
            if ($v !== null && count($operandos) < 64) { $operandos[] = $v; }
            continue;
        }
        $op = $v['k'];
        switch ($op) {
            case 'BT':
                $e['tm'] = $e['tlm'] = array(1, 0, 0, 1, 0, 0);
                break;
            case 'Tf':
                $nombre = isset($operandos[0]) ? musa_pdf_nombre_de($operandos[0]) : '';
                if (isset($operandos[1]) && is_numeric($operandos[1])) { $e['tamano'] = (float) $operandos[1]; }
                $e['fuente'] = musa_pdf_fuente($ctx, musa_pdf_dic($fuentes, $nombre));
                break;
            case 'TL': $e['interlineado'] = $numero(0); break;
            case 'Tc': $e['tc'] = $numero(0); break;
            case 'Tw': $e['tw'] = $numero(0); break;
            case 'Tz': $e['th'] = $numero(0) / 100; break;
            case 'Td': case 'TD':
                if ($op === 'TD') { $e['interlineado'] = -$numero(1); }
                musa_pdf_mover($e, $numero(0), $numero(1));
                break;
            case 'Tm':
                if (count($operandos) >= 6) { $e['tm'] = $e['tlm'] = array_map('floatval', array_slice($operandos, 0, 6)); }
                break;
            case 'T*':
                musa_pdf_mover($e, 0, -$e['interlineado']);
                break;
            case 'Tj':
                if (isset($operandos[0]['s'])) { musa_pdf_mostrar($e, $operandos[0]['s']); }
                break;
            case "'": case '"':
                if ($op === '"' && count($operandos) >= 3) { $e['tw'] = $numero(0); $e['tc'] = $numero(1); }
                musa_pdf_mover($e, 0, -$e['interlineado']);
                $ultimo = end($operandos);
                if (is_array($ultimo) && isset($ultimo['s'])) { musa_pdf_mostrar($e, $ultimo['s']); }
                break;
            case 'TJ':
                if (isset($operandos[0]['a'])) {
                    foreach ($operandos[0]['a'] as $pieza) {
                        if (is_array($pieza) && isset($pieza['s'])) { musa_pdf_mostrar($e, $pieza['s']); }
                        elseif (is_numeric($pieza)) {
                            $avance = -$pieza / 1000 * $e['tamano'] * $e['th'];
                            $e['tm'][4] += $avance * $e['tm'][0];
                            $e['tm'][5] += $avance * $e['tm'][1];
                        }
                    }
                }
                break;
            case 'Do':
                $nombre = isset($operandos[0]) ? musa_pdf_nombre_de($operandos[0]) : '';
                $ref = musa_pdf_dic($formas, $nombre);
                if ($profundidad < 3 && is_array($ref) && isset($ref['r'])) {
                    $dic = musa_pdf_valor_objeto($ctx, $ref['r']);
                    if (musa_pdf_nombre_de(musa_pdf_dic($dic, 'Subtype')) === 'Form') {
                        $flujo = musa_pdf_flujo($ctx, $ref['r'], false);
                        $propios = musa_pdf_dic($dic, 'Resources');
                        if ($flujo !== null) {
                            $texto = musa_pdf_interpretar($ctx, $flujo, $propios !== null ? musa_pdf_res($ctx, $propios) : $recursos, $profundidad + 1);
                            if (trim($texto) !== '') { $e['salida'] .= "\n" . $texto . "\n"; $e['ultimaY'] = null; $e['finX'] = null; }
                        }
                    }
                }
                break;
            case 'BI':
                // Imagen en línea: sus datos binarios van entre «ID» y «EI».
                if (preg_match('/\bID[\x00\t\n\f\r ]/', $contenido, $x, PREG_OFFSET_CAPTURE, $i)) {
                    $i = preg_match('/[\x00\t\n\f\r ]EI(?=[\x00\t\n\f\r ]|$)/', $contenido, $y, PREG_OFFSET_CAPTURE, $x[0][1] + 3) ? $y[0][1] + 3 : $n;
                }
                break;
        }
        $operandos = array();
    }
    return $e['salida'];
}

/** Td / T*: nueva posición de línea relativa al inicio de la línea anterior. */
function musa_pdf_mover(&$e, $tx, $ty) {
    $l = $e['tlm'];
    $l[4] += $tx * $l[0] + $ty * $l[2];
    $l[5] += $tx * $l[1] + $ty * $l[3];
    $e['tlm'] = $e['tm'] = $l;
}

/**
 * Escribe una cadena en la posición actual. Si cae en otra línea agrega un salto (dos si el salto
 * vertical es grande: párrafo nuevo); si en la misma línea queda un hueco mayor que ~0,18 del tamaño
 * de la letra respecto de donde terminó lo anterior, agrega un espacio.
 */
function musa_pdf_mostrar(&$e, $bytes) {
    $tm = $e['tm'];
    $escalaX = sqrt($tm[0] * $tm[0] + $tm[1] * $tm[1]);
    $escalaY = sqrt($tm[2] * $tm[2] + $tm[3] * $tm[3]);
    $tamanoY = abs($e['tamano']) * ($escalaY > 0 ? $escalaY : 1);
    $tamanoX = abs($e['tamano']) * ($escalaX > 0 ? $escalaX : 1);
    $x = $tm[4]; $y = $tm[5];
    $final = substr($e['salida'], -1);
    if ($e['ultimaY'] !== null && abs($y - $e['ultimaY']) > max(0.5, $tamanoY * 0.4)) {
        $e['salida'] .= abs($y - $e['ultimaY']) > max(4, $tamanoY * 1.9) ? "\n\n" : "\n";
    } elseif ($e['finX'] !== null && $final !== ' ' && $final !== "\n" && $final !== '' && abs($x - $e['finX']) > $tamanoX * 0.18) {
        $e['salida'] .= ' ';
    }
    $ancho = 0.0; $glifos = 0; $espacios = 0;
    $e['salida'] .= musa_pdf_decodificar_texto($bytes, $e['fuente'], $ancho, $glifos, $espacios);
    $avance = ($ancho * $e['tamano'] + $glifos * $e['tc'] + $espacios * $e['tw']) * $e['th'];
    $e['tm'][4] += $avance * $tm[0];
    $e['tm'][5] += $avance * $tm[1];
    $e['finX'] = $e['tm'][4];
    $e['ultimaY'] = $y;
}

/* ------------------------------------------------------------------ */
/*  Fuentes y codificación                                              */
/* ------------------------------------------------------------------ */

/**
 * Tabla de una fuente: array('mapa' => [código => texto UTF-8], 'bytes' => 1|2, 'simple' => bool).
 * Se guarda en caché por número de objeto.
 */
function musa_pdf_fuente(&$ctx, $ref) {
    $clave = is_array($ref) && isset($ref['r']) ? 'o' . $ref['r'] : 'd' . md5(serialize($ref));
    if (isset($ctx['fuentes'][$clave])) { return $ctx['fuentes'][$clave]; }
    $dic = musa_pdf_res($ctx, $ref);
    $subtipo = musa_pdf_nombre_de(musa_pdf_dic($dic, 'Subtype'));
    $compuesta = $subtipo === 'Type0';
    $fuente = array('mapa' => array(), 'bytes' => $compuesta ? 2 : 1, 'simple' => !$compuesta, 'anchos' => array(), 'defecto' => 0.5);
    if (!$compuesta) {
        $fuente['mapa'] = musa_pdf_codificacion_simple($ctx, musa_pdf_dic($dic, 'Encoding'));
        // Anchos en milésimas de la letra: /FirstChar + /Widths (y /MissingWidth del descriptor).
        $primero = (int) musa_pdf_res($ctx, musa_pdf_dic($dic, 'FirstChar', 0));
        $anchos = musa_pdf_res($ctx, musa_pdf_dic($dic, 'Widths'));
        if (is_array($anchos) && isset($anchos['a'])) {
            foreach ($anchos['a'] as $k => $w) { $w = musa_pdf_res($ctx, $w); if (is_numeric($w)) { $fuente['anchos'][$primero + $k] = $w / 1000; } }
        }
        $faltante = musa_pdf_res($ctx, musa_pdf_dic(musa_pdf_res($ctx, musa_pdf_dic($dic, 'FontDescriptor')), 'MissingWidth'));
        if (is_numeric($faltante) && $faltante > 0) { $fuente['defecto'] = $faltante / 1000; }
    } else {
        // Fuente compuesta: anchos en la fuente descendiente (/W y /DW).
        $descendientes = musa_pdf_res($ctx, musa_pdf_dic($dic, 'DescendantFonts'));
        $cid = is_array($descendientes) && isset($descendientes['a'][0]) ? musa_pdf_res($ctx, $descendientes['a'][0]) : null;
        $dw = musa_pdf_res($ctx, musa_pdf_dic($cid, 'DW'));
        $fuente['defecto'] = is_numeric($dw) ? $dw / 1000 : 1.0;
        $w = musa_pdf_res($ctx, musa_pdf_dic($cid, 'W'));
        if (is_array($w) && isset($w['a'])) {
            $lista = $w['a']; $total = count($lista); $cuenta = 0;
            for ($k = 0; $k < $total && $cuenta < 70000; ) {
                $c = musa_pdf_res($ctx, $lista[$k]);
                $siguiente = isset($lista[$k + 1]) ? musa_pdf_res($ctx, $lista[$k + 1]) : null;
                if (!is_numeric($c)) { $k++; continue; }
                if (is_array($siguiente) && isset($siguiente['a'])) {
                    foreach ($siguiente['a'] as $j => $ancho) { if (is_numeric($ancho)) { $fuente['anchos'][(int) $c + $j] = $ancho / 1000; $cuenta++; } }
                    $k += 2;
                } elseif (is_numeric($siguiente) && isset($lista[$k + 2]) && is_numeric($lista[$k + 2])) {
                    for ($cc = (int) $c; $cc <= (int) $siguiente && $cuenta < 70000; $cc++, $cuenta++) { $fuente['anchos'][$cc] = $lista[$k + 2] / 1000; }
                    $k += 3;
                } else { $k++; }
            }
        }
    }
    $toUnicode = musa_pdf_dic($dic, 'ToUnicode');
    if (is_array($toUnicode) && isset($toUnicode['r'])) {
        $cmap = musa_pdf_flujo($ctx, $toUnicode['r']);
        if ($cmap !== null) {
            $leido = musa_pdf_cmap($cmap);
            if ($leido['bytes'] > 0) { $fuente['bytes'] = $leido['bytes']; }
            $fuente['mapa'] = $leido['mapa'] + $fuente['mapa'];
        }
    }
    $ctx['fuentes'][$clave] = $fuente;
    return $fuente;
}

/**
 * Lee una tabla ToUnicode (bfchar y bfrange). Se recorre entrada por entrada, con un tope común de
 * 200 000 códigos y de 4 MB de tabla: una tabla enorme no puede agotar la memoria.
 */
function musa_pdf_cmap($cmap) {
    $cmap = substr((string) $cmap, 0, 4 * 1024 * 1024);
    $mapa = array(); $bytes = 0; $total = 0; $tope = 200000;
    if (preg_match('/begincodespacerange(.{0,4096}?)endcodespacerange/s', $cmap, $rango) && preg_match_all('/<([0-9A-Fa-f]+)>/', $rango[1], $h)) {
        foreach ($h[1] as $hex) { $bytes = max($bytes, (int) ceil(strlen($hex) / 2)); }
    }
    $unicode = function ($hex) {
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex);
        if ($hex === '') { return ''; }
        if (strlen($hex) % 4) { $hex = str_pad($hex, (int) ceil(strlen($hex) / 4) * 4, '0', STR_PAD_LEFT); }
        return (string) @mb_convert_encoding((string) hex2bin(substr($hex, 0, 64)), 'UTF-8', 'UTF-16BE');
    };
    $largo = strlen($cmap);
    // bfchar: <código> <unicode>
    for ($pos = 0; $total < $tope && ($ini = strpos($cmap, 'beginbfchar', $pos)) !== false; $pos = $fin + 9) {
        $fin = strpos($cmap, 'endbfchar', $ini);
        if ($fin === false) { $fin = $largo; }
        for ($i = $ini + 11; $total < $tope && $i < $fin && preg_match('/\G\s*<([0-9A-Fa-f]{1,8})>\s*<([0-9A-Fa-f]{0,64})>/', $cmap, $p, 0, $i); $i += strlen($p[0])) {
            $mapa[hexdec($p[1])] = $unicode($p[2]);
            if (!$bytes) { $bytes = (int) ceil(strlen($p[1]) / 2); }
            $total++;
        }
    }
    // bfrange: <desde> <hasta> <unicode inicial> o <desde> <hasta> [<u1> <u2> …]
    for ($pos = 0; $total < $tope && ($ini = strpos($cmap, 'beginbfrange', $pos)) !== false; $pos = $fin + 10) {
        $fin = strpos($cmap, 'endbfrange', $ini);
        if ($fin === false) { $fin = $largo; }
        for ($i = $ini + 12; $total < $tope && $i < $fin && preg_match('/\G\s*<([0-9A-Fa-f]{1,8})>\s*<([0-9A-Fa-f]{1,8})>\s*(<[0-9A-Fa-f]{0,64}>|\[[^\]]{0,65535}\])/', $cmap, $r, 0, $i); $i += strlen($r[0])) {
            $desde = hexdec($r[1]); $hasta = hexdec($r[2]);
            if ($hasta < $desde || $hasta - $desde > 65535) { continue; }
            $hasta = min($hasta, $desde + $tope - $total - 1);
            $total += $hasta - $desde + 1;
            if (!$bytes) { $bytes = (int) ceil(strlen($r[1]) / 2); }
            if ($r[3][0] === '[') {
                preg_match_all('/<([0-9A-Fa-f]{0,64})>/', $r[3], $destinos);
                foreach ($destinos[1] as $k => $hex) { if ($desde + $k > $hasta) { break; } $mapa[$desde + $k] = $unicode($hex); }
            } else {
                $base = preg_replace('/[^0-9A-Fa-f]/', '', $r[3]);
                if ($base === '') { continue; }
                if (strlen($base) % 4) { $base = str_pad($base, (int) ceil(strlen($base) / 4) * 4, '0', STR_PAD_LEFT); }
                $prefijo = substr($base, 0, -4);
                $ultimo = hexdec(substr($base, -4));
                for ($c = $desde; $c <= $hasta; $c++) {
                    $mapa[$c] = $unicode($prefijo . str_pad(dechex(($ultimo + $c - $desde) & 0xFFFF), 4, '0', STR_PAD_LEFT));
                }
            }
        }
    }
    return array('mapa' => $mapa, 'bytes' => $bytes);
}

/** Tabla de una fuente simple: WinAnsi (o MacRoman) y las diferencias por nombre de glifo. */
function musa_pdf_codificacion_simple(&$ctx, $codificacion) {
    static $winAnsi = null;
    if ($winAnsi === null) {
        $winAnsi = array();
        for ($b = 32; $b < 256; $b++) { $winAnsi[$b] = (string) @mb_convert_encoding(chr($b), 'UTF-8', 'Windows-1252'); }
    }
    $mapa = $winAnsi;
    $codificacion = musa_pdf_res($ctx, $codificacion);
    $diferencias = musa_pdf_res($ctx, musa_pdf_dic($codificacion, 'Differences'));
    if (is_array($diferencias) && isset($diferencias['a'])) {
        $codigo = 0;
        foreach ($diferencias['a'] as $d) {
            if (is_int($d) || is_float($d)) { $codigo = (int) $d; continue; }
            $glifo = musa_pdf_nombre_de($d);
            if ($glifo !== '') { $caracter = musa_pdf_glifo($glifo); if ($caracter !== null) { $mapa[$codigo] = $caracter; } $codigo++; }
        }
    }
    return $mapa;
}

/** Carácter de un nombre de glifo (uniXXXX, letras sueltas y los nombres más comunes en español). */
function musa_pdf_glifo($nombre) {
    static $tabla = array(
        'space' => ' ', 'exclam' => '!', 'quotedbl' => '"', 'numbersign' => '#', 'dollar' => '$', 'percent' => '%', 'ampersand' => '&',
        'quotesingle' => "'", 'parenleft' => '(', 'parenright' => ')', 'asterisk' => '*', 'plus' => '+', 'comma' => ',', 'hyphen' => '-',
        'period' => '.', 'slash' => '/', 'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5', 'six' => '6',
        'seven' => '7', 'eight' => '8', 'nine' => '9', 'colon' => ':', 'semicolon' => ';', 'less' => '<', 'equal' => '=', 'greater' => '>',
        'question' => '?', 'at' => '@', 'bracketleft' => '[', 'backslash' => '\\', 'bracketright' => ']', 'underscore' => '_',
        'quoteleft' => '‘', 'quoteright' => '’', 'quotedblleft' => '“', 'quotedblright' => '”', 'endash' => '–', 'emdash' => '—',
        'bullet' => '•', 'ellipsis' => '…', 'exclamdown' => '¡', 'questiondown' => '¿', 'degree' => '°', 'ordfeminine' => 'ª', 'ordmasculine' => 'º',
        'guillemotleft' => '«', 'guillemotright' => '»', 'copyright' => '©', 'registered' => '®', 'fi' => 'fi', 'fl' => 'fl', 'nbspace' => ' ',
        'aacute' => 'á', 'eacute' => 'é', 'iacute' => 'í', 'oacute' => 'ó', 'uacute' => 'ú', 'ntilde' => 'ñ', 'udieresis' => 'ü',
        'Aacute' => 'Á', 'Eacute' => 'É', 'Iacute' => 'Í', 'Oacute' => 'Ó', 'Uacute' => 'Ú', 'Ntilde' => 'Ñ', 'Udieresis' => 'Ü',
        'agrave' => 'à', 'egrave' => 'è', 'ccedilla' => 'ç', 'Ccedilla' => 'Ç', 'euro' => '€',
    );
    if (isset($tabla[$nombre])) { return $tabla[$nombre]; }
    if (preg_match('/^[A-Za-z]$/', $nombre)) { return $nombre; }
    if (preg_match('/^uni([0-9A-Fa-f]{4})/', $nombre, $m)) { return (string) @mb_convert_encoding(hex2bin($m[1]), 'UTF-8', 'UTF-16BE'); }
    return null;
}

/**
 * Bytes de una cadena mostrada → texto UTF-8 según la fuente activa. Suma también el ancho de los
 * glifos (en unidades de la letra), cuántos son y cuántos espacios de un byte hay (para Tc y Tw).
 */
function musa_pdf_decodificar_texto($bytes, $fuente, &$ancho = 0.0, &$glifos = 0, &$espacios = 0) {
    if (!is_array($fuente)) { $fuente = array('mapa' => array(), 'bytes' => 1, 'simple' => true, 'anchos' => array(), 'defecto' => 0.5); }
    $salida = '';
    $largo = strlen($bytes);
    $paso = $fuente['bytes'] >= 2 ? 2 : 1;
    for ($k = 0; $k + $paso - 1 < $largo; $k += $paso) {
        $codigo = $paso === 2 ? (ord($bytes[$k]) << 8) | ord($bytes[$k + 1]) : ord($bytes[$k]);
        $ancho += isset($fuente['anchos'][$codigo]) ? $fuente['anchos'][$codigo] : $fuente['defecto'];
        $glifos++;
        if ($paso === 1 && $codigo === 32) { $espacios++; }
        if (isset($fuente['mapa'][$codigo])) { $salida .= $fuente['mapa'][$codigo]; }
        elseif ($paso === 1 && $fuente['simple'] && $codigo >= 32) { $salida .= (string) @mb_convert_encoding(chr($codigo), 'UTF-8', 'Windows-1252'); }
    }
    return $salida;
}
