<?php
/**
 * QuéDice! · Interfaz pública del avatar conversacional
 * Gobernación de Nariño
 *
 * Toda la experiencia vive en un solo contenedor (#app) de 100 % de ancho y 100vh de alto,
 * sin desplazamiento de página: escena 3D y avatar al centro, transcripción y controles abajo.
 */
require_once __DIR__ . '/wj-includes/arranque.php';

musa_cabeceras_seguridad('publica');
$nonce = musa_nonce();
musa_sesion();

$ajustes = musa_ajustes();
$colores = musa_dato($ajustes, 'colores', array());
$textos  = musa_dato($ajustes, 'textos', array());
$marca   = musa_dato($ajustes, 'marca', array());
$form    = musa_dato($ajustes, 'formulario', array());
$avatar  = musa_dato($ajustes, 'avatar', array());
$token   = musa_token();

$imagen = function ($ruta, $respaldo = '') {
    return musa_ruta_imagen_valida($ruta) ? musa_url($ruta) : ($respaldo !== '' && musa_ruta_imagen_valida($respaldo) ? musa_url($respaldo) : '');
};
$t = function ($clave, $respaldo = '') use ($textos) {
    $valor = musa_dato($textos, $clave, '');
    return (string) ($valor !== '' ? $valor : $respaldo);
};
$c = function ($clave, $respaldo) use ($colores) {
    return musa_color(musa_dato($colores, $clave, ''), $respaldo);
};

$logo        = $imagen(musa_dato($marca, 'logo', ''), 'wj-includes/images/quedice/logo-quedice.png');
$rama        = $imagen(musa_dato($marca, 'fondo', ''));
$barra       = $imagen(musa_dato($marca, 'barra', ''));
$fondo       = $imagen(musa_dato($marca, 'imagen_fondo', ''));
$logoEntidad = $imagen(musa_dato($marca, 'logo_entidad', ''));
$favicon     = $imagen(musa_dato($marca, 'favicon', ''), 'wj-includes/images/quedice/icono-quedice.png');
$retrato     = $imagen(musa_dato($avatar, 'retrato', ''), 'wj-includes/images/avatar/avatar-cafe.webp');

$formularioActivo = !empty($form['activo']);
$exigirAceptacion = !empty(musa_dato($ajustes, 'seguridad.exigir_aceptacion', true));
$permitirEscribir = !empty(musa_dato($avatar, 'permitir_escribir', true));
$sugerencias      = musa_sugerencias($ajustes);
$nombreAvatar     = (string) musa_dato($avatar, 'nombre', 'Anfitrión');
$disponible       = musa_heygen_configurado($ajustes);
$mostrarGovco     = !empty(musa_dato($marca, 'mostrar_govco', true));
$entidad          = (string) musa_dato($marca, 'entidad', 'Gobernación de Nariño');
$sitioEntidad     = musa_url_externa(musa_dato($marca, 'sitio_entidad', ''));
$opacidadFondo    = max(0, min(100, (int) musa_dato($marca, 'opacidad_fondo', 35))) / 100;
$formatos         = array('3/4' => '3 / 4', '1/1' => '1 / 1', '16/9' => '16 / 9', '9/16' => '9 / 16');
$formato          = (string) musa_dato($avatar, 'formato', '3/4');
$proporcion       = isset($formatos[$formato]) ? $formatos[$formato] : '3 / 4';
$municipios       = !empty($form['ciudad_lista']) ? musa_municipios_narino() : array();

$configJs = array(
    'token'            => $token,
    'efectos3d'        => (bool) musa_dato($ajustes, 'sistema.efectos_3d', true),
    'formulario'       => $formularioActivo,
    'exigirAceptacion' => $exigirAceptacion,
    'disponible'       => $disponible,
    'avatar'           => array(
        'nombre'       => $nombreAvatar,
        'microfono'    => (bool) musa_dato($avatar, 'microfono_inicial', true),
        'escribir'     => $permitirEscribir,
        'pulsarHablar' => musa_dato($avatar, 'interactividad', 'CONVERSATIONAL') === 'PUSH_TO_TALK',
    ),
    'colores'          => array(
        'fondo'    => $c('fondo', '#0B7A2E'),
        'profundo' => $c('fondo_profundo', '#003366'),
        'texto'    => $c('texto', '#FFFFFF'),
        'acento'   => $c('acento', '#FFD500'),
        'acento2'  => $c('acento_secundario', '#4FC3F7'),
    ),
    'textos'           => array(
        'conectando' => $t('conectando', 'Preparando al anfitrión…'),
        'escuchando' => $t('escuchando', 'Te escucho…'),
        'hablando'   => $t('hablando', 'Respondiendo…'),
        'microfono'  => $t('aviso_microfono', ''),
        'persona'    => 'Tú',
    ),
    'rutas'            => array(
        'sesion'    => musa_url('wj-includes/api/sesion.php'),
        'mensajes'  => musa_url('wj-includes/api/mensajes.php'),
        'mantener'  => musa_url('wj-includes/api/mantener.php'),
        'finalizar' => musa_url('wj-includes/api/finalizar.php'),
    ),
);

$css = array(
    '--musa-fondo'             => $c('fondo', '#0B7A2E'),
    '--musa-fondo-profundo'    => $c('fondo_profundo', '#003366'),
    '--musa-fondo-rgb'         => musa_color_rgb($c('fondo_profundo', '#003366'), '0, 51, 102'),
    '--musa-tarjeta'           => $c('tarjeta', '#0A5C2A'),
    '--musa-tarjeta-borde'     => $c('tarjeta_borde', '#10A13B'),
    '--musa-texto'             => $c('texto', '#FFFFFF'),
    '--musa-texto-rgb'         => musa_color_rgb($c('texto', '#FFFFFF'), '255, 255, 255'),
    '--musa-texto-suave'       => $c('texto_suave', '#EAF5EC'),
    '--musa-acento'            => $c('acento', '#FFD500'),
    '--musa-acento-rgb'        => musa_color_rgb($c('acento', '#FFD500'), '255, 213, 0'),
    '--musa-sobre-acento'      => $c('texto_sobre_acento', '#1A1A1A'),
    '--musa-acento-secundario' => $c('acento_secundario', '#4FC3F7'),
    '--musa-burbuja-persona'   => $c('burbuja_persona', '#FFFFFF'),
    '--musa-texto-persona'     => $c('texto_persona', '#003366'),
    '--musa-exito'             => $c('exito', '#10A13B'),
    '--musa-error'             => $c('error', '#FFB3BC'),
    '--musa-institucional'     => $c('institucional', '#003366'),
    '--musa-opacidad-fondo'    => (string) $opacidadFondo,
    '--musa-proporcion'        => $proporcion,
);
// url() sin comillas y cada segmento codificado: dentro de <style> no hay forma de cerrar la regla.
if ($fondo !== '') { $css['--musa-imagen-fondo'] = 'url(' . implode('/', array_map('rawurlencode', explode('/', $fondo))) . ')'; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="description" content="<?php echo musa_e(musa_dato($marca, 'descripcion', '')); ?>">
<meta name="theme-color" content="<?php echo musa_e($c('fondo_profundo', '#003366')); ?>">
<title><?php echo musa_e(musa_dato($marca, 'titulo_sitio', 'QuéDice!')); ?></title>
<?php if ($favicon !== '') : ?><link rel="icon" href="<?php echo musa_e($favicon); ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Hind+Madurai:wght@500;600;700&family=Nunito+Sans:opsz,wght@6..12,400;6..12,600;6..12,700&display=swap" rel="stylesheet">
<?php if ($retrato !== '') : ?><link rel="preload" as="image" href="<?php echo musa_e($retrato); ?>"><?php endif; ?>
<link rel="stylesheet" href="<?php echo musa_e(musa_recurso('wj-includes/css/app.css')); ?>">
<style nonce="<?php echo musa_e($nonce); ?>">:root{<?php foreach ($css as $var => $valor) { echo $var . ':' . musa_e($valor) . ';'; } ?>}</style>
</head>
<body class="estado-inicio">

<div id="app" class="app<?php echo $mostrarGovco ? ' con-govco' : ''; ?>">

  <?php if ($fondo !== '') : ?><div class="capa-fondo" aria-hidden="true"></div><?php endif; ?>
  <canvas id="escena" aria-hidden="true"></canvas>
  <?php if ($rama !== '') : ?><img class="deco deco-rama" src="<?php echo musa_e($rama); ?>" alt="" aria-hidden="true"><?php endif; ?>
  <?php if ($barra !== '') : ?><img class="deco deco-barra" src="<?php echo musa_e($barra); ?>" alt="" aria-hidden="true"><?php endif; ?>

  <a class="saltar" href="#pregunta">Ir a la caja de preguntas</a>

  <?php if ($mostrarGovco) : ?>
  <div class="govco" role="navigation" aria-label="Portal del Estado colombiano">
    <a class="govco-marca" href="https://www.gov.co" target="_blank" rel="noopener" aria-label="GOV.CO, portal del Estado colombiano">GOV.CO</a>
    <a class="govco-entidad" href="<?php echo musa_e($sitioEntidad !== '' ? $sitioEntidad : 'https://www.narino.gov.co'); ?>" target="_blank" rel="noopener">
      <?php if ($logoEntidad !== '') : ?><img src="<?php echo musa_e($logoEntidad); ?>" alt="<?php echo musa_e($entidad); ?>"><?php else : ?><?php echo musa_e($entidad); ?><?php endif; ?>
    </a>
  </div>
  <?php endif; ?>

  <header class="cabecera">
    <?php if ($logo !== '') : ?>
      <img class="logo" src="<?php echo musa_e($logo); ?>" alt="<?php echo musa_e(musa_dato($marca, 'nombre', 'QuéDice!')); ?>">
    <?php else : ?>
      <strong class="logo logo-texto"><?php echo musa_e(musa_dato($marca, 'nombre', 'QuéDice!')); ?></strong>
    <?php endif; ?>
    <div class="titulos">
      <h1><?php echo musa_e($t('titulo', 'Conversa sobre café')); ?></h1>
      <p><?php echo musa_e($t('subtitulo', '')); ?></p>
    </div>
    <div class="cabecera-herramientas">
      <div class="reloj" id="reloj" hidden aria-live="off" title="Tiempo restante de la conversación">
        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        <span id="reloj-texto">10:00</span>
      </div>
      <button type="button" class="boton-accesible" id="accesibilidad" aria-pressed="false" title="Texto grande y alto contraste">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><circle cx="12" cy="4.2" r="2.2" fill="currentColor"/><path d="M4 8.2c2.7.9 5.3 1.3 8 1.3s5.3-.4 8-1.3M12 9.5v5.2m0 0-3.2 6.1M12 14.7l3.2 6.1" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span class="solo-lectores">Accesibilidad: texto grande y alto contraste</span>
      </button>
    </div>
  </header>

  <main class="escenario" id="escenario">
    <section class="avatar-marco" id="avatar-marco" aria-label="<?php echo musa_e($nombreAvatar); ?>">
      <?php if ($retrato !== '') : ?>
        <img class="avatar-retrato" id="avatar-retrato" src="<?php echo musa_e($retrato); ?>" alt="<?php echo musa_e($nombreAvatar); ?>">
      <?php endif; ?>
      <video class="avatar-video" id="avatar-video" playsinline autoplay muted></video>
      <audio id="avatar-audio" autoplay></audio>

      <div class="estado-avatar" id="estado-avatar" role="status" aria-live="polite">
        <span class="ondas" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span>
        <span id="estado-texto"></span>
      </div>

      <div class="velo" id="velo">
        <div class="velo-contenido" id="velo-inicio">
          <p class="velo-nombre"><?php echo musa_e($nombreAvatar); ?></p>
          <?php if ($disponible) : ?>
            <button type="button" class="boton boton-grande" id="iniciar">
              <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7a2.5 2.5 0 0 1-2.5 2.5H10l-4.5 4v-4A1.5 1.5 0 0 1 4 14.5z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
              <?php echo musa_e($t('boton_iniciar', 'Iniciar conversación')); ?>
            </button>
          <?php else : ?>
            <p class="aviso-no-disponible">El anfitrión no está disponible en este momento.</p>
          <?php endif; ?>
        </div>
        <div class="velo-contenido" id="velo-cargando" hidden>
          <span class="giro" aria-hidden="true"></span>
          <p role="status"><?php echo musa_e($t('conectando', 'Preparando al anfitrión…')); ?></p>
        </div>
        <div class="velo-contenido" id="velo-final" hidden>
          <h2><?php echo musa_e($t('despedida_titulo', '¡Gracias por conversar!')); ?></h2>
          <p><?php echo musa_e($t('despedida_texto', '')); ?></p>
          <p class="codigo">Código: <strong id="codigo-final">—</strong></p>
          <button type="button" class="boton" id="otra"><?php echo musa_e($t('boton_iniciar', 'Iniciar conversación')); ?></button>
        </div>
        <div class="velo-contenido" id="velo-audio" hidden>
          <button type="button" class="boton" id="activar-audio">Activar el sonido</button>
        </div>
      </div>
    </section>
  </main>

  <section class="dock" aria-labelledby="titulo-transcripcion">
    <h2 id="titulo-transcripcion" class="solo-lectores"><?php echo musa_e($t('transcripcion', 'Transcripción de la conversación')); ?></h2>
    <div class="transcripcion" id="transcripcion" role="log" aria-live="polite" aria-relevant="additions" tabindex="0" aria-label="<?php echo musa_e($t('transcripcion', 'Transcripción de la conversación')); ?>">
      <p class="transcripcion-vacia" id="transcripcion-vacia"><?php echo musa_e($t('transcripcion_vacia', 'Aquí verás la transcripción de la conversación.')); ?></p>
    </div>
    <p class="parcial" id="parcial" aria-hidden="true"></p>

    <?php if ($sugerencias !== array()) : ?>
      <div class="sugerencias" id="sugerencias" role="group" aria-label="<?php echo musa_e($t('sugerencias', 'Puedes preguntar:')); ?>">
        <span><?php echo musa_e($t('sugerencias', 'Puedes preguntar:')); ?></span>
        <?php foreach ($sugerencias as $sugerencia) : ?>
          <button type="button" class="sugerencia" disabled><?php echo musa_e($sugerencia); ?></button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="entrada">
      <div class="controles" id="controles" hidden>
        <button type="button" class="control" id="microfono" aria-pressed="true" title="<?php echo musa_e($t('boton_microfono', 'Micrófono')); ?>">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
            <rect x="9" y="2" width="6" height="12" rx="3"></rect><path d="M5 11a7 7 0 0 0 14 0"></path><path d="M12 18v4"></path><path class="tachado" d="M3 3l18 18"></path>
          </svg>
          <span class="solo-lectores" id="microfono-texto"><?php echo musa_e($t('boton_microfono', 'Micrófono')); ?></span>
        </button>
      </div>

      <?php if ($permitirEscribir) : ?>
        <form class="escribir" id="escribir" autocomplete="off">
          <label for="pregunta" class="solo-lectores"><?php echo musa_e($t('escribir_ayuda', 'Escribe tu pregunta…')); ?></label>
          <input type="text" id="pregunta" maxlength="500" placeholder="<?php echo musa_e($t('escribir_ayuda', 'Escribe tu pregunta…')); ?>" disabled>
          <button type="submit" class="boton-enviar" id="enviar-pregunta" disabled title="<?php echo musa_e($t('boton_enviar', 'Enviar')); ?>">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M12 19V5m0 0-6 6m6-6 6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="solo-lectores"><?php echo musa_e($t('boton_enviar', 'Enviar')); ?></span>
          </button>
        </form>
      <?php else : ?>
        <p class="entrada-ayuda" id="entrada-ayuda"><?php echo musa_e($t('pie', '')); ?></p>
      <?php endif; ?>

      <div class="controles controles-fin" id="controles-fin" hidden>
        <button type="button" class="control" id="interrumpir" title="<?php echo musa_e($t('boton_interrumpir', 'Interrumpir')); ?>">
          <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><rect x="6" y="6" width="12" height="12" rx="2.5" fill="currentColor"/></svg>
          <span class="solo-lectores"><?php echo musa_e($t('boton_interrumpir', 'Interrumpir')); ?></span>
        </button>
        <button type="button" class="control control-peligro" id="terminar" title="<?php echo musa_e($t('boton_terminar', 'Terminar')); ?>">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M18 6 6 18M6 6l12 12"/></svg>
          <span class="solo-lectores"><?php echo musa_e($t('boton_terminar', 'Terminar')); ?></span>
        </button>
      </div>
    </div>

    <p class="pie">
      <span><?php echo musa_e($t('pie', 'Gobernación de Nariño')); ?></span>
      <span class="hora-legal">Hora legal colombiana: <time id="hora-legal">—</time></span>
    </p>
  </section>

  <?php if ($formularioActivo) : ?>
  <dialog class="dialogo" id="dialogo-datos" aria-labelledby="titulo-datos">
    <form id="formulario" novalidate autocomplete="off">
      <h2 id="titulo-datos"><?php echo musa_e($t('formulario_titulo', 'Antes de empezar, cuéntanos quién eres')); ?></h2>
      <p class="ayuda"><?php echo musa_e($t('formulario_ayuda', '')); ?></p>

      <div class="campo">
        <label for="nombre">Nombres y apellidos</label>
        <input type="text" id="nombre" name="nombre" autocomplete="off" maxlength="120" required>
        <span class="error-campo" id="error-nombre"></span>
      </div>
      <?php if (!empty($form['pedir_correo'])) : ?>
      <div class="campo">
        <label for="correo">Correo electrónico <?php echo empty($form['correo_obligatorio']) ? '<small>(opcional)</small>' : ''; ?></label>
        <input type="email" id="correo" name="correo" autocomplete="off" inputmode="email" maxlength="160" <?php echo !empty($form['correo_obligatorio']) ? 'required' : ''; ?>>
        <span class="error-campo" id="error-correo"></span>
      </div>
      <?php endif; ?>
      <?php if (!empty($form['pedir_ciudad'])) : ?>
      <div class="campo">
        <label for="ciudad">Municipio <?php echo empty($form['ciudad_obligatoria']) ? '<small>(opcional)</small>' : ''; ?></label>
        <?php if ($municipios !== array()) : ?>
          <select id="ciudad" name="ciudad" <?php echo !empty($form['ciudad_obligatoria']) ? 'required' : ''; ?>>
            <option value="">Elige tu municipio</option>
            <?php foreach ($municipios as $municipio) : ?>
              <option value="<?php echo musa_e($municipio); ?>"><?php echo musa_e($municipio); ?></option>
            <?php endforeach; ?>
            <?php if (!empty($form['ciudad_otro'])) : ?><option value="Otro municipio">Otro municipio (fuera de Nariño)</option><?php endif; ?>
          </select>
        <?php else : ?>
          <input type="text" id="ciudad" name="ciudad" maxlength="80" autocomplete="off" <?php echo !empty($form['ciudad_obligatoria']) ? 'required' : ''; ?>>
        <?php endif; ?>
        <span class="error-campo" id="error-ciudad"></span>
      </div>
      <?php endif; ?>
      <?php if (!empty($form['pedir_telefono'])) : ?>
      <div class="campo">
        <label for="telefono">Teléfono <?php echo empty($form['telefono_obligatorio']) ? '<small>(opcional)</small>' : ''; ?></label>
        <input type="tel" id="telefono" name="telefono" autocomplete="off" inputmode="tel" maxlength="40" <?php echo !empty($form['telefono_obligatorio']) ? 'required' : ''; ?>>
        <span class="error-campo" id="error-telefono"></span>
      </div>
      <?php endif; ?>

      <div class="trampa" aria-hidden="true">
        <label for="sitio_web">No llenar</label>
        <input type="text" id="sitio_web" name="sitio_web" tabindex="-1" autocomplete="off">
      </div>

      <label class="acepto">
        <input type="checkbox" id="autorizacion" name="autorizacion" <?php echo $exigirAceptacion ? '' : 'checked'; ?>>
        <span><?php echo musa_e($t('aviso_datos', '')); ?></span>
      </label>
      <span class="error-campo" id="error-autorizacion"></span>

      <div class="aviso" id="aviso-formulario" role="alert" hidden></div>

      <div class="acciones">
        <button type="button" class="boton boton-linea" id="cancelar-datos">Cancelar</button>
        <button type="submit" class="boton" id="confirmar-datos"><?php echo musa_e($t('boton_iniciar', 'Iniciar conversación')); ?></button>
      </div>
    </form>
  </dialog>
  <?php endif; ?>

  <div class="aviso-flotante" id="aviso-flotante" role="alert" hidden></div>
</div>

<script nonce="<?php echo musa_e($nonce); ?>">window.MUSA_CONFIG = <?php echo json_encode($configJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="<?php echo musa_e(musa_recurso('wj-includes/js/vendor/three.min.js')); ?>" defer></script>
<script src="<?php echo musa_e(musa_recurso('wj-includes/js/vendor/livekit-client.umd.js')); ?>" defer></script>
<script src="<?php echo musa_e(musa_recurso('wj-includes/js/app.js')); ?>" defer></script>
</body>
</html>
