<?php
/**
 * QuéDice! · Claves de API (opcional)
 *
 * Copia este archivo como  wj-content/config/claves.php  y escribe tu clave.
 * Se usa la primera vez que arranca el sitio para dejar la API lista sin
 * tener que escribirla a mano en el panel. A partir de ahí, la configuración
 * vive en wj-content/config/ajustes.json.php y se edita desde wj-admin → API HeyGen.
 *
 * IMPORTANTE: claves.php está excluido del repositorio (.gitignore).
 * Nunca subas claves de API a GitHub: los servicios las revocan al detectarlas.
 */

return array(
    'heygen' => array(
        // Clave de LiveAvatar (app.liveavatar.com → Developers)
        'api_key' => 'TU_CLAVE_DE_LIVEAVATAR',
    ),
);
