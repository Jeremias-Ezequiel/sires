/**
 * Mover habitación y extender estadía (solo Administrador / Gerente), con sesión de Gerente.
 * Usan reservas con check-in HOY: cada test deja las habitaciones como estaban
 * (checkout anticipado + limpieza, fin de mantenimiento, cancelación de las reservas auxiliares).
 * Tags y numeración como reservas-recepcion.spec.ts (informe 2026-11-05).
 */
import { test } from '@playwright/test';
import { sesion, ruta } from './helpers';
import {
  expect, fecha, rangoFuturo, crearReserva, accion, pagar, resumen, estadoHabitacion,
  habitacionLibre, limpiar, post, get, csrf,
} from './reservas.helpers';

test.use({ storageState: sesion('gerente') });

/** Crea una reserva desde hoy, la paga completa y le hace check-in. */
async function enCasa(page: import('@playwright/test').Page, noches: number) {
  const hab = await habitacionLibre(page, fecha(0), fecha(noches));
  const { id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: fecha(0), fecha_salida: fecha(noches) });
  expect(id, 'no se pudo crear la reserva de hoy').not.toBeNull();
  await pagar(page, id!, (await resumen(page, id!)).saldo);
  const ck = await accion(page, 'checkin', id!);
  expect(ck.ok, ck.mensaje).toBe(true);
  return { hab, id: id! };
}

test.describe('mover habitación', () => {
  test('[10] no se mueve a una habitación con reservas en lo que resta de la estadía @bug', async ({ page }) => {
    const a = await enCasa(page, 3);
    // habitación destino libre hoy pero reservada mañana
    const ocupadaManana = await habitacionLibre(page, fecha(0), fecha(3));
    const b = await crearReserva(page, { id_habitacion: ocupadaManana.id, fecha_entrada: fecha(1), fecha_salida: fecha(2) });
    const libre = await habitacionLibre(page, fecha(0), fecha(3));

    const rechazo = await post(page, '/dashboard/booking/move-room', { _csrf_token: await csrf(page), id: a.id, nueva_habitacion: ocupadaManana.id });
    const ok = await post(page, '/dashboard/booking/move-room', { _csrf_token: await csrf(page), id: a.id, nueva_habitacion: libre.id });
    const estadoDestino = await estadoHabitacion(page, libre.numero);
    const estadoOrigen = await estadoHabitacion(page, a.hab.numero);

    // limpieza
    await accion(page, 'early-checkout', a.id);
    await limpiar(page, ok.ok ? libre.id : a.hab.id);
    if (ok.ok) await get(page, '/dashboard/rooms/maintenance/end', { id: a.hab.id });
    await accion(page, 'cancel', b.id!);

    expect(rechazo.ok, 'se movió a una habitación reservada para mañana').toBe(false);
    expect(rechazo.mensaje).toMatch(/tiene reservas durante lo que resta/);
    expect(ok.ok, ok.mensaje).toBe(true);
    expect(estadoDestino).toBe('Ocupada');
    expect(estadoOrigen).toBe('Mantenimiento');
  });
});

test.describe('extender estadía', () => {
  test('extender en la misma habitación recalcula el total @ok', async ({ page }) => {
    const a = await enCasa(page, 1);
    const antes = (await resumen(page, a.id)).total;
    const r = await post(page, '/dashboard/booking/extend', { _csrf_token: await csrf(page), id: a.id, nueva_fecha_salida: fecha(2) });
    const despues = await resumen(page, a.id);
    await pagar(page, a.id, despues.saldo);
    await accion(page, 'early-checkout', a.id);
    await limpiar(page, a.hab.id);

    expect(r.ok, r.mensaje).toBe(true);
    expect(despues.total).toBeGreaterThan(antes);
  });

  test('[9] extender con la habitación reservada divide la estadía en otra habitación @bug', async ({ page }) => {
    const a = await enCasa(page, 1);
    // la habitación actual está reservada a partir de mañana
    const b = await crearReserva(page, { id_habitacion: a.hab.id, fecha_entrada: fecha(1), fecha_salida: fecha(2) });
    expect(b.id, b.r.mensaje).not.toBeNull();

    const r = await post(page, '/dashboard/booking/extend', { _csrf_token: await csrf(page), id: a.id, nueva_fecha_salida: fecha(2) });
    const idSplit = Number(r.mensaje.match(/reserva #(\d+)/)?.[1] ?? 0);
    const numHab = Number(r.mensaje.match(/habitación #(\d+)/)?.[1] ?? 0);
    const split = idSplit ? await resumen(page, idSplit) : null;
    const estadoHabSplit = numHab ? await estadoHabitacion(page, numHab) : '';

    // limpieza
    if (idSplit) await accion(page, 'cancel', idSplit);
    await accion(page, 'cancel', b.id!);
    await accion(page, 'early-checkout', a.id);
    await limpiar(page, a.hab.id);

    expect(r.ok, r.mensaje).toBe(true);
    expect(r.mensaje).toMatch(/Estadía dividida/);
    expect(numHab, 'la extensión quedó en la misma habitación').not.toBe(a.hab.numero);
    expect(split?.estadoReserva).toMatch(/Pendiente/);
    expect(split?.total, 'la reserva de extensión no tiene importe a cobrar').toBeGreaterThan(0);
    expect(estadoHabSplit, 'la habitación nueva se ocupó antes del traslado').not.toBe('Ocupada');
  });
});

test.describe('recalcular precio', () => {
  test('recalcular solo por POST con token CSRF @bug', async ({ page }) => {
    const [e, s] = rangoFuturo(1);
    const hab = await habitacionLibre(page, e, s);
    const { id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e, fecha_salida: s });
    const porGet = await page.request.get(`${process.env.SIRES_URL}/index.php?route=${encodeURIComponent('/dashboard/payments/recalculate')}&id=${id}`, { maxRedirects: 0 });
    const sinCsrf = await post(page, '/dashboard/payments/recalculate', { id: id! });
    const conCsrf = await post(page, '/dashboard/payments/recalculate', { _csrf_token: await csrf(page), id: id! });
    // desde la pantalla: botón → modal → Confirmar envía el form oculto
    await page.goto(ruta('/dashboard/payments/detail', { id: id! }));
    await page.getByRole('button', { name: 'Recalcular Precio' }).click();
    await page.locator('#btn-modal-confirm').click();
    await page.waitForLoadState();
    const flashUi = await page.locator('.alert').first().innerText().catch(() => '');
    await accion(page, 'cancel', id!);

    expect(porGet.status(), 'el GET sigue mutando datos').toBe(405);
    expect(sinCsrf.ok, 'se recalculó sin CSRF').toBe(false);
    expect(sinCsrf.mensaje).toMatch(/Token de seguridad/);
    expect(conCsrf.ok, conCsrf.mensaje).toBe(true);
    expect(conCsrf.mensaje).toMatch(/Precio recalculado/);
    expect(flashUi, 'el botón de la pantalla no recalcula').toMatch(/Precio recalculado/);
  });
});
