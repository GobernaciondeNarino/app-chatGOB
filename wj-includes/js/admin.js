/* Musa Café · Panel de administración */

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

  /* Tarjetas de proveedor de IA */
  $$('.opcion input[type="radio"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      $$('.opcion').forEach(function (opcion) { opcion.classList.remove('activa'); });
      if (radio.checked) { radio.closest('.opcion').classList.add('activa'); }
    });
  });
})();
