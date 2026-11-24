/**
 * Reembolsos (solo Administrador): A Reembolsar → Reembolsado / Retenido.
 * Política: cancelación gratis con más de 72 h; con menos se retiene 1 noche (Configuracion).
 */
import { test } from '@playwright/test';
import { sesion } from './helpers';
import {
  expect, fecha, rangoFuturo, crearReserva, accion, pagar, resumen,
  habitacionLibre, post, csrf,
} from './reservas.helpers';

test.use({ storageState: sesion('admin') });

const reembolsar = async (page: import('@playwright/test').Page, id: number) =>
  post(page, '/dashboard/payments/refund/process', { _csrf_token: await csrf(page), id_reserva: id });

test.describe('reembolsos', () => {
  test('cancelación gratis: se reembolsa todo y queda Reembolsado @regla', async ({ page }) => {
    const [e, s] = rangoFuturo(2);
    const hab = await habitacionLibre(page, e, s);
    const { id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e, fecha_salida: s });
    await pagar(page, id!, Math.ceil((await resumen(page, id!)).total * 0.3));
    expect((await accion(page, 'confirm', id!)).ok).toBe(true);
    expect((await accion(page, 'cancel', id!)).ok).toBe(true);
    expect((await resumen(page, id!)).estadoPago).toMatch(/A Reembolsar/);

    const r = await reembolsar(page, id!);
    expect(r.ok, r.mensaje).toBe(true);
    expect(r.mensaje).toMatch(/Reembolso procesado/);
    expect(r.mensaje).not.toMatch(/Retenido/);
    const t = await resumen(page, id!);
    expect(t.estadoPago).toMatch(/Reembolsado/);
    expect(t.cobrado).toBe(0);

    // no se puede reembolsar dos veces
    const otra = await reembolsar(page, id!);
    expect(otra.ok).toBe(false);
    expect(otra.mensaje).toMatch(/no está pendiente de reembolso/);
  });

  test('cancelación con menos de 72 h: se reembolsa el resto y queda Retenido @regla', async ({ page }) => {
    const hab = await habitacionLibre(page, fecha(1), fecha(4));
    const { id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: fecha(1), fecha_salida: fecha(4) });
    await pagar(page, id!, Math.round((await resumen(page, id!)).total * 0.6));
    expect((await accion(page, 'cancel', id!)).ok).toBe(true);
    expect((await resumen(page, id!)).estadoPago).toMatch(/A Reembolsar/);

    const r = await reembolsar(page, id!);
    expect(r.ok, r.mensaje).toBe(true);
    expect(r.mensaje).toMatch(/Retenido por el hotel/);
    const t = await resumen(page, id!);
    expect(t.estadoPago).toMatch(/Retenido/);
    expect(t.cobrado, 'lo cobrado debe quedar igual a lo retenido (1 noche)').toBeGreaterThan(0);
    expect(t.saldo).toBe(0);
  });
});

test.describe('permisos de reembolso', () => {
  test.use({ storageState: sesion('gerente') });
  test('el gerente no puede procesar reembolsos @ok', async ({ page }) => {
    const [e, s] = rangoFuturo(1);
    const hab = await habitacionLibre(page, e, s);
    const { id } = await crearReserva(page, { id_habitacion: hab.id, fecha_entrada: e, fecha_salida: s });
    await pagar(page, id!, 1000);
    await accion(page, 'cancel', id!);
    await reembolsar(page, id!);
    expect((await resumen(page, id!)).estadoPago).toMatch(/A Reembolsar/);
  });
});
