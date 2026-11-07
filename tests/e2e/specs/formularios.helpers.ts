import type { Page } from '@playwright/test';

/**
 * Cambia campos de un formulario y lo envía con HTMLFormElement.submit(), que no dispara
 * el evento submit ni la validación HTML: sirve para probar la validación del servidor
 * aunque el JS de la vista la frene antes.
 */
export async function enviarSinValidarCliente(page: Page, selectorForm: string, valores: Record<string, string>) {
  await Promise.all([
    page.waitForNavigation(),
    page.locator(selectorForm).evaluate((form: HTMLFormElement, vals: Record<string, string>) => {
      for (const [nombre, valor] of Object.entries(vals)) {
        const campo = form.elements.namedItem(nombre) as HTMLInputElement | null;
        if (campo) campo.value = valor;
      }
      HTMLFormElement.prototype.submit.call(form);
    }, valores),
  ]);
}
