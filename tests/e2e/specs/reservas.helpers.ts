import { expect, type Page } from '@playwright/test';
import { ruta } from './helpers';

/** Fecha YYYY-MM-DD en la zona del hotel, desplazada `d` días desde hoy. */
export function fecha(d: number): string {
  const x = new Date(); x.setDate(x.getDate() + d);
  return x.toLocaleDateString('sv-SE', { timeZone: 'America/Argentina/Buenos_Aires' });
}
export function horaHotel(): string {
  return new Date().toLocaleTimeString('sv-SE', { timeZone: 'America/Argentina/Buenos_Aires' }).slice(0, 5);
}

/** Desplazamiento aleatorio lejano para que cada corrida use fechas libres. */
let cursor = 300 + Math.floor(Math.random() * 3000);
export function rangoFuturo(noches = 2): [string, string] {
  const desde = cursor; cursor += noches + 3;
  return [fecha(desde), fecha(desde + noches)];
}

export type Resultado = { url: string; ok: boolean; tipo: string; mensaje: string; html: string };
function leerFlash(url: string, html: string): Resultado {
  const m = html.match(/class="alert alert--(\w+)[^"]*"[^>]*>[\s\S]*?<\/i>([\s\S]*?)<\/div>/);
  const tipo = m?.[1] ?? '';
  return { url: decodeURIComponent(url), ok: tipo === 'success', tipo, mensaje: (m?.[2] ?? '').trim(), html };
}

export async function csrf(page: Page): Promise<string> {
  await page.goto(ruta('/dashboard/booking'));
  return page.locator('input[name="_csrf_token"]').first().inputValue();
}
export async function post(page: Page, r: string, form: Record<string, string | number>): Promise<Resultado> {
  const res = await page.request.post(ruta(r), { form });
  return leerFlash(res.url(), await res.text());
}
export async function get(page: Page, r: string, params: Record<string, string | number> = {}): Promise<Resultado> {
  const res = await page.request.get(ruta(r, params));
  return leerFlash(res.url(), await res.text());
}

type Room = { id: number; numero: number; tipo: string; estado: string; precio: number; capacidad: number };
type Act = { id: number; habitacion: number; entrada: string; salida: string };
export async function datosAlta(page: Page) {
  await page.goto(ruta('/dashboard/booking/add'));
  return page.evaluate(() => ({
    // @ts-ignore variables globales de addBooking.phtml
    clientes: CLIENTES as { id: number }[], rooms: ROOMS as Room[], reservas: RESERVAS as Act[],
  }));
}
/** Habitación Disponible sin reservas activas que se crucen con [entrada, salida). */
export async function habitacionLibre(page: Page, entrada: string, salida: string, minCap = 1): Promise<Room> {
  const { rooms, reservas } = await datosAlta(page);
  const libre = rooms.find(r => r.estado === 'Disponible' && r.capacidad >= minCap &&
    !reservas.some(x => x.habitacion === r.id && x.entrada < salida && x.salida > entrada));
  if (!libre) throw new Error(`No hay habitación libre para ${entrada}..${salida}`);
  return libre;
}

export type Alta = { id_habitacion: number; fecha_entrada: string; fecha_salida: string; cantidad_huespedes?: number; id_cliente?: number; id_canal_origen?: number; observaciones?: string };
export async function crearReserva(page: Page, a: Alta, conCsrf = true): Promise<{ r: Resultado; id: number | null }> {
  const { clientes } = await datosAlta(page);
  const form: Record<string, string | number> = {
    id_cliente: a.id_cliente ?? clientes[0].id, id_canal_origen: a.id_canal_origen ?? 3, cantidad_huespedes: a.cantidad_huespedes ?? 1,
    observaciones: a.observaciones ?? 'E2E', id_habitacion: a.id_habitacion, fecha_entrada: a.fecha_entrada, fecha_salida: a.fecha_salida,
  };
  if (conCsrf) form._csrf_token = await csrf(page);
  const r = await post(page, '/dashboard/booking/add/process', form);
  const { reservas } = await datosAlta(page);
  const hit = reservas.filter(x => x.habitacion === a.id_habitacion && x.entrada === a.fecha_entrada && x.salida === a.fecha_salida).pop();
  return { r, id: hit?.id ?? null };
}
export async function accion(page: Page, a: 'confirm' | 'checkin' | 'cancel' | 'noshow' | 'finalize' | 'early-checkout', id: number) {
  return post(page, `/dashboard/booking/${a}`, { _csrf_token: await csrf(page), id });
}
export async function pagar(page: Page, id: number, monto: number, metodo = 1) {
  return post(page, '/dashboard/payments/add/process', { _csrf_token: await csrf(page), id_reserva: id, id_metodo_pago: metodo, monto_abonado: monto });
}
/** Lee el detalle de pagos: estado de la reserva, estado de pago, total, cobrado y saldo. */
export async function resumen(page: Page, id: number) {
  await page.goto(ruta('/dashboard/payments/detail', { id }));
  const t = (await page.locator('main').innerText()).replace(/\s+/g, ' ');
  const num = (s?: string) => s ? Number(s.replace(/\./g, '').replace(',', '.')) : NaN;
  return {
    estadoReserva: t.match(/Estado Reserva (.+?) Cliente/)?.[1] ?? '',
    estadoPago: t.match(/Estado de Pago (.+?) Monto Total/)?.[1] ?? '',
    total: num(t.match(/Monto Total \$([\d.,]+)/)?.[1]),
    cobrado: num(t.match(/Monto Cobrado \$([\d.,]+)/)?.[1]),
    saldo: num(t.match(/Saldo Pendiente \$([\d.,]+)/)?.[1]),
  };
}
export async function estadoHabitacion(page: Page, numero: number): Promise<string> {
  await page.goto(ruta('/dashboard/rooms'));
  const fila = (await page.locator('table tbody tr', { hasText: `#${numero}` }).first().innerText()).replace(/\s+/g, ' ');
  return fila.match(/(Disponible|Ocupada|Sucia|Limpiando|Mantenimiento|Bloqueada)/)?.[1] ?? fila;
}
/** Deja una habitación Sucia otra vez en Disponible (limpieza inicio + fin). */
export async function limpiar(page: Page, idHab: number) {
  await get(page, '/dashboard/rooms/cleaning/start', { id: idHab });
  await get(page, '/dashboard/rooms/cleaning/complete', { id: idHab });
}
export { expect };
