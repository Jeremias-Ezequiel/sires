import { test as base, type Page } from '@playwright/test';
import path from 'node:path';

/** Arma la URL de una ruta: /sires/index.php?route=/x&param=... */
export function ruta(r: string, params: Record<string, string | number> = {}): string {
  const qs = new URLSearchParams({ route: r, ...Object.fromEntries(
    Object.entries(params).map(([k, v]) => [k, String(v)])) });
  return `${process.env.SIRES_URL}/index.php?${qs}`;
}

export const ROLES = {
  admin: 'ADMIN',
  recepcion: 'RECEPCION',
  gerente: 'GERENTE',
  limpieza: 'LIMPIEZA',
} as const;
export type Rol = keyof typeof ROLES;

export function credenciales(rol: Rol) {
  const p = ROLES[rol];
  return { email: process.env[`${p}_EMAIL`] ?? '', pass: process.env[`${p}_PASS`] ?? '' };
}

export async function login(page: Page, rol: Rol) {
  const { email, pass } = credenciales(rol);
  await page.goto(ruta('/login'));
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(pass);
  await page.getByRole('button', { name: 'Iniciar sesión' }).click();
}

/** Test con sesión ya iniciada: test.use({ storageState: sesion('admin') }) */
export const sesion = (rol: Rol) => path.join(__dirname, '..', '.auth', `${rol}.json`);
export const test = base;
export { expect } from '@playwright/test';
