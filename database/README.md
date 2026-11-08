# Base de datos de SIRES

| Archivo | Para qué |
|---|---|
| `sires-demo.sql.gz` | Base completa para instalar: estructura 1.8.0 (migraciones 000 a 016), catálogos y datos de demo inventados. **Sin EVENTs**: los crean los scripts 01 y 02. |
| `scripts/` | Scripts a correr **después** de importar el dump, en orden (01 a 04). |
| `migraciones/` | Historial de cambios de la base (`up` / `down`). Ya están aplicados en el dump y registrados en la tabla `migraciones`. Sirven para actualizar una base existente o para revertir un cambio. |

## Datos de demo

Todos los datos son inventados. Los cinco usuarios tienen la contraseña **`Sires2026`**:

| Rol | Usuario | Qué puede probar |
|---|---|---|
| Administrador | `admin@hotel.com` | Todo, incluidos empleados, configuración, reembolsos y procesos automáticos |
| Recepcionista | `recepcion1@hotel.com`, `recepcion2@hotel.com` | Reservas, check-in/out, pagos, clientes, planning |
| Gerente | `gerencia@hotel.com` | Lo de recepción más habitaciones (alta, mantenimiento), traslados, extensiones y auditoría |
| Personal de Limpieza | `limpieza1@hotel.com` | Lista de limpieza (hay 2 habitaciones sucias: 103 y 202, esta urgente) |

Además trae:

- 10 habitaciones (101 a 205) de los cuatro tipos, con su precio por noche.
- 6 clientes, 3 argentinos y 3 extranjeros (Uruguay, Chile y Brasil), con correos `@example.com`.
- Las políticas vigentes en `Configuracion` y los datos del hotel para los comprobantes (Hotel Demo SIRES).
- Ninguna reserva, pago, comprobante ni registro de auditoría: cada persona carga lo que quiera.

Los mails al huésped salen a los correos de los clientes. Con los de demo (`@example.com`) no le llegan a nadie.

## Instalación

Importar el dump **reemplaza** todas las tablas de la base destino. Si ya tiene datos, hacer un backup antes.

1. Crear la base y un usuario para la app (con un usuario administrador de MariaDB):

   ```sql
   CREATE DATABASE siresDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
   CREATE USER 'sires'@'localhost' IDENTIFIED BY 'una-clave-segura';
   GRANT ALL ON siresDB.* TO 'sires'@'localhost';
   ```

2. Importar el dump **con el usuario de la app** (el de `.env`):

   ```bash
   zcat database/sires-demo.sql.gz | mariadb -u sires -p siresDB
   ```

   Con DBeaver: descomprimir antes (`gunzip -k database/sires-demo.sql.gz`) y usar
   Tools > Restore database con el `.sql`.

3. Correr los scripts en orden. Usan `DELIMITER`: con DBeaver, abrirlos con Tools > Execute script, no con el editor SQL.

   ```bash
   mariadb -u sires -p siresDB < database/scripts/01_reparar_triggers_y_corte.sql
   mariadb -u sires -p siresDB < database/scripts/02_eventos_late_fee_y_no_show.sql
   mariadb -u root -p         < database/scripts/03_activar_scheduler.sql
   mariadb -u sires -p -t siresDB < database/scripts/04_verificar.sql
   ```

   | Script | Qué hace | Si falla |
   |---|---|---|
   | `01_reparar_triggers_y_corte.sql` | Crea los triggers (solapamiento de reservas y limpieza) y el EVENT `corte_pagos` a nombre del usuario que lo corre | Falta privilegio TRIGGER o EVENT |
   | `02_eventos_late_fee_y_no_show.sql` | Crea los EVENTs `cargo_late_checkout` y `liberacion_no_show` | Falta privilegio EVENT |
   | `03_activar_scheduler.sql` | `SET GLOBAL event_scheduler = ON` (necesita SUPER: correrlo como root) | Ver "Procesos automáticos" |
   | `04_verificar.sql` | Diagnóstico de solo lectura: cada fila dice OK o qué falta | No modifica nada |

   01 y 02 tienen que correrse con el mismo usuario que usa la app: los triggers y EVENTs quedan a su nombre.

## Procesos automáticos

Los EVENTs (corte de pagos, late checkout y liberación de No-Show) solo corren con `event_scheduler = ON`.
`SET GLOBAL` se pierde al reiniciar MariaDB; para dejarlo fijo, en el `.cnf` del servidor:

```ini
[mysqld]
event_scheduler = ON
```

El inicio del administrador avisa si el planificador está apagado o si un proceso no corre
(Auditoría > Procesos automáticos).

La hora de la base tiene que ser la de Buenos Aires (`04_verificar.sql` la muestra): los EVENTs usan `NOW()`.

## Cambios de base (migraciones)

Cada cambio de esquema es un par `NNN_nombre.up.sql` / `NNN_nombre.down.sql` en `migraciones/`, aditivo
siempre que se pueda. El `up` termina registrándose en la tabla `migraciones`. Para actualizar una base
existente: correr los `up` que no figuren en `SELECT nombre FROM migraciones` en orden, y después los
scripts 01, 02 y 04. `migraciones/README.md` y `migraciones/events/` documentan los primeros cambios
(000 a 006) y los EVENTs originales que reemplazaron.

Si se cambia un EVENT, actualizar el script 01 o 02 y el método PHP equivalente
(`PagoService::ejecutarCortePagos()`, `PagoService::aplicarLateCheckout()`, `ReservaService::liberarNoShowConPago()`).
