import { test, expect } from '@playwright/test';
import { ruta, sesion } from './helpers';
import { fecha } from './reservas.helpers';

/** Planning de ocupación (SIRES 1.5.0). Solo lectura: no envía formularios. */

test.use({ storageState: sesion('recepcion') });

test('muestra 14 días desde hoy con una fila por habitación', async ({ page }) => {
  await page.goto(ruta('/dashboard/planning'));
  await expect(page.getByRole('heading', { level: 1, name: 'Planning de ocupación' })).toBeVisible();
  await expect(page.locator('.plan-fila--cabecera .plan-dia')).toHaveCount(14);
  await expect(page.locator('.plan-dia--hoy')).toHaveCount(1);
  expect(await page.locator('.plan-fila:not(.plan-fila--cabecera)').count()).toBeGreaterThan(0);
});

test('cambia a 30 días y navega hacia adelante y atrás', async ({ page }) => {
  await page.goto(ruta('/dashboard/planning'));
  await page.getByRole('link', { name: '30 días' }).click();
  await expect(page.locator('.plan-fila--cabecera .plan-dia')).toHaveCount(30);

  await page.getByRole('link', { name: '30 días después' }).click();
  await expect(page).toHaveURL(new RegExp(`desde=${fecha(30)}`));
  await expect(page.locator('.plan-dia--hoy')).toHaveCount(0);

  await page.getByRole('link', { name: 'Hoy' }).click();
  await expect(page.locator('.plan-dia--hoy')).toHaveCount(1);
});

test('ir a una fecha', async ({ page }) => {
  await page.goto(ruta('/dashboard/planning'));
  await page.locator('#plan-desde').fill(fecha(60));
  await page.getByRole('button', { name: 'Ir' }).click();
  await expect(page).toHaveURL(new RegExp(`desde=${fecha(60)}`));
});

test('parámetros inválidos vuelven a hoy y 14 días', async ({ page }) => {
  await page.goto(ruta('/dashboard/planning', { desde: '2026-02-30', dias: 99 }));
  await expect(page.locator('.plan-fila--cabecera .plan-dia')).toHaveCount(14);
  await expect(page.locator('.plan-dia--hoy')).toHaveCount(1);
});

test('clic en una reserva abre su detalle', async ({ page }) => {
  await page.goto(ruta('/dashboard/planning', { dias: 30 }));
  const barra = page.locator('.plan-reserva').first();
  test.skip(await barra.count() === 0, 'no hay reservas en los próximos 30 días');
  const id = (await barra.innerText()).match(/#(\d+)/)?.[1];
  await barra.click();
  await expect(page).toHaveURL(new RegExp(`booking%2Fdetail.*id=${id}|booking/detail.*id=${id}`));
});

test('clic en un día libre abre una reserva nueva precargada', async ({ page }) => {
  await page.goto(ruta('/dashboard/planning'));
  const celda = page.locator('.plan-celda--libre').first();
  test.skip(await celda.count() === 0, 'no hay días libres');
  const href = (await celda.getAttribute('href')) ?? '';
  const params = new URL(href, 'http://x').searchParams;
  await celda.click();
  await expect(page.locator('#id_habitacion')).toHaveValue(params.get('id_habitacion') ?? '');
  await expect(page.locator('#fecha_entrada')).toHaveValue(params.get('fecha_entrada') ?? '');
  await expect(page.locator('#habitacion-search')).not.toHaveValue('');
});

test('no ofrece reservar días pasados', async ({ page }) => {
  await page.goto(ruta('/dashboard/planning', { desde: fecha(-7) }));
  // Las 7 primeras columnas son pasadas: ninguna celda libre en ellas
  for (let i = 0; i < 7; i++) {
    await expect(page.locator(`.plan-celda--libre.plan-ini-${i}`)).toHaveCount(0);
  }
});

test('limpieza no tiene acceso al planning', async ({ browser }) => {
  const ctx = await browser.newContext({ storageState: sesion('limpieza') });
  const page = await ctx.newPage();
  await page.goto(ruta('/dashboard/planning'));
  await expect(page.getByRole('heading', { name: 'Planning de ocupación' })).toHaveCount(0);
  await ctx.close();
});
