# Migraciones SIRES (Paso 7 de REFACTOR-BD.md)

Generadas a partir del esquema del dump `dump-siresDB-202611041916.sql` (MariaDB).
Todas son **aditivas** salvo `002`, `005` y `006`, que reemplazan o eliminan triggers/EVENTs (tienen `down`).
El código actual funciona antes y después de aplicarlas.

| Archivo | Qué hace | Equivale en REFACTOR-BD.md |
|---|---|---|
| `000_migraciones` | Tabla de control `migraciones(nombre, aplicada_en)` | Checklist punto 5 |
| `001_configuracion` | Tabla `Configuracion` versionada + valores de `Politicas.php` | 001 |
| `002_triggers_solapamiento` | Triggers de solapamiento solo validan filas en estado activo | **Nuevo** (ver abajo) |
| `003_reservas_precio_pactado` | `Reservas.precio_noche_pactado` + backfill | 002 |
| `004_reservas_late_fee` | `Reservas.late_fee_aplicado` + backfill | 003 |
| `005_event_corte_pagos` | Reemplaza `corte_pagos_48hs` por `corte_pagos`: lee horas de `Configuracion`, no toca `Habitaciones` | 004 |
| `006_drop_events_duplicados` | Elimina los EVENTs `late_checkout_fee` y `liberar_no_show` (quedan los crons PHP) | **Nuevo** (ver abajo) |

## Por qué 002 (nuevo)

`trg_check_solapamiento_update` valida solapamiento en **cualquier** UPDATE, aunque la fila
editada esté Cancelada/Finalizada/No-Show. Si existe una reserva cancelada que se superpone con
una activa en la misma habitación (caso normal: se cancela y se vuelve a reservar), todo UPDATE
sobre la cancelada falla con `La habitación ya tiene una reserva solapada en esas fechas.`
Eso rompería los backfill de `003`/`004` y la observación que agrega el corte de pagos.
Detectar si hay casos así hoy:

```sql
SELECT a.id, b.id, a.id_habitacion
FROM Reservas a JOIN Reservas b
  ON b.id_habitacion = a.id_habitacion AND b.id <> a.id
 AND a.fecha_entrada < b.fecha_salida AND a.fecha_salida > b.fecha_entrada
WHERE a.id_estado_reserva NOT IN (1,2,5,7) AND b.id_estado_reserva IN (1,2,5,7);
```

## Por qué 006 (nuevo)

`DATABASE_OCT/siresDB-eventos.sql` muestra 3 EVENTs. Dos duplican crons PHP con reglas distintas:

- `late_checkout_fee`: 0.5 y 11:00 fijos. No marca `late_fee_aplicado` (004), así que el PHP volvería a cobrar.
- `liberar_no_show`: libera por `fecha_entrada` (el PHP, por `fecha_salida`) y pasa a Disponible
  toda habitación Ocupada que tuvo algún No-Show, aunque haya un huésped alojado.

Queda una implementación por regla:

| Regla | Implementación |
|---|---|
| Corte de pagos | EVENT `corte_pagos` (005). `cron/payment-cutoff.php` queda solo como respaldo manual |
| Late checkout fee | `cron/late-checkout-fee.php` |
| Liberación No-Show con pago | `cron/no-show-release.php` |

## Antes de aplicar

1. Backup **con events y routines** (el dump actual no incluye el EVENT):
   `mysqldump --single-transaction --routines --triggers --events -u USER -p siresDB > backup_YYYYMMDD.sql`
2. Confirmar que los EVENTs del servidor coinciden con `events/*.original.sql` (ver `events/LEEME.md`).
3. Confirmar que los crons de late fee y no-show están en el `crontab` del servidor (ver cabecera de `006`).
4. Verificar zona horaria: `SELECT @@global.time_zone, @@session.time_zone, NOW();`
   debe coincidir con `America/Argentina/Buenos_Aires` (el EVENT usa `NOW()` del servidor).

## Aplicar (en una copia primero)

Usar el cliente `mysql` (los archivos usan `DELIMITER`; phpMyAdmin/DBeaver pueden no soportarlo igual).

```bash
cd migraciones
for f in 00{0,1,2,3,4,5,6}_*.up.sql; do
  echo "== $f"; mysql -u USER -p siresDB < "$f" || break
done
mysql -u USER -p siresDB -e "SELECT * FROM migraciones;"
```

## Revertir

Orden inverso: `006 → 005 → 004 → 003 → 002 → 001 → 000`. Los `down` de `005`/`006` recrean los EVENTs
originales. Como el código actual no usa las columnas nuevas, no hace falta redeploy.

## Notas

- `003`: el backfill deja `NULL` las reservas con late fee ya aplicado; el código debe usar
  `COALESCE(r.precio_noche_pactado, h.precio_noche_base)`.
- `004.down` es seguro solo mientras el código siga escribiendo el texto `Late Checkout Fee Aplicado`.
- Los `ALTER TABLE Reservas` pueden bloquear la tabla unos segundos: aplicar en horario de bajo uso.
- El EVENT `corte_pagos` y `cron/payment-cutoff.php` hacen lo mismo: no programar el cron.
- `PagoService::ejecutarCortePagos()` tampoco modifica `Habitaciones` (mismo criterio que 005).

## event_scheduler (estado del servidor al 2026-11-05)

En el servidor existen los 3 EVENTs originales (`corte_pagos_48hs`, `late_checkout_fee`, `liberar_no_show`),
pero `event_scheduler` estaría en `OFF`: hoy ningún EVENT se ejecuta.
Probablemente se activó con `SET GLOBAL` (siresDB-nuevos-estados.sql) y se perdió al reiniciar MariaDB.

**No activarlo antes de aplicar 006.** Si se activa antes, corren `liberar_no_show` (libera habitaciones
ocupadas) y `late_checkout_fee` (duplica el cobro del cron).

Después de aplicar 000 → 006:

```sql
SET GLOBAL event_scheduler = ON;
SHOW EVENTS;        -- solo corte_pagos
SHOW PROCESSLIST;   -- debe aparecer el usuario event_scheduler
```

Y persistirlo en la configuración de MariaDB (ej. `/etc/my.cnf.d/server.cnf`):

```ini
[mysqld]
event_scheduler = ON
```

Si se revierte 006, desactivar antes el scheduler (`SET GLOBAL event_scheduler = OFF;`) para no reactivar
los EVENTs con bugs.

## Dump completo ya migrado

`../dump-siresDB-migrado-20261105.sql` = `dump-siresDB-202611041916.sql` + EVENTs originales + `000 → 006`.
Incluye datos, triggers, el EVENT `corte_pagos` y la tabla `migraciones` con las 7 migraciones registradas.

Verificado en una instancia local MariaDB 13.0.2:
- aplicar `up`, revertir todos los `down` y volver a aplicar los `up`: sin errores;
- el cuerpo del EVENT, probado como procedimiento: cancela las reservas con pago parcial y las pasa a A reembolsar,
  no toca las que están pagadas ni la habitación, y si corre dos veces no repite nada;
- restaurar el dump en una base vacía: `CHECKSUM TABLE` idéntico al origen.

No probado en 11.8 (versión del servidor). El dump anterior se generó con el mismo cliente 13.0.2.

**Restaurar el dump REEMPLAZA la base entera (`DROP TABLE` en cada tabla).** Cualquier dato cargado
en el destino después del 2026-11-04 19:16 se pierde.

En cada servidor (testing / producción):
1. Backup previo de la base destino (DBeaver: Dump database con "Dump events" y "Dump routines").
2. Confirmar que existe el usuario `root@localhost`: es el DEFINER de los triggers y del EVENT.
3. Restaurar `dump-siresDB-migrado-20261105.sql` (DBeaver: Tools > Restore database).
4. Programar en el crontab `late-checkout-fee.php` y `no-show-release.php`.
5. `SET GLOBAL event_scheduler = ON;` y persistirlo en el `.cnf` (ver sección anterior).
6. Deployar el código de la rama `kevinomonte` (incluye el cambio en `PagoService::ejecutarCortePagos()`).
7. Verificar: `SELECT * FROM migraciones;` (7 filas), `SHOW EVENTS;` (solo `corte_pagos`).

### Variante limpia (recomendada para testing / primer deploy)

`../dump-siresDB-migrado-limpio-20261105.sql` = el dump migrado, sin datos de reservas:
- `Reservas`, `Resumen_Pago` y `Transacciones_Pago` vacías, con AUTO_INCREMENT = 1;
- la habitación 105 (id 10) pasó de Ocupada a Disponible (estaba ocupada solo por la reserva #9);
- se mantienen usuarios, clientes, habitaciones, catálogos, datos geográficos, `Configuracion` y `migraciones`.

Verificado: se restaura sin errores, queda solo el EVENT `corte_pagos` y el trigger rechaza una reserva solapada.
Se carga igual que el dump migrado (mismos pasos 1-7).

### Variante sin DEFINER (usar si no se controla el servidor)

`../dump-siresDB-migrado-limpio-sin-definer-20261105.sql` = la variante limpia sin `DEFINER=root@localhost`
en los 2 triggers y en el EVENT. Cada objeto queda a nombre del usuario que importa el dump.

Por qué: si `root@localhost` no existe en el servidor, todo INSERT o UPDATE sobre `Reservas` falla
(`The user specified as a definer ... does not exist`) y el EVENT no corre. Además, un usuario sin `SUPER`
no puede importar objetos con un DEFINER ajeno: la importación se corta en el primer trigger y faltan los
triggers, el EVENT y la tabla `migraciones`.

Verificado con un usuario que solo tiene `GRANT ALL ON base.*` (sin SUPER): importa completo,
los triggers rechazan solapamientos y el EVENT corre (cancela y pasa a A reembolsar).

Requisitos del usuario que importa: privilegios `TRIGGER` y `EVENT` sobre la base (incluidos en `ALL`).
Si el servidor tiene binlog (`log_bin = ON`) y el usuario no tiene SUPER, crear triggers requiere
`log_bin_trust_function_creators = 1`.

## Actualización: EVENTs de late fee y no-show (scripts/02)

Sin acceso al crontab del servidor, los crons PHP no corren. `../scripts/02_eventos_late_fee_y_no_show.sql`
crea `cargo_late_checkout` y `liberacion_no_show`: la misma lógica de los Services PHP, ya corregida,
dentro de MariaDB (registrado como `007_eventos_late_fee_y_no_show`). Instalación: ver `../LEEME.md`.
