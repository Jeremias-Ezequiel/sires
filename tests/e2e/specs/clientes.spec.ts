import { test, expect } from '@playwright/test';
import { credenciales, ruta, sesion } from './helpers';
import { enviarSinValidarCliente } from './formularios.helpers';

/**
 * Clientes: acceso, validación del lado del servidor y alta + edición.
 * Red de seguridad para mover la lógica del ClienteController a un Service.
 * El alta deja un cliente nuevo sin vuelta atrás: solo corre con E2E_MUTAR=1.
 */

test.describe('clientes: acceso', () => {
  for (const rol of ['admin', 'gerente', 'recepcion'] as const) {
    test(`${rol} ve el listado de clientes`, async ({ browser }) => {
      const ctx = await browser.newContext({ storageState: sesion(rol) });
      const page = await ctx.newPage();
      await page.goto(ruta('/dashboard/clients'));
      await expect(page.getByRole('heading', { level: 1, name: 'Administración de Clientes' })).toBeVisible();
      await ctx.close();
    });
  }

  test('limpieza no entra al listado de clientes', async ({ browser }) => {
    test.skip(!credenciales('limpieza').email, 'sin credenciales de limpieza en .env');
    const ctx = await browser.newContext({ storageState: sesion('limpieza') });
    const page = await ctx.newPage();
    await page.goto(ruta('/dashboard/clients'));
    await expect(page.getByRole('heading', { level: 1, name: 'Administración de Clientes' })).toHaveCount(0);
    await ctx.close();
  });
});

test.describe('clientes: validación', () => {
  test.use({ storageState: sesion('recepcion') });

  test('el servidor rechaza un documento inválido en el alta y conserva lo cargado', async ({ page }) => {
    await page.goto(ruta('/dashboard/clients/add'));
    await enviarSinValidarCliente(page, 'form.client-form', {
      nombre: 'Prueba', apellido: 'Validacion', dni_pasaporte: '12', mail: 'valida@prueba.com',
    });
    await expect(page.locator('.alert--error')).toContainText('DNI o Pasaporte no es válido');
    await expect(page.locator('#apellido')).toHaveValue('Validacion');
  });

  test('el servidor rechaza un correo inválido al editar', async ({ page }) => {
    await page.goto(ruta('/dashboard/clients'));
    const editar = page.locator('tbody tr a[href*="clients"]').first();
    test.skip(await editar.count() === 0, 'no hay clientes');
    await editar.click();
    await expect(page.getByRole('heading', { level: 1, name: 'Editar Cliente' })).toBeVisible();
    await enviarSinValidarCliente(page, 'form.client-form', { mail: 'no-es-correo' });
    await expect(page.locator('.alert--error')).toBeVisible();
    await expect(page).toHaveURL(/clients%2Fedit|clients\/edit/);
  });

  test('un id inválido vuelve al listado con error', async ({ page }) => {
    await page.goto(ruta('/dashboard/clients/edit', { id: 'abc' }));
    await expect(page.locator('.alert--error')).toContainText('ID de cliente inválido.');
  });
});

test.describe('clientes: alta y edición', () => {
  test.use({ storageState: sesion('recepcion') });

  test('alta y edición de un cliente nuevo @muta', async ({ page }) => {
    test.skip(process.env.E2E_MUTAR !== '1', 'crea un cliente: correr con E2E_MUTAR=1');
    const dni = String(40000000 + Math.floor(Math.random() * 9999999));

    await page.goto(ruta('/dashboard/clients/add'));
    await page.locator('#nombre').fill('Cliente');
    await page.locator('#apellido').fill('Automatizado');
    await page.locator('#dni_pasaporte').fill(dni);
    await page.locator('#mail').fill(`e2e.cliente.${Date.now()}@prueba.com`);
    await page.locator('#id_nacionalidad').selectOption({ label: 'Argentina' });
    await expect(page.locator('#id_provincia')).toBeEnabled();
    await expect(page.locator('#id_provincia option:not([disabled])').first()).toBeAttached();
    await page.locator('#id_provincia').selectOption({ index: 1 });
    await page.locator('form.client-form button[type="submit"]').click();
    await expect(page.locator('.alert--success')).toContainText('Cliente registrado exitosamente.');

    await page.goto(ruta('/dashboard/clients', { search: dni }));
    const fila = page.locator('tbody tr').filter({ hasText: dni });
    await expect(fila).toHaveCount(1);
    await fila.locator('a[href*="clients"]').click();
    await expect(page.locator('#id_provincia')).toBeEnabled();
    await page.locator('#apellido').fill('Editado');
    await page.locator('form.client-form button[type="submit"]').click();
    await expect(page.locator('.alert--success')).toContainText('Cliente actualizado exitosamente.');

    await page.goto(ruta('/dashboard/clients', { search: dni }));
    await expect(page.locator('tbody tr').filter({ hasText: dni })).toContainText('Cliente Editado');
  });
});
