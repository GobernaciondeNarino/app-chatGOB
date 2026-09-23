<?php
/**
 * Musa Café · Avatar conversacional · Arranque del sistema
 * -------------------------------------------------------------
 * Carga constantes, funciones base y la configuración del sitio.
 * Compatible con PHP 7.4 o superior (Plesk).
 */

if (defined('MUSA_ARRANQUE')) { return; }
define('MUSA_ARRANQUE', true);
define('MUSA_VERSION', '2.0.0');

define('MUSA_RAIZ', str_replace('\\', '/', dirname(__DIR__)));
define('MUSA_INCLUDES', MUSA_RAIZ . '/wj-includes');
define('MUSA_CONTENT', MUSA_RAIZ . '/wj-content');
define('MUSA_ADMIN', MUSA_RAIZ . '/wj-admin');

define('MUSA_DIR_CONFIG', MUSA_CONTENT . '/config');
define('MUSA_DIR_DATOS', MUSA_CONTENT . '/datos');
define('MUSA_DIR_SUBIDAS', MUSA_CONTENT . '/subidas');
define('MUSA_DIR_LOGS', MUSA_CONTENT . '/logs');

define('MUSA_ARCHIVO_AJUSTES', MUSA_DIR_CONFIG . '/ajustes.json.php');
define('MUSA_ARCHIVO_CONVERSACIONES', MUSA_DIR_DATOS . '/conversaciones.json.php');

require_once MUSA_INCLUDES . '/funciones.php';
require_once MUSA_INCLUDES . '/configuracion.php';
require_once MUSA_INCLUDES . '/almacenamiento.php';
require_once MUSA_INCLUDES . '/seguridad.php';
require_once MUSA_INCLUDES . '/correo.php';
require_once MUSA_INCLUDES . '/heygen.php';

musa_preparar_carpetas();

$GLOBALS['musa_ajustes'] = musa_ajustes();

$zona = musa_dato($GLOBALS['musa_ajustes'], 'sistema.zona_horaria', 'America/Bogota');
if (@date_default_timezone_set($zona) === false) { date_default_timezone_set('America/Bogota'); }
