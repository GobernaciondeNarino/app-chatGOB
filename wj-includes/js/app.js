/* QuéDice! · Avatar conversacional (motor económico o HeyGen LiveAvatar + three.js)
 *
 * Flujo:
 *  1. «Iniciar conversación» → formulario de inicio (si está activo en wj-admin).
 *  2. POST api/sesion.php → el servidor crea la conversación y devuelve su código.
 *
 *  Motor económico (predeterminado):
 *  3. El avatar es un video en bucle (reposo) que cambia al video «hablando» mientras suena la voz.
 *  4. La persona habla (reconocimiento de voz del navegador o ElevenLabs Scribe vía api/transcribir.php)
 *     o escribe; la pregunta va a api/responder.php, que devuelve el texto y el audio de la respuesta
 *     y guarda ambos en el servidor. Si no llega audio, el navegador lo lee con su propia voz.
 *
 *  LiveAvatar:
 *  3. El servidor inicia la sesión y devuelve la sala LiveKit (URL + token); el navegador se une:
 *     video y voz del avatar, micrófono de la persona. Los eventos llegan por «agent-response» y los
 *     comandos salen por «agent-control». Lo dicho se guarda con api/mensajes.php.
 *
 *  5. «Terminar», fin del tiempo o cierre de la pestaña → api/finalizar.php.
 */
(function () {
  'use strict';

  var CONFIG = window.MUSA_CONFIG || {};
  var MOTOR = CONFIG.motor === 'liveavatar' ? 'liveavatar' : 'economico';
  var doc = document;
  var TOPICO_COMANDOS = 'agent-control';
  var TOPICO_EVENTOS = 'agent-response';
  // Nombres observados en las salas de LiveAvatar. No figuran en su documentación, así que
  // solo se usan como preferencia: el video se toma de cualquier participante que lo publique.
  var PARTICIPANTE_AVATAR = 'heygen';
  var PREFIJO_AGENTE = 'liveavatar-agent-';

  function $(id) { return doc.getElementById(id); }
  function $$(sel) { return Array.prototype.slice.call(doc.querySelectorAll(sel)); }
  function ahora() { return Date.now(); }
  function uuid() {
    if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16);
    });
  }
  function normalizar(texto) {
    return String(texto || '').toLowerCase().replace(/[¿?¡!.,;:\s]+/g, ' ').trim();
  }

  var reducirMovimiento = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ------------------------------------------------------------------ */
  /*  Utilidades de red y avisos                                          */
  /* ------------------------------------------------------------------ */

  function enviar(url, datos) {
    datos.token = CONFIG.token;
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(datos)
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, mensaje: 'Respuesta no válida del servidor.' }; })
        .then(function (cuerpo) { cuerpo._estado = r.status; return cuerpo; });
    });
  }

  var temporizadorAviso = null;
  function avisar(texto, segundos) {
    var caja = $('aviso-flotante');
    if (!caja) { return; }
    caja.textContent = texto;
    caja.hidden = false;
    clearTimeout(temporizadorAviso);
    temporizadorAviso = setTimeout(function () { caja.hidden = true; }, (segundos || 6) * 1000);
  }

  /* ------------------------------------------------------------------ */
  /*  Hora legal colombiana (pie de página)                               */
  /* ------------------------------------------------------------------ */

  (function horaLegal() {
    var el = $('hora-legal');
    if (!el || !window.Intl) { return; }
    var formato = new Intl.DateTimeFormat('es-CO', { timeZone: 'America/Bogota', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
    function pintar() { el.textContent = formato.format(new Date()); }
    pintar();
    setInterval(pintar, 1000);
  })();

  /* ------------------------------------------------------------------ */
  /*  Escena 3D: granos de café, vapor y aura sonora alrededor del avatar */
  /* ------------------------------------------------------------------ */

  var Escena = (function () {
    var api = { activa: false, nivel: 0, estado: 'inactivo', analizador: null };
    var THREE = window.THREE;
    var lienzo = $('escena');

    if (!CONFIG.efectos3d || !THREE || !lienzo) { doc.body.classList.add('sin-webgl'); return api; }

    var renderer;
    try {
      renderer = new THREE.WebGLRenderer({ canvas: lienzo, alpha: true, antialias: true, powerPreference: 'low-power' });
    } catch (e) {
      doc.body.classList.add('sin-webgl');
      return api;
    }
    api.activa = true;
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.75));
    if (THREE.sRGBEncoding) { renderer.outputEncoding = THREE.sRGBEncoding; }

    var escena = new THREE.Scene();
    var camara = new THREE.PerspectiveCamera(40, 1, 0.1, 200);
    camara.position.set(0, 0, 30);

    escena.add(new THREE.AmbientLight(0xffffff, 0.55));
    var luz = new THREE.DirectionalLight(0xfff1dc, 0.9);
    luz.position.set(-8, 12, 16);
    escena.add(luz);
    var luzAcento = new THREE.PointLight(new THREE.Color(CONFIG.colores.acento), 0.6, 60);
    luzAcento.position.set(0, 0, 8);
    escena.add(luzAcento);

    var colorAcento = new THREE.Color(CONFIG.colores.acento);
    var colorAcento2 = new THREE.Color(CONFIG.colores.acento2);
    var colorTexto = new THREE.Color(CONFIG.colores.texto);

    /* Textura suave para brillos y vapor */
    function texturaSuave() {
      var c = doc.createElement('canvas');
      c.width = c.height = 64;
      var g = c.getContext('2d');
      var grad = g.createRadialGradient(32, 32, 0, 32, 32, 32);
      grad.addColorStop(0, 'rgba(255,255,255,1)');
      grad.addColorStop(0.4, 'rgba(255,255,255,.45)');
      grad.addColorStop(1, 'rgba(255,255,255,0)');
      g.fillStyle = grad;
      g.fillRect(0, 0, 64, 64);
      return new THREE.CanvasTexture(c);
    }
    var suave = texturaSuave();

    /* Grano de café: elipsoide con su surco en S */
    var geoGrano = new THREE.SphereGeometry(1, 28, 18);
    var matGrano = new THREE.MeshStandardMaterial({ color: 0x5a2d17, roughness: 0.42, metalness: 0.05 });
    var puntosSurco = [];
    for (var i = 0; i <= 12; i++) {
      var x = -0.86 + (1.72 * i / 12);
      var y = Math.sin((x / 0.86) * Math.PI) * 0.09;
      var z = 0.5 * Math.sqrt(Math.max(0, 1 - x * x - (y / 0.72) * (y / 0.72))) + 0.012;
      puntosSurco.push(new THREE.Vector3(x, y, z));
    }
    var geoSurco = new THREE.TubeGeometry(new THREE.CatmullRomCurve3(puntosSurco), 40, 0.045, 6, false);
    var matSurco = new THREE.MeshStandardMaterial({ color: 0x1c0b05, roughness: 0.8 });

    function crearGrano() {
      var grupo = new THREE.Group();
      var cuerpo = new THREE.Mesh(geoGrano, matGrano);
      cuerpo.scale.set(1, 0.72, 0.5);
      grupo.add(cuerpo);
      var surco = new THREE.Mesh(geoSurco, matSurco);
      grupo.add(surco);
      return grupo;
    }

    var granos = [];
    var totalGranos = window.innerWidth < 700 ? 12 : 24;
    for (var g = 0; g < totalGranos; g++) {
      var grano = crearGrano();
      grano.userData = {
        base: new THREE.Vector3(),
        fase: Math.random() * Math.PI * 2,
        giro: new THREE.Vector3((Math.random() - 0.5) * 0.6, (Math.random() - 0.5) * 0.6, (Math.random() - 0.5) * 0.3),
        escala: 0.45 + Math.random() * 0.75,
        profundidad: -4 - Math.random() * 14,
        ang: Math.random(), rad: Math.random()
      };
      grano.rotation.set(Math.random() * 6, Math.random() * 6, Math.random() * 6);
      grano.scale.setScalar(grano.userData.escala);
      granos.push(grano);
      escena.add(grano);
    }

    /* Aura sonora: barras en círculo detrás del marco del avatar */
    var BARRAS = 72;
    var geoBarra = new THREE.BoxGeometry(1, 1, 1);
    var matBarra = new THREE.MeshBasicMaterial({ color: colorAcento, transparent: true, opacity: 0.8 });
    var barras = new THREE.InstancedMesh(geoBarra, matBarra, BARRAS);
    barras.frustumCulled = false;
    escena.add(barras);
    var alturas = new Float32Array(BARRAS);

    var halo = new THREE.Sprite(new THREE.SpriteMaterial({ map: suave, color: colorAcento, transparent: true, opacity: 0.35, depthWrite: false, blending: THREE.AdditiveBlending }));
    halo.position.z = -1;
    escena.add(halo);

    var anillo = new THREE.Mesh(
      new THREE.RingGeometry(1, 1.012, 128),
      new THREE.MeshBasicMaterial({ color: colorTexto, transparent: true, opacity: 0.35, side: THREE.DoubleSide })
    );
    escena.add(anillo);

    /* Vapor: partículas que suben detrás del avatar */
    var VAPOR = window.innerWidth < 700 ? 90 : 170;
    var geoVapor = new THREE.BufferGeometry();
    var posVapor = new Float32Array(VAPOR * 3);
    var velVapor = new Float32Array(VAPOR);
    geoVapor.setAttribute('position', new THREE.BufferAttribute(posVapor, 3));
    var matVapor = new THREE.PointsMaterial({ map: suave, color: colorTexto, size: 0.9, transparent: true, opacity: 0.22, depthWrite: false, blending: THREE.AdditiveBlending });
    var vapor = new THREE.Points(geoVapor, matVapor);
    escena.add(vapor);

    var centro = new THREE.Vector3();
    var radio = 8;
    var ancho = 1, alto = 1, unidad = 0.05;
    var puntero = { x: 0, y: 0 };

    function pantallaAMundo(px, py) {
      return new THREE.Vector3((px - ancho / 2) * unidad, -(py - alto / 2) * unidad, 0);
    }

    function reiniciarVapor(i, inicial) {
      posVapor[i * 3] = centro.x + (Math.random() - 0.5) * radio * 1.4;
      posVapor[i * 3 + 1] = centro.y - radio * (inicial ? Math.random() * 2 - 0.2 : 1.05);
      posVapor[i * 3 + 2] = -3 - Math.random() * 3;
      velVapor[i] = 0.012 + Math.random() * 0.03;
    }

    function recolocar() {
      var app = $('app');
      ancho = app ? app.clientWidth : window.innerWidth;
      alto = app ? app.clientHeight : window.innerHeight;
      renderer.setSize(ancho, alto, false);
      camara.aspect = ancho / alto;
      camara.updateProjectionMatrix();
      var visible = 2 * Math.tan((camara.fov * Math.PI / 180) / 2) * camara.position.z;
      unidad = visible / alto;

      var marco = $('avatar-marco');
      if (marco) {
        var r = marco.getBoundingClientRect();
        centro.copy(pantallaAMundo(r.left + r.width / 2, r.top + r.height / 2));
        // El aura asoma por los costados del marco (arriba y abajo queda detrás del avatar).
        radio = Math.max(r.width / 2 + 30, r.height * 0.4) * unidad;
      }
      halo.position.set(centro.x, centro.y, -1);
      anillo.position.set(centro.x, centro.y, -0.5);
      anillo.scale.setScalar(radio * 0.97);

      // Granos repartidos por los bordes, lejos del avatar y de la transcripción.
      var anchoMundo = ancho * unidad, altoMundo = alto * unidad;
      granos.forEach(function (grano, n) {
        var d = grano.userData;
        var lado = n % 2 === 0 ? -1 : 1;
        var bx = lado * (anchoMundo * 0.30 + d.rad * anchoMundo * 0.2);
        var by = (d.ang - 0.5) * altoMundo * 0.95;
        if (Math.abs(bx - centro.x) < radio * 1.1) { bx = centro.x + lado * radio * 1.25; }
        d.base.set(bx, by, d.profundidad);
      });
      for (var i = 0; i < VAPOR; i++) { reiniciarVapor(i, true); }
    }

    var datosFrecuencia = null;
    function leerNivel() {
      if (api.analizador) {
        if (!datosFrecuencia) { datosFrecuencia = new Uint8Array(api.analizador.frequencyBinCount); }
        api.analizador.getByteFrequencyData(datosFrecuencia);
        return datosFrecuencia;
      }
      return null;
    }

    var matriz = new THREE.Matrix4();
    var q = new THREE.Quaternion();
    var eje = new THREE.Vector3(0, 0, 1);
    var escala = new THREE.Vector3();
    var pos = new THREE.Vector3();
    var reloj = new THREE.Clock();
    var visible = true;

    function cuadro() {
      if (!visible) { return; }
      var t = reloj.getElapsedTime();
      var frecuencias = leerNivel();
      var hablando = api.estado === 'hablando';
      var escuchando = api.estado === 'escuchando';

      // Nivel general (0..1)
      var objetivo = 0;
      if (frecuencias) {
        var suma = 0;
        for (var k = 2; k < 40; k++) { suma += frecuencias[k]; }
        objetivo = Math.min(1, suma / (38 * 170));
      } else if (hablando) {
        objetivo = 0.35 + 0.25 * Math.sin(t * 9) * Math.sin(t * 3.1);
      }
      api.nivel += (objetivo - api.nivel) * 0.18;

      // Barras del aura
      var colorObjetivo = escuchando ? colorAcento2 : colorAcento;
      matBarra.color.lerp(colorObjetivo, 0.08);
      for (var b = 0; b < BARRAS; b++) {
        var valor;
        if (frecuencias) {
          var indice = 2 + Math.floor((b < BARRAS / 2 ? b : BARRAS - b) / (BARRAS / 2) * 44);
          valor = frecuencias[indice] / 255;
        } else {
          valor = hablando ? (0.25 + 0.25 * Math.sin(t * 7 + b * 0.7)) : 0;
        }
        var reposo = escuchando ? 0.12 + 0.08 * Math.sin(t * 3 + b * 0.35) : 0.06 + 0.03 * Math.sin(t * 1.5 + b * 0.5);
        alturas[b] += (Math.max(reposo, valor) - alturas[b]) * 0.25;
        var angulo = (b / BARRAS) * Math.PI * 2 + (reducirMovimiento ? 0 : t * 0.05);
        var largo = radio * (0.04 + alturas[b] * 0.28);
        var r = radio + largo / 2;
        pos.set(centro.x + Math.cos(angulo) * r, centro.y + Math.sin(angulo) * r, -0.6);
        q.setFromAxisAngle(eje, angulo);
        escala.set(largo, radio * 0.022, 0.1);
        matriz.compose(pos, q, escala);
        barras.setMatrixAt(b, matriz);
      }
      barras.instanceMatrix.needsUpdate = true;

      halo.scale.setScalar(radio * (2.5 + api.nivel * 0.9));
      halo.material.opacity = 0.18 + api.nivel * 0.35 + (escuchando ? 0.06 : 0);
      halo.material.color.lerp(colorObjetivo, 0.08);
      anillo.material.opacity = 0.2 + api.nivel * 0.4;
      luzAcento.position.set(centro.x, centro.y, 8);
      luzAcento.intensity = 0.4 + api.nivel * 1.2;

      if (!reducirMovimiento) {
        granos.forEach(function (grano) {
          var d = grano.userData;
          grano.position.set(
            d.base.x + Math.sin(t * 0.3 + d.fase) * 0.8,
            d.base.y + Math.sin(t * 0.45 + d.fase * 2) * 0.9 + api.nivel * 0.3,
            d.base.z
          );
          grano.rotation.x += d.giro.x * 0.01;
          grano.rotation.y += d.giro.y * 0.01;
          grano.rotation.z += d.giro.z * 0.01;
        });
        for (var v = 0; v < VAPOR; v++) {
          posVapor[v * 3 + 1] += velVapor[v] * (1 + api.nivel * 1.5);
          posVapor[v * 3] += Math.sin(t * 0.8 + v) * 0.006;
          if (posVapor[v * 3 + 1] > centro.y + radio * 1.2) { reiniciarVapor(v, false); }
        }
        geoVapor.attributes.position.needsUpdate = true;
        camara.position.x += (puntero.x * 1.2 - camara.position.x) * 0.03;
        camara.position.y += (puntero.y * 0.8 - camara.position.y) * 0.03;
        camara.lookAt(0, 0, 0);
      } else {
        granos.forEach(function (grano) { grano.position.copy(grano.userData.base); });
      }

      renderer.render(escena, camara);
      requestAnimationFrame(cuadro);
    }

    window.addEventListener('resize', recolocar);
    window.addEventListener('pointermove', function (e) {
      puntero.x = (e.clientX / ancho - 0.5) * 2;
      puntero.y = -(e.clientY / alto - 0.5) * 2;
    }, { passive: true });
    doc.addEventListener('visibilitychange', function () {
      var antes = visible;
      visible = !doc.hidden;
      if (visible && !antes) { requestAnimationFrame(cuadro); }
    });
    if (window.ResizeObserver && $('avatar-marco')) { new ResizeObserver(recolocar).observe($('avatar-marco')); }

    recolocar();
    requestAnimationFrame(cuadro);

    api.estadoAvatar = function (estado) { api.estado = estado; };
    return api;
  })();

  /* ------------------------------------------------------------------ */
  /*  Transcripción en pantalla                                           */
  /* ------------------------------------------------------------------ */

  var Transcripcion = (function () {
    var caja = $('transcripcion');
    var vacia = $('transcripcion-vacia');
    var parcial = $('parcial');
    var enCurso = null;   // burbuja del avatar que se está escribiendo

    function alFinal() { if (caja) { caja.scrollTop = caja.scrollHeight; } }

    function burbuja(rol, texto) {
      if (vacia) { vacia.hidden = true; }
      var envoltura = doc.createElement('div');
      envoltura.className = 'mensaje ' + rol;
      var quien = doc.createElement('span');
      quien.className = 'quien';
      quien.textContent = rol === 'persona' ? CONFIG.textos.persona : CONFIG.avatar.nombre;
      var cuerpo = doc.createElement('div');
      cuerpo.className = 'texto';
      cuerpo.textContent = texto;
      envoltura.appendChild(quien);
      envoltura.appendChild(cuerpo);
      caja.appendChild(envoltura);
      alFinal();
      return envoltura;
    }

    return {
      persona: function (texto) {
        this.parcial('');
        return burbuja('persona', texto);
      },
      avatarParcial: function (fragmento) {
        if (!enCurso) {
          enCurso = burbuja('avatar', '');
          enCurso.classList.add('en-curso');
          enCurso.dataset.texto = '';
        }
        var actual = enCurso.dataset.texto;
        // Los fragmentos pueden llegar acumulados o como piezas nuevas.
        var nuevo = fragmento.indexOf(actual) === 0 ? fragmento : (actual + (actual && !/^[\s.,;:!?]/.test(fragmento) ? ' ' : '') + fragmento);
        enCurso.dataset.texto = nuevo;
        enCurso.querySelector('.texto').textContent = nuevo;
        alFinal();
      },
      avatarPensando: function () {
        if (enCurso) { return; }
        enCurso = burbuja('avatar', '');
        enCurso.classList.add('en-curso');
        enCurso.dataset.texto = '';
      },
      avatarFinal: function (texto) {
        if (enCurso) {
          enCurso.classList.remove('en-curso');
          enCurso.querySelector('.texto').textContent = texto;
          enCurso = null;
          alFinal();
        } else {
          burbuja('avatar', texto);
        }
      },
      cerrarEnCurso: function () {
        if (enCurso) {
          var texto = enCurso.dataset.texto;
          if (!texto) { enCurso.remove(); } else { enCurso.classList.remove('en-curso'); }
          enCurso = null;
          return texto;
        }
        return '';
      },
      parcial: function (texto) { if (parcial) { parcial.textContent = texto ? '“' + texto + '”' : ''; } },
      vaciar: function () {
        $$('#transcripcion .mensaje').forEach(function (m) { m.remove(); });
        if (vacia) { vacia.hidden = false; }
        enCurso = null;
        this.parcial('');
      }
    };
  })();

  /* ------------------------------------------------------------------ */
  /*  Guardado de preguntas y respuestas en el servidor                   */
  /* ------------------------------------------------------------------ */

  var Guardado = (function () {
    var cola = [];
    var conversacion = null;
    var enviando = false;
    var temporizador = null;

    function vaciar() {
      if (!conversacion || enviando || cola.length === 0) { return Promise.resolve(); }
      enviando = true;
      var lote = cola.splice(0, 25);
      return enviar(CONFIG.rutas.mensajes, { codigo: conversacion.codigo, clave: conversacion.clave, mensajes: lote })
        .then(function (r) {
          if (!r.ok && r._estado !== 409) { cola = lote.concat(cola); }
        })
        .catch(function () { cola = lote.concat(cola); })
        .then(function () { enviando = false; if (cola.length) { programar(); } });
    }

    function programar() {
      clearTimeout(temporizador);
      temporizador = setTimeout(vaciar, 1500);
    }

    return {
      iniciar: function (datos) { conversacion = datos; cola = []; },
      agregar: function (rol, texto, origen, ref) {
        if (!conversacion || !texto) { return; }
        cola.push({ rol: rol, texto: texto, origen: origen || 'voz', ref: ref || uuid() });
        programar();
      },
      pendientes: function () { var p = cola.slice(); cola = []; clearTimeout(temporizador); return p; },
      conversacion: function () { return conversacion; },
      terminar: function () { conversacion = null; clearTimeout(temporizador); }
    };
  })();

  /* ------------------------------------------------------------------ */
  /*  Sesión con el avatar                                                */
  /* ------------------------------------------------------------------ */

  var LK = window.LivekitClient;
  var sala = null;
  var estado = 'inicio';      // inicio | conectando | activa | terminando | final
  var agenteListo = false;
  var videoListo = false;
  var microfonoActivo = false;
  var contextoAudio = null;
  var ultimoTexto = { texto: '', hora: 0 };
  var reloj = { fin: 0, intervalo: null };
  var mantener = null;
  var esperaConexion = null;

  var video = $('avatar-video');
  var audio = $('avatar-audio');
  var participanteVideo = null;
  var audioPrincipal = false;
  var extrasAudio = [];

  function claseEstado(nombre) {
    doc.body.classList.remove('estado-inicio', 'estado-conectando', 'estado-escuchando', 'estado-hablando', 'estado-pensando', 'estado-final');
    if (nombre) { doc.body.classList.add('estado-' + nombre); }
  }

  function estadoAvatar(nombre) {
    var texto = $('estado-texto');
    if (nombre === 'hablando') { texto.textContent = CONFIG.textos.hablando; claseEstado('hablando'); }
    else if (nombre === 'escuchando') { texto.textContent = CONFIG.textos.escuchando; claseEstado('escuchando'); }
    else if (nombre === 'pensando') { texto.textContent = CONFIG.textos.pensando || '…'; claseEstado('pensando'); }
    else { texto.textContent = CONFIG.avatar.nombre; claseEstado(null); }
    Escena.estadoAvatar && Escena.estadoAvatar(nombre);
  }

  function velo(cual) {
    ['velo-inicio', 'velo-cargando', 'velo-final', 'velo-audio'].forEach(function (id) {
      var el = $(id); if (el) { el.hidden = id !== cual; }
    });
  }

  function habilitarEntrada(activo) {
    var pregunta = $('pregunta'), boton = $('enviar-pregunta');
    if (pregunta) { pregunta.disabled = !activo; }
    if (boton) { boton.disabled = !activo; }
    $$('.sugerencia').forEach(function (s) { s.disabled = !activo; });
    $('controles').hidden = !(estado === 'activa');
    $('controles-fin').hidden = !(estado === 'activa');
  }

  function comprobarListo() {
    if (estado !== 'activa' && estado !== 'conectando') { return; }
    if (videoListo && agenteListo && estado === 'conectando') {
      estado = 'activa';
      clearTimeout(esperaConexion);
      doc.body.classList.add('en-vivo');
      habilitarEntrada(true);
      estadoAvatar(CONFIG.avatar.pulsarHablar ? '' : (microfonoActivo ? 'escuchando' : ''));
      iniciarReloj();
    }
  }

  /** Publica un comando para el agente del avatar. */
  function comando(tipo, datos) {
    if (!sala || !sala.localParticipant) { return; }
    var carga = { event_id: uuid(), event_type: tipo };
    if (datos) { for (var k in datos) { if (Object.prototype.hasOwnProperty.call(datos, k)) { carga[k] = datos[k]; } } }
    try {
      sala.localParticipant.publishData(new TextEncoder().encode(JSON.stringify(carga)), { reliable: true, topic: TOPICO_COMANDOS });
    } catch (e) { /* la sala se está cerrando */ }
  }

  /** Eventos que envía LiveAvatar por el canal «agent-response». */
  function alEvento(ev) {
    var tipo = ev && ev.event_type;
    if (!tipo) { return; }
    switch (tipo) {
      case 'user.speak_started':
        estadoAvatar('escuchando');
        break;
      case 'user.speak_ended':
        break;
      case 'user.transcription.chunk':
      case 'user.transcription_chunk':
        Transcripcion.parcial(ev.text || '');
        break;
      case 'user.transcription':
        var texto = (ev.text || '').trim();
        Transcripcion.parcial('');
        if (!texto) { break; }
        // Si la persona lo escribió hace un momento, el servidor puede devolverlo como transcripción.
        if (normalizar(texto) === normalizar(ultimoTexto.texto) && ahora() - ultimoTexto.hora < 8000) { break; }
        Transcripcion.persona(texto);
        Guardado.agregar('persona', texto, 'voz', ev.event_id);
        break;
      case 'avatar.speak_started':
        estadoAvatar('hablando');
        Transcripcion.avatarPensando();
        break;
      case 'avatar.speak_ended':
        estadoAvatar(microfonoActivo && !CONFIG.avatar.pulsarHablar ? 'escuchando' : '');
        break;
      case 'avatar.transcription.chunk':
      case 'avatar.transcription_chunk':
        if (ev.text) { Transcripcion.avatarParcial(ev.text); }
        break;
      case 'avatar.transcription':
        if (ev.text) {
          Transcripcion.avatarFinal(ev.text.trim());
          Guardado.agregar('avatar', ev.text.trim(), 'voz', ev.event_id);
        }
        break;
      case 'user.push_to_talk_start_failed':
        avisar('No fue posible activar el micrófono. Inténtalo de nuevo o escribe tu pregunta.');
        break;
      case 'session.stopped':
        // La documentación de LiveAvatar llama al campo end_reason.
        var razon = ev.end_reason || ev.stop_reason || '';
        terminar(razon === 'MAX_DURATION_REACHED' ? 'tiempo' : 'servidor');
        break;
    }
  }

  /** Conecta el audio del avatar al analizador de la escena 3D. */
  function conectarAnalizador(pista) {
    if (!contextoAudio || !Escena.activa || !pista || !pista.mediaStreamTrack) { return; }
    try {
      var fuente = contextoAudio.createMediaStreamSource(new MediaStream([pista.mediaStreamTrack]));
      var analizador = contextoAudio.createAnalyser();
      analizador.fftSize = 256;
      analizador.smoothingTimeConstant = 0.7;
      fuente.connect(analizador);
      Escena.analizador = analizador;
    } catch (e) { /* sin análisis: el aura se anima con el estado */ }
  }

  function prepararSala() {
    sala = new LK.Room({ adaptiveStream: true, dynacast: true });
    var E = LK.RoomEvent;

    sala.on(E.TrackSubscribed, function (pista, publicacion, participante) {
      var identidad = participante.identity || '';
      if (pista.kind === 'video') {
        // Un solo video: el del participante «heygen» si existe, si no el primero que llegue.
        if (participanteVideo && participanteVideo !== identidad && identidad !== PARTICIPANTE_AVATAR) { return; }
        participanteVideo = identidad;
        pista.attach(video);
        videoListo = true;
        comprobarListo();
      } else if (pista.kind === 'audio') {
        // Todo audio remoto se reproduce; el primero alimenta el aura de la escena 3D.
        if (!audioPrincipal) {
          audioPrincipal = true;
          pista.attach(audio);
          conectarAnalizador(pista);
        } else {
          var extra = pista.attach();
          extra.hidden = true;
          $('app').appendChild(extra);
          extrasAudio.push(extra);
        }
      }
    });

    sala.on(E.ParticipantConnected, function (participante) {
      if (participante.identity.indexOf(PREFIJO_AGENTE) === 0) { agenteListo = true; comprobarListo(); }
    });

    sala.on(E.DataReceived, function (carga, participante, tipo, topico) {
      if (topico !== TOPICO_EVENTOS) { return; }
      try { alEvento(JSON.parse(new TextDecoder().decode(carga))); } catch (e) { /* mensaje no válido */ }
    });

    sala.on(E.AudioPlaybackStatusChanged, function () {
      if (!sala.canPlaybackAudio) { doc.body.classList.add('pide-audio'); velo('velo-audio'); }
      else { doc.body.classList.remove('pide-audio'); }
    });

    sala.on(E.Disconnected, function () {
      if (estado === 'activa' || estado === 'conectando') { terminar('servidor'); }
    });
  }

  function activarMicrofono(activo) {
    if (!sala) { return Promise.resolve(false); }
    return sala.localParticipant.setMicrophoneEnabled(activo).then(function () {
      microfonoActivo = activo;
      var boton = $('microfono');
      if (boton) { boton.setAttribute('aria-pressed', activo ? 'true' : 'false'); }
      if (!CONFIG.avatar.pulsarHablar) {
        comando(activo ? 'avatar.start_listening' : 'avatar.stop_listening');
        if (doc.body.classList.contains('en-vivo') && !doc.body.classList.contains('estado-hablando')) { estadoAvatar(activo ? 'escuchando' : ''); }
      }
      return true;
    }).catch(function () {
      microfonoActivo = false;
      var boton = $('microfono');
      if (boton) { boton.setAttribute('aria-pressed', 'false'); }
      if (activo) { avisar(CONFIG.textos.microfono || 'No fue posible usar el micrófono.', 8); }
      return false;
    });
  }

  function iniciarReloj() {
    var caja = $('reloj'), texto = $('reloj-texto');
    var conversacion = Guardado.conversacion();
    if (!caja || !conversacion || !conversacion.duracion) { return; }
    reloj.fin = ahora() + conversacion.duracion * 1000;
    caja.hidden = false;
    clearInterval(reloj.intervalo);
    reloj.intervalo = setInterval(function () {
      var restante = Math.max(0, Math.round((reloj.fin - ahora()) / 1000));
      texto.textContent = Math.floor(restante / 60) + ':' + ('0' + (restante % 60)).slice(-2);
      caja.classList.toggle('alerta', restante <= 60);
      if (restante <= 0) { terminar('tiempo'); }
    }, 1000);
  }

  /** Pide la sesión al servidor y se une a la sala (LiveAvatar) o arranca el motor económico. */
  function conectar(datosPersona) {
    if (MOTOR === 'economico') { return conectarEconomico(datosPersona); }
    if (!LK) { avisar('El navegador no pudo cargar el módulo de video. Recarga la página.'); return Promise.resolve(false); }
    estado = 'conectando';
    agenteListo = false; videoListo = false;
    participanteVideo = null; audioPrincipal = false;
    velo('velo-cargando');
    claseEstado('conectando');
    Transcripcion.vaciar();

    var datos = datosPersona || {};
    return enviar(CONFIG.rutas.sesion, datos).then(function (r) {
      if (!r.ok) { return r; }
      Guardado.iniciar({ codigo: r.codigo, clave: r.clave, duracion: r.duracion });
      prepararSala();
      agenteListo = false;
      esperaConexion = setTimeout(function () {
        // Si el agente no se anunció, se habilita igual cuando el video está listo.
        agenteListo = true; comprobarListo();
        if (estado === 'conectando') { terminar('error'); avisar('El anfitrión tardó demasiado en conectarse. Inténtalo de nuevo.'); }
      }, 35000);
      return sala.connect(r.livekit_url, r.livekit_token).then(function () {
        sala.remoteParticipants.forEach(function (p) {
          if (p.identity.indexOf(PREFIJO_AGENTE) === 0) { agenteListo = true; }
        });
        // Si el agente no aparece con ese nombre, no se bloquea la conversación.
        setTimeout(function () { if (!agenteListo) { agenteListo = true; comprobarListo(); } }, 5000);
        comprobarListo();
        var conMicrofono = CONFIG.avatar.pulsarHablar ? true : CONFIG.avatar.microfono;
        return activarMicrofono(conMicrofono);
      }).then(function () {
        mantener = setInterval(function () {
          var c = Guardado.conversacion();
          if (c && estado === 'activa') { enviar(CONFIG.rutas.mantener, { codigo: c.codigo, clave: c.clave }).catch(function () {}); }
        }, 60000);
        return { ok: true };
      });
    }).then(function (r) {
      if (r && !r.ok) {
        estado = 'inicio';
        velo('velo-inicio');
        claseEstado('inicio');
        return r;
      }
      return r;
    }).catch(function () {
      terminar('error');
      avisar('No fue posible conectar con el anfitrión. Revisa tu conexión e inténtalo de nuevo.');
      return { ok: false };
    });
  }

  /** Cierra la sesión y guarda lo pendiente. */
  function terminar(motivo) {
    if (estado === 'terminando' || estado === 'final' || estado === 'inicio') { return; }
    estado = 'terminando';
    clearInterval(reloj.intervalo);
    clearInterval(mantener);
    clearTimeout(esperaConexion);
    var enCurso = Transcripcion.cerrarEnCurso();
    if (enCurso) { Guardado.agregar('avatar', enCurso, 'voz'); }

    var conversacion = Guardado.conversacion();
    var pendientes = Guardado.pendientes();
    Guardado.terminar();

    if (sala) {
      try { sala.disconnect(); } catch (e) { /* ya cerrada */ }
      sala = null;
    }
    if (MOTOR === 'economico') {
      eco.turno++;
      eco.esperando = false;
      Voz.detener();
      Escucha.apagar();
    }
    Escena.analizador = null;
    doc.body.classList.remove('en-vivo', 'pide-audio');
    estadoAvatar('');
    claseEstado('final');
    habilitarEntrada(false);
    $('controles').hidden = true;
    $('controles-fin').hidden = true;
    $('reloj').hidden = true;
    extrasAudio.forEach(function (el) { el.remove(); });
    extrasAudio = [];
    participanteVideo = null;
    if (video) { video.srcObject = null; }

    var final = function () {
      estado = 'final';
      $('codigo-final').textContent = conversacion ? conversacion.codigo : '—';
      velo(conversacion ? 'velo-final' : 'velo-inicio');
      if (!conversacion) { estado = 'inicio'; claseEstado('inicio'); }
      olvidarVisitante();
      // En un kiosco compartido, la siguiente persona no debe ver la conversación anterior.
      clearTimeout(esperaLimpieza);
      esperaLimpieza = setTimeout(function () {
        if (estado !== 'final') { return; }
        Transcripcion.vaciar();
        $('codigo-final').textContent = '—';
        estado = 'inicio';
        claseEstado('inicio');
        velo('velo-inicio');
      }, 90000);
    };
    if (!conversacion) { final(); return; }
    enviar(CONFIG.rutas.finalizar, { codigo: conversacion.codigo, clave: conversacion.clave, motivo: motivo || 'usuario', mensajes: pendientes })
      .catch(function () {})
      .then(final);
  }

  /** Pregunta escrita o sugerencia. */
  function preguntar(texto) {
    if (MOTOR === 'economico') { preguntarEconomico(texto, 'texto'); return; }
    texto = String(texto || '').trim();
    if (!texto || estado !== 'activa') { return; }
    if (doc.body.classList.contains('estado-hablando')) { comando('avatar.interrupt'); }
    var enCurso = Transcripcion.cerrarEnCurso();
    if (enCurso) { Guardado.agregar('avatar', enCurso, 'voz'); }
    ultimoTexto = { texto: texto, hora: ahora() };
    Transcripcion.persona(texto);
    Guardado.agregar('persona', texto, 'texto');
    comando('avatar.speak_response', { text: texto });
  }

  /* ------------------------------------------------------------------ */
  /*  Motor económico: videos del avatar                                  */
  /* ------------------------------------------------------------------ */

  var Animacion = (function () {
    var reposo = $('avatar-reposo');
    var hablandoV = $('avatar-hablando');
    var marco = $('avatar-marco');
    var apagar = null;

    function reproducir(v) {
      if (!v) { return; }
      try { var p = v.play(); if (p && p.catch) { p.catch(function () {}); } } catch (e) { /* sin video */ }
    }
    // El bucle de reposo no se reproduce si la persona pidió reducir el movimiento (queda el primer cuadro).
    if (reposo && !reducirMovimiento) { reproducir(reposo); }

    return {
      /** Cambia al video «hablando» (fundido corto) o vuelve al de reposo. */
      hablar: function (activo) {
        if (hablandoV) {
          clearTimeout(apagar);
          if (activo) {
            if (!hablandoV.classList.contains('visible')) { try { hablandoV.currentTime = 0; } catch (e) { /* aún cargando */ } }
            reproducir(hablandoV);
            hablandoV.classList.add('visible');
          } else {
            hablandoV.classList.remove('visible');
            apagar = setTimeout(function () { hablandoV.pause(); }, 250);
          }
        } else if (marco && !reducirMovimiento) {
          marco.classList.toggle('hablando', !!activo);
        }
      },
      reanudar: function () { if (reposo && !reducirMovimiento) { reproducir(reposo); } }
    };
  })();

  /* ------------------------------------------------------------------ */
  /*  Motor económico: voz del avatar                                     */
  /* ------------------------------------------------------------------ */

  var Voz = (function () {
    var api = { hablando: false };
    var fin = null;          // cierra la frase en curso
    var pendiente = null;    // audio que el navegador bloqueó hasta que la persona active el sonido
    var urlActual = null;
    var fuenteAnalisis = null, analizador = null;

    function blobDesdeBase64(b64, tipo) {
      var binario = atob(b64);
      var bytes = new Uint8Array(binario.length);
      for (var i = 0; i < binario.length; i++) { bytes[i] = binario.charCodeAt(i); }
      return new Blob([bytes], { type: tipo });
    }

    function soltarUrl() {
      if (urlActual) { try { URL.revokeObjectURL(urlActual); } catch (e) { /* nada */ } urlActual = null; }
    }

    /**
     * El aura 3D sigue el volumen de la voz. Se analiza una copia del audio (captureStream) para no
     * desviar la salida por el contexto de audio: si ese contexto se suspende, la voz se sigue oyendo.
     */
    function analizar() {
      if (!contextoAudio || !Escena.activa || !audio || !audio.captureStream) { return; }
      try {
        var pistas = audio.captureStream().getAudioTracks();
        if (!pistas.length) { return; }
        if (!analizador) {
          analizador = contextoAudio.createAnalyser();
          analizador.fftSize = 256;
          analizador.smoothingTimeConstant = 0.7;
        }
        if (fuenteAnalisis) { try { fuenteAnalisis.disconnect(); } catch (e) { /* nada */ } }
        fuenteAnalisis = contextoAudio.createMediaStreamSource(new MediaStream([pistas[0]]));
        fuenteAnalisis.connect(analizador);
        Escena.analizador = analizador;
      } catch (e) { /* sin análisis: el aura se anima con el estado */ }
    }

    function elegirVoz() {
      var voces = (window.speechSynthesis && window.speechSynthesis.getVoices()) || [];
      var preferidas = [String(CONFIG.voz && CONFIG.voz.idioma || 'es-CO').toLowerCase(), 'es-co', 'es-us', 'es-419', 'es-mx', 'es-es'];
      for (var p = 0; p < preferidas.length; p++) {
        for (var v = 0; v < voces.length; v++) {
          if (String(voces[v].lang).toLowerCase().replace('_', '-') === preferidas[p]) { return voces[v]; }
        }
      }
      for (var w = 0; w < voces.length; w++) { if (/^es/i.test(voces[w].lang)) { return voces[w]; } }
      return null;
    }
    if (window.speechSynthesis && 'onvoiceschanged' in window.speechSynthesis) {
      window.speechSynthesis.onvoiceschanged = function () { /* las voces cargan tarde en Chrome */ };
    }

    /** Frases cortas: Chrome corta las lecturas largas de speechSynthesis a los ~15 s. */
    function trocear(texto) {
      var frases = String(texto).replace(/([.!?…;])\s+/g, '$1\n').split('\n');
      var partes = [], actual = '';
      frases.forEach(function (f) {
        if ((actual + ' ' + f).length > 180 && actual) { partes.push(actual); actual = f; }
        else { actual = actual ? actual + ' ' + f : f; }
      });
      if (actual) { partes.push(actual); }
      return partes;
    }

    /** Lee el texto con la voz del navegador (gratis; la calidad depende del equipo). */
    function hablarNavegador(texto, empezar, terminarFrase) {
      var sintesis = window.speechSynthesis;
      if (!sintesis || !window.SpeechSynthesisUtterance || !texto) { empezar(); setTimeout(terminarFrase, 300); return; }
      sintesis.cancel();
      var voz = elegirVoz();
      var partes = trocear(texto);
      var i = 0;
      // Seguros: hay navegadores que no avisan el inicio o el final de la lectura.
      var arranque = setTimeout(empezar, 2500);
      var limite = setTimeout(function () { sintesis.cancel(); terminarFrase(); }, 6000 + texto.length * 110);
      function cerrar() { clearTimeout(arranque); clearTimeout(limite); terminarFrase(); }
      function siguiente() {
        if (i >= partes.length) { cerrar(); return; }
        var u = new SpeechSynthesisUtterance(partes[i++]);
        u.lang = (CONFIG.voz && CONFIG.voz.idioma) || 'es-CO';
        if (voz) { u.voice = voz; }
        u.rate = (CONFIG.voz && CONFIG.voz.velocidad) || 1;
        u.onstart = function () { clearTimeout(arranque); empezar(); };
        u.onend = siguiente;
        u.onerror = cerrar;
        sintesis.speak(u);
      }
      siguiente();
    }

    /**
     * Dice una respuesta { texto, audio (base64), tipo }. alEmpezar se llama cuando empieza a sonar
     * (ahí se muestra el texto). La promesa se cumple al terminar o al interrumpir.
     */
    api.decir = function (datos, alEmpezar) {
      api.detener();
      return new Promise(function (resolver) {
        var empezo = false, termino = false;
        function empezar() {
          if (empezo || termino) { return; }
          empezo = true;
          api.hablando = true;
          Animacion.hablar(true);
          estadoAvatar('hablando');
          if (alEmpezar) { alEmpezar(); }
        }
        fin = function () {
          if (termino) { return; }
          termino = true;
          fin = null;
          pendiente = null;
          api.hablando = false;
          Animacion.hablar(false);
          if (audio) { audio.onplaying = audio.onended = audio.onerror = null; }
          soltarUrl();
          if (!empezo && alEmpezar) { alEmpezar(); }   // el texto se muestra aunque no haya sonado
          doc.body.classList.remove('pide-audio');
          resolver();
        };
        var cierre = fin;
        var respaldo = function () { if (!termino && !empezo) { hablarNavegador(datos.texto, empezar, cierre); } };

        var blob = null;
        if (datos.audio && audio) { try { blob = blobDesdeBase64(datos.audio, datos.tipo || 'audio/mpeg'); } catch (e) { blob = null; } }
        if (!blob) { hablarNavegador(datos.texto, empezar, cierre); return; }

        soltarUrl();
        urlActual = URL.createObjectURL(blob);
        audio.src = urlActual;
        audio.onplaying = function () { empezar(); analizar(); };
        audio.onended = cierre;
        audio.onerror = respaldo;   // formato no soportado: lo lee el navegador
        var intento = audio.play();
        if (intento && intento.catch) {
          intento.catch(function (error) {
            if (termino) { return; }
            if (error && error.name === 'NotAllowedError') {
              // El navegador exige un toque: se muestra el texto y el botón «Activar el sonido».
              pendiente = function () { var p = audio.play(); if (p && p.catch) { p.catch(respaldo); } };
              if (alEmpezar) { alEmpezar(); alEmpezar = null; }
              doc.body.classList.add('pide-audio');
              velo('velo-audio');
            } else {
              respaldo();
            }
          });
        }
      });
    };

    /** Corta la voz en curso (botón «Interrumpir», nueva pregunta o fin de la conversación). */
    api.detener = function () {
      if (audio) { try { audio.pause(); } catch (e) { /* nada */ } }
      if (window.speechSynthesis) { try { window.speechSynthesis.cancel(); } catch (e) { /* nada */ } }
      if (fin) { fin(); }
    };

    /** «Activar el sonido»: reproduce el audio que el navegador había bloqueado. */
    api.activar = function () {
      doc.body.classList.remove('pide-audio');
      if (pendiente) { var p = pendiente; pendiente = null; p(); }
    };

    return api;
  })();

  /* ------------------------------------------------------------------ */
  /*  Motor económico: escucha (voz a texto)                              */
  /* ------------------------------------------------------------------ */

  var Escucha = (function () {
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    var puedeGrabar = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder && window.FormData);
    var escucha = CONFIG.escucha || {};
    // «navegador»: reconocimiento del propio navegador (gratis). «servidor»: se graba y se transcribe
    // con ElevenLabs Scribe. Sin reconocimiento propio (Firefox), se usa el servidor si está configurado.
    var modo = escucha.proveedor === 'servidor' && puedeGrabar ? 'servidor' : (SR ? 'navegador' : (escucha.respaldo && puedeGrabar ? 'servidor' : ''));
    var api = { activa: false, alTexto: null, alSilencio: null, alBloqueo: null };
    var reconocedor = null;
    var flujoMic = null, analizadorMic = null, datosMic = null, grabadora = null, trozos = [], vigilancia = null, descartar = false;
    var UMBRAL = 0.045;   // volumen (RMS) a partir del cual se considera que la persona habla

    api.disponible = function () { return modo !== ''; };

    function entregar(texto) { if (api.alTexto) { api.alTexto(texto); } }
    function silencio() { if (api.alSilencio) { api.alSilencio(); } }
    function bloqueo() { if (api.alBloqueo) { api.alBloqueo(); } }

    /* --- Reconocimiento del navegador --- */
    function escucharNavegador(ptt) {
      var r = new SR();
      var final = '';
      r.lang = escucha.idioma || 'es-CO';
      r.interimResults = true;
      r.continuous = !!ptt;
      r.maxAlternatives = 1;
      r.onresult = function (e) {
        var parcial = '';
        for (var i = e.resultIndex; i < e.results.length; i++) {
          if (e.results[i].isFinal) { final += e.results[i][0].transcript + ' '; }
          else { parcial += e.results[i][0].transcript; }
        }
        Transcripcion.parcial((final + parcial).trim());
      };
      r.onerror = function (e) {
        if (e.error === 'not-allowed' || e.error === 'service-not-allowed') { r.bloqueado = true; }
        // Sin conexión con el servicio de voz del navegador: se pasa a ElevenLabs si está configurado.
        else if (e.error === 'network' && escucha.respaldo && puedeGrabar) { modo = 'servidor'; }
      };
      r.onend = function () {
        if (reconocedor === r) { reconocedor = null; }
        api.activa = false;
        Transcripcion.parcial('');
        if (r.descartado) { return; }
        if (r.bloqueado) { bloqueo(); return; }
        var texto = final.trim();
        if (texto) { entregar(texto); } else { silencio(); }
      };
      reconocedor = r;
      api.activa = true;
      try { r.start(); } catch (e) { reconocedor = null; api.activa = false; setTimeout(silencio, 500); }
    }

    /* --- Grabación para ElevenLabs Scribe, con detección de voz --- */
    function prepararMic() {
      if (flujoMic) { return Promise.resolve(); }
      return navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true } }).then(function (flujo) {
        flujoMic = flujo;
        if (contextoAudio) {
          try {
            analizadorMic = contextoAudio.createAnalyser();
            analizadorMic.fftSize = 1024;
            contextoAudio.createMediaStreamSource(flujo).connect(analizadorMic);
            datosMic = new Uint8Array(analizadorMic.fftSize);
          } catch (e) { analizadorMic = null; }
        }
      });
    }

    function nivelMic() {
      if (!analizadorMic) { return 1; }   // sin análisis: se graba todo el turno
      analizadorMic.getByteTimeDomainData(datosMic);
      var suma = 0;
      for (var i = 0; i < datosMic.length; i++) { var v = (datosMic[i] - 128) / 128; suma += v * v; }
      return Math.sqrt(suma / datosMic.length);
    }

    function tipoGrabacion() {
      var tipos = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4', 'audio/webm'];
      for (var i = 0; i < tipos.length; i++) { if (window.MediaRecorder.isTypeSupported && window.MediaRecorder.isTypeSupported(tipos[i])) { return tipos[i]; } }
      return '';
    }

    function empezarGrabacion() {
      trozos = [];
      descartar = false;
      var tipo = tipoGrabacion();
      grabadora = tipo ? new window.MediaRecorder(flujoMic, { mimeType: tipo, audioBitsPerSecond: 32000 }) : new window.MediaRecorder(flujoMic);
      grabadora.ondataavailable = function (e) { if (e.data && e.data.size) { trozos.push(e.data); } };
      grabadora.onstop = enviarGrabacion;
      grabadora.start();
    }

    function detenerGrabacion(sinEnviar) {
      clearInterval(vigilancia);
      vigilancia = null;
      descartar = !!sinEnviar;
      if (grabadora && grabadora.state !== 'inactive') { try { grabadora.stop(); } catch (e) { /* nada */ } }
      else { api.activa = false; }
    }

    function enviarGrabacion() {
      api.activa = false;
      var tipo = grabadora && grabadora.mimeType ? grabadora.mimeType.split(';')[0] : 'audio/webm';
      grabadora = null;
      if (descartar) { return; }
      var blob = new Blob(trozos, { type: tipo });
      trozos = [];
      if (blob.size < 2000) { silencio(); return; }
      var conversacion = Guardado.conversacion();
      if (!conversacion) { return; }
      var datos = new FormData();
      datos.append('token', CONFIG.token);
      datos.append('codigo', conversacion.codigo);
      datos.append('clave', conversacion.clave);
      datos.append('audio', blob, 'pregunta');
      estadoAvatar('pensando');
      fetch(CONFIG.rutas.transcribir, { method: 'POST', credentials: 'same-origin', body: datos })
        .then(function (r) { return r.json(); })
        .then(function (r) {
          if (r && r.ok && r.texto) { entregar(r.texto); }
          else { if (r && r.mensaje) { avisar(r.mensaje, 5); } silencio(); }
        })
        .catch(function () { silencio(); });
    }

    function escucharServidor(ptt) {
      api.activa = true;
      prepararMic().then(function () {
        if (!api.activa) { return; }
        if (ptt) { empezarGrabacion(); return; }
        var hablando = false, inicio = ahora(), inicioVoz = 0, ultimoSonido = 0;
        vigilancia = setInterval(function () {
          var t = ahora();
          if (nivelMic() > UMBRAL) {
            ultimoSonido = t;
            if (!hablando) { hablando = true; inicioVoz = t; empezarGrabacion(); }
          }
          // Fin de la pregunta: 1,2 s de silencio o 20 s hablando. Sin voz en 20 s, se reinicia el ciclo.
          if (hablando && (t - ultimoSonido > 1200 || t - inicioVoz > 20000)) { detenerGrabacion(false); }
          else if (!hablando && t - inicio > 20000) { clearInterval(vigilancia); vigilancia = null; api.activa = false; silencio(); }
        }, 100);
      }).catch(function () { api.activa = false; bloqueo(); });
    }

    /** Empieza a escuchar un turno. ptt: «mantén presionado para hablar». */
    api.escuchar = function (ptt) {
      if (api.activa || !modo) { return; }
      if (modo === 'navegador') { escucharNavegador(ptt); } else { escucharServidor(ptt); }
    };

    /** Fin del «mantén presionado»: se procesa lo dicho. */
    api.soltar = function () {
      if (reconocedor) { try { reconocedor.stop(); } catch (e) { /* nada */ } }
      else if (grabadora) { detenerGrabacion(false); }
      else { api.activa = false; }   // se soltó antes de tener el micrófono: no se graba nada
    };

    /** Deja de escuchar y descarta lo que se estaba oyendo (la persona escribió o el avatar habla). */
    api.detener = function () {
      if (reconocedor) { reconocedor.descartado = true; try { reconocedor.abort(); } catch (e) { /* nada */ } reconocedor = null; }
      if (vigilancia || grabadora) { detenerGrabacion(true); }
      api.activa = false;
      Transcripcion.parcial('');
    };

    /** Al terminar la conversación también se libera el micrófono. */
    api.apagar = function () {
      api.detener();
      if (flujoMic) { flujoMic.getTracks().forEach(function (t) { t.stop(); }); flujoMic = null; analizadorMic = null; }
    };

    return api;
  })();

  /* ------------------------------------------------------------------ */
  /*  Motor económico: conversación                                       */
  /* ------------------------------------------------------------------ */

  var eco = { turno: 0, esperando: false };

  function marcarMicrofono(activo) {
    microfonoActivo = activo;
    var boton = $('microfono');
    if (boton && !CONFIG.avatar.pulsarHablar) { boton.setAttribute('aria-pressed', activo ? 'true' : 'false'); }
  }

  /** Vuelve a escuchar cuando el avatar calla (modo conversación con el micrófono encendido). */
  function escucharSiToca() {
    if (estado !== 'activa' || eco.esperando || Voz.hablando) { return; }
    if (!microfonoActivo || CONFIG.avatar.pulsarHablar || !Escucha.disponible()) { estadoAvatar(''); return; }
    estadoAvatar('escuchando');
    Escucha.escuchar(false);
  }

  Escucha.alTexto = function (texto) { preguntarEconomico(texto, 'voz'); };
  Escucha.alSilencio = function () { setTimeout(escucharSiToca, 300); };
  Escucha.alBloqueo = function () {
    marcarMicrofono(false);
    estadoAvatar('');
    avisar(CONFIG.textos.microfono || 'Pulsa el micrófono para hablar o escribe tu pregunta.', 8);
  };

  /** Muestra y dice una respuesta { texto, audio, tipo }. */
  function decirRespuesta(datos) {
    return Voz.decir(datos, function () { Transcripcion.avatarFinal(datos.texto); });
  }

  function conectarEconomico(datosPersona) {
    estado = 'conectando';
    velo('velo-cargando');
    claseEstado('conectando');
    Transcripcion.vaciar();
    return enviar(CONFIG.rutas.sesion, datosPersona || {}).then(function (r) {
      if (!r.ok) {
        estado = 'inicio';
        velo('velo-inicio');
        claseEstado('inicio');
        return r;
      }
      Guardado.iniciar({ codigo: r.codigo, clave: r.clave, duracion: r.duracion });
      estado = 'activa';
      eco.esperando = false;
      doc.body.classList.add('en-vivo');
      habilitarEntrada(true);
      $('controles').hidden = !Escucha.disponible();   // sin forma de escuchar, solo se escribe
      estadoAvatar('');
      iniciarReloj();
      Animacion.reanudar();
      marcarMicrofono(!CONFIG.avatar.pulsarHablar && !!CONFIG.avatar.microfono && Escucha.disponible());
      mantener = setInterval(function () {
        var c = Guardado.conversacion();
        if (c && estado === 'activa') { enviar(CONFIG.rutas.mantener, { codigo: c.codigo, clave: c.clave }).catch(function () {}); }
      }, 60000);
      var turno = ++eco.turno;
      var saludo = r.saludo && r.saludo.texto ? decirRespuesta(r.saludo) : Promise.resolve();
      saludo.then(function () { if (turno === eco.turno) { escucharSiToca(); } });
      return { ok: true };
    }).catch(function () {
      terminar('error');
      avisar('No fue posible conectar con el anfitrión. Revisa tu conexión e inténtalo de nuevo.');
      return { ok: false };
    });
  }

  /** Pregunta hablada o escrita → api/responder.php → texto y voz del avatar. */
  function preguntarEconomico(texto, origen) {
    texto = String(texto || '').trim();
    if (!texto || estado !== 'activa') { return; }
    if (eco.esperando) { avisar('Un momento: el anfitrión está preparando la respuesta.', 3); return; }
    var conversacion = Guardado.conversacion();
    if (!conversacion) { return; }
    var turno = ++eco.turno;
    Voz.detener();       // una pregunta nueva interrumpe la respuesta anterior
    Escucha.detener();
    Transcripcion.cerrarEnCurso();
    if (origen === 'texto') { ultimoTexto = { texto: texto, hora: ahora() }; }
    Transcripcion.persona(texto);
    Transcripcion.avatarPensando();
    eco.esperando = true;
    estadoAvatar('pensando');
    enviar(CONFIG.rutas.responder, { codigo: conversacion.codigo, clave: conversacion.clave, pregunta: texto, origen: origen })
      .then(function (r) {
        if (turno !== eco.turno || estado !== 'activa') { return; }
        eco.esperando = false;
        if (!r.ok) {
          Transcripcion.cerrarEnCurso();
          avisar(r.mensaje || 'El anfitrión no pudo responder. Inténtalo de nuevo.', 6);
          if (r._estado === 409 || r.fin) { terminar('servidor'); }
          return;
        }
        return decirRespuesta(r);
      })
      .catch(function () {
        if (turno !== eco.turno) { return; }
        Transcripcion.cerrarEnCurso();
        avisar('No hay conexión con el servidor. Inténtalo de nuevo.', 6);
      })
      .then(function () {
        if (turno !== eco.turno) { return; }
        eco.esperando = false;
        if (estado === 'activa') { estadoAvatar(''); escucharSiToca(); }
      });
  }

  /* ------------------------------------------------------------------ */
  /*  Formulario de inicio                                                */
  /* ------------------------------------------------------------------ */

  var dialogo = $('dialogo-datos');
  var formulario = $('formulario');
  var esperaLimpieza = null;

  /** Borra del formulario los datos de la persona anterior (kiosco compartido). */
  function olvidarVisitante() {
    if (formulario) { formulario.reset(); }
    limpiarErrores();
  }

  function limpiarErrores() {
    $$('.error-campo').forEach(function (e) { e.textContent = ''; });
    $$('#formulario [aria-invalid]').forEach(function (e) { e.removeAttribute('aria-invalid'); });
    var aviso = $('aviso-formulario'); if (aviso) { aviso.hidden = true; }
  }

  function mostrarErrores(errores) {
    var primero = null;
    Object.keys(errores || {}).forEach(function (campo) {
      var caja = $('error-' + campo), entrada = $(campo);
      if (caja) { caja.textContent = errores[campo]; }
      if (entrada) {
        entrada.setAttribute('aria-invalid', 'true');
        entrada.setAttribute('aria-describedby', 'error-' + campo);
        if (!primero) { primero = entrada; }
      }
    });
    if (primero) { primero.focus(); }
  }

  function datosFormulario() {
    var datos = {};
    ['nombre', 'correo', 'telefono', 'ciudad', 'sitio_web'].forEach(function (campo) {
      var el = $(campo); if (el) { datos[campo] = el.value.trim(); }
    });
    var aut = $('autorizacion');
    datos.autorizacion = aut ? aut.checked : false;
    return datos;
  }

  function validarLocal(datos) {
    var errores = {};
    if (!datos.nombre || datos.nombre.length < 3) { errores.nombre = 'Escribe tu nombre.'; }
    var correo = $('correo');
    if (correo) {
      if (datos.correo && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(datos.correo)) { errores.correo = 'El correo no es válido.'; }
      else if (!datos.correo && correo.required) { errores.correo = 'Escribe tu correo.'; }
    }
    var tel = $('telefono');
    if (tel && !datos.telefono && tel.required) { errores.telefono = 'Escribe tu teléfono.'; }
    var ciudad = $('ciudad');
    if (ciudad && !datos.ciudad && ciudad.required) { errores.ciudad = 'Elige tu municipio.'; }
    if (CONFIG.exigirAceptacion && !datos.autorizacion) { errores.autorizacion = 'Debes autorizar el tratamiento de datos para continuar.'; }
    return errores;
  }

  if (formulario) {
    formulario.addEventListener('submit', function (e) {
      e.preventDefault();
      limpiarErrores();
      var datos = datosFormulario();
      var errores = validarLocal(datos);
      if (Object.keys(errores).length) { mostrarErrores(errores); return; }
      var boton = $('confirmar-datos');
      boton.disabled = true;
      desbloquearAudio();
      dialogo.close();
      conectar(datos).then(function (r) {
        boton.disabled = false;
        if (r && !r.ok) {
          dialogo.showModal();
          if (r.errores) { mostrarErrores(r.errores); }
          var aviso = $('aviso-formulario');
          if (aviso) { aviso.textContent = r.mensaje || 'No fue posible iniciar la conversación.'; aviso.hidden = false; }
        }
      });
    });
    $('cancelar-datos').addEventListener('click', function () { dialogo.close(); });
  }

  /* ------------------------------------------------------------------ */
  /*  Controles                                                           */
  /* ------------------------------------------------------------------ */

  /** Un instante de silencio en WAV (para habilitar el reproductor dentro del gesto de la persona). */
  function silencioWav() {
    var muestras = 800, datos = new DataView(new ArrayBuffer(44 + muestras * 2));
    var texto = function (pos, t) { for (var i = 0; i < t.length; i++) { datos.setUint8(pos + i, t.charCodeAt(i)); } };
    texto(0, 'RIFF'); datos.setUint32(4, 36 + muestras * 2, true); texto(8, 'WAVEfmt ');
    datos.setUint32(16, 16, true); datos.setUint16(20, 1, true); datos.setUint16(22, 1, true);
    datos.setUint32(24, 8000, true); datos.setUint32(28, 16000, true); datos.setUint16(32, 2, true); datos.setUint16(34, 16, true);
    texto(36, 'data'); datos.setUint32(40, muestras * 2, true);
    return new Blob([datos], { type: 'audio/wav' });
  }

  /**
   * Crea el contexto de audio dentro del gesto de la persona (requisito de Safari y Chrome). En el motor
   * económico además reproduce un silencio y una lectura vacía: Safari solo deja sonar después, sin
   * nuevo toque, un reproductor y una voz que ya se usaron dentro de un gesto.
   */
  function desbloquearAudio() {
    try {
      if (!contextoAudio && (window.AudioContext || window.webkitAudioContext)) {
        contextoAudio = new (window.AudioContext || window.webkitAudioContext)();
      }
      if (contextoAudio && contextoAudio.state === 'suspended') { contextoAudio.resume(); }
      if (audio && MOTOR === 'economico' && !Voz.hablando) {
        audio.src = URL.createObjectURL(silencioWav());
        if (window.speechSynthesis && window.SpeechSynthesisUtterance) { window.speechSynthesis.speak(new SpeechSynthesisUtterance('')); }
      }
      if (audio) { var p = audio.play(); if (p && p.catch) { p.catch(function () {}); } }
    } catch (e) { /* sin audio web */ }
  }

  function empezar() {
    if (estado !== 'inicio' && estado !== 'final') { return; }
    estado = 'inicio';
    clearTimeout(esperaLimpieza);
    olvidarVisitante();
    if (CONFIG.formulario && dialogo && dialogo.showModal) {
      limpiarErrores();
      dialogo.showModal();
      var nombre = $('nombre'); if (nombre) { nombre.focus(); }
      return;
    }
    desbloquearAudio();
    conectar({}).then(function (r) {
      if (r && !r.ok) { avisar(r.mensaje || 'No fue posible iniciar la conversación.'); }
    });
  }

  var iniciar = $('iniciar');
  if (iniciar) { iniciar.addEventListener('click', empezar); }
  var otra = $('otra');
  if (otra) { otra.addEventListener('click', function () { estado = 'final'; empezar(); }); }

  var activarAudio = $('activar-audio');
  if (activarAudio) {
    activarAudio.addEventListener('click', function () {
      if (MOTOR === 'economico') {
        // Dentro del toque: reproduce la respuesta que el navegador había bloqueado.
        if (contextoAudio && contextoAudio.state === 'suspended') { contextoAudio.resume(); }
        Voz.activar();
        return;
      }
      desbloquearAudio();
      if (sala) { sala.startAudio().then(function () { doc.body.classList.remove('pide-audio'); }); }
    });
  }

  var botonMicrofono = $('microfono');
  if (botonMicrofono) {
    if (CONFIG.avatar.pulsarHablar) {
      // Modo «mantén presionado para hablar».
      botonMicrofono.setAttribute('aria-pressed', 'false');
      botonMicrofono.dataset.ptt = '1';
      var etiquetaPtt = $('microfono-texto');
      etiquetaPtt.textContent = 'Mantén presionado para hablar';
      etiquetaPtt.classList.remove('solo-lectores');
      // LiveAvatar recibe comandos por la sala; el motor económico escucha en el navegador.
      var empezarPtt = function () {
        if (MOTOR === 'economico') {
          if (eco.esperando || !Escucha.disponible()) { return false; }
          Voz.detener();
          Escucha.escuchar(true);
        } else {
          if (doc.body.classList.contains('estado-hablando')) { comando('avatar.interrupt'); }
          comando('user.start_push_to_talk');
        }
        botonMicrofono.classList.add('pulsado');
        botonMicrofono.setAttribute('aria-pressed', 'true');
        estadoAvatar('escuchando');
        return true;
      };
      var soltar = function () {
        if (!botonMicrofono.classList.contains('pulsado')) { return; }
        botonMicrofono.classList.remove('pulsado');
        botonMicrofono.setAttribute('aria-pressed', 'false');
        if (MOTOR === 'economico') { Escucha.soltar(); } else { comando('user.stop_push_to_talk'); }
        estadoAvatar('');
      };
      botonMicrofono.addEventListener('pointerdown', function (e) {
        if (estado !== 'activa') { return; }
        e.preventDefault();
        empezarPtt();
      });
      ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (ev) { botonMicrofono.addEventListener(ev, soltar); });
      botonMicrofono.addEventListener('keydown', function (e) {
        if ((e.key === ' ' || e.key === 'Enter') && !e.repeat && estado === 'activa' && !botonMicrofono.classList.contains('pulsado')) {
          e.preventDefault();
          empezarPtt();
        }
      });
      botonMicrofono.addEventListener('keyup', function (e) { if (e.key === ' ' || e.key === 'Enter') { soltar(); } });
    } else {
      botonMicrofono.addEventListener('click', function () {
        if (estado !== 'activa') { return; }
        if (MOTOR === 'liveavatar') { activarMicrofono(!microfonoActivo); return; }
        // Motor económico: el toque también sirve de gesto para que Safari permita escuchar.
        if (!Escucha.disponible()) { avisar('Este navegador no puede escuchar. Escribe tu pregunta.', 6); return; }
        marcarMicrofono(!microfonoActivo);
        if (microfonoActivo) { Voz.detener(); escucharSiToca(); } else { Escucha.detener(); if (!Voz.hablando && !eco.esperando) { estadoAvatar(''); } }
      });
    }
  }

  var interrumpir = $('interrumpir');
  if (interrumpir) {
    interrumpir.addEventListener('click', function () {
      if (MOTOR === 'economico') { Voz.detener(); } else { comando('avatar.interrupt'); }
    });
  }

  var botonTerminar = $('terminar');
  if (botonTerminar) { botonTerminar.addEventListener('click', function () { terminar('usuario'); }); }

  var escribir = $('escribir');
  if (escribir) {
    escribir.addEventListener('submit', function (e) {
      e.preventDefault();
      var entrada = $('pregunta');
      preguntar(entrada.value);
      entrada.value = '';
      entrada.focus();
    });
  }

  $$('.sugerencia').forEach(function (boton) {
    boton.addEventListener('click', function () { preguntar(boton.textContent); });
  });

  /* Si la persona cierra o abandona la pestaña, se avisa al servidor. */
  window.addEventListener('pagehide', function () {
    var conversacion = Guardado.conversacion();
    if (!conversacion || (estado !== 'activa' && estado !== 'conectando')) { return; }
    var enCurso = Transcripcion.cerrarEnCurso();
    if (enCurso) { Guardado.agregar('avatar', enCurso, 'voz'); }
    var carga = JSON.stringify({ token: CONFIG.token, codigo: conversacion.codigo, clave: conversacion.clave, motivo: 'salida', mensajes: Guardado.pendientes() });
    if (navigator.sendBeacon) { navigator.sendBeacon(CONFIG.rutas.finalizar, carga); }
    try { if (sala) { sala.disconnect(); } } catch (e) { /* nada */ }
  });

  var botonAccesible = $('accesibilidad');
  if (botonAccesible) {
    var aplicarAccesible = function (activo) {
      $('app').classList.toggle('modo-accesible', activo);
      botonAccesible.setAttribute('aria-pressed', activo ? 'true' : 'false');
    };
    var guardado = false;
    try { guardado = window.localStorage.getItem('musa_accesible') === '1'; } catch (e) { /* sin almacenamiento */ }
    aplicarAccesible(guardado);
    botonAccesible.addEventListener('click', function () {
      var activo = botonAccesible.getAttribute('aria-pressed') !== 'true';
      aplicarAccesible(activo);
      try { window.localStorage.setItem('musa_accesible', activo ? '1' : '0'); } catch (e) { /* sin almacenamiento */ }
    });
  }

  estadoAvatar('');
})();
