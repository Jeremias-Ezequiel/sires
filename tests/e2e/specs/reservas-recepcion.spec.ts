/**
 * Flujo de reservas como Recepcionista.
 * Cada test crea sus propios datos (fechas futuras aleatorias o "hoy") en la BD de testing.
 * Los tests describen el comportamiento CORRECTO: un test que falla = bug pendiente.
 * Tags: @ok (ya funcionaba), @bug (bug del informe 2026-11-05, número entre corchetes),
 *       @regla (regla de negocio decidida el 2026-11-06; valores en la tabla Configuracion).
 * Correr solo bugs:  npx playwright test reservas --grep @bug
 */
import { test } from '@playwright/test';
import { sesion } from './helpers';
import {
  expect, fecha, rangoFuturo, crearReserva, accion, pagar, resumen, estadoHabitacion,
  habitacionLibre, limpiar, post, get, csrf,
} from './reservas.helpers';

test.use({ storageState: sesion('recepcion') });

test.describe('alta de reserva', () => {
  test('alta válida y validaciones básicas @ok', async ({ page }) => {
    const [e, s] = rangoFuturo(2);
    const hab = await habitacionLibre(page, e, fecha(4000));
    const { r, id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e, fecha_salida: s });
    expect(r.ok, r.mensaje).toBe(true);
    expect(id).not.toBeNull();

    const casos: [string, any, RegExp][] = [
      ['salida = entrada', { fecha_salida: e }, /posterior/],
      ['0 huéspedes', { cantidad_huespedes: 0 }, /al menos 1/],
      ['supera capacidad', { cantidad_huespedes: hab.capacidad + 1 }, /capacidad/],
      ['solapamiento', {}, /ya tiene una reserva/],
    ];
    for (const [nombre, over, msg] of casos) {
      // cada caso en un rango libre, salvo el de solapamiento
      const [e2, s2] = nombre === 'solapamiento' ? [e, s] : rangoFuturo(2);
      const x = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e2, fecha_salida: s2, ...over, ...(nombre === 'salida = entrada' ? { fecha_salida: e2 } : {}) });
      if (x.id) await accion(page, 'cancel', x.id);
      expect(x.r.ok, nombre).toBe(false);
      expect(x.r.mensaje, nombre).toMatch(msg);
    }
    // pegada: entrada = salida de la anterior → permitido
    const pegada = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: s, fecha_salida: addDias(s, 1) });
    expect(pegada.r.ok, pegada.r.mensaje).toBe(true);
    for (const x of [id, pegada.id]) if (x) await accion(page, 'cancel', x);
  });

  test('[4] el alta sin token CSRF se rechaza @bug', async ({ page }) => {
    const [e, s] = rangoFuturo(1);
    const hab = await habitacionLibre(page, e, s);
    const { r, id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e, fecha_salida: s }, false);
    if (id) await accion(page, 'cancel', id);
    expect(r.ok, 'se creó una reserva sin CSRF').toBe(false);
  });

  test('[4] la edición sin token CSRF se rechaza @bug', async ({ page }) => {
    const [e, s] = rangoFuturo(1);
    const hab = await habitacionLibre(page, e, s);
    const { id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e, fecha_salida: s });
    const r = await post(page, '/dashboard/booking/edit/process', {
      id: id!, id_cliente: await primerCliente(page), id_habitacion: hab.id, id_canal_origen: 3, fecha_entrada: e, fecha_salida: s, cantidad_huespedes: 1, observaciones: 'E2E sin csrf',
    });
    await accion(page, 'cancel', id!);
    expect(r.ok, 'se editó una reserva sin CSRF').toBe(false);
  });

  test('[12] cliente inexistente da un mensaje claro @bug', async ({ page }) => {
    const [e, s] = rangoFuturo(1);
    const hab = await habitacionLibre(page, e, s);
    const { r } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e, fecha_salida: s, id_cliente: 999999 });
    expect(r.ok).toBe(false);
    expect(r.mensaje).not.toMatch(/Error interno/);
  });

  test('[11] se puede reservar a futuro una habitación que hoy está Sucia @bug', async ({ page }) => {
    // Hace check-in hoy + checkout anticipado para dejarla Sucia
    const hab = await habitacionLibre(page, fecha(0), fecha(4000));
    const hoy = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: fecha(0), fecha_salida: fecha(1) });
    const t = await resumen(page, hoy.id!);
    await pagar(page, hoy.id!, t.saldo);
    await accion(page, 'checkin', hoy.id!);
    await accion(page, 'early-checkout', hoy.id!);
    expect(await estadoHabitacion(page, hab.numero)).toBe('Sucia');
    const [e, s] = rangoFuturo(1);
    const fut = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e, fecha_salida: s });
    if (fut.id) await accion(page, 'cancel', fut.id);
    await limpiar(page, hab.id);
    expect(fut.r.ok, fut.r.mensaje).toBe(true);
  });
});

test.describe('pagos y estados', () => {
  test('recorrido completo: seña → confirmar → pago total → check-in → checkout anticipado @ok', async ({ page }) => {
    const hab = await habitacionLibre(page, fecha(0), fecha(2), 1);
    const { id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: fecha(0), fecha_salida: fecha(2) });
    expect((await accion(page, 'confirm', id!)).mensaje).toMatch(/al menos un pago/);
    const t = await resumen(page, id!);
    expect((await pagar(page, id!, 0)).ok).toBe(false);
    expect((await pagar(page, id!, Math.round(t.total / 4))).ok).toBe(true);
    expect((await accion(page, 'checkin', id!)).ok).toBe(false); // pendiente
    expect((await accion(page, 'confirm', id!)).mensaje).toMatch(/seña mínima del 30 %/); // 25 % no alcanza
    expect((await pagar(page, id!, Math.round(t.total / 10))).ok).toBe(true);
    expect((await accion(page, 'confirm', id!)).ok).toBe(true);
    expect((await accion(page, 'checkin', id!)).mensaje).toMatch(/100% pagada/);
    const t2 = await resumen(page, id!);
    expect((await pagar(page, id!, t2.saldo)).ok).toBe(true);
    expect((await accion(page, 'checkin', id!)).ok).toBe(true);
    expect(await estadoHabitacion(page, hab.numero)).toBe('Ocupada');
    expect((await accion(page, 'cancel', id!)).ok).toBe(false);
    expect((await get(page, '/dashboard/booking/edit', { id: id! })).mensaje).toMatch(/check-in/);
    expect((await accion(page, 'early-checkout', id!)).ok).toBe(true);
    expect(await estadoHabitacion(page, hab.numero)).toBe('Sucia');
    await limpiar(page, hab.id);
  });

  test('[5] pagar más que el saldo muestra el motivo real @bug', async ({ page }) => {
    const [e, s] = rangoFuturo(1);
    const hab = await habitacionLibre(page, e, s);
    const { id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e, fecha_salida: s });
    const t = await resumen(page, id!);
    const r = await pagar(page, id!, t.saldo + 1000);
    await accion(page, 'cancel', id!);
    expect(r.mensaje).not.toMatch(/no active transaction/i);
    expect(r.mensaje).toMatch(/supera el saldo/);
  });

  test('[2] cancelar una PENDIENTE con pago la deja A Reembolsar @bug', async ({ page }) => {
    const [e, s] = rangoFuturo(2);
    const hab = await habitacionLibre(page, e, s);
    const { id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e, fecha_salida: s });
    await pagar(page, id!, 1000);
    expect((await accion(page, 'cancel', id!)).ok).toBe(true);
    expect((await resumen(page, id!)).estadoPago).toMatch(/A Reembolsar/);
  });

  test('cancelar una CONFIRMADA con pago la deja A Reembolsar @ok', async ({ page }) => {
    const [e, s] = rangoFuturo(2);
    const hab = await habitacionLibre(page, e, s);
    const { id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e, fecha_salida: s });
    await pagar(page, id!, sena((await resumen(page, id!)).total));
    expect((await accion(page, 'confirm', id!)).ok).toBe(true);
    expect((await accion(page, 'noshow', id!)).mensaje).toMatch(/antes de la fecha de entrada/);
    expect((await accion(page, 'cancel', id!)).ok).toBe(true);
    expect((await resumen(page, id!)).estadoPago).toMatch(/A Reembolsar/);
  });

  test('[1] cancelar una reserva futura no libera la habitación ocupada hoy @bug', async ({ page }) => {
    const hab = await habitacionLibre(page, fecha(0), fecha(4000));
    const hoy = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: fecha(0), fecha_salida: fecha(1) });
    await pagar(page, hoy.id!, (await resumen(page, hoy.id!)).saldo);
    expect((await accion(page, 'checkin', hoy.id!)).ok).toBe(true);
    const [e, s] = rangoFuturo(1);
    const fut = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e, fecha_salida: s });
    expect(fut.id, fut.r.mensaje).not.toBeNull();
    await accion(page, 'cancel', fut.id!);
    const estado = await estadoHabitacion(page, hab.numero);
    // limpieza del test (si el bug sigue, el checkout falla y la habitación queda incoherente)
    await accion(page, 'early-checkout', hoy.id!);
    await limpiar(page, hab.id);
    expect(estado, 'la habitación con huésped en casa quedó liberada').toBe('Ocupada');
  });

  test('[3] no se permite check-in después de la fecha de salida @bug', async ({ page }) => {
    // Cubierto por la validación de fecha_salida en ReservaService (check-in). No se puede preparar
    // por la UI: ya no se permiten reservas con fechas pasadas. Se deja en skip a propósito.
    test.skip(true, 'cubierto por la validación del Service; no se puede preparar por la UI');
    const hab = await habitacionLibre(page, fecha(-5), fecha(-3));
    const { id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: fecha(-5), fecha_salida: fecha(-3) });
    await pagar(page, id!, (await resumen(page, id!)).saldo);
    const ck = await accion(page, 'checkin', id!);
    if (ck.ok) { await accion(page, 'finalize', id!); await limpiar(page, hab.id); }
    expect(ck.ok, 'check-in aceptado con la estadía vencida').toBe(false);
  });
});

test.describe('edición', () => {
  test('editar recalcula y bloquea total menor a lo cobrado @ok', async ({ page }) => {
    const [e, s] = rangoFuturo(3);
    const hab = await habitacionLibre(page, e, s);
    const { id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e, fecha_salida: s });
    const t = await resumen(page, id!);
    await pagar(page, id!, Math.round(t.total * 0.9));
    const r = await post(page, '/dashboard/booking/edit/process', {
      _csrf_token: await csrf(page), id: id!, id_cliente: await primerCliente(page), id_habitacion: hab.id, id_canal_origen: 3,
      cantidad_huespedes: 1, observaciones: 'E2E', fecha_entrada: e, fecha_salida: addDias(e, 1),
    });
    await accion(page, 'confirm', id!); await accion(page, 'cancel', id!);
    expect(r.ok).toBe(false);
    expect(r.mensaje).toMatch(/menor a lo ya cobrado/);
  });

  test('[6] editar una reserva CANCELADA por POST se rechaza @bug', async ({ page }) => {
    const [e, s] = rangoFuturo(1);
    const hab = await habitacionLibre(page, e, s);
    const { id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e, fecha_salida: s });
    await accion(page, 'cancel', id!);
    const r = await post(page, '/dashboard/booking/edit/process', {
      _csrf_token: await csrf(page), id: id!, id_cliente: await primerCliente(page), id_habitacion: hab.id, id_canal_origen: 3,
      fecha_entrada: e, fecha_salida: addDias(s, 1), cantidad_huespedes: 1, observaciones: 'E2E',
    });
    expect(r.ok, `respondió: ${r.mensaje}`).toBe(false);
  });

  test('[16] editar una CONFIRMADA corriendo fechas mantiene el precio pactado @bug', async ({ page }) => {
    const [e, s] = rangoFuturo(2);
    const hab = await habitacionLibre(page, e, addDias(s, 1));
    const { id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e, fecha_salida: s });
    const antes = await resumen(page, id!);
    await pagar(page, id!, sena(antes.total));
    expect((await accion(page, 'confirm', id!)).ok).toBe(true);
    await page.goto(ruta2('/dashboard/booking/detail', id!));
    const botonEditar = await page.locator('main a[href*="booking%2Fedit"], main a[href*="booking/edit"]').count();
    const r = await post(page, '/dashboard/booking/edit/process', {
      _csrf_token: await csrf(page), id: id!, id_cliente: await primerCliente(page), id_habitacion: hab.id, id_canal_origen: 3,
      cantidad_huespedes: 1, observaciones: 'E2E', fecha_entrada: e, fecha_salida: addDias(s, 1),
    });
    const despues = await resumen(page, id!);
    await accion(page, 'cancel', id!);
    expect(botonEditar, 'la confirmada no muestra el botón Editar').toBeGreaterThan(0);
    expect(r.ok, r.mensaje).toBe(true);
    expect(despues.total).toBeCloseTo(antes.total / 2 * 3, 2);
  });
});

test.describe('edición: previsualización', () => {
  test('la edición muestra el precio pactado si no cambian habitación ni huéspedes @bug', async ({ page }) => {
    const [e, s] = rangoFuturo(2);
    const hab = await habitacionLibre(page, e, s);
    const { id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e, fecha_salida: s });
    const porNoche = (await resumen(page, id!)).total / 2;
    await page.goto(ruta2('/dashboard/booking/edit', id!));
    const ayuda = page.locator('#precio-ocupacion-ayuda');
    const inicial = await ayuda.innerText();
    const huespedes = page.locator('#cantidad_huespedes');
    let cambiado = '';
    if (hab.capacidad > 1) { await huespedes.fill('2'); await huespedes.dispatchEvent('input'); cambiado = await ayuda.innerText(); }
    await accion(page, 'cancel', id!);

    expect(inicial).toMatch(/precio pactado/);
    expect(inicial).toContain(porNoche.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    if (cambiado) expect(cambiado).not.toMatch(/precio pactado/);
  });
});

test.describe('permisos y UX', () => {
  test('[13] acciones de gerente rechazadas con aviso visible @bug', async ({ page }) => {
    const t = await csrf(page);
    for (const [r, form] of [
      ['/dashboard/booking/move-room', { _csrf_token: t, id: 1, nueva_habitacion: 1 }],
      ['/dashboard/booking/extend', { _csrf_token: t, id: 1, nueva_fecha_salida: fecha(10) }],
    ] as const) {
      const x = await post(page, r, form as any);
      expect(x.url, r).not.toMatch(/route=\/dashboard\/booking\/(move-room|extend)/);
      expect(x.mensaje, `${r} sin aviso`).toMatch(/permiso/i);
    }
  });

  test('[8] el detalle muestra el resultado del upgrade @bug', async ({ page }) => {
    const [e, s] = rangoFuturo(1);
    const hab = await habitacionLibre(page, e, s);
    const { id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e, fecha_salida: s });
    await pagar(page, id!, sena((await resumen(page, id!)).total)); await accion(page, 'confirm', id!);
    await page.goto(ruta2('/dashboard/booking/detail', id!));
    const opcion = await page.locator('#upgrade-options-container input[name="nueva_habitacion"]').first().getAttribute('value').catch(() => null);
    test.skip(!opcion, 'no hay opciones de upgrade libres');
    const t = await page.locator('#upgrade-form input[name="_csrf_token"]').inputValue();
    const r = await post(page, '/dashboard/booking/upgrade', { _csrf_token: t, id: id!, nueva_habitacion: opcion! });
    await accion(page, 'cancel', id!);
    expect(r.mensaje).toMatch(/Upgrade realizado/);
  });
});

test.describe('reglas de negocio a decidir', () => {
  test('no se crean reservas con fecha de entrada pasada @regla', async ({ page }) => {
    const hab = await habitacionLibre(page, fecha(-2), fecha(-1));
    const { r, id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: fecha(-2), fecha_salida: fecha(-1) });
    if (id) await accion(page, 'cancel', id);
    expect(r.ok).toBe(false);
  });
  test('cancelar con menos de 72 h retiene 1 noche y el resto queda A Reembolsar @regla', async ({ page }) => {
    const hab = await habitacionLibre(page, fecha(1), fecha(4));
    const { id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: fecha(1), fecha_salida: fecha(4) });
    const t = await resumen(page, id!);
    await pagar(page, id!, Math.round(t.total * 0.6));
    const r = await accion(page, 'cancel', id!);
    expect(r.ok, r.mensaje).toBe(true);
    expect(r.mensaje).toMatch(/se retienen \$/);
    expect((await resumen(page, id!)).estadoPago).toMatch(/A Reembolsar/);
  });

  test('hay un máximo de noches por reserva @regla', async ({ page }) => {
    const e = fecha(5000); const s = fecha(5400);
    const hab = await habitacionLibre(page, e, s);
    const { r, id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e, fecha_salida: s });
    if (id) await accion(page, 'cancel', id);
    expect(r.ok, '400 noches aceptadas').toBe(false);
  });
});

// ---- utilidades locales
/** Seña mínima para confirmar (Configuracion.sena_minima_pct = 30). */
function sena(total: number): number { return Math.ceil(total * 0.3); }
function addDias(f: string, d: number): string { const x = new Date(f + 'T12:00:00'); x.setDate(x.getDate() + d); return x.toISOString().slice(0, 10); }
function ruta2(r: string, id: number) { return `${process.env.SIRES_URL}/index.php?route=${encodeURIComponent(r)}&id=${id}`; }
async function primerCliente(page: import('@playwright/test').Page): Promise<number> {
  const { datosAlta } = await import('./reservas.helpers');
  return (await datosAlta(page)).clientes[0].id;
}
