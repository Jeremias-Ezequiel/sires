/**
 * SIRES - Calendario de disponibilidad de una habitación (alta y edición de reservas).
 *
 * Muestra dos meses con las noches ocupadas por otras reservas y la estadía
 * elegida en los inputs de fecha. Un clic en un día carga la entrada y el
 * siguiente, la salida; no deja elegir un rango que pise noches ocupadas.
 *
 * Una noche "ocupada" es cada fecha d con entrada <= d < salida (el día de
 * salida queda libre para otra entrada). El servidor vuelve a validar todo.
 *
 * Cada reserva se pinta con el color de su estado, igual que en el Planning
 * (estilo: pendiente, confirmada, en-casa, sale-hoy, no-show), y debajo se listan
 * las reservas de los meses visibles con enlace a su detalle.
 *
 * Uso:
 *   var cal = SiresCalendario.crear({ contenedor, entrada, salida, ignorarId,
 *                                     estilos, urlDetalle });
 *   //   estilos: { 'en-casa': ['En casa', 'fa-user'], ... } (Planning::ESTILOS)
 *   cal.mostrar(reservasDeLaHabitacion);   // [{ id, entrada, salida, estilo, cliente }, ...]
 *   cal.ocultar();
 */
var SiresCalendario = (function () {
    'use strict';

    var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
        'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    var MESES_CORTOS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    var DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    var DIAS_CORTOS = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];
    var MESES_VISIBLES = 2;

    /** YYYY-MM-DD en hora local (toISOString usa UTC y puede correr un día). */
    function iso(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
    }

    function desdeIso(s) {
        var p = s.split('-');
        return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
    }

    function sumarDias(s, n) {
        var d = desdeIso(s);
        d.setDate(d.getDate() + n);
        return iso(d);
    }

    function fechaCorta(s) {
        var d = desdeIso(s);
        return d.getDate() + ' ' + MESES_CORTOS[d.getMonth()];
    }

    function crear(opciones) {
        var cont = opciones.contenedor;
        var inEntrada = opciones.entrada;
        var inSalida = opciones.salida;
        var ignorarId = opciones.ignorarId || 0;
        var estilos = opciones.estilos || {};
        var urlDetalle = opciones.urlDetalle || '';

        var reservas = [];
        var pendiente = false;      // true = ya se eligió la entrada, falta la salida
        var aviso = '';             // mensaje del último clic inválido
        var vista = null;           // primer día del primer mes visible
        var ultimaEntrada = null;   // para recentrar cuando la entrada cambia desde el input
        var foco = null;            // fecha con tabindex=0 (navegación con flechas)

        /** Reserva (de otra) que ocupa la noche de esa fecha, o null. */
        function reservaEn(fecha) {
            for (var i = 0; i < reservas.length; i++) {
                var r = reservas[i];
                if (r.id === ignorarId) continue;
                if (fecha >= r.entrada && fecha < r.salida) return r;
            }
            return null;
        }

        function ocupada(fecha) {
            return reservaEn(fecha) !== null;
        }

        function nombreEstilo(r) {
            return (estilos[r.estilo] || ['Reservada'])[0];
        }

        function textoReserva(r) {
            return 'Reserva #' + r.id + (r.cliente ? ' · ' + r.cliente : '') + ' · ' + nombreEstilo(r) +
                ' (' + fechaCorta(r.entrada) + ' → ' + fechaCorta(r.salida) + ')';
        }

        /**
         * Mientras se elige la salida: primera noche ocupada después de la entrada (null si
         * no hay en el próximo año). Ese día todavía sirve como salida; los siguientes no.
         */
        var cacheTope = { entrada: null, tope: null };
        function topeSalida(entrada) {
            if (cacheTope.entrada !== entrada) {
                cacheTope = { entrada: entrada, tope: primeraOcupada(sumarDias(entrada, 1), sumarDias(entrada, 366)) };
            }
            return cacheTope.tope;
        }

        /** Primera noche ocupada en [desde, hasta), o null si el rango está libre. */
        function primeraOcupada(desde, hasta) {
            for (var f = desde; f < hasta; f = sumarDias(f, 1)) {
                if (ocupada(f)) return f;
            }
            return null;
        }

        function minimo() {
            return inEntrada.min || iso(new Date());
        }

        /**
         * Elegible o no. Una noche ocupada no se puede elegir como entrada. Al elegir la salida,
         * solo se puede hasta la primera noche ocupada inclusive (ese día otra reserva entra y
         * la nuestra sale); más allá, todo deshabilitado.
         */
        function deshabilitado(fecha) {
            if (fecha < minimo()) return true;
            var e = inEntrada.value;
            if (pendiente && e && fecha > e) {
                var tope = topeSalida(e);
                return tope !== null && fecha > tope;
            }
            return ocupada(fecha);
        }

        function asignar(entradaVal, salidaVal) {
            inEntrada.value = entradaVal;
            inSalida.value = salidaVal;
            inSalida.min = entradaVal;
            // Los handlers de la vista recalculan precio y disponibilidad (y vuelven a llamar a mostrar()).
            inEntrada.dispatchEvent(new Event('change', { bubbles: true }));
            inSalida.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function clicDia(fecha) {
            aviso = '';
            var e = inEntrada.value;
            if (pendiente && e && fecha > e) {
                var choque = primeraOcupada(e, fecha);
                if (choque) {
                    aviso = 'El ' + fechaCorta(choque) + ' ya está ocupada. Elegí una salida anterior.';
                    render();
                    return;
                }
                pendiente = false;
                foco = fecha;
                asignar(e, fecha);
                return;
            }
            if (ocupada(fecha)) return;
            pendiente = true;
            foco = fecha;
            ultimaEntrada = fecha;
            asignar(fecha, sumarDias(fecha, 1));
        }

        function estadoResumen(e, s) {
            if (pendiente) {
                return { clase: 'info', icono: 'fa-hand-pointer', texto: 'Elegí la fecha de salida' };
            }
            if (!e || !s || s <= e) {
                return { clase: 'info', icono: 'fa-calendar-day', texto: 'Elegí la fecha de entrada' };
            }
            if (primeraOcupada(e, s)) {
                return { clase: 'danger', icono: 'fa-triangle-exclamation', texto: 'Choca con otra reserva' };
            }
            return { clase: 'success', icono: 'fa-circle-check', texto: 'Disponible' };
        }

        function htmlMes(primero, e, s, hoy, min) {
            var anio = primero.getFullYear();
            var mes = primero.getMonth();
            var html = '<div class="cal-mes">' +
                '<div class="cal-mes__titulo">' + MESES[mes] + ' ' + anio + '</div>' +
                '<div class="cal-grilla" role="grid">';
            for (var i = 0; i < 7; i++) {
                html += '<div class="cal-semana-dia" aria-hidden="true">' + DIAS_CORTOS[i] + '</div>';
            }
            var blancos = (primero.getDay() + 6) % 7; // semana empieza el lunes
            for (var b = 0; b < blancos; b++) html += '<div></div>';

            var dias = new Date(anio, mes + 1, 0).getDate();
            for (var n = 1; n <= dias; n++) {
                var f = iso(new Date(anio, mes, n));
                var res = reservaEn(f);
                var esOcupada = res !== null;
                var pasada = f < min;
                var enRango = e && s && f >= e && f <= s;
                var clases = ['cal-dia'];
                var estado = 'libre';

                if (pasada) { clases.push('cal-dia--pasado'); estado = 'no disponible'; }
                if (esOcupada) {
                    clases.push('cal-dia--ocupado', 'cal-dia--res-' + res.estilo);
                    if (f === res.entrada) clases.push('cal-dia--res-ini');
                    if (sumarDias(f, 1) === res.salida) clases.push('cal-dia--res-fin');
                    estado = 'ocupada: ' + textoReserva(res);
                }
                if (enRango) {
                    clases.push('cal-dia--rango');
                    if (f === e) clases.push('cal-dia--inicio');
                    if (f === s && !pendiente) clases.push('cal-dia--fin');
                    if (esOcupada && f < s) clases.push('cal-dia--choque');
                    estado = f === e ? 'entrada' : (f === s ? 'salida' : 'tu estadía');
                    if (esOcupada && f < s) estado = 'ocupada, choca con tu estadía';
                }
                if (f === hoy) clases.push('cal-dia--hoy');

                var d = new Date(anio, mes, n);
                var etiqueta = DIAS[d.getDay()] + ' ' + n + ' de ' + MESES[mes] + (f === hoy ? ' (hoy)' : '') + ': ' + estado;

                html += '<button type="button" class="' + clases.join(' ') + '" data-fecha="' + f + '"' +
                    ' tabindex="' + (f === foco ? '0' : '-1') + '"' +
                    ' aria-label="' + escHtml(etiqueta) + '" title="' + escHtml(etiqueta) + '"' +
                    (deshabilitado(f) ? ' disabled' : '') + '>' + n + '</button>';
            }
            return html + '</div></div>';
        }

        /** Reservas (de otras) que tienen alguna noche en los meses visibles. */
        function reservasVisibles() {
            var desde = iso(vista);
            var hasta = iso(new Date(vista.getFullYear(), vista.getMonth() + MESES_VISIBLES, 1));
            return reservas.filter(function (r) {
                return r.id !== ignorarId && r.entrada < hasta && r.salida > desde;
            });
        }

        function htmlLeyenda(visibles) {
            var html = '<span class="cal-leyenda__item"><span class="cal-muestra cal-muestra--libre"></span>Libre</span>';
            var vistos = {};
            visibles.forEach(function (r) {
                if (vistos[r.estilo]) return;
                vistos[r.estilo] = true;
                html += '<span class="cal-leyenda__item"><span class="cal-muestra cal-dia--res-' + escHtml(r.estilo) + '"></span>' +
                    escHtml(nombreEstilo(r)) + '</span>';
            });
            return html +
                '<span class="cal-leyenda__item"><span class="cal-muestra cal-muestra--rango"></span>Tu estadía</span>' +
                '<span class="cal-leyenda__item"><span class="cal-muestra cal-muestra--hoy"></span>Hoy</span>';
        }

        function htmlReservas(visibles) {
            if (!visibles.length) return '';
            var html = '<div class="cal-reservas"><div class="cal-reservas__titulo">Reservas de la habitación en estos meses</div><ul class="cal-reservas__lista">';
            visibles.forEach(function (r) {
                var icono = (estilos[r.estilo] || [null, 'fa-bed'])[1];
                var href = urlDetalle ? urlDetalle + (urlDetalle.indexOf('?') === -1 ? '?' : '&') + 'id=' + encodeURIComponent(r.id) : '';
                var cuerpo = '<i class="fas ' + escHtml(icono) + '" aria-hidden="true"></i>' +
                    '<span class="cal-reservas__id">#' + escHtml(String(r.id)) + '</span>' +
                    (r.cliente ? '<span>' + escHtml(r.cliente) + '</span>' : '') +
                    '<span class="cal-reservas__fechas">' + escHtml(fechaCorta(r.entrada) + ' → ' + fechaCorta(r.salida)) + '</span>' +
                    '<span class="cal-reservas__estado">' + escHtml(nombreEstilo(r)) + '</span>';
                html += '<li>' + (href
                    ? '<a class="cal-reservas__item plan-estado--' + escHtml(r.estilo) + '" href="' + escHtml(href) + '" target="_blank" rel="noopener" title="Abrir el detalle en otra pestaña">' + cuerpo + '</a>'
                    : '<span class="cal-reservas__item plan-estado--' + escHtml(r.estilo) + '">' + cuerpo + '</span>') + '</li>';
            });
            return html + '</ul></div>';
        }

        function render() {
            var e = inEntrada.value || '';
            var s = inSalida.value || '';
            var hoy = iso(new Date());
            var min = minimo();

            if (e !== ultimaEntrada) {
                ultimaEntrada = e;
                if (!pendiente) aviso = '';
                var base = e ? desdeIso(e) : new Date();
                vista = new Date(base.getFullYear(), base.getMonth(), 1);
            }
            if (!foco || foco < min) foco = e && e >= min ? e : (hoy >= min ? hoy : min);

            var minMes = desdeIso(min);
            minMes = new Date(minMes.getFullYear(), minMes.getMonth(), 1);
            var puedeAtras = vista > minMes;

            var noches = (e && s && s > e && !pendiente)
                ? Math.round((desdeIso(s) - desdeIso(e)) / 86400000) : 0;
            var rangoTxt = e
                ? fechaCorta(e) + (s && !pendiente ? ' → ' + fechaCorta(s) : ' → …')
                : 'Sin fechas';
            var est = estadoResumen(e, s);
            var visibles = reservasVisibles();

            var html =
                '<div class="cal-cabecera">' +
                    '<div class="cal-resumen">' +
                        '<span class="cal-resumen__rango">' + escHtml(rangoTxt) + '</span>' +
                        (noches ? '<span class="cal-resumen__noches">' + noches + (noches === 1 ? ' noche' : ' noches') + '</span>' : '') +
                        '<span class="cal-estado cal-estado--' + est.clase + '"><i class="fas ' + est.icono + '" aria-hidden="true"></i>' + escHtml(est.texto) + '</span>' +
                    '</div>' +
                    '<div class="cal-nav">' +
                        '<button type="button" class="cal-nav__btn" data-nav="-1" aria-label="Mes anterior"' + (puedeAtras ? '' : ' disabled') + '><i class="fas fa-chevron-left" aria-hidden="true"></i></button>' +
                        '<button type="button" class="cal-nav__btn" data-nav="1" aria-label="Mes siguiente"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>' +
                    '</div>' +
                '</div>' +
                '<div class="cal-meses">';
            for (var m = 0; m < MESES_VISIBLES; m++) {
                html += htmlMes(new Date(vista.getFullYear(), vista.getMonth() + m, 1), e, s, hoy, min);
            }
            html += '</div>' +
                '<div class="cal-pie">' +
                    '<div class="cal-leyenda" aria-hidden="true">' + htmlLeyenda(visibles) + '</div>' +
                    '<span class="cal-ayuda' + (aviso ? ' cal-ayuda--error' : '') + '" role="status">' +
                        (aviso ? '<i class="fas fa-circle-exclamation" aria-hidden="true"></i>' + escHtml(aviso)
                               : (pendiente ? 'Clic en el día de salida · Esc para cancelar' : 'Clic en un día para elegir la entrada')) +
                    '</span>' +
                '</div>' + htmlReservas(visibles);

            var teniaFoco = cont.contains(document.activeElement);
            cont.innerHTML = html;
            cont.style.display = 'block';
            // Siempre un día alcanzable con Tab (si el del foco está deshabilitado, el primero libre)
            if (!cont.querySelector('.cal-dia[tabindex="0"]:not([disabled])')) {
                var primero = cont.querySelector('.cal-dia:not([disabled])');
                if (primero) primero.tabIndex = 0;
            }
            if (teniaFoco) {
                var b = cont.querySelector('.cal-dia[tabindex="0"]');
                if (b && !b.disabled) b.focus();
            }
        }

        cont.addEventListener('click', function (ev) {
            var nav = ev.target.closest('[data-nav]');
            if (nav) {
                vista = new Date(vista.getFullYear(), vista.getMonth() + Number(nav.dataset.nav), 1);
                render();
                return;
            }
            var dia = ev.target.closest('.cal-dia');
            if (dia && !dia.disabled) clicDia(dia.dataset.fecha);
        });

        // Vista previa del rango mientras se elige la salida
        cont.addEventListener('mouseover', function (ev) {
            if (!pendiente) return;
            var dia = ev.target.closest('.cal-dia');
            var e = inEntrada.value;
            cont.querySelectorAll('.cal-dia--previa').forEach(function (b) { b.classList.remove('cal-dia--previa'); });
            if (!dia || !e || dia.dataset.fecha <= e) return;
            cont.querySelectorAll('.cal-dia').forEach(function (b) {
                if (b.dataset.fecha > e && b.dataset.fecha <= dia.dataset.fecha) b.classList.add('cal-dia--previa');
            });
        });

        // Teclado: flechas mueven el foco entre días, Esc cancela la elección de salida
        cont.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && pendiente) {
                ev.preventDefault();
                ev.stopPropagation();
                pendiente = false;
                aviso = '';
                render();
                return;
            }
            var dia = ev.target.closest('.cal-dia');
            var pasos = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[ev.key];
            if (!dia || !pasos) return;
            ev.preventDefault();
            var destino = sumarDias(dia.dataset.fecha, pasos);
            for (var i = 0; i < 60 && destino >= minimo() && deshabilitado(destino); i++) {
                destino = sumarDias(destino, pasos);
            }
            if (destino < minimo() || deshabilitado(destino)) return;
            foco = destino;
            var d = desdeIso(destino);
            var ultimoVisible = new Date(vista.getFullYear(), vista.getMonth() + MESES_VISIBLES, 0);
            if (d < vista || d > ultimoVisible) {
                vista = new Date(d.getFullYear(), d.getMonth() - (d > ultimoVisible ? MESES_VISIBLES - 1 : 0), 1);
            }
            render();
            var b = cont.querySelector('.cal-dia[data-fecha="' + destino + '"]');
            if (b) b.focus();
        });

        return {
            mostrar: function (lista) {
                reservas = lista || [];
                render();
            },
            ocultar: function () {
                pendiente = false;
                aviso = '';
                cont.style.display = 'none';
            }
        };
    }

    return { crear: crear };
})();
