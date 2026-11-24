import { test, expect } from '@playwright/test';
import { ruta, sesion } from './helpers';

/** Auditoría (SIRES 1.3.0): acceso por rol, filtros y paginación. Solo lectura. */

for (const rol of ['admin', 'gerente'] as const) {
  test(`${rol} ve la auditoría de acciones`, async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: sesion(rol) });
    const page = await ctx.newPage();
    await page.goto(ruta('/dashboard/audit'));
    await expect(page.getByRole('heading', { level: 1, name: 'Auditoría' })).toBeVisible();
    await expect(page.locator('.aud-vista--activa')).toContainText('Acciones');
    await ctx.close();
  });
}

test('recepción no tiene acceso a auditoría', async ({ browser }) => {
  const ctx = await browser.newContext({ storageState: sesion('recepcion') });
  const page = await ctx.newPage();
  await page.goto(ruta('/dashboard/audit'));
  await expect(page.getByRole('heading', { level: 1, name: 'Auditoría' })).toHaveCount(0);
  await expect(page.locator('.alert--error')).toContainText('No tiene permisos');
  await ctx.close();
});

test.describe('filtros', () => {
  test.use({ storageState: sesion('admin') });

  test('filtrar por entidad muestra solo esa entidad', async ({ page }) => {
    await page.goto(ruta('/dashboard/audit'));
    await page.locator('#aud-entidad').selectOption('reserva');
    await page.getByRole('button', { name: 'Filtrar' }).click();
    await expect(page.locator('#aud-entidad')).toHaveValue('reserva');
    await expect(page.getByRole('link', { name: 'Limpiar filtros' })).toBeVisible();

    const filas = page.locator('tbody tr');
    const n = await filas.count();
    if (n === 0) {
      await expect(page.getByText('No hay acciones que coincidan con los filtros.')).toBeVisible();
      return;
    }
    // La columna "Sobre" es la 4.ª: todas las filas deben ser de reservas
    const sobre = await filas.locator('td:nth-child(4)').allInnerTexts();
    for (const s of sobre) expect(s.toLowerCase()).toContain('reserva');
  });

  test('un rango de fechas sin datos muestra el aviso vacío', async ({ page }) => {
    await page.goto(ruta('/dashboard/audit', { desde: '2000-01-01', hasta: '2000-01-02' }));
    await expect(page.getByText('No hay acciones que coincidan con los filtros.')).toBeVisible();
  });

  test('el historial de una reserva aparece en su detalle', async ({ page }) => {
    await page.goto(ruta('/dashboard/audit', { entidad: 'reserva' }));
    const enlace = page.locator('tbody tr td:nth-child(4) a').first();
    test.skip(await enlace.count() === 0, 'no hay acciones sobre reservas');
    await enlace.click();
    await expect(page).toHaveURL(/booking/);
    await expect(page.locator('table').filter({ hasText: 'Acción' }).first()).toBeVisible();
  });
});
