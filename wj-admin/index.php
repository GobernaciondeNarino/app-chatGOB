<?php
/**
 * QuéDice! · Conversaciones registradas
 * Muestra los datos de cada persona, todas sus preguntas y las respuestas del avatar,
 * y permite marcar las casillas "Creado SÍ/NO" y "Enviado SÍ/NO".
 */
require_once __DIR__ . '/comun.php';

// Cierra las conversaciones que quedaron abiertas (pestañas cerradas sin aviso).
musa_conversaciones_cerrar_vencidas($ajustesPanel);
// Bitácoras con más de 12 meses (contienen IP de quien intentó entrar): se borran.
musa_logs_purgar(12);
$usoArchivo = musa_conversaciones_uso();

$filtros = musa_filtros_desde($_GET);
$porPagina = (int) musa_dato($ajustesPanel, 'sistema.registros_por_pagina', 25);
$pagina = max(1, (int) (isset($_GET['pagina']) ? $_GET['pagina'] : 1));
$resultado = musa_conversaciones_filtrar($filtros, $pagina, $porPagina);
$resumen = musa_conversaciones_resumen();
$nombreAvatar = (string) musa_dato($ajustesPanel, 'avatar.nombre', 'Anfitrión');

$consulta = array_filter(array(
    'q' => $filtros['busqueda'], 'estado' => $filtros['estado'], 'creado' => $filtros['creado'],
    'enviado' => $filtros['enviado'], 'desde' => $filtros['desde'], 'hasta' => $filtros['hasta'],
), function ($v) { return $v !== ''; });

$estados = array('activa' => 'Activa', 'finalizada' => 'Finalizada', 'iniciando' => 'Iniciando', 'error' => 'Con error');

musa_panel_inicio('Conversaciones', 'registros');
musa_panel_mensaje();
?>

<?php if (!musa_motor_disponible($ajustesPanel)) : ?>
  <?php if (musa_motor($ajustesPanel) === 'liveavatar') : ?>
    <div class="alerta error">Falta la clave de API de HeyGen LiveAvatar: el avatar no puede atender a nadie. Configúrala en <a href="api.php">HeyGen LiveAvatar</a> o cambia al motor económico en <a href="motor.php">Motor y APIs</a>.</div>
  <?php else : ?>
    <div class="alerta error">Falta configurar la IA de texto del motor económico: el avatar no puede atender a nadie. Configúrala en <a href="motor.php">Motor y APIs</a>.</div>
  <?php endif; ?>
<?php endif; ?>
<?php if (file_exists(MUSA_DIR_CONFIG . '/claves.php')) : ?>
  <div class="alerta error">El archivo <code>wj-content/config/claves.php</code> todavía existe y guarda la clave de API en texto plano. Ya no se usa (la clave vive en los ajustes): bórralo desde el Administrador de archivos de Plesk.</div>
<?php endif; ?>
<?php if ($usoArchivo >= 70) : ?>
  <div class="alerta <?php echo $usoArchivo >= 100 ? 'error' : ''; ?>">
    El archivo de conversaciones está al <?php echo (int) min($usoArchivo, 100); ?> % de su tamaño máximo<?php echo $usoArchivo >= 100 ? ' y el avatar ya no acepta conversaciones nuevas' : ''; ?>.
    Exporta y archiva las conversaciones antiguas:
    <form method="post" action="acciones.php" class="en-linea confirmar" data-confirmar="¿Mover a un archivo aparte las conversaciones cerradas con más de esos días? Seguirán en wj-content/datos, fuera del panel.">
      <?php musa_campo_token(); ?>
      <input type="hidden" name="accion" value="archivar">
      <label class="en-linea">más de <input type="number" name="dias" value="90" min="1" max="3650" class="corto"> días</label>
      <button type="submit" class="boton-linea pequeno">Archivar</button>
    </form>
  </div>
<?php endif; ?>

<section class="tarjetas-resumen">
  <div class="resumen"><span><?php echo (int) $resumen['total']; ?></span> conversaciones</div>
  <div class="resumen"><span><?php echo (int) $resumen['hoy']; ?></span> hoy</div>
  <div class="resumen"><span><?php echo (int) $resumen['preguntas']; ?></span> preguntas</div>
  <div class="resumen ok"><span><?php echo (int) $resumen['creados']; ?></span> creados</div>
  <div class="resumen aviso"><span><?php echo (int) $resumen['pendientes_creacion']; ?></span> por crear</div>
  <div class="resumen ok"><span><?php echo (int) $resumen['enviados']; ?></span> enviados</div>
  <div class="resumen aviso"><span><?php echo (int) $resumen['pendientes_envio']; ?></span> por enviar</div>
  <?php if ($resumen['activas'] > 0) : ?><div class="resumen"><span><?php echo (int) $resumen['activas']; ?></span> en curso</div><?php endif; ?>
  <?php if ($resumen['errores'] > 0) : ?><div class="resumen mal"><span><?php echo (int) $resumen['errores']; ?></span> con error</div><?php endif; ?>
</section>

<form class="filtros" method="get" action="index.php">
  <input type="search" name="q" value="<?php echo musa_e($filtros['busqueda']); ?>" placeholder="Buscar por nombre, correo, código o texto de la conversación…">
  <select name="estado">
    <option value="">Todos los estados</option>
    <?php foreach ($estados as $clave => $etiqueta) : ?>
      <option value="<?php echo musa_e($clave); ?>" <?php echo $filtros['estado'] === $clave ? 'selected' : ''; ?>><?php echo musa_e($etiqueta); ?></option>
    <?php endforeach; ?>
  </select>
  <select name="creado">
    <option value="">Creado: todos</option>
    <option value="si" <?php echo $filtros['creado'] === 'si' ? 'selected' : ''; ?>>Creado: SÍ</option>
    <option value="no" <?php echo $filtros['creado'] === 'no' ? 'selected' : ''; ?>>Creado: NO</option>
  </select>
  <select name="enviado">
    <option value="">Enviado: todos</option>
    <option value="si" <?php echo $filtros['enviado'] === 'si' ? 'selected' : ''; ?>>Enviado: SÍ</option>
    <option value="no" <?php echo $filtros['enviado'] === 'no' ? 'selected' : ''; ?>>Enviado: NO</option>
  </select>
  <label>Desde <input type="date" name="desde" value="<?php echo musa_e($filtros['desde']); ?>"></label>
  <label>Hasta <input type="date" name="hasta" value="<?php echo musa_e($filtros['hasta']); ?>"></label>
  <button type="submit" class="boton">Filtrar</button>
  <a class="boton-linea" href="index.php">Limpiar</a>
</form>

<p class="nota exportar">
  Exportar lo filtrado:
  <a class="boton-linea pequeno" href="acciones.php?accion=exportar&amp;modo=conversaciones&amp;<?php echo musa_e(http_build_query($consulta)); ?>">CSV por conversación</a>
  <a class="boton-linea pequeno" href="acciones.php?accion=exportar&amp;modo=preguntas&amp;<?php echo musa_e(http_build_query($consulta)); ?>">CSV de preguntas y respuestas</a>
  <a class="boton-linea pequeno" href="acciones.php?accion=exportar&amp;modo=json&amp;<?php echo musa_e(http_build_query($consulta)); ?>">JSON</a>
  · Las casillas <strong>Creado</strong> y <strong>Enviado</strong> se guardan al instante.
</p>

<?php if (empty($resultado['conversaciones'])) : ?>
  <div class="vacio">Todavía no hay conversaciones que coincidan con el filtro.</div>
<?php else : ?>
<div class="tabla-envoltura">
<table class="tabla" id="tabla-registros" data-token="<?php echo musa_e(musa_token()); ?>">
  <thead>
    <tr>
      <th>Código y fecha</th>
      <th>Persona</th>
      <th>Contacto</th>
      <th class="centro">Preguntas</th>
      <th>Primera pregunta</th>
      <th class="centro">Creado</th>
      <th class="centro">Enviado</th>
      <th>Estado</th>
      <th>Acciones</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($resultado['conversaciones'] as $c) :
      $id = (string) $c['id'];
      $pares = musa_conversacion_pares($c);
      $primera = '';
      foreach ($pares as $par) { if ($par['pregunta'] !== '') { $primera = $par['pregunta']; break; } }
  ?>
    <tr id="fila-<?php echo musa_e($id); ?>">
      <td>
        <strong><?php echo musa_e($c['codigo']); ?></strong>
        <span class="tenue"><?php echo musa_e($c['fecha']); ?> · <?php echo musa_e(musa_conversacion_duracion($c)); ?> min</span>
      </td>
      <td>
        <?php echo $c['nombre'] !== '' ? musa_e($c['nombre']) : '<span class="tenue">Anónimo</span>'; ?>
        <?php if ($c['ciudad'] !== '') : ?><span class="tenue"><?php echo musa_e($c['ciudad']); ?></span><?php endif; ?>
      </td>
      <td>
        <?php if ($c['correo'] !== '') : ?><a href="mailto:<?php echo musa_e(rawurlencode($c['correo'])); ?>"><?php echo musa_e($c['correo']); ?></a><?php else : ?><span class="tenue">—</span><?php endif; ?>
        <?php if ($c['telefono'] !== '') : ?><span class="tenue"><?php echo musa_e($c['telefono']); ?></span><?php endif; ?>
      </td>
      <td class="centro"><strong><?php echo (int) musa_conversacion_preguntas($c); ?></strong></td>
      <td class="historia"><?php echo musa_e(mb_substr($primera, 0, 110, 'UTF-8')); ?><?php echo mb_strlen($primera, 'UTF-8') > 110 ? '…' : ''; ?></td>
      <td class="centro">
        <label class="casilla">
          <input type="checkbox" class="marca" data-id="<?php echo musa_e($id); ?>" data-campo="creado" <?php echo !empty($c['creado']) ? 'checked' : ''; ?>>
          <span><?php echo !empty($c['creado']) ? 'SÍ' : 'NO'; ?></span>
        </label>
      </td>
      <td class="centro">
        <label class="casilla">
          <input type="checkbox" class="marca" data-id="<?php echo musa_e($id); ?>" data-campo="enviado" <?php echo !empty($c['enviado']) ? 'checked' : ''; ?>>
          <span><?php echo !empty($c['enviado']) ? 'SÍ' : 'NO'; ?></span>
        </label>
      </td>
      <td><span class="etiqueta estado-<?php echo musa_e($c['estado']); ?>"><?php echo musa_e(isset($estados[$c['estado']]) ? $estados[$c['estado']] : $c['estado']); ?></span></td>
      <td class="acciones-fila">
        <button type="button" class="boton-linea pequeno ver-detalle" data-id="<?php echo musa_e($id); ?>">Detalle</button>
        <?php if ($c['correo'] !== '') : ?>
        <form method="post" action="acciones.php" class="en-linea confirmar" data-confirmar="¿Enviar a <?php echo musa_e($c['correo']); ?> el resumen de su conversación?">
          <?php musa_campo_token(); ?>
          <input type="hidden" name="accion" value="enviar">
          <input type="hidden" name="id" value="<?php echo musa_e($id); ?>">
          <button type="submit" class="boton-linea pequeno">Enviar</button>
        </form>
        <?php endif; ?>
        <form method="post" action="acciones.php" class="en-linea confirmar" data-confirmar="¿Eliminar definitivamente esta conversación?">
          <?php musa_campo_token(); ?>
          <input type="hidden" name="accion" value="eliminar">
          <input type="hidden" name="id" value="<?php echo musa_e($id); ?>">
          <button type="submit" class="boton-linea pequeno peligro">Eliminar</button>
        </form>
      </td>
    </tr>
    <tr class="detalle" id="detalle-<?php echo musa_e($id); ?>" hidden>
      <td colspan="9">
        <div class="detalle-rejilla">
          <div>
            <h3>Datos</h3>
            <dl>
              <dt>Código</dt><dd><?php echo musa_e($c['codigo']); ?></dd>
              <dt>Inicio</dt><dd><?php echo musa_e($c['fecha']); ?></dd>
              <dt>Fin</dt><dd><?php echo musa_e($c['fecha_fin'] !== '' ? $c['fecha_fin'] : '—'); ?></dd>
              <dt>Duración</dt><dd><?php echo musa_e(musa_conversacion_duracion($c)); ?> min</dd>
              <dt>Nombre</dt><dd><?php echo musa_e($c['nombre'] !== '' ? $c['nombre'] : '—'); ?></dd>
              <dt>Correo</dt><dd><?php echo musa_e($c['correo'] !== '' ? $c['correo'] : '—'); ?></dd>
              <dt>Teléfono</dt><dd><?php echo musa_e($c['telefono'] !== '' ? $c['telefono'] : '—'); ?></dd>
              <dt>Municipio</dt><dd><?php echo musa_e($c['ciudad'] !== '' ? $c['ciudad'] : '—'); ?></dd>
              <dt>Autoriza datos</dt><dd><?php echo !empty($c['autorizacion']) ? 'SÍ' : 'NO'; ?></dd>
              <dt>Tema</dt><dd><?php echo musa_e($c['tema']); ?></dd>
              <dt>Cierre</dt><dd><?php echo musa_e($c['motivo_fin'] !== '' ? $c['motivo_fin'] : '—'); ?></dd>
              <dt>Creado el</dt><dd><?php echo musa_e($c['fecha_creado'] !== '' ? $c['fecha_creado'] : '—'); ?></dd>
              <dt>Enviado el</dt><dd><?php echo musa_e($c['fecha_enviado'] !== '' ? $c['fecha_enviado'] : '—'); ?></dd>
              <dt>Sesión</dt><dd class="tenue"><?php echo musa_e($c['session_id'] !== '' ? $c['session_id'] : '—'); ?></dd>
              <dt>IP</dt><dd><?php echo musa_e($c['ip']); ?></dd>
            </dl>
            <form method="post" action="acciones.php" class="nota-form">
              <?php musa_campo_token(); ?>
              <input type="hidden" name="accion" value="nota">
              <input type="hidden" name="id" value="<?php echo musa_e($id); ?>">
              <label for="nota-<?php echo musa_e($id); ?>">Notas internas</label>
              <textarea id="nota-<?php echo musa_e($id); ?>" name="notas" rows="3"><?php echo musa_e($c['notas']); ?></textarea>
              <button type="submit" class="boton-linea pequeno">Guardar nota</button>
            </form>
          </div>
          <div class="detalle-chat">
            <h3>Preguntas y respuestas</h3>
            <?php if (empty($c['mensajes'])) : ?>
              <p class="tenue">Sin mensajes registrados.</p>
            <?php else : ?>
              <div class="chat">
                <?php foreach ($c['mensajes'] as $m) :
                    $esPersona = ($m['rol'] ?? '') === 'persona'; ?>
                  <div class="chat-mensaje <?php echo $esPersona ? 'persona' : 'avatar'; ?>">
                    <span class="chat-quien"><?php echo musa_e($esPersona ? ($c['nombre'] !== '' ? $c['nombre'] : 'Visitante') : $nombreAvatar); ?>
                      · <?php echo musa_e(substr((string) ($m['hora'] ?? ''), 11, 8)); ?>
                      <?php if (($m['origen'] ?? '') === 'texto') : ?>· escrito<?php endif; ?>
                      <?php if (!musa_mensaje_verificado($m)) : ?><span class="etiqueta estado-error" title="Texto enviado por el navegador; no coincide con la transcripción oficial de LiveAvatar. No se incluye en los correos.">sin verificar</span><?php endif; ?></span>
                    <p><?php echo nl2br(musa_e($m['texto'] ?? '')); ?></p>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if ($c['session_id'] !== '') : ?>
              <form method="post" action="acciones.php" class="en-linea confirmar" data-confirmar="¿Recuperar la transcripción oficial de LiveAvatar? Reemplaza las respuestas enviadas por el navegador por las verificadas.">
                <?php musa_campo_token(); ?>
                <input type="hidden" name="accion" value="transcripcion">
                <input type="hidden" name="id" value="<?php echo musa_e($id); ?>">
                <button type="submit" class="boton-linea pequeno">Recuperar transcripción de LiveAvatar</button>
              </form>
            <?php endif; ?>
            <?php if (in_array($c['estado'], array('activa', 'iniciando'), true)) : ?>
              <form method="post" action="acciones.php" class="en-linea confirmar" data-confirmar="¿Cerrar esta conversación y detener la sesión del avatar?">
                <?php musa_campo_token(); ?>
                <input type="hidden" name="accion" value="cerrar">
                <input type="hidden" name="id" value="<?php echo musa_e($id); ?>">
                <button type="submit" class="boton-linea pequeno peligro">Cerrar conversación</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($resultado['paginas'] > 1) : ?>
<nav class="paginacion" aria-label="Paginación">
  <?php for ($i = 1; $i <= $resultado['paginas']; $i++) :
      $consulta['pagina'] = $i; ?>
    <a href="index.php?<?php echo musa_e(http_build_query($consulta)); ?>" class="<?php echo $i === $resultado['pagina'] ? 'activo' : ''; ?>"><?php echo $i; ?></a>
  <?php endfor; ?>
</nav>
<?php endif; ?>
<?php endif; ?>

<?php musa_panel_fin(); ?>
