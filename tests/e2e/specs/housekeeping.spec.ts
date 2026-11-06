import { test, expect } from '@playwright/test';
import { credenciales, ruta, sesion } from './helpers';

/**
 * Housekeeping (SIRES 1.2.0): lista de limpieza, permisos por rol y marca de urgente.
 * Los tests @muta cambian el estado de una habitación sin poder volver atrás
 * (Sucia → Disponible): solo corren con E2E_MUTAR=1.
 */

test.describe('housekeeping: acceso', () => {
  test('limpieza entra directo a la lista de limpieza', async ({ browser }) => {
    test.skip(!credenciales('limpieza').email, 'sin credenciales de limpieza en .env');
    const ctx = await browser.newContext({ storageState: sesion('limpieza') });
    const page = await ctx.newPage();
    await page.goto(ruta('/dashboard'));
    await expect(page).toHaveURL(/housekeeping/);
    await expect(page.getByRole('heading', { level: 1, name: 'Limpieza' })).toBeVisible();
    await ctx.close();
  });

  test('limpieza no puede entrar a auditoría', async ({ browser }) => {
    test.skip(!credenciales('limpieza').email, 'sin credenciales de limpieza en .env');
    const ctx = await browser.newContext({ storageState: sesion('limpieza') });
    const page = await ctx.newPage();
    await page.goto(ruta('/dashboard/audit'));
    await expect(page.getByRole('heading', { level: 1, name: 'Auditoría' })).toHaveCount(0);
    await ctx.close();
  });

  for (const rol of ['admin', 'gerente', 'recepcion'] as const) {
    test(`${rol} ve la lista de limpieza`, async ({ browser }) => {
      const ctx = await browser.newContext({ storageState: sesion(rol) });
      const page = await ctx.newPage();
      await page.goto(ruta('/dashboard/housekeeping'));
      await expect(page.getByRole('heading', { level: 1, name: 'Limpieza' })).toBeVisible();
      await expect(page.locator('#hk-pendientes')).toBeVisible();
      await ctx.close();
    });
  }
});

test.describe('housekeeping: acciones', () => {
  test.use({ storageState: sesion('recepcion') });

  test('marcar y quitar urgente deja la habitación como estaba y queda auditado', async ({ page }) => {
    await page.goto(ruta('/dashboard/housekeeping'));
    const tarjeta = page.locator('.hk-card').filter({ has: page.locator('[action*="urgent"]') }).first();
    test.skip(await tarjeta.count() === 0, 'no hay habitaciones sucias o en limpieza');

    const numero = (await tarjeta.locator('.hk-card__numero').innerText()).trim();
    const eraUrgente = await tarjeta.locator('.hk-card__urgente').count() > 0;
    const card = () => page.locator('.hk-card').filter({ hasText: numero }).first();

    await card().locator('[action*="urgent"] button').click();
    await expect(page.locator('.alert--success')).toBeVisible();
    await expect(card().locator('.hk-card__urgente')).toHaveCount(eraUrgente ? 0 : 1);

    await card().locator('[action*="urgent"] button').click();
    await expect(card().locator('.hk-card__urgente')).toHaveCount(eraUrgente ? 1 : 0);

    // El gerente ve las dos acciones en auditoría (entidad habitación)
    const gerente = await page.context().browser()!.newContext({ storageState: sesion('gerente') });
    const g = await gerente.newPage();
    await g.goto(ruta('/dashboard/audit', { entidad: 'habitacion' }));
    const filas = g.locator('tbody tr');
    await expect(filas.filter({ hasText: 'Marcada como limpieza urgente' }).first()).toBeVisible();
    await expect(filas.filter({ hasText: 'Se quita la limpieza urgente' }).first()).toBeVisible();
    await gerente.close();
  });

  test('iniciar y terminar limpieza @muta', async ({ page }) => {
    test.skip(process.env.E2E_MUTAR !== '1', 'cambia el estado de una habitación: correr con E2E_MUTAR=1');
    await page.goto(ruta('/dashboard/housekeeping'));
    const pendiente = page.locator('#hk-pendientes').locator('..').locator('.hk-card').first();
    test.skip(await pendiente.count() === 0, 'no hay habitaciones sucias');
    const numero = (await pendiente.locator('.hk-card__numero').innerText()).trim();

    await pendiente.locator('[action*="start"] button').click();
    await expect(page.locator('.alert--success')).toBeVisible();
    const enCurso = page.locator('#hk-en-curso').locator('..').locator('.hk-card').filter({ hasText: numero });
    await expect(enCurso).toHaveCount(1);

    // Terminar pide confirmación con el diálogo propio (public/js/confirmar.js), no confirm() nativo
    await enCurso.locator('[action*="complete"] button').click();
    await page.getByRole('button', { name: 'Sí, está limpia' }).click();
    await expect(page.locator('.alert--success')).toBeVisible();
    await expect(page.locator('.hk-card').filter({ hasText: numero })).toHaveCount(0);
  });
});
