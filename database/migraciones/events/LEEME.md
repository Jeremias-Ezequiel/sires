# events/

Copias de los EVENTs originales, extraídas de `DATABASE_OCT/siresDB-eventos.sql` (v0.7.0, 2026-10-01).
Los `down` de `005` y `006` los recrean desde una copia inline de estos archivos.

| Archivo | Estado tras migrar |
|---|---|
| `corte_pagos_48hs.original.sql` | Reemplazado por `corte_pagos` (005) |
| `late_checkout_fee.original.sql` | Eliminado (006), queda `cron/late-checkout-fee.php` |
| `liberar_no_show.original.sql` | Eliminado (006), queda `cron/no-show-release.php` |

Confirmar que el servidor tiene estas mismas versiones antes de migrar:

```sql
SHOW EVENTS;
SHOW CREATE EVENT corte_pagos_48hs\G
SHOW CREATE EVENT late_checkout_fee\G
SHOW CREATE EVENT liberar_no_show\G
```
