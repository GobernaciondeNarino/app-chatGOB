/* QuéDice! · Panel de administración */

(function () {
  'use strict';

  var doc = document;

  function $(sel, ctx) { return (ctx || doc).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || doc).querySelectorAll(sel)); }

  /* Casillas "Creado" y "Enviado": se guardan al instante. */
  var tabla = $('#tabla-registros');
  if (tabla) {
    var token = tabla.dataset.token;
    $$('.marca', tabla).forEach(function (casilla) {
      casilla.addEventListener('change', function () {
        var etiqueta = casilla.closest('.casilla');
        var texto = etiqueta ? etiqueta.querySelector('span') : null;
        etiqueta.classList.add('ocupada');
        casilla.disabled = true;

        fetch('acciones.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
          body: JSON.stringify({
            token: token,
            accion: 'marcar',
            id: casilla.dataset.id,
            campo: casilla.dataset.campo,
            valor: casilla.checked
          })
        }).then(function (r) { return r.json(); }).then(function (cuerpo) {
          if (!cuerpo.ok) {
            casilla.checked = !casilla.checked;
            alert(cuerpo.mensaje || 'No fue posible guardar el cambio.');
          } else if (texto) {
            texto.textContent = casilla.checked ? 'SÍ' : 'NO';
          }
        }).catch(function () {
          casilla.checked = !casilla.checked;
          alert('No hay conexión con el servidor.');
        }).then(function () {
          etiqueta.classList.remove('ocupada');
          casilla.disabled = false;
        });
      });
    });
  }

  /* Fila de detalle */
  $$('.ver-detalle').forEach(function (boton) {
    boton.addEventListener('click', function () {
      var fila = $('#detalle-' + CSS.escape(boton.dataset.id));
      if (!fila) { return; }
      fila.hidden = !fila.hidden;
      boton.textContent = fila.hidden ? 'Detalle' : 'Ocultar';
    });
  });

  /* Confirmaciones */
  $$('form.confirmar').forEach(function (formulario) {
    formulario.addEventListener('submit', function (evento) {
      if (!window.confirm(formulario.dataset.confirmar || '¿Confirmas esta acción?')) {
        evento.preventDefault();
      }
    });
  });

  /* Selectores de color: muestran el valor en hexadecimal */
  $$('.color input[type="color"]').forEach(function (entrada) {
    entrada.addEventListener('input', function () {
      var codigo = entrada.parentNode.querySelector('code');
      if (codigo) { codigo.textContent = entrada.value.toUpperCase(); }
    });
  });

  /* Añadir una pregunta sugerida */
  var agregarSugerencia = $('#agregar-sugerencia');
  if (agregarSugerencia) {
    agregarSugerencia.addEventListener('click', function () {
      var lista = $('#lista-sugerencias');
      if ($$('input', lista).length >= 8) { alert('Máximo 8 preguntas sugeridas.'); return; }
      var entrada = doc.createElement('input');
      entrada.type = 'text';
      entrada.name = 'tema[sugerencias][]';
      entrada.maxLength = 140;
      entrada.placeholder = 'Pregunta sugerida';
      lista.appendChild(entrada);
      entrada.focus();
    });
  }

  /* Añadir una página de referencia */
  var agregarEnlace = $('#agregar-enlace');
  if (agregarEnlace) {
    agregarEnlace.addEventListener('click', function () {
      var indice = parseInt(agregarEnlace.dataset.siguiente, 10) || 0;
      var fila = doc.createElement('div');
      fila.className = 'enlace-fila';
      fila.innerHTML =
        '<label>Dirección<input type="url" name="tema[enlaces][' + indice + '][url]" placeholder="https://"></label>' +
        '<label>¿Qué encuentra allí?<input type="text" name="tema[enlaces][' + indice + '][faq]" maxlength="1000"></label>';
      $('#lista-enlaces').appendChild(fila);
      agregarEnlace.dataset.siguiente = indice + 1;
      fila.querySelector('input').focus();
    });
  }

  /* Paletas rápidas de color (Apariencia) */
  var PALETAS = {
    // Manual de identidad visual de la Gobernación de Nariño: verde #10A13B, amarillo #FFD500, azul #003366.
    institucional: {
      fondo: '#0B7A2E', fondo_profundo: '#003366', tarjeta: '#0A5C2A', tarjeta_borde: '#10A13B',
      texto: '#FFFFFF', texto_suave: '#EAF5EC', acento: '#FFD500', texto_sobre_acento: '#1A1A1A',
      acento_secundario: '#4FC3F7', burbuja_persona: '#FFFFFF', texto_persona: '#003366',
      exito: '#10A13B', error: '#FFB3BC', institucional: '#003366'
    },
    cafe: {
      fondo: '#AE1D2C', fondo_profundo: '#7E0E1C', tarjeta: '#9F1427', tarjeta_borde: '#C3364A',
      texto: '#F7EFE0', texto_suave: '#EBC9CE', acento: '#F2B705', texto_sobre_acento: '#3A0A10',
      acento_secundario: '#12A5C4', burbuja_persona: '#F6EDD9', texto_persona: '#5A0B16',
      exito: '#2E9E6B', error: '#FFB3BC', institucional: '#003366'
    }
  };
  $$('[data-paleta]').forEach(function (boton) {
    boton.addEventListener('click', function () {
      var paleta = PALETAS[boton.dataset.paleta];
      if (!paleta) { return; }
      Object.keys(paleta).forEach(function (clave) {
        var entrada = $('input[name="colores[' + clave + ']"]');
        if (!entrada) { return; }
        entrada.value = paleta[clave];
        var codigo = entrada.parentNode.querySelector('code');
        if (codigo) { codigo.textContent = paleta[clave]; }
      });
    });
  });

  /* Tarjetas de proveedor de IA */
  $$('.opcion input[type="radio"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      $$('.opcion').forEach(function (opcion) { opcion.classList.remove('activa'); });
      if (radio.checked) { radio.closest('.opcion').classList.add('activa'); }
    });
  });
})();
