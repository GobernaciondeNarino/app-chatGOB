# QuéDice! · Documento técnico (versión 2.4.0)

Complemento del `README.md` para quien vaya a mantener o ampliar el sistema.

---

## 1. Principios

- **Sin dependencias de servidor.** PHP 7.4+ con `curl`, `json` y `mbstring`. Sin Composer, sin
  base de datos, sin compilación. Se despliega copiando archivos (Plesk).
- **Tres carpetas.** `wj-admin` (panel), `wj-includes` (núcleo y recursos) y `wj-content` (datos,
  la única carpeta escribible).
- **Todo configurable.** La interfaz no tiene textos, colores ni temas escritos en el código: se
  leen de `wj-content/config/ajustes.json.php`.
- **La clave de API no sale del servidor.** El navegador recibe solo la URL y el token de la sala
  LiveKit de su propia sesión.
- **Degradación elegante.** Sin WebGL, la escena 3D se omite; sin micrófono, la persona escribe.

---

## 2. Flujo de una conversación

```
Navegador                         Servidor PHP                        LiveAvatar
   │ GET /                           │ index.php: ajustes + token CSRF   │
   │ «Iniciar» (+ formulario)        │                                   │
   │ POST api/sesion.php ──────────► │ valida, límites, crea registro    │
   │                                 │ sincroniza contexto (si cambió) ─►│ PATCH/POST /v1/contexts
   │                                 │ token de sesión ─────────────────►│ POST /v1/sessions/token
   │                                 │ inicia la sesión ────────────────►│ POST /v1/sessions/start
   │ ◄── código, clave, livekit_url, livekit_token, duración             │
   │ LiveKit: room.connect() ═══════════════════════════════════════════►│ sala WebRTC
   │   video/audio del participante «heygen»  ◄══════════════════════════│
   │   micrófono de la persona ══════════════════════════════════════════►│ STT → LLM → TTS
   │   eventos «agent-response» ◄════════════════════════════════════════│ transcripciones
   │   comandos «agent-control» ═════════════════════════════════════════►│ speak_response, interrupt…
   │ POST api/mensajes.php (lotes) ► │ agrega preguntas y respuestas     │
   │ POST api/mantener.php (60 s) ─► │ ─────────────────────────────────►│ POST /v1/sessions/keep-alive
   │ POST api/finalizar.php ───────► │ detiene la sesión ───────────────►│ POST /v1/sessions/stop
   │   (o sendBeacon al cerrar)      │ si no hay mensajes, pide la ─────►│ GET /v1/sessions/{id}/transcript
   │                                 │ transcripción oficial             │
```

---

## 3. HeyGen LiveAvatar (`wj-includes/heygen.php`)

| Función | Endpoint | Autenticación |
|---|---|---|
| `musa_heygen_sincronizar_contexto()` | `POST /v1/contexts`, `PATCH /v1/contexts/{id}` | `X-API-KEY` |
| `musa_heygen_crear_token()` | `POST /v1/sessions/token` | `X-API-KEY` |
| `musa_heygen_iniciar()` | `POST /v1/sessions/start` | `Bearer <session_token>` |
| `musa_heygen_mantener()` | `POST /v1/sessions/keep-alive` | `X-API-KEY` |
| `musa_heygen_detener()` | `POST /v1/sessions/stop` | `X-API-KEY` |
| `musa_heygen_transcripcion()` | `GET /v1/sessions/{id}/transcript` | `X-API-KEY` |
| `musa_heygen_verificar()` | `GET /v1/users/credits` | `X-API-KEY` |
| `musa_heygen_verificar_avatar()` / `_voz()` | `GET /v1/avatars/{id}`, `GET /v1/voices/{id}` | `X-API-KEY` |

Cuerpo del token (modo FULL):

```json
{
  "mode": "FULL",
  "avatar_id": "56aa5373-edb1-4809-a157-2b36af99b94c",
  "is_sandbox": false,
  "video_settings": { "quality": "high", "encoding": "H264" },
  "max_session_duration": 600,
  "interactivity_type": "CONVERSATIONAL",
  "avatar_persona": { "context_id": "…", "language": "es", "voice_id": "5fab49b6-cbd8-4b2c-b032-0fd28f9e49de" }
}
```

- `musa_heygen_uuid()` convierte los ID de 32 caracteres hexadecimales al formato UUID.
- El **contexto** se arma con `musa_heygen_prompt()` (personalidad + tema + reglas + máximo de
  palabras + conocimiento) y el saludo (`opening_text`). Su huella SHA-1 se guarda en
  `heygen.context_huella`: solo se vuelve a enviar si algo cambió.
- Verificado contra `https://docs.liveavatar.com/openapi.json` (septiembre de 2026): rutas, métodos,
  autenticación (`X-API-KEY` o `Bearer`), cuerpo del token FULL y respuesta de `sessions/start`
  (`livekit_url`, `livekit_client_token`, `max_session_duration`).
- `avatar_persona` figura como obsoleto en la documentación a favor de `voice_agent` (agentes
  guardados en el panel de LiveAvatar). Se usa porque permite configurar voz, idioma y contexto
  desde wj-admin. Si LiveAvatar lo retira, el cambio es solo en `musa_heygen_sesion_cuerpo()`.
- Para pruebas locales, el endpoint admite `http://localhost` o `http://127.0.0.1` (simulador).

### Eventos en la sala (canales de datos de LiveKit)

Según la página *FULL Mode → Events* de la documentación de LiveAvatar:

| Canal | Evento | Uso en `app.js` |
|---|---|---|
| `agent-response` | `user.transcription` `{text}` | Burbuja de la persona + guardado (`ref` = `event_id`) |
| `agent-response` | `avatar.transcription` `{text}` | Texto final del avatar + guardado |
| `agent-response` | `user.speak_started/ended`, `avatar.speak_started/ended` | Estado «Te escucho… / Respondiendo…», aura y burbuja «•••» mientras el avatar habla |
| `agent-response` | `user.push_to_talk_start_failed` | Aviso en pantalla |
| `agent-response` | `session.stopped` `{end_reason}` | Cierra la conversación (`MAX_DURATION_REACHED` → motivo «tiempo») |
| `agent-control` | `avatar.speak_response` `{text}` | Pregunta escrita o sugerida (el avatar responde) |
| `agent-control` | `avatar.interrupt` | Botón interrumpir |
| `agent-control` | `avatar.start_listening` / `stop_listening` | Micrófono encendido / apagado |
| `agent-control` | `user.start_push_to_talk` / `stop_push_to_talk` | Modo «mantener presionado para hablar» |

Todos los mensajes son JSON con `event_id` y `event_type`, codificados en UTF-8 y publicados con
`reliable: true`.

- La documentación **no** menciona fragmentos parciales (`*.transcription.chunk`): `app.js` los
  atiende si llegan, pero no depende de ellos; el texto del avatar aparece al terminar cada frase.
- La documentación **no** fija el nombre de los participantes. `app.js` prefiere el participante
  `heygen` para el video (nombre observado en las salas), pero toma el video del primero que lo
  publique y reproduce todo audio remoto, así un cambio de nombre no deja la pantalla en negro.

### Prueba local sin créditos

`heygen.endpoint` acepta `http://localhost` o `http://127.0.0.1`. Con un simulador de las rutas
REST y un servidor LiveKit en modo `--dev` con un participante de prueba que publique video y los
eventos anteriores, se recorre todo el flujo (formulario → sala → transcripción → JSON → panel).

## 4. API pública (`wj-includes/api/`)

Todas las respuestas son JSON. Todas exigen el token CSRF de la página (`token`).

| Punto | Cuerpo | Respuesta |
|---|---|---|
| `POST sesion.php` | `nombre, correo, telefono, ciudad, autorizacion, sitio_web` | `codigo, clave, session_id, livekit_url, livekit_token, duracion` · 422 errores del formulario · 429 límite por origen · 503 cupo global, archivo lleno o sin clave · 502 LiveAvatar |
| `POST mensajes.php` | `codigo, clave, mensajes: [{rol, texto, origen, ref}]` | `total` · 409 conversación cerrada o vencida |
| `POST mantener.php` | `codigo, clave` | `ok` · 429 si hubo otro keep-alive hace menos de 25 s |
| `POST finalizar.php` | `codigo, clave, motivo, mensajes` | `codigo, preguntas` |

- `clave` es un token aleatorio de 32 caracteres creado con la conversación: sin él no se puede
  escribir en una conversación ajena.
- `finalizar.php` acepta el cuerpo como `text/plain` porque `navigator.sendBeacon()` lo envía así
  al cerrar la pestaña.
- `rol` solo puede ser `persona` o `avatar`; los textos se limpian (UTF-8 inválido, controles,
  caracteres invisibles y U+2028) y se cortan a 1 000 (persona) o 2 000 (avatar) caracteres, con un
  tope de 64 KB por conversación; `ref` evita duplicados cuando un lote se reintenta.
- Todo lo que llega del navegador se guarda con `fuente: navegador`. Al cerrar, el servidor pide la
  transcripción oficial (`musa_conversacion_aplicar_oficial()`): sus mensajes quedan con
  `fuente: liveavatar` y reemplazan las respuestas del avatar enviadas por el navegador; de estas
  solo se conservan las preguntas escritas. El correo usa `musa_conversacion_para_correo()`.
- Los límites de `sesion.php` se comprueban dentro de la transacción que crea el registro
  (`musa_conversacion_crear()`), agrupando IPv6 por /64 (`musa_ip_grupo()`).
- `musa_conversaciones_transaccion()` espera el bloqueo como máximo 8 s y responde 503.

---

## 5. Almacenamiento (`wj-content/datos/conversaciones.json.php`)

```jsonc
{
  "version": 2,
  "secuencia": 12,
  "actualizado": "2026-09-23T10:26:31-05:00",
  "conversaciones": [
    {
      "id": "c3f…", "codigo": "CAFE-20260923-0012",
      "fecha": "…", "fecha_fin": "…", "estado": "finalizada", "motivo_fin": "usuario",
      "nombre": "…", "correo": "…", "telefono": "", "ciudad": "…", "autorizacion": true,
      "tema": "Café", "avatar_id": "…", "session_id": "…",
      "mensajes": [
        { "rol": "avatar",  "texto": "¡Hola!…", "origen": "voz",   "hora": "…", "ref": "…" },
        { "rol": "persona", "texto": "¿…?",     "origen": "texto", "hora": "…", "ref": "…" }
      ],
      "creado": false, "enviado": false, "fecha_creado": "", "fecha_enviado": "",
      "notas": "", "ip": "…", "navegador": "…", "clave": "…", "actualizado": "…"
    }
  ]
}
```

Toda escritura pasa por `musa_conversaciones_transaccion()`: bloqueo exclusivo (`flock`) sobre
`conversaciones.lock`, lectura, cambio y guardado atómico (archivo temporal + `rename`).

`estado`: `iniciando` → `activa` → `finalizada` (o `error` si LiveAvatar no pudo iniciar).
Las casillas `creado` y `enviado` son independientes del estado; `enviado` se marca sola al enviar
el resumen por correo desde el panel.

---

## 6. La pantalla (`index.php`, `app.css`) y la escena 3D (`app.js`)

### Distribución: un solo contenedor sin scroll
- `html` y `body` tienen `overflow: hidden`; todo vive en `div#app` (100 % × `100vh`/`100dvh`)
  con una rejilla de filas: cabecera · **escenario** (`1fr`, el avatar
  al centro con `aspect-ratio` configurable) · **panel inferior** (transcripción, sugerencias,
  entrada y controles). Solo la transcripción se desplaza por dentro, con un desvanecido en su
  borde superior.
- En pantallas horizontales bajas (`orientation: landscape` y `max-height: 560px`) el avatar y el
  panel se ponen lado a lado. En pantallas bajas se ocultan primero los accesorios (subtítulo, pie).
- Probado sin scroll en 1920×1080, 1366×768, 1080×1920 (kiosco vertical), 768×1024, 390×844,
  360×640 y 844×390.

### Criterios de diseño (apple-design-skill, traducido a web)
- Dos capas: contenido (fondo, escena, avatar) y funcional (cabecera y panel inferior). El
  material translúcido (`backdrop-filter: blur(24px) saturate(1.4)`) solo va en la capa funcional.
- El color de marca se reserva para la acción principal (Iniciar, Enviar) y los estados
  (escuchando, hablando). Objetivos táctiles de 44-48 px.
- Contraste AA verificado con los valores hexadecimales de las dos paletas (mínimo 4.57:1).
- `prefers-reduced-transparency`, `prefers-contrast: more` y `prefers-reduced-motion` tienen
  respuesta, y el botón de accesibilidad activa texto grande con superficies opacas.

### Escena 3D
- `WebGLRenderer` transparente sobre el degradado CSS; cámara en perspectiva.
- **Granos de café**: elipsoide + surco en S (`TubeGeometry` sobre una `CatmullRomCurve3`),
  repartidos a los lados, flotando y girando despacio.
- **Aura sonora**: 72 barras (`InstancedMesh`) en círculo detrás del marco del avatar. Su largo sale
  del `AnalyserNode` conectado a la pista de audio del avatar; color de acento cuando habla y
  acento secundario cuando escucha. Un halo (`Sprite` aditivo) crece con el volumen.
- **Vapor**: partículas (`Points`) que suben detrás del avatar.
- El centro y el radio se calculan desde la caja DOM del marco (`pantallaAMundo()`), con
  `ResizeObserver`.
- Respeta `prefers-reduced-motion` y el interruptor «Efectos 3D» del panel.

## 7. Convenciones

- Funciones y variables en español, prefijo `musa_` en PHP.
- Sintaxis compatible con PHP 7.4: `array()`, sin tipos de retorno nuevos, sin `match`.
- JavaScript en ES5 dentro de una IIFE (sin compilación). `livekit-client` y `three.js` están
  incluidos en `wj-includes/js/vendor/` para no depender de CDN.
- Todo lo que sale al HTML pasa por `musa_e()`; todo lo que entra, por `musa_texto()`,
  `musa_color()`, `musa_correo_valido()` o `musa_ruta_imagen_valida()`.
- Ajustes con notación de puntos: `musa_dato($ajustes, 'avatar.voice_id')`,
  `musa_fijar($nuevos, 'tema.nombre', 'Café')`.

---

## 8. Cómo añadir algo

| Quiero… | Dónde |
|---|---|
| Otro campo en el formulario de inicio | `index.php` (HTML), `api/sesion.php` (validación), `musa_conversacion_base()`, `wj-admin/ajustes.php` y el detalle de `wj-admin/index.php`. |
| Cambiar la lista de municipios | `musa_municipios_narino()` en `wj-includes/configuracion.php` (la usa el formulario y la validación del servidor). |
| Otro tema de conversación | Solo desde el panel: **Avatar y tema** (tema, conocimiento, saludo y sugerencias). |
| Usar un agente de voz guardado en LiveAvatar | Reemplazar `avatar_persona` por `voice_agent: { id }` en `musa_heygen_sesion_cuerpo()`. |
| Reaccionar a un evento nuevo de LiveAvatar | `alEvento()` en `wj-includes/js/app.js`. |
| Cambiar la escena 3D | Módulo `Escena` en `wj-includes/js/app.js`. |

---

Gobernación de Nariño · QuéDice! · versión 2.4.0
