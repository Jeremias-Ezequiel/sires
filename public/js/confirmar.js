/**
 * SIRES - Confirmación antes de enviar un formulario (sin confirm() del navegador).
 *
 * Uso: <form data-confirmar="¿Seguro?" data-confirmar-boton="Sí, hacerlo">...</form>
 * Arma un <dialog> nativo (Esc cierra) la primera vez que se necesita.
 */
(function () {
    'use strict';

    var dialogo, texto, aceptar, pendiente = null;

    function crearDialogo() {
        dialogo = document.createElement('dialog');
        dialogo.className = 'confirmar';
        dialogo.setAttribute('aria-labelledby', 'confirmar-texto');
        dialogo.innerHTML =
            '<p id="confirmar-texto" class="confirmar__texto"></p>' +
            '<div class="confirmar__acciones">' +
                '<button type="button" class="btn btn--ghost" data-cerrar>Cancelar</button>' +
                '<button type="button" class="btn btn--primary" data-aceptar>Confirmar</button>' +
            '</div>';
        document.body.appendChild(dialogo);
        texto = dialogo.querySelector('#confirmar-texto');
        aceptar = dialogo.querySelector('[data-aceptar]');
        dialogo.querySelector('[data-cerrar]').addEventListener('click', function () { dialogo.close(); });
        aceptar.addEventListener('click', function () {
            if (!pendiente) return;
            pendiente.dataset.confirmado = '1';
            aceptar.disabled = true;
            pendiente.submit();
        });
    }

    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form.matches || !form.matches('form[data-confirmar]') || form.dataset.confirmado === '1') return;
        if (!form.checkValidity()) return;
        ev.preventDefault();
        if (!dialogo) crearDialogo();
        pendiente = form;
        texto.textContent = form.dataset.confirmar;
        aceptar.textContent = form.dataset.confirmarBoton || 'Confirmar';
        aceptar.disabled = false;
        dialogo.showModal();
        aceptar.focus();
    });

    /** Abierto el diálogo, otros scripts pueden consultarlo (ej. atajos de teclado). */
    window.SiresConfirmar = { abierto: function () { return !!(dialogo && dialogo.open); } };
})();
