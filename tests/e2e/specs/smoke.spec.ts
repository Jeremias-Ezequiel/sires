import { test, expect } from '@playwright/test';
import { ruta } from './helpers';

test('la pantalla de login carga', async ({ page }) => {
  await page.goto(ruta('/login'));
  await expect(page.getByRole('button', { name: 'Iniciar sesión' })).toBeVisible();
});
