# QuéDice! · Avatar conversacional

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
| **Interfaz pública** (`index.php`) | Un solo contenedor `div#app` de 100 % de ancho y 100vh de alto, **sin scroll**. Avatar en video en el centro, escena 3D con **three.js** (granos de café, vapor y un aura que reacciona a la voz del avatar) y abajo el panel con la transcripción en vivo, preguntas escritas y sugeridas, micrófono, interrumpir y terminar. Franja GOV.CO y botón de accesibilidad (texto grande y alto contraste). |
| **Formulario de inicio** | Opcional: nombre, correo, municipio (lista de los 64 municipios de Nariño o texto libre), teléfono y autorización de datos (Ley 1581). Se activa o desactiva y se eligen sus campos desde el panel. |
| **Panel** (`wj-admin/`) | Conversaciones con todas las preguntas y respuestas, casillas **Creado** y **Enviado**, exportación CSV/JSON, avatar y tema, API de HeyGen con verificación, apariencia, correo y credenciales. |
| **Núcleo** (`wj-includes/`) | Configuración, almacenamiento JSON, seguridad, correo, cliente de HeyGen LiveAvatar y la API pública. |
| **Contenido** (`wj-content/`) | Ajustes, conversaciones, imágenes subidas y bitácoras. Es la única carpeta que necesita permisos de escritura. |

Solo existen tres carpetas en la raíz del proyecto: `wj-admin`, `wj-includes` y `wj-content`.

---

## 2. HeyGen LiveAvatar: lo que debes saber

HeyGen está retirando su antigua **Interactive Avatar** y remite a la plataforma **LiveAvatar**
(`api.liveavatar.com`), su versión de producción para avatares en tiempo real. Este sistema usa
LiveAvatar en **modo FULL**: LiveAvatar escucha a la persona, entiende la pregunta, genera la
respuesta con el contexto que configuras y la dice con la voz del avatar.

Fuentes consultadas (septiembre de 2026): [documentación de LiveAvatar](https://docs.liveavatar.com)
y su especificación [openapi.json](https://docs.liveavatar.com/openapi.json), y el anuncio de HeyGen
[Introducing LiveAvatar](https://help.heygen.com/en/articles/12758516-introducing-liveavatar).
HeyGen no publica en esas páginas una fecha exacta de cierre de la API anterior.

- **La clave, el avatar y la voz deben ser de LiveAvatar** (`app.liveavatar.com`). Según HeyGen,
  los avatares creados en HeyGen no son compatibles directamente con LiveAvatar: se migran con
  su ayuda y pueden quedar con otro ID.
- Los ID configurados de fábrica son los entregados para este proyecto:
  avatar `56aa5373edb14809a1572b36af99b94c` y voz `5fab49b6cbd84b2cb0320fd28f9e49de`.
  LiveAvatar espera el formato UUID con guiones y el sistema los convierte solo
  (`56aa5373-edb1-4809-a157-2b36af99b94c`). **Que ese ID exista en LiveAvatar solo se puede
  confirmar con la clave real:** pulsa **API HeyGen → Verificar todo**. Si responde «no
  encontrado», usa **Ver mis avatares y voces** y copia el ID migrado en **Avatar y tema**.
- **Modo sandbox**: para probar sin gastar créditos (avatar genérico, sesiones de ~1 minuto).
- La clave **nunca llega al navegador**: el servidor crea e inicia cada sesión y solo entrega al
  visitante el acceso temporal a la sala de video.

---

## 3. Instalación en Plesk

1. **Sube los archivos.** Descarga el repositorio y copia todo su contenido dentro de
   `httpdocs` (o la carpeta del dominio o subdominio). No requiere Composer, Node ni base de datos.

2. **PHP.** En *Plesk → Dominios → Configuración de PHP*, selecciona **PHP 7.4 o superior**
   (probado en PHP 8.4). Deja activadas las extensiones `curl`, `json` y `mbstring`.

3. **HTTPS obligatorio.** Activa el certificado **Let's Encrypt** del dominio y, en
   *Hosting y DNS*, la **redirección 301 permanente de HTTP a HTTPS**. Sin HTTPS el navegador no
   permite usar el micrófono. (El `.htaccess` no la fuerza para no dejar el sitio caído si el
   certificado aún no está instalado.)

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

6. **Entra al panel y crea la cuenta:** `https://tu-dominio/wj-admin/`

   La primera vez aparece **Configuración inicial** y pide un **código de instalación**. El
   sistema lo acaba de escribir en `wj-content/config/codigo-instalacion.php`: ábrelo en
   *Plesk → Administrador de archivos* y cópialo (la web no entrega ese archivo). Así solo puede
   crear la cuenta quien tiene acceso al servidor. Luego escribe el usuario y una contraseña de al
   menos 10 caracteres con letras y números. Se guarda cifrada con bcrypt en
   `wj-content/config/.htpasswd.php` (fuera del repositorio) y el código se borra. No hay
   contraseña de fábrica.

   > **Instalaciones anteriores:** la versión 1 de este README publicó una contraseña de fábrica y
   > el repositorio es público, así que esa contraseña está **quemada**: el panel la rechaza siempre.
   > Si tu servidor la usaba, al intentarla el panel explica cómo restablecer el acceso (borrar
   > `wj-content/config/.htpasswd.php` desde Plesk y repetir la configuración inicial). Las
   > credenciales de `wj-admin/.htpasswd` o `wj-content/config/.htpasswd` se trasladan solas al
   > archivo nuevo y el archivo viejo se borra.

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
  (360p a 1080p), formato del marco (3:4, 9:16, 1:1 o 16:9), forma de hablar (*conversación natural* o *mantener presionado para hablar*,
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
- **Identidad:** nombre, eslogan, entidad, sitio de la entidad y la **franja GOV.CO** superior
  (activable), como pide el manual de sitios web de la Gobernación.
- **Imágenes y logos:** imagen del avatar (se ve mientras conecta), logo, **imagen de fondo de
  pantalla completa** con control de visibilidad (0-100 %), decoraciones de esquina y lateral,
  logo de la entidad para la franja GOV.CO y favicon. Puedes elegir una existente o subir una nueva
  (`wj-content/subidas`, máximo 5 MB).
- **Colores:** catorce colores con selector visual y dos paletas en un clic:
  **Institucional Gobernación de Nariño** (verde #10A13B, amarillo #FFD500, azul #003366 del
  Manual de Identidad Visual 2024, con los verdes ajustados para cumplir contraste AA;
  predeterminada) y **Café** (rojo y dorado).
- **Formulario de inicio:** activarlo o no («saber con quién se habla») y qué campos pedir
  (correo, municipio, teléfono) y cuáles son obligatorios. El municipio puede elegirse de la
  **lista de los 64 municipios de Nariño** (con «Otro municipio» opcional) o escribirse libre.
  El nombre siempre se pide cuando está activo. Si se desactiva, las conversaciones quedan anónimas.
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

Auditoría estática de septiembre de 2026 (código propio, sin pruebas contra el servidor en
producción): los hallazgos se corrigieron y se verificaron con pruebas automatizadas. El informe
detallado (TLP:AMBER) se entrega aparte y **no** se publica en este repositorio.

**Acceso al panel**
- Primera cuenta solo con el código de instalación de un solo uso (legible únicamente desde el
  servidor), creada de forma atómica. Si el archivo de credenciales existe pero está dañado, el
  panel queda cerrado en lugar de ofrecer otra cuenta.
- Credenciales en `wj-content/config/.htpasswd.php`: formato de Apache con la primera línea
  `#<?php http_response_code(403); exit; ?>` (comentario para Apache, bloqueo para PHP). Solo se
  aceptan hashes bcrypt o APR1 (este se convierte a bcrypt al entrar); nunca texto plano.
- Bloqueo por intentos: 8 fallos por IP (o por bloque /64 de IPv6) y 40 en total cada 15 minutos.
  El intento se cuenta antes de comprobar la contraseña, así las solicitudes en paralelo no se
  cuelan. El tiempo de respuesta es el mismo exista o no el usuario.
- Sesión: cookie `HttpOnly`/`SameSite=Lax` (y `Secure` en HTTPS), modo estricto, identificador y
  token CSRF nuevos al entrar, cierre por inactividad (2 horas), cambio de contraseña que cierra
  las demás sesiones y salida por POST con token. Todas las acciones del panel van por POST con
  token CSRF (la exportación es la única lectura por GET).

**Sitio público y API**
- La clave de LiveAvatar vive solo en el servidor; al navegador llega un token temporal de sala.
  El endpoint solo puede ser `https://api.liveavatar.com` (o `localhost` para pruebas) y las
  peticiones no siguen redirecciones (la clave no viaja a otro servidor).
- Límites comprobados de forma atómica: conversaciones por IP (o /64) por hora y por día, total
  por hora y conversaciones abiertas al mismo tiempo (configurables en *Apariencia → Sistema y
  límites*). Keep-alive como máximo cada 25 s. Las sesiones sin actividad se cierran a los 3 minutos.
- Topes de tamaño: 1 000 caracteres por pregunta, 2 000 por respuesta y 64 KB por conversación.
  Si el archivo de conversaciones llega a 16 MB, el sitio deja de aceptar conversaciones y el
  panel ofrece **archivar** las antiguas (`wj-content/datos/archivo-*.json.php`).
- La transcripción oficial de LiveAvatar es la fuente de verdad: al cerrar, las respuestas del
  avatar que envió el navegador se reemplazan por las oficiales. Si no hay transcripción oficial,
  el panel las marca «sin verificar» y **no** se incluyen en el correo. El correo institucional
  nunca lleva enlaces escritos por el visitante, y su nombre solo admite letras.
- Cabeceras: Content-Security-Policy con *nonce* (sin JavaScript en línea en el panel),
  Strict-Transport-Security en HTTPS, `X-Frame-Options`, `nosniff`, `Referrer-Policy`,
  `Cross-Origin-Opener-Policy` y `Permissions-Policy` (micrófono solo en la página del avatar).
- En un kiosco compartido, los datos de la persona se borran al terminar y la transcripción a los
  90 segundos; el formulario no usa autocompletado.

**Archivos y servidor**
- `.htaccess` incluidos: raíz (sin listado de carpetas; bloquea `.git/` y todo lo que empiece por
  punto, `.md`, `.json`, `.log`, respaldos y temporales), `wj-admin` (autenticación de Apache
  opcional), `wj-content` (nada se sirve) y `wj-content/subidas` (solo imágenes).
- Los archivos de datos, los temporales y las credenciales llevan una línea PHP que responde 403.
- `.gitignore` en modo lista blanca: de `wj-content` solo se versionan las reglas y los ejemplos.
- Las bitácoras no guardan correos de visitantes y se borran a los 12 meses. `claves.php` solo se
  usa en el primer arranque; el panel avisa si sigue en el servidor para que lo borres.

> **Datos personales:** el formulario pide autorización explícita conforme a la Ley 1581 de 2012.
> Las conversaciones contienen datos personales y lo que la persona dijo: haz copias de seguridad,
> archiva las antiguas y trátalas según la política de la entidad. Informa en el sitio que la
> conversación se transcribe.

### Si el servidor usa solo nginx (sin Apache)
Los `.htaccess` no se aplican. Los datos siguen protegidos por la línea PHP, pero agrega en
*Plesk → Configuración de Apache y nginx → Directivas adicionales de nginx*:

```nginx
location ~ /\.(?!well-known/) { deny all; }
location ~ ^/wj-content/(config|datos|logs)/ { deny all; }
location ~* \.(md|tmp|lock|log|bak|json)$ { deny all; }
```

Lo recomendado es dejar el modo proxy de Plesk (Apache detrás de nginx) activado.

### Contraseña del panel con Apache (.htaccess)
Para que además el navegador pida usuario y contraseña, quita el comentario de estas líneas en
`wj-admin/.htaccess` con la ruta absoluta real (aparece calculada en *Acceso*):

```apache
AuthType Basic
AuthName "Panel QueDice"
AuthUserFile /var/www/vhosts/TU-DOMINIO/httpdocs/wj-content/config/.htpasswd.php
Require valid-user
```

Apache no limita los intentos de esta autenticación: actívala solo junto con **Fail2Ban** de
Plesk (*Herramientas y configuración → Bloqueo de direcciones IP*, cárcel de Apache).

---

## 7. Mantenimiento

| Tarea | Cómo |
|---|---|
| Copia de seguridad | Guarda `wj-content/` completo. |
| Actualizar desde el repositorio | `git pull`. Tus ajustes y conversaciones no se sobrescriben: están en archivos ignorados por git. |
| Revisar errores | Bitácora mensual en `wj-content/logs/`. |
| Diagnóstico del servidor | *Panel → Acceso → Estado de la instalación* (PHP, cURL, HTTPS, permisos). |
| Conversaciones que quedaron abiertas | Se cierran solas (sin actividad 3 minutos o pasada la duración máxima). |
| Archivo de conversaciones grande | *Conversaciones → Archivar* mueve las cerradas antiguas a `wj-content/datos/archivo-*.json.php`. |
| Actualizar con git | Mejor con la extensión Git de Plesk y ruta de despliegue (así `.git` no queda en `httpdocs`). |

---

## 8. Estructura de archivos

Solo existen tres carpetas: `wj-admin`, `wj-includes` y `wj-content`.

```
index.php                     Interfaz pública (un solo div de 100 % × 100vh, sin scroll)
.htaccess  .gitignore         Seguridad de Apache y archivos que no se suben al repositorio
README.md · ARQUITECTURA.md   Documentación

wj-admin/
  .htaccess                   Protección (autenticación de Apache opcional)
  index.php                   Conversaciones, casillas Creado/Enviado, detalle
  avatar.php                  Avatar, voz, tema, conocimiento y sugerencias
  api.php                     Clave de LiveAvatar y verificaciones
  ajustes.php                 Apariencia, formulario de inicio y límites
  correo.php                  Correo y prueba de envío
  cuenta.php                  Credenciales y estado del sistema
  acceso.php  salir.php       Primera configuración, inicio y cierre de sesión
  acciones.php  comun.php     Acciones del panel y plantilla común

wj-includes/
  arranque.php                Constantes y carga del sistema
  funciones.php               Utilidades (JSON, HTTP, rutas, textos)
  configuracion.php           Ajustes predeterminados, conocimiento del café, municipios y migración
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

wj-content/                   Única carpeta escribible (sus datos no se suben al repositorio)
  .htaccess                   Solo imágenes por la web
  config/                     Ajustes, credenciales (.htpasswd.php), ejemplos y claves.php (opcional)
  datos/                      conversaciones.json.php
  subidas/                    Imágenes cargadas desde el panel (.htaccess: solo imágenes)
  logs/                       Bitácoras mensuales
```

---

## 9. Herramientas de desarrollo

Para construir y revisar esta versión se usaron, instaladas en el equipo de desarrollo y **no
dentro del proyecto** (para respetar la regla de tres carpetas):
[repomix](https://github.com/yamadashy/repomix),
[anthropics/skills](https://github.com/anthropics/skills),
[chrome-devtools-mcp](https://github.com/ChromeDevTools/chrome-devtools-mcp),
[claude-code-security-review](https://github.com/anthropics/claude-code-security-review),
[graphify](https://github.com/Graphify-Labs/graphify) y
[apple-design-skill](https://github.com/dickwu/apple-design-skill) (guía de diseño aplicada a la
interfaz: capa funcional translúcida, objetivos táctiles de 44 px, contraste AA, preferencias de
movimiento y transparencia reducidos). Ninguna es necesaria en el servidor.

---

Gobernación de Nariño · QuéDice! · versión 2.3.0
