import { test, expect } from '@playwright/test';
import { ruta } from './helpers';

/**
 * Recuperación de contraseña sin sesión. No se pide un enlace para un correo real (mandaría
 * un mail y dejaría un token): solo los casos que no cambian datos.
 * Cada solicitud cuenta para LimiteIntentosService, por eso hay una sola por corrida.
 */

test.describe('recuperación de contraseña', () => {
  test('un correo no registrado recibe el mismo mensaje que uno registrado', async ({ page }) => {
    await page.goto(ruta('/password/recovery'));
    await page.locator('input[name="email"]').fill(`no.existe.${Date.now()}@prueba.com`);
    await page.getByRole('button', { name: 'Enviar enlace' }).click();
    await expect(page).toHaveURL(/login/);
    await expect(page.locator('.alert--success')).toContainText('Si el correo pertenece a un usuario registrado');
  });

  test('un enlace inválido no permite cambiar la contraseña', async ({ page }) => {
    await page.goto(ruta('/password/reset/' + 'f'.repeat(32)));
    await page.locator('#password').fill('NuevaClave123');
    await page.locator('#confirm_password').fill('NuevaClave123');
    await page.getByRole('button', { name: 'Restablecer contraseña' }).click();
    await expect(page).toHaveURL(/password%2Frecovery|password\/recovery/);
    await expect(page.locator('.alert--error')).toContainText('expirado, ya fue utilizado o es inválido');
  });

  test('contraseñas distintas se rechazan antes de mirar el token', async ({ page }) => {
    await page.goto(ruta('/password/reset/' + 'f'.repeat(32)));
    await page.locator('#password').fill('NuevaClave123');
    await page.locator('#confirm_password').fill('NuevaClave124');
    await page.getByRole('button', { name: 'Restablecer contraseña' }).click();
    await expect(page.locator('.alert--error')).toContainText('no coinciden');
  });
});
