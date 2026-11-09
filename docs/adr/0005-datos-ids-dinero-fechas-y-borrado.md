# ADR-0005: Datos — PostgreSQL, identificadores, dinero, fechas y borrado

- **Fecha:** 2026-11-09
- **Estado:** Propuesta
- **Alcance:** esquema de base de datos y modelos

## Contexto

El cpanel de origen guardaba contraseñas en texto plano, usaba varios estados contradictorios por columnas sueltas, mezclaba esquemas viejos y nuevos, escribía columnas de idioma triplicadas y no versionaba el esquema. Se parte de cero y conviene fijar desde el inicio cómo se guarda cada tipo de dato.

## Decisión

| Tema | Regla |
|---|---|
| Base de datos | **PostgreSQL** (mismo motor en desarrollo, pruebas y producción). Migraciones versionadas en `database/migrations` |
| Identificadores | `uuid` con `HasUuids` (ordenado por tiempo). No se exponen enteros consecutivos |
| Dinero | **Entero en unidades menores** (centavos) y código de moneda ISO (`MXN`, `USD`); objeto `Money` en `app/Support/Money`. Nunca `float` |
| Fechas | Instantes en **UTC**, con zona horaria; la zona del evento se guarda en el evento y se aplica en los bordes. La fecha civil (un cumpleaños) y el periodo de vigencia se modelan aparte |
| Estados | `enum` de PHP con cast sobre una columna `string` con `CHECK`. Nunca varios booleanos que se contradigan. Las transiciones se hacen en Actions |
| Borrado | `SoftDeletes` en entidades con historial o evidencia (propuestas, pagos, permisos); las eliminadas se filtran siempre. Las tablas de relación pura pueden borrarse |
| Textos traducibles | `spatie/laravel-translatable` (un registro, varios idiomas). Idioma base español como respaldo |
| Contraseñas | Hash de Laravel; nunca se guardan, devuelven ni exportan en claro |
| Archivos | Solo la ruta y metadatos en base; el archivo en disco privado (`app/CLAUDE.md` §8) |
| Relaciones | Claves foráneas reales y tabla de relación para muchos a muchos. Nada de listas separadas por comas ni arrays JSON para relaciones |
| JSON | Solo para datos realmente flexibles y validados (`jsonb`), nunca para sustituir entidades consultables |

## Alternativas consideradas

| Alternativa | Por qué se descartó |
|---|---|
| MySQL/MariaDB | Válido, pero PostgreSQL da mejores restricciones, `jsonb`, búsqueda de texto y RLS (ADR-0003, DA-2) |
| Enteros autoincrementales | Predecibles; facilitan enumerar recursos si falla una autorización |
| Dinero en `decimal` | Viable, pero obliga a cuidar la aritmética; el entero en unidades menores es más robusto |

## Consecuencias

**Positivas:** integridad garantizada por la base; sin errores de redondeo; historial conservado; el agente tiene reglas claras para cada migración.

**Negativas o riesgos:** el borrado lógico exige recordar el filtro (lo cubre `SoftDeletes`); los UUID ocupan más espacio que los enteros.

**Qué cambia para el equipo:** toda migración sigue `docs/database-design.md`, con propuesta previa.

## Decisiones abiertas

- **DA-1:** ¿soporte de multimoneda por evento desde el inicio? Se recomienda **sí** (campo `currency` en el evento y en cada cobro), aunque la primera versión use solo `MXN`.
- **DA-2:** ¿tablas de historial (`*_history`) o solo `spatie/laravel-activitylog`? Se recomienda **activitylog** para auditoría y tablas propias únicamente para historial de negocio (cambios de precio, estados de un pago).

## Referencias

- `docs/database-design.md`, `app/CLAUDE.md` §5
