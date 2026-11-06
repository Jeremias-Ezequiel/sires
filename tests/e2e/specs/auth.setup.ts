import { test, expect } from '@playwright/test';
import { ROLES, credenciales, login, sesion, type Rol } from './helpers';

for (const rol of Object.keys(ROLES) as Rol[]) {
  test(`login ${rol}`, async ({ page }) => {
    test.skip(!credenciales(rol).email, `sin credenciales para ${rol} en .env`);
    await login(page, rol);
    await expect(page).toHaveURL(/route=(%2F|\/)dashboard/);
    await page.context().storageState({ path: sesion(rol) });
  });
}
