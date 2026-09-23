# Musa Café · Avatar conversacional

Aplicación web de la **Gobernación de Nariño**. Un avatar de **HeyGen** aparece en el centro de
la pantalla y conversa por voz con las personas sobre el tema configurado (por defecto, **el café
de Nariño**). Debajo, un cuadro transcribe en tiempo real las preguntas y lo que el avatar
responde. Todo queda guardado en un archivo JSON y se consulta desde el panel **wj-admin**, con
las casillas **Creado SÍ/NO** y **Enviado SÍ/NO**.

Todo lo que se ve y se dice se configura desde el panel, sin tocar código: avatar, voz, tema,
personalidad, conocimiento, saludo, preguntas sugeridas, colores, imágenes, logos, textos y el
formulario de inicio.

---

## 1. Qué incluye

| Parte | Descripción |
|---|---|
| **Interfaz pública** (`index.php`) | Avatar en video en el centro, escena 3D con **three.js** (granos de café, vapor y un aura que reacciona a la voz del avatar), transcripción en vivo, preguntas escritas y sugeridas, micrófono, interrumpir y terminar. |
| **Formulario de inicio** | Opcional: nombre, correo, teléfono, municipio y autorización de datos (Ley 1581). Se activa o desactiva y se eligen sus campos desde el panel. |
| **Panel** (`wj-admin/`) | Conversaciones con todas las preguntas y respuestas, casillas **Creado** y **Enviado**, exportación CSV/JSON, avatar y tema, API de HeyGen con verificación, apariencia, correo y credenciales. |
| **Núcleo** (`wj-includes/`) | Configuración, almacenamiento JSON, seguridad, correo, cliente de HeyGen LiveAvatar y la API pública. |
| **Contenido** (`wj-content/`) | Ajustes, conversaciones, imágenes subidas y bitácoras. Es la única carpeta que necesita permisos de escritura. |

Solo existen tres carpetas en la raíz del proyecto: `wj-admin`, `wj-includes` y `wj-content`.

---

## 2. HeyGen LiveAvatar: lo que debes saber

HeyGen reemplazó su antigua **Interactive Avatar API** por la plataforma **LiveAvatar**
(`api.liveavatar.com`). La API anterior dejó de funcionar el **31 de marzo de 2026**, así que
este sistema usa LiveAvatar en **modo FULL**: LiveAvatar escucha a la persona, entiende la
pregunta, genera la respuesta con el contexto que configuras y la dice con la voz del avatar.

- **La clave, el avatar y la voz deben ser de LiveAvatar** (`app.liveavatar.com`). En la
  migración, HeyGen creó *copias* de los avatares con **ID nuevos**.
- Los ID configurados de fábrica son los entregados para este proyecto:
  avatar `56aa5373edb14809a1572b36af99b94c` y voz `5fab49b6cbd84b2cb0320fd28f9e49de`.
  LiveAvatar espera el formato UUID con guiones; el sistema los convierte solo
  (`56aa5373-edb1-4809-a157-2b36af99b94c`). Si al verificar responde «no encontrado», busca el
  avatar migrado en `app.liveavatar.com` o usa **API HeyGen → Ver mis avatares y voces** y copia
  el ID nuevo en **Avatar y tema**.
- **Modo sandbox**: para probar sin gastar créditos (avatar genérico, sesiones de ~1 minuto).
- La clave **nunca llega al navegador**: el servidor crea e inicia cada sesión y solo entrega al
  visitante el acceso temporal a la sala de video.

---

## 3. Instalación en Plesk

1. **Sube los archivos.** Descarga el repositorio y copia todo su contenido dentro de
   `httpdocs` (o la carpeta del dominio o subdominio). No requiere Composer, Node ni base de datos.

2. **PHP.** En *Plesk → Dominios → Configuración de PHP*, selecciona **PHP 7.4 o superior**
   (probado en PHP 8.4). Deja activadas las extensiones `curl`, `json` y `mbstring`.

3. **HTTPS obligatorio.** Activa el certificado **Let's Encrypt** del dominio. Sin HTTPS el
   navegador no permite usar el micrófono.

4. **Permisos de escritura** sobre `wj-content` para el usuario del servidor web:

   ```bash
   chmod -R 775 wj-content
   ```

   En *Plesk → Administrador de archivos* basta con dar permiso de escritura al grupo. El sistema
   crea solo las subcarpetas que falten.

5. **Clave de LiveAvatar.** Dos formas:
   - *La sencilla:* entra al panel y pégala en **API HeyGen** (se guarda fuera del repositorio y
     se muestra enmascarada).
   - *La automática:* antes de la primera visita copia `wj-content/config/claves.ejemplo.php`
     como `wj-content/config/claves.php` y escribe la clave. Ese archivo está excluido del
     repositorio.

6. **Entra al panel:** `https://tu-dominio/wj-admin/`

   | Usuario | Contraseña |
   |---|---|
   | `admin` | `MusaCafe2026*Narino` |

   > **Cámbiala apenas ingreses**, en *Acceso → Usuario y contraseña*.

7. En **API HeyGen** pulsa **Verificar todo** (créditos, avatar y voz) y luego **Prueba de
   sesión** (valida avatar, voz, idioma y contexto sin consumir créditos).

8. Abre el sitio, pulsa **Iniciar conversación** y habla con el avatar.

> **Actualización desde la versión 1 (creador de canciones):** al cargar la nueva versión, los
> ajustes se migran solos. Se conservan identidad, imágenes, colores, correo SMTP y seguridad;
> se descartan géneros musicales y APIs de música. El archivo `registros.json.php` anterior no se
> borra, pero ya no se usa.

---

## 4. Configuración desde el panel

### Avatar y tema
- **Avatar:** nombre del personaje, ID del avatar, ID de la voz, idioma, calidad de video
  (360p a 1080p), forma de hablar (*conversación natural* o *mantener presionado para hablar*,
  útil en lugares ruidosos), duración máxima, micrófono al iniciar, permitir escribir y sandbox.
- **Tema:** nombre del tema (por defecto **Café**), saludo inicial, personalidad,
  **información que debe conocer** (base de conocimiento), reglas y máximo de palabras por
  respuesta.
- **Preguntas sugeridas:** hasta 8 botones debajo de la transcripción.
- **Páginas de referencia:** direcciones web que LiveAvatar puede consultar.
- Al guardar, el contexto se **sincroniza con LiveAvatar**. Abajo se ve la instrucción completa
  que recibe el avatar.

### API HeyGen
| Botón | Qué hace | ¿Consume créditos? |
|---|---|---|
| **Verificar todo** | Consulta los créditos y confirma que el avatar y la voz existen y están activos. | No |
| **Sincronizar contexto** | Crea o actualiza en LiveAvatar el tema, la personalidad y el saludo. | No |
| **Prueba de sesión** | Pide un token de sesión con la configuración completa y lo cierra sin transmitir. | No |
| **Ver mis avatares y voces** | Lista los avatares y voces de la cuenta con sus ID. | No |

### Apariencia y formulario
- **Identidad, imágenes y logos:** imagen del avatar (se ve mientras conecta), logo, rama
  decorativa, imagen lateral y favicon. Puedes elegir una existente o subir una nueva
  (`wj-content/subidas`, máximo 5 MB).
- **Colores:** trece colores con selector visual.
- **Formulario de inicio:** activarlo o no («saber con quién se habla») y qué campos pedir
  (correo, teléfono, municipio) y cuáles son obligatorios. El nombre siempre se pide cuando está
  activo. Si se desactiva, las conversaciones quedan anónimas.
- **Textos:** todos los textos visibles de la experiencia.
- **Sistema y límites:** efectos 3D, conversaciones por hora y por día (por IP o correo),
  máximo de mensajes por conversación, prefijo del código y zona horaria.

### Correo
Remitente, asunto y mensaje con etiquetas (`{nombre}`, `{tema}`, `{codigo}`, `{preguntas}`,
`{avatar}`, `{conversacion}`…), opción de incluir las preguntas y respuestas, envío por
`mail()` o SMTP y botón de prueba. El resumen se envía desde **Conversaciones → Enviar** y la
casilla **Enviado** se marca sola.

---

## 5. Conversaciones registradas

Cada conversación queda en `wj-content/datos/conversaciones.json.php`:

- Código, inicio, fin y duración.
- Datos de la persona (si el formulario está activo) y su autorización.
- **Todas las preguntas y respuestas en orden**, con la hora y si la pregunta fue hablada o escrita.
- Casillas **Creado** y **Enviado**, fechas de cada una, notas internas, estado e IP.

En el panel puedes filtrar por texto (también dentro de la conversación), estado, casillas y
fechas; ver el detalle como chat; marcar **Creado/Enviado** al instante; enviar el resumen por
correo; recuperar la transcripción oficial de LiveAvatar si faltó algo; cerrar una conversación
abierta; y exportar a **CSV por conversación**, **CSV de preguntas y respuestas** (una fila por
pregunta) o **JSON**.

---

## 6. Seguridad

- La clave de LiveAvatar vive solo en el servidor; al navegador llega un token temporal de sala.
- Panel protegido con bcrypt (`wj-admin/.htpasswd`), bloqueo tras 8 intentos fallidos, cierre por
  inactividad (2 horas) y token CSRF en todas las acciones.
- La API pública exige token CSRF y, para cada conversación, una clave aleatoria propia.
- Límite de conversaciones por hora y por día (por IP o correo) para proteger los créditos,
  campo trampa antirrobots y máximo de mensajes por conversación.
- Los archivos de datos empiezan con una línea PHP que responde 403: aunque el servidor no aplique
  `.htaccess`, nunca muestran su contenido por la web.
- La exportación CSV neutraliza los valores que empiezan por `=`, `+`, `-` o `@`.
- Si el sitio está detrás de un balanceador o CDN, agrega su IP en
  `seguridad.proxies_confiables` para leer la IP real del visitante.

> **Datos personales:** el formulario pide autorización explícita conforme a la Ley 1581 de 2012.
> Las conversaciones contienen datos personales y lo que la persona dijo: haz copias de seguridad
> y trátalas según la política de la entidad. Informa en el sitio que la conversación se transcribe.

### Contraseña del panel con Apache (.htaccess)
Para que además el navegador pida usuario y contraseña, quita el comentario de estas líneas en
`wj-admin/.htaccess` con la ruta absoluta real (aparece calculada en *Acceso*):

```apache
AuthType Basic
AuthName "Panel Musa Cafe"
AuthUserFile /var/www/vhosts/TU-DOMINIO/httpdocs/wj-admin/.htpasswd
Require valid-user
```

---

## 7. Mantenimiento

| Tarea | Cómo |
|---|---|
| Copia de seguridad | Guarda `wj-content/` completo. |
| Actualizar desde el repositorio | `git pull`. Tus ajustes y conversaciones no se sobrescriben: están en archivos ignorados por git. |
| Revisar errores | Bitácora mensual en `wj-content/logs/`. |
| Diagnóstico del servidor | *Panel → Acceso → Estado de la instalación* (PHP, cURL, HTTPS, permisos). |
| Conversaciones que quedaron abiertas | Se cierran solas al abrir el panel, pasada la duración máxima. |

---

## 8. Estructura de archivos

```
index.php                     Interfaz pública del avatar
.htaccess                     Seguridad, caché y compresión
README.md · ARQUITECTURA.md   Documentación

wj-admin/
  .htaccess  .htpasswd        Protección y credenciales del panel
  index.php                   Conversaciones, casillas Creado/Enviado, detalle
  avatar.php                  Avatar, voz, tema, conocimiento y sugerencias
  api.php                     Clave de LiveAvatar y verificaciones
  ajustes.php                 Apariencia, formulario de inicio y límites
  correo.php                  Correo y prueba de envío
  cuenta.php                  Credenciales y estado del sistema
  acceso.php  salir.php       Inicio y cierre de sesión
  acciones.php  comun.php     Acciones del panel y plantilla común

wj-includes/
  arranque.php                Constantes y carga del sistema
  funciones.php               Utilidades (JSON, HTTP, rutas, textos)
  configuracion.php           Ajustes predeterminados, conocimiento del café y migración
  almacenamiento.php          Conversaciones en JSON con bloqueo
  heygen.php                  Cliente de HeyGen LiveAvatar
  seguridad.php               Sesión, CSRF, .htpasswd, límites
  correo.php                  Envío por mail() y SMTP
  api/sesion.php              Inicia una conversación (POST)
  api/mensajes.php            Guarda preguntas y respuestas (POST)
  api/mantener.php            Mantiene viva la sesión (POST)
  api/finalizar.php           Cierra la conversación (POST, también sendBeacon)
  css/app.css  css/admin.css  Estilos
  js/app.js  js/admin.js      Experiencia pública y panel
  js/vendor/three.min.js      three.js r149
  js/vendor/livekit-client.umd.js  LiveKit 2.22.3 (video en tiempo real)
  images/                     Logo, fondos y avatar (images/avatar/avatar-cafe.webp)

wj-content/
  config/                     Ajustes vigentes, ejemplo y claves.php (opcional)
  datos/                      conversaciones.json.php
  subidas/                    Imágenes cargadas desde el panel
  logs/                       Bitácoras mensuales
```

---

Gobernación de Nariño · Musa Café · versión 2.0.0
