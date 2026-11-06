/**
 * SIRES - Cálculo de precio de reserva (solo para mostrar en pantalla).
 *
 * El servidor recalcula y guarda el precio real (Habitacion::precioEfectivoNoche).
 * La tabla de descuentos NO se define acá: la vista la inyecta desde
 * App\Config\Politicas::DESCUENTO_OCUPACION para que PHP y JS usen la misma fuente.
 */
var SiresPrecio = (function () {
    'use strict';

    /** Porcentaje de descuento según capacidad de la habitación y cantidad de huéspedes. */
    function descuentoOcupacion(tabla, capacidad, huespedes) {
        var cap = tabla && tabla[capacidad];
        if (!cap) return 0;
        return (huespedes in cap) ? Number(cap[huespedes]) : 0;
    }

    /** Precio por noche con el descuento aplicado. */
    function precioNoche(precioBase, descuento) {
        return precioBase * (1 - descuento / 100);
    }

    /** Cantidad de noches entre dos fechas YYYY-MM-DD (0 si el rango es inválido). */
    function calcularNoches(entrada, salida) {
        if (!entrada || !salida || salida <= entrada) return 0;
        var diff = (new Date(salida) - new Date(entrada)) / (1000 * 60 * 60 * 24);
        return Math.max(1, Math.round(diff));
    }

    return {
        descuentoOcupacion: descuentoOcupacion,
        precioNoche: precioNoche,
        calcularNoches: calcularNoches
    };
})();
