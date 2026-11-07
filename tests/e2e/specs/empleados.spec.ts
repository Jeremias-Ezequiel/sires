import { test, expect, type Page } from '@playwright/test';
import { credenciales, ruta, sesion, ROLES, type Rol } from './helpers';
import { enviarSinValidarCliente } from './formularios.helpers';

/**
 * Empleados (solo administrador): edición, validación del lado del servidor, baja y
 * reactivación, cambio de contraseña y alta. Red de seguridad para mover la lógica del
 * EmployeeController a un Service.
 *
 * Los tests sin @muta dejan los datos como estaban (solo suman filas de auditoría).
 * Alta y cambio de contraseña no tienen vuelta atrás: solo corren con E2E_MUTAR=1.
 */

const correosDePrueba = () =>
  (Object.keys(ROLES) as Rol[]).map((r) => credenciales(r).email.toLowerCase()).filter(Boolean);

/** Busca en el listado (todas las páginas) la fila con ese correo y devuelve su id. */
async function idPorEmail(page: Page, email: string): Promise<number | null> {
  for (let pagina = 1; pagina <= 50; pagina++) {
    await page.goto(ruta('/dashboard/employees', { page: pagina }));
    const fila = page.locator('tbody tr').filter({ hasText: email });
    if (await fila.count() > 0) {
      const texto = await fila.first().locator('td').first().innerText();
      return Number(texto.replace(/\D/g, ''));
    }
    const siguiente = page.locator('.pagination a').filter({ hasText: String(pagina + 1) });
    if (await siguiente.count() === 0) return null;
  }
  return null;
}

/** Espera a que la cascada nacionalidad → provincia → localidad termine de cargar. */
async function esperarCascada(page: Page) {
  await expect(page.locator('#id_provincia')).toBeEnabled();
  await expect(page.locator('#id_provincia')).not.toHaveValue('');
}

test.describe('empleados: acceso', () => {
  for (const rol of ['recepcion', 'gerente'] as const) {
    test(`${rol} no entra al listado de empleados`, async ({ browser }) => {
      const ctx = await browser.newContext({ storageState: sesion(rol) });
      const page = await ctx.newPage();
      await page.goto(ruta('/dashboard/employees'));
      await expect(page.getByRole('heading', { level: 1, name: 'Administración de Empleados' })).toHaveCount(0);
      await ctx.close();
    });
  }
});

test.describe('empleados: edición', () => {
  test.use({ storageState: sesion('admin') });

  test('el servidor rechaza un nombre inválido y conserva lo cargado', async ({ page }) => {
    const email = credenciales('recepcion').email;
    test.skip(!email, 'sin credenciales de recepción en .env');
    const id = await idPorEmail(page, email);
    test.skip(id === null, 'el usuario de recepción no aparece en el listado');

    await page.goto(ruta('/dashboard/employees/edit', { id: id! }));
    await esperarCascada(page);
    await enviarSinValidarCliente(page, 'form.employee-form', { nombre: 'Juan123' });

    await expect(page).toHaveURL(/employees%2Fedit|employees\/edit/);
    await expect(page.locator('.alert--error')).toContainText('solo puede contener letras y espacios');
    await expect(page.locator('#nombre')).toHaveValue('Juan123');
  });

  test('un id inválido vuelve al listado con error', async ({ page }) => {
    await page.goto(ruta('/dashboard/employees/edit', { id: 'abc' }));
    await expect(page.locator('.alert--error')).toContainText('ID de empleado inválido.');
  });
});

test.describe('empleados: baja y reactivación', () => {
  test.use({ storageState: sesion('admin') });

  test('dar de baja y reactivar deja al empleado como estaba', async ({ page }) => {
    // Un empleado que no sea ninguno de los usuarios de prueba (sus sesiones se reutilizan)
    await page.goto(ruta('/dashboard/employees', { status_filter: 1 }));
    const propios = correosDePrueba();
    const filas = page.locator('tbody tr');
    let nombre = '';
    for (let i = 0; i < await filas.count(); i++) {
      const correo = (await filas.nth(i).locator('td').nth(2).innerText()).trim().toLowerCase();
      if (!propios.includes(correo)) {
        nombre = (await filas.nth(i).locator('td').nth(1).innerText()).trim();
        break;
      }
    }
    test.skip(nombre === '', 'no hay un empleado activo que no sea usuario de prueba');
    const fila = () => page.locator('tbody tr').filter({ hasText: nombre }).first();

    await fila().locator('[data-action="deactivate"]').click();
    await page.locator('#btn-modal-confirm').click();
    try {
      await expect(page.locator('.alert--success')).toContainText('Empleado desactivado exitosamente.');
    } finally {
      // Reactivar siempre, aunque falle una verificación: el empleado tiene que quedar como estaba
      await page.goto(ruta('/dashboard/employees', { status_filter: 0 }));
      await fila().locator('[data-action="activate"]').click();
      await page.locator('#btn-modal-confirm').click();
    }
    await expect(page.locator('.alert--success')).toContainText('Empleado reactivado exitosamente.');
    await page.goto(ruta('/dashboard/employees', { status_filter: 1 }));
    await expect(fila().locator('[data-action="deactivate"]')).toBeVisible();

    await page.goto(ruta('/dashboard/audit', { entidad: 'empleado' }));
    await expect(page.locator('tbody tr').filter({ hasText: 'Baja de acceso' }).first()).toBeVisible();
    await expect(page.locator('tbody tr').filter({ hasText: 'Reactivación de acceso' }).first()).toBeVisible();
  });
});

test.describe('empleados: cambio de contraseña', () => {
  test.use({ storageState: sesion('admin') });

  test('contraseñas distintas se rechazan sin cambiar nada', async ({ page }) => {
    const email = credenciales('recepcion').email;
    test.skip(!email, 'sin credenciales de recepción en .env');
    const id = await idPorEmail(page, email);
    test.skip(id === null, 'el usuario de recepción no aparece en el listado');

    await page.goto(ruta('/dashboard/employees/reset-password', { id: id! }));
    await page.locator('#password').fill('Distinta123');
    await page.locator('#confirm_password').fill('Distinta124');
    await enviarSinValidarCliente(page, 'form:has(#confirm_password)', {});
    await expect(page.locator('.alert--error')).toContainText('Las contraseñas ingresadas no coinciden.');
  });

  test('una contraseña débil se rechaza', async ({ page }) => {
    const email = credenciales('recepcion').email;
    test.skip(!email, 'sin credenciales de recepción en .env');
    const id = await idPorEmail(page, email);
    test.skip(id === null, 'el usuario de recepción no aparece en el listado');

    await page.goto(ruta('/dashboard/employees/reset-password', { id: id! }));
    await enviarSinValidarCliente(page, 'form:has(#confirm_password)', { password: 'corta', confirm_password: 'corta' });
    await expect(page.locator('.alert--error')).toContainText('al menos');
  });
});

/** CUIL válido (prefijo 20) para un DNI de 8 dígitos. */
function cuilPara(dni: string): string {
  const base = '20' + dni;
  const pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
  const suma = base.split('').reduce((s, d, i) => s + Number(d) * pesos[i], 0);
  const resto = 11 - (suma % 11);
  const dv = resto === 11 ? 0 : resto === 10 ? 9 : resto;
  return `20-${dni}-${dv}`;
}

test.describe('empleados: alta', () => {
  test.use({ storageState: sesion('admin') });

  test('alta y edición de un empleado nuevo quedan auditadas @muta', async ({ page }) => {
    test.skip(process.env.E2E_MUTAR !== '1', 'crea un empleado: correr con E2E_MUTAR=1');
    const dni = String(30000000 + Math.floor(Math.random() * 9999999));
    const email = `e2e.${Date.now()}@prueba.com`;

    await page.goto(ruta('/dashboard/employees/add'));
    const form = page.locator('form.employee-form');
    await page.locator('#nombre').fill('Prueba');
    await page.locator('#apellido').fill('Automatizada');
    await page.locator('#email').fill(email);
    await page.locator('#dni').fill(dni);
    await page.locator('#id_nacionalidad').selectOption({ label: 'Argentina' });
    await expect(page.locator('#id_provincia')).toBeEnabled();
    await expect(page.locator('#id_provincia option:not([disabled])').first()).toBeAttached();
    await page.locator('#id_provincia').selectOption({ index: 1 });
    await page.locator('#cuil').fill(cuilPara(dni));
    await page.locator('#id_rol').selectOption({ label: 'Recepcionista' });
    await page.locator('#password').fill('Prueba1234');
    await page.locator('#confirm_password').fill('Prueba1234');
    await form.locator('button[type="submit"]').click();

    await expect(page.locator('.alert--success')).toContainText('Empleado registrado exitosamente.');
    const id = await idPorEmail(page, email);
    expect(id).not.toBeNull();

    // Edición del mismo empleado (sus datos son válidos: los cargó este test)
    await page.goto(ruta('/dashboard/employees/edit', { id: id! }));
    await esperarCascada(page);
    await page.locator('#apellido').fill('Editada');
    await page.locator('form.employee-form button[type="submit"]').click();
    await expect(page.locator('.alert--success')).toContainText('Empleado actualizado exitosamente.');

    await page.goto(ruta('/dashboard/audit', { entidad: 'empleado' }));
    await expect(page.locator('tbody tr').filter({ hasText: 'Alta de empleado' }).first()).toBeVisible();
    await expect(page.locator('tbody tr').filter({ hasText: 'Edición de empleado' }).first()).toBeVisible();
  });
});
