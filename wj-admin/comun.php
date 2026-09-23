<?php
/**
 * QuéDice! · Base del panel de administración (avatar conversacional)
 * Protege la carpeta y dibuja la cabecera y el pie de todas las pantallas.
 */
require_once dirname(__DIR__) . '/wj-includes/arranque.php';

musa_cabeceras_seguridad();
$usuarioActual = musa_exigir_admin();
$ajustesPanel = musa_ajustes();

/** Cabecera del panel. */
function musa_panel_inicio($titulo, $activo = 'registros') {
    global $usuarioActual, $ajustesPanel;
    $menu = array(
        'registros' => array('Conversaciones', 'index.php'),
        'avatar'    => array('Avatar y tema', 'avatar.php'),
        'api'       => array('API HeyGen', 'api.php'),
        'ajustes'   => array('Apariencia y formulario', 'ajustes.php'),
        'correo'    => array('Correo', 'correo.php'),
        'cuenta'    => array('Acceso', 'cuenta.php'),
    );
    ?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo musa_e($titulo); ?> · QuéDice!</title>
<link rel="icon" href="<?php echo musa_e(musa_url('wj-includes/images/quedice/icono-quedice.png')); ?>">
<link rel="stylesheet" href="<?php echo musa_e(musa_recurso('wj-includes/css/admin.css')); ?>">
</head>
<body>
<header class="barra">
  <div class="barra-marca">
    <img src="<?php echo musa_e(musa_url('wj-includes/images/quedice/logo-quedice.png')); ?>" alt="">
    <div>
      <strong>Panel de administración</strong>
      <span><?php echo musa_e(musa_dato($ajustesPanel, 'marca.entidad', 'Gobernación de Nariño')); ?></span>
    </div>
  </div>
  <div class="barra-acciones">
    <a class="boton-linea" href="<?php echo musa_e(musa_url('')); ?>" target="_blank" rel="noopener">Ver sitio</a>
    <span class="usuario"><?php echo musa_e($usuarioActual); ?></span>
    <form method="post" action="salir.php" class="en-linea"><?php musa_campo_token(); ?><button type="submit" class="boton-linea">Salir</button></form>
  </div>
</header>
<nav class="menu" aria-label="Secciones del panel">
  <?php foreach ($menu as $clave => $item) : ?>
    <a href="<?php echo musa_e($item[1]); ?>" class="<?php echo $clave === $activo ? 'activo' : ''; ?>"><?php echo musa_e($item[0]); ?></a>
  <?php endforeach; ?>
</nav>
<main class="contenido">
<h1><?php echo musa_e($titulo); ?></h1>
<?php
}

/** Pie del panel. */
function musa_panel_fin() {
    ?>
</main>
<footer class="pie-panel">QuéDice! v<?php echo musa_e(MUSA_VERSION); ?> · Gobernación de Nariño</footer>
<script src="<?php echo musa_e(musa_recurso('wj-includes/js/admin.js')); ?>"></script>
</body>
</html>
<?php
}

/** Mensaje de resultado guardado en la sesión (patrón PRG). */
function musa_panel_mensaje($texto = null, $tipo = 'exito') {
    musa_sesion();
    if ($texto !== null) {
        $_SESSION['musa_mensaje'] = array('texto' => $texto, 'tipo' => $tipo);
        return;
    }
    if (empty($_SESSION['musa_mensaje'])) { return; }
    $mensaje = $_SESSION['musa_mensaje'];
    unset($_SESSION['musa_mensaje']);
    echo '<div class="alerta ' . musa_e($mensaje['tipo']) . '">' . musa_e($mensaje['texto']) . '</div>';
}

/** Casilla de verificación de un formulario del panel. */
function musa_casilla($nombre, $marcada, $texto) {
    echo '<label class="interruptor"><input type="checkbox" name="' . musa_e($nombre) . '"' . ($marcada ? ' checked' : '') . '> ' . musa_e($texto) . '</label>';
}

/** Filtros del listado de conversaciones a partir de la URL. */
function musa_filtros_desde($origen) {
    $fecha = function ($clave) use ($origen) {
        $valor = (string) (isset($origen[$clave]) ? $origen[$clave] : '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) ? $valor : '';
    };
    $opcion = function ($clave, $validas) use ($origen) {
        $valor = (string) (isset($origen[$clave]) ? $origen[$clave] : '');
        return in_array($valor, $validas, true) ? $valor : '';
    };
    return array(
        'busqueda' => musa_texto(isset($origen['q']) ? $origen['q'] : '', 80),
        'estado'   => $opcion('estado', array('activa', 'finalizada', 'iniciando', 'error')),
        'creado'   => $opcion('creado', array('si', 'no')),
        'enviado'  => $opcion('enviado', array('si', 'no')),
        'desde'    => $fecha('desde'),
        'hasta'    => $fecha('hasta'),
    );
}

/** Campo oculto con el token CSRF. */
function musa_campo_token() {
    echo '<input type="hidden" name="token" value="' . musa_e(musa_token()) . '">';
}
