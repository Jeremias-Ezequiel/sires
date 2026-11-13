/**
 * Latido del dashboard: con una pantalla del sistema abierta y visible, avisa al servidor
 * cada 5 minutos para que corra los procesos automáticos vencidos (ejecución por pedido).
 * No renueva la inactividad de la sesión. Se corta solo si la sesión venció (401) o el
 * token dejó de ser válido (419).
 *
 * Uso: <script src="latido.js" data-url="..." data-token="..."></script>
 */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script || !script.dataset.url || !script.dataset.token) return;

    var INTERVALO_MS = 5 * 60 * 1000;
    var url = script.dataset.url;
    var token = script.dataset.token;
    var temporizador = null;

    function latir() {
        if (document.hidden) return;
        var datos = new FormData();
        datos.append('_csrf_token', token);
        fetch(url, { method: 'POST', body: datos, credentials: 'same-origin' })
            .then(function (r) {
                if (r.status === 401 || r.status === 419) clearInterval(temporizador);
            })
            .catch(function () { /* sin red: se reintenta en el próximo latido */ });
    }

    temporizador = setInterval(latir, INTERVALO_MS);
})();
