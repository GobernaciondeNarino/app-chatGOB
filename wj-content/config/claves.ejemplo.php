<?php
/**
 * QuéDice! · Claves de API (opcional)
 *
 * Copia este archivo como  wj-content/config/claves.php  y escribe tu clave.
 * Se usa la primera vez que arranca el sitio para dejar la API lista sin
 * tener que escribirla a mano en el panel. A partir de ahí, la configuración
 * vive en wj-content/config/ajustes.json.php y se edita desde wj-admin → Motor y APIs
 * (y HeyGen LiveAvatar). Borra claves.php después de la primera visita.
 *
 * IMPORTANTE: claves.php está excluido del repositorio (.gitignore).
 * Nunca subas claves de API a GitHub: los servicios las revocan al detectarlas.
 */

return array(
    // Motor económico: IA de texto (Gemini: aistudio.google.com/apikey)
    'ia' => array(
        'api_key' => 'TU_CLAVE_DE_GEMINI',
    ),
    // Voz y escucha (ElevenLabs → API Keys). Borra este bloque si usarás otra voz.
    'voz' => array(
        'elevenlabs' => array(
            'api_key' => 'TU_CLAVE_DE_ELEVENLABS',
        ),
    ),
    // Avatar en vivo, opcional (app.liveavatar.com → Developers). Borra este bloque si no lo usarás.
    'heygen' => array(
        'api_key' => 'TU_CLAVE_DE_LIVEAVATAR',
    ),
);
