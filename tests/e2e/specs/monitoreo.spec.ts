import { test, expect } from '@playwright/test';
import { ruta, sesion } from './helpers';

/** Monitoreo de procesos automáticos (SIRES 1.3.0). Solo lectura. */

const PROCESOS = ['Corte de pagos', 'Cargo de late checkout', 'Liberación de No-Show'];

test.use({ storageState: sesion('admin') });

test('la vista de procesos lista los tres procesos automáticos', async ({ page }) => {
  await page.goto(ruta('/dashboard/audit', { vista: 'procesos' }));
  await expect(page.locator('.aud-vista--activa')).toContainText('Procesos automáticos');
  const tabla = page.locator('table').filter({ hasText: 'Frecuencia' });
  for (const p of PROCESOS) {
    await expect(tabla.getByRole('link', { name: p })).toBeVisible();
  }
});

test('elegir un proceso filtra sus últimas corridas', async ({ page }) => {
  await page.goto(ruta('/dashboard/audit', { vista: 'procesos' }));
  await page.getByRole('link', { name: 'Corte de pagos' }).first().click();
  await expect(page.getByRole('heading', { name: /Últimas corridas: Corte de pagos/ })).toBeVisible();
  const procesos = await page.locator('table').filter({ hasText: 'Origen' }).locator('tbody td:nth-child(2)').allInnerTexts();
  for (const p of procesos) expect(p.trim()).toBe('Corte de pagos');
});

test('las alertas de procesos del inicio llevan a la vista de procesos', async ({ page }) => {
  await page.goto(ruta('/dashboard'));
  const alerta = page.locator('.alertas a[href*="procesos"]').first();
  test.skip(await alerta.count() === 0, 'sin alertas de procesos: los EVENTs corren bien');
  await alerta.click();
  await expect(page.locator('.aud-vista--activa')).toContainText('Procesos automáticos');
});

test('recepción no ve la vista de procesos', async ({ browser }) => {
  const ctx = await browser.newContext({ storageState: sesion('recepcion') });
  const page = await ctx.newPage();
  await page.goto(ruta('/dashboard/audit', { vista: 'procesos' }));
  await expect(page.locator('.aud-vistas')).toHaveCount(0);
  await ctx.close();
});
