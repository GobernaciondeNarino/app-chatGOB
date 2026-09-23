# QuéDice! · Avatar conversacional

Aplicación web de la **Gobernación de Nariño**. Un avatar aparece en el centro de la pantalla y
conversa por voz con las personas sobre el tema configurado (por defecto, **el café de Nariño**).
Debajo, un cuadro transcribe las preguntas y lo que el avatar responde. Todo queda guardado en un
archivo JSON y se consulta desde el panel **wj-admin**, con las casillas **Creado SÍ/NO** y
**Enviado SÍ/NO**.

El avatar funciona con uno de dos **motores**, que se eligen en el panel (*Motor y APIs*):

- **Económico (predeterminado):** el personaje es un video en bucle que cambia a un video
  «hablando» mientras suena la voz; las respuestas las escribe una IA de texto (Google Gemini,
  Hugging Face u otra compatible con OpenAI) y las dice **ElevenLabs**, **Gemini TTS** o la voz del
  navegador. Cuesta céntimos por conversación.
- **Avatar en vivo (HeyGen LiveAvatar):** video en tiempo real con los labios sincronizados. Es
  el más realista y el más costoso (≈ USD 0,20-0,25 por minuto de sesión); queda listo para
  activarlo cuando haya recursos.

Todo lo que se ve y se dice se configura desde el panel, sin tocar código: avatar, voz, tema,
personalidad, conocimiento, saludo, preguntas sugeridas, colores, imágenes, logos, textos y el
formulario de inicio.

---

## 1. Qué incluye

| Parte | Descripción |
|---|---|
| **Interfaz pública** (`index.php`) | Un solo contenedor `div#app` de 100 % de ancho y 100vh de alto, **sin scroll**. Avatar en video en el centro (videos en bucle o LiveAvatar), escena 3D con **three.js** (granos de café, vapor y un aura que reacciona a la voz del avatar) y abajo el panel con la transcripción en vivo, preguntas escritas y sugeridas, micrófono, interrumpir y terminar. Botón de accesibilidad (texto grande y alto contraste). |
| **Formulario de inicio** | Opcional: nombre, correo, municipio (lista de los 64 municipios de Nariño o texto libre), teléfono y autorización de datos (Ley 1581). Se activa o desactiva y se eligen sus campos desde el panel. |
| **Panel** (`wj-admin/`) | Conversaciones con todas las preguntas y respuestas, casillas **Creado** y **Enviado**, exportación CSV/JSON, avatar y tema, **motor y APIs con verificación de cada proveedor**, HeyGen LiveAvatar, apariencia, correo y credenciales. |
| **Núcleo** (`wj-includes/`) | Configuración, almacenamiento JSON, seguridad, correo, motor económico (IA, voz y escucha), cliente de HeyGen LiveAvatar y la API pública. |
| **Contenido** (`wj-content/`) | Ajustes, conversaciones, imágenes subidas y bitácoras. Es la única carpeta que necesita permisos de escritura. |

Solo existen tres carpetas en la raíz del proyecto: `wj-admin`, `wj-includes` y `wj-content`.

---

## 2. Motor económico (predeterminado)

Nace porque el avatar en vivo de LiveAvatar cobra **2 créditos por cada minuto de sesión**, también
mientras escucha (≈ USD 0,20-0,25 por minuto). El motor económico separa las piezas y solo paga lo
que usa:

| Pieza | Opciones | Costo (septiembre de 2026) |
|---|---|---|
| **Avatar** | Dos videos en bucle del mismo personaje: en reposo y hablando (incluidos, generados a partir del retrato). El de «hablando» aparece con un fundido solo mientras suena la voz. Se pueden subir otros en *Motor y APIs*. | Sin costo por uso |
| **IA de texto** (escribe la respuesta con el tema del panel) | **Google Gemini** (predeterminado: `gemini-3.1-flash-lite`), **Hugging Face** Inference Providers (`router.huggingface.co/v1`) u otra API compatible con OpenAI (OpenAI, Groq, OpenRouter, Ollama local). | Gemini tiene nivel gratuito; de pago, Flash-Lite ≈ USD 0,25 / 1,50 por millón de tokens de entrada / salida → ≈ USD 0,0007 por respuesta |
| **Voz** | **ElevenLabs** (Flash v2.5, Turbo v2.5, Multilingual v2 o v3), **Gemini TTS** (`gemini-3.8-flash-lite-tts` o `gemini-3.8-flash-tts`, 30 voces) o **la voz del navegador**. | ElevenLabs Flash/Turbo USD 0,05 y Multilingual/v3 USD 0,10 por 1 000 caracteres (≈ USD 0,018 y 0,035 por respuesta de 60 palabras). Gemini TTS: nivel gratuito; de pago ≈ USD 0,003 por respuesta. Navegador: gratis. |
| **Escucha** (voz a texto) | Reconocimiento del **navegador** (Chrome, Edge, Safari) o **ElevenLabs Scribe** v2 (igual en todos los navegadores; es el respaldo automático en Firefox si hay clave). | Navegador: gratis. Scribe: USD 0,22 por hora de audio |

**Una conversación de 10 preguntas** cuesta ≈ USD 0,01 con Gemini y la voz del navegador,
≈ USD 0,04 con Gemini TTS y ≈ USD 0,20 con ElevenLabs Flash, frente a ≈ USD 2,00-2,50 de 10 minutos
de LiveAvatar. El saludo y las respuestas a las **preguntas sugeridas** se guardan en caché: la
segunda vez no cuestan nada.

**Puesta en marcha (5 minutos):**
1. Crea una clave de Gemini en [aistudio.google.com/apikey](https://aistudio.google.com/apikey)
   (o un token de Hugging Face con permiso *Make calls to Inference Providers*).
2. Crea una clave de ElevenLabs en *elevenlabs.io → API Keys* con permisos de *Text to Speech*,
   *Speech to Text*, *Voices* (lectura) y *User* (lectura). Si prefieres no usar ElevenLabs, elige
   Gemini TTS o la voz del navegador.
3. En **wj-admin → Motor y APIs** pega las claves, pulsa **Guardar** y después **Verificar todo**.
4. En la misma página, **Ver voces de la cuenta** → **Usar esta voz** (para una voz latinoamericana,
   agrégala antes a *My Voices* desde la biblioteca de ElevenLabs) y **Probar voz**.

**Qué hace el servidor en cada pregunta:** reserva el turno (máximo de preguntas por
conversación, una cada 2 s y un tope global de respuestas por hora), envía a la IA el tema del
panel y los últimos turnos, limpia la respuesta para leerla en voz alta (sin markdown, enlaces ni
emojis, y con el largo configurado), genera el audio, guarda la pregunta y la respuesta como
mensajes verificados y devuelve texto y audio al navegador. Si la voz del servidor falla (sin
saldo, sin conexión), el navegador lee la respuesta con su propia voz.

> **Privacidad:** en el nivel gratuito de Gemini, Google puede usar lo que se envía para mejorar
> sus productos. Las preguntas de los visitantes pueden contener datos personales: para uso
> institucional se recomienda activar la facturación de Gemini (nivel de pago) o usar un proveedor
> con condiciones de privacidad acordadas.

**¿Y D-ID?** Se evaluó en septiembre de 2026 ([docs.d-id.com](https://docs.d-id.com/reference/get-started),
[precios de la API](https://www.d-id.com/pricing/api/)). Ofrece avatares en tiempo real a partir de
una foto (WebRTC) y permite enviar el texto que el avatar debe decir (`speak`), así que se podría
usar con la misma IA de texto. Cobra por el tiempo que **habla** el avatar (0,5 créditos por cada
15 s): el plan de API *Launch* cuesta USD 50 al mes por 90 minutos de video en tiempo real
(≈ USD 0,55 por minuto hablado); *Build* (USD 18) es de licencia personal y con marca de agua, y
*Scale* va de USD 198 a 297. Una conversación de 10 preguntas en la que el avatar habla 3-4 minutos
costaría ≈ USD 1,70-2,20 más la mensualidad: parecido a LiveAvatar y unas diez veces más que el
motor económico. No está integrado.

---

## 3. HeyGen LiveAvatar (avatar en vivo, opcional)

Se activa en **Motor y APIs → Avatar en vivo**. La clave y las verificaciones se configuran en
**HeyGen LiveAvatar**; pueden quedar listas y verificadas aunque el sitio use el motor económico.

HeyGen está retirando su antigua **Interactive Avatar** y remite a la plataforma **LiveAvatar**
(`api.liveavatar.com`), su versión de producción para avatares en tiempo real. Este sistema usa
LiveAvatar en **modo FULL**: LiveAvatar escucha a la persona, entiende la pregunta, genera la
respuesta con el contexto que configuras y la dice con la voz del avatar.

Fuentes consultadas (septiembre de 2026): [documentación de LiveAvatar](https://docs.liveavatar.com)
y su especificación [openapi.json](https://docs.liveavatar.com/openapi.json), y el anuncio de HeyGen
[Introducing LiveAvatar](https://help.heygen.com/en/articles/12758516-introducing-liveavatar).
HeyGen no publica en esas páginas una fecha exacta de cierre de la API anterior.

- **La clave debe ser de LiveAvatar:** se crea en
  [app.liveavatar.com/developers](https://app.liveavatar.com/developers), con su propia cuenta.
  La clave de la API de videos de HeyGen (app.heygen.com, la que se verifica con
  `GET api.heygen.com/v3/users/me`) **no sirve**: la documentación de HeyGen remite los avatares en
  tiempo real a LiveAvatar y la API anterior ya no existe (`POST api.heygen.com/v1/streaming.new`
  responde 404, comprobado en septiembre de 2026). Si se pega una clave de HeyGen, «Verificar
  todo» lo detecta y lo indica.
- **El avatar y la voz también deben ser de LiveAvatar** (`app.liveavatar.com`). Según HeyGen,
  los avatares creados en HeyGen no son compatibles directamente con LiveAvatar: se migran con
  su ayuda y pueden quedar con otro ID.
- Los ID configurados de fábrica son los entregados para este proyecto:
  avatar `56aa5373edb14809a1572b36af99b94c` y voz `5fab49b6cbd84b2cb0320fd28f9e49de`.
  LiveAvatar espera el formato UUID con guiones y el sistema los convierte solo
  (`56aa5373-edb1-4809-a157-2b36af99b94c`). **Que ese ID exista en LiveAvatar solo se puede
  confirmar con la clave real:** pulsa **HeyGen LiveAvatar → Verificar todo**. Si responde «no
  encontrado», usa **Ver mis avatares y voces** y copia el ID migrado en **Avatar y tema**.
- **Modo sandbox**: para probar sin gastar créditos (avatar genérico, sesiones de ~1 minuto).
- La clave **nunca llega al navegador**: el servidor crea e inicia cada sesión y solo entrega al
  visitante el acceso temporal a la sala de video.

---

## 4. Instalación en Plesk

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

5. **Claves de las APIs** (IA de texto y voz del motor económico; LiveAvatar solo si lo usarás).
   Dos formas:
   - *La sencilla:* entra al panel y pégalas en **Motor y APIs** (y la de LiveAvatar en
     **HeyGen LiveAvatar**). Se guardan fuera del repositorio y se muestran enmascaradas.
   - *La automática:* antes de la primera visita copia `wj-content/config/claves.ejemplo.php`
     como `wj-content/config/claves.php` y escribe las claves. Ese archivo está excluido del
     repositorio; bórralo después de la primera visita.

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

7. En **Motor y APIs** pulsa **Verificar todo**: comprueba la IA de texto, la voz, la escucha y
   los videos del motor elegido. Si usarás LiveAvatar, en **HeyGen LiveAvatar** pulsa también
   **Prueba de sesión** (valida avatar, voz, idioma y contexto sin consumir créditos).

8. Abre el sitio, pulsa **Iniciar conversación** y habla con el avatar.

> **Actualización desde la versión 1 (creador de canciones):** al cargar la nueva versión, los
> ajustes se migran solos. Se conservan identidad, imágenes, colores, correo SMTP y seguridad;
> se descartan géneros musicales y APIs de música. El archivo `registros.json.php` anterior no se
> borra, pero ya no se usa.

---

## 5. Configuración desde el panel

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

### Motor y APIs
Elige el motor y configura cada pieza del motor económico: IA de texto (proveedor, modelo, clave,
razonamiento, creatividad y turnos que recuerda), voz (ElevenLabs, Gemini TTS o navegador, con sus
opciones), escucha, los dos videos del avatar (se pueden subir MP4 o WebM) y los topes de uso.
Muestra las respuestas y los caracteres de voz del día y del mes, con el gasto estimado.

| Botón | Qué hace | ¿Consume saldo? |
|---|---|---|
| **Verificar todo** | Verifica cada pieza del motor elegido (IA, voz, escucha y videos; o LiveAvatar). | Solo Gemini TTS genera una palabra |
| **Verificar clave y modelo** | Lista los modelos de la IA y confirma que el configurado existe. | No |
| **Probar respuesta** | Hace la primera pregunta sugerida y muestra la respuesta y el tiempo. | Una respuesta |
| **Verificar saldo y voz** | Plan, caracteres usados y límite de ElevenLabs, y datos de la voz elegida. | No |
| **Ver voces de la cuenta** | Lista las voces de ElevenLabs con muestra de audio y botón «Usar esta voz». | No |
| **Probar Scribe** | Envía un segundo de silencio a ElevenLabs Scribe. | Fracción de centavo |
| **Probar voz** | Genera y reproduce una frase con la voz elegida. | Una frase |
| **Vaciar caché de respuestas** | Borra el saludo y las respuestas sugeridas guardadas. | No |

Si falta el video en reposo o el de «hablando», se muestra el retrato con un movimiento suave.
Junto a cada video puede haber una copia con el mismo nombre en el otro formato (`.webm` y `.mp4`):
el sitio ofrece las dos y cada navegador usa la que puede reproducir.

### HeyGen LiveAvatar
| Botón | Qué hace | ¿Consume créditos? |
|---|---|---|
| **Verificar todo** | Consulta los créditos y confirma que el avatar y la voz existen y están activos. | No |
| **Sincronizar contexto** | Crea o actualiza en LiveAvatar el tema, la personalidad y el saludo. | No |
| **Prueba de sesión** | Pide un token de sesión con la configuración completa y lo cierra sin transmitir. | No |
| **Ver mis avatares y voces** | Lista los avatares y voces de la cuenta con sus ID. | No |

### Apariencia y formulario
- **Identidad:** nombre, eslogan, entidad y sitio de la entidad (enlace del pie de página).
- **Imágenes y logos:** imagen del avatar (se ve mientras conecta), logo, **imagen de fondo de
  pantalla completa** con control de visibilidad (0-100 %), decoraciones de esquina y lateral
  y favicon. Puedes elegir una existente o subir una nueva
  (`wj-content/subidas`, máximo 5 MB).
- **Colores:** catorce colores con selector visual y tres paletas en un clic: **Predeterminada**
  (color principal #8F1824, con el amarillo #FFD500 y el verde #10A13B del Manual de Identidad
  Visual como acentos), **Verde institucional** y **Café** (rojo y dorado). Todas cumplen contraste
  AA. Al actualizar, los colores que seguían con los valores predeterminados anteriores (y cualquier
  azul) pasan solos a la paleta #8F1824; los que elegiste a mano se respetan.
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

## 6. Conversaciones registradas

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

## 7. Seguridad

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

**Motor económico**
- Las claves de la IA, ElevenLabs y Gemini viven solo en el servidor; el navegador habla
  únicamente con este sitio (`connect-src 'self'`) y no carga el módulo de LiveKit.
- La voz solo lee textos que genera el servidor (el saludo y las respuestas de la IA), nunca un
  texto enviado por el visitante: el sitio no sirve como lector gratuito.
- Topes atómicos: preguntas por conversación, una pregunta cada 2 s, **respuestas por hora en
  total y por origen** (IP o /64 de IPv6) y **minutos de audio por hora para Scribe**. Scribe cobra
  por duración, así que el servidor la mide con lo que de verdad se decodificará (cuenta los
  paquetes Opus de cada WebM, o usa la cabecera de un WAV), no con los metadatos del archivo: solo
  acepta WebM/Opus o WAV de hasta 512 KB y 30 s. Las respuestas se guardan desde el servidor como
  verificadas; lo que envíe el navegador como respuesta del avatar se descarta.
- Las respuestas de las preguntas sugeridas se generan **sin el historial** de la conversación
  (otro visitante no puede «sembrar» una respuesta falsa que luego oirían todos) y vencen a los
  30 días. La voz lee como máximo 8 caracteres por palabra configurada.
- Con «Otra API», la dirección debe ser pública (se rechazan IP privadas o reservadas, también si el
  nombre se resuelve a ellas; `http://127.0.0.1` solo para un modelo local) y la clave se borra al
  cambiar la dirección, para que no pueda enviarse a otro servidor. Los errores de esa dirección no
  muestran el texto que devuelva.
- La IA recibe reglas contra el cambio de instrucciones; aun así, el correo institucional quita
  todas las direcciones web de la conversación, también de las respuestas.
- Los videos subidos se aceptan solo si sus primeros bytes son de MP4 o WebM y quedan con nombre
  generado.

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

## 8. Mantenimiento

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

## 9. Estructura de archivos

Solo existen tres carpetas: `wj-admin`, `wj-includes` y `wj-content`.

```
index.php                     Interfaz pública (un solo div de 100 % × 100vh, sin scroll)
.htaccess  .gitignore         Seguridad de Apache y archivos que no se suben al repositorio
README.md · ARQUITECTURA.md   Documentación

wj-admin/
  .htaccess                   Protección (autenticación de Apache opcional)
  index.php                   Conversaciones, casillas Creado/Enviado, detalle
  avatar.php                  Avatar, voz, tema, conocimiento y sugerencias
  motor.php                   Motor del avatar, IA, voz, escucha, videos, topes y verificaciones
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
  motor.php                   Motor económico: IA de texto, ElevenLabs, Gemini TTS, caché y topes
  heygen.php                  Cliente de HeyGen LiveAvatar
  seguridad.php               Sesión, CSRF, .htpasswd, límites
  correo.php                  Envío por mail() y SMTP
  api/sesion.php              Inicia una conversación (POST)
  api/mensajes.php            Guarda preguntas y respuestas (POST)
  api/mantener.php            Mantiene viva la sesión (POST)
  api/finalizar.php           Cierra la conversación (POST, también sendBeacon)
  api/responder.php           Respuesta de la IA con su voz (motor económico, POST)
  api/transcribir.php         Voz a texto con ElevenLabs Scribe (motor económico, POST)
  css/app.css  css/admin.css  Estilos
  js/app.js  js/admin.js      Experiencia pública y panel
  js/vendor/three.min.js      three.js r149
  js/vendor/livekit-client.umd.js  LiveKit 2.22.3 (solo se carga con LiveAvatar)
  images/                     Logo, fondos y avatar: retrato (avatar-cafe.webp) y videos en bucle
                              (avatar-reposo y avatar-hablando, en .webm y .mp4)

wj-content/                   Única carpeta escribible (sus datos no se suben al repositorio)
  .htaccess                   Solo imágenes por la web
  config/                     Ajustes, credenciales (.htpasswd.php), ejemplos y claves.php (opcional)
  datos/                      conversaciones.json.php, uso-ia.json.php (contador de gasto)
                              y voz/ (caché del saludo y de las respuestas sugeridas)
  subidas/                    Imágenes y videos cargados desde el panel (.htaccess: solo esos formatos)
  logs/                       Bitácoras mensuales
```

---

## 10. Herramientas de desarrollo

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

Gobernación de Nariño · QuéDice! · versión 2.5.0
