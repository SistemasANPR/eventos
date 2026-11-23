# Diseño y evolución de la base de datos

**Ámbito:** cualquier cambio que cree, modifique, migre o elimine datos persistentes.
**Objetivo:** preservar integridad, aislamiento entre organizaciones y capacidad de evolución de los módulos.

> Prevalece sobre cualquier otro documento para datos persistentes. Decisiones base en ADR-0003 (tenancy) y ADR-0005 (tipos de datos).

## 1. Antes de modificar el esquema

1. Inspecciona el esquema actual, las migraciones, restricciones, índices, consultas y reglas del módulo afectado. Comprueba si la información ya existe con otro nombre o pertenece a otra entidad.
2. Define el hecho de negocio que se guardará, quién es dueño del dato, su **ámbito** (plataforma, organización, evento, persona), su ciclo de vida y quién puede cambiarlo.
3. Distingue si es un atributo de una entidad existente, una entidad nueva, una referencia por clave foránea, una relación muchos a muchos o un evento histórico. Una tabla pivote con atributos, vigencia o estados es una entidad de relación con reglas propias.
4. Presenta la propuesta de la sección 6 al responsable **antes** de escribir la migración. Pregunta solo si falta una regla de negocio que cambie relaciones o restricciones; no la inventes.

## 2. Modelado relacional

- Modela entidades y dependencias funcionales; aplica 1FN a 3FN cuando corresponda. No crees tablas por formalismo: explica las excepciones.
- No guardes listas de identificadores separados por comas ni relaciones en arrays o JSON. Usa claves foráneas y tablas de relación. `jsonb` solo para datos realmente flexibles, validados y justificados.
- Separa **cuenta de acceso, persona, organización, rol y permiso**. El correo no es clave primaria ni prueba suficiente de identidad o autorización.
- Toda relación declara su clave foránea y su comportamiento al borrar o desactivar. Prefiere desactivar o conservar historial cuando borrar destruya evidencia de pagos, vigencias o permisos.
- Distingue lo **ofrecido** (tarifa, tipo de boleto, catálogo) de lo **contratado** y su historial. Un cambio de la oferta no altera retroactivamente lo ya contratado sin una regla explícita.
- Separa **hechos financieros** (cargo, pago, devolución, comisión) de **estados derivados**. Guarda la referencia externa del proveedor de pagos de forma única e idempotente. Importes en unidades menores enteras con código de moneda (ADR-0005).
- Distingue fecha civil, instante y periodo de vigencia. Usa un estado enumerado en lugar de varios booleanos que puedan contradecirse.

## 3. Aislamiento entre organizaciones

(ADR-0003.)

- Toda tabla con datos de una organización lleva `organization_id uuid NOT NULL` con clave foránea. Los datos globales de la plataforma se mantienen separados y se declaran en `TenantModels::GLOBAL_MODELS`.
- Las claves únicas y los índices incluyen el ámbito: `unique(organization_id, slug)`, no `unique(slug)`.
- Evita referencias cruzadas entre organizaciones: las claves foráneas compuestas o las restricciones comprueban el mismo ámbito en ambos extremos, además de las validaciones de aplicación. Ejemplo: una propuesta y su evento deben tener el mismo `organization_id`.
- Con RLS de PostgreSQL: el contexto se establece con `SET LOCAL app.organization_id` por transacción y se limpia al terminar; se prueban los accesos permitidos y los denegados. RLS complementa la autorización, no la reemplaza.
- Soporte y exportación de datos requieren autorización explícita, alcance acotado y auditoría.
- **`organization_id` es inmutable en la base:** toda tabla con tenant lleva un trigger `<tabla>_organization_id_immutable` (`BEFORE UPDATE OF organization_id`) que ejecuta `prevent_organization_id_change()`, para que un UPDATE por builder, SQL o `saveQuietly` no pueda mover una fila a otra organización. La migración de la tabla nueva lo declara (`DB::unprepared`; la función usa `CREATE OR REPLACE`) y `OrganizationIdImmutableTest` falla si falta. Los insertos masivos (`insert`, `upsert`), `DB::table` y `withoutGlobalScopes` quedan prohibidos fuera de `app/Support/Tenancy` por la guardiana `tests/Arch/TenantBypassTest.php`.

## 4. Restricciones, índices e historial

- Tipos adecuados; `NOT NULL` cuando el dato sea obligatorio; `UNIQUE`, `CHECK` y claves foráneas para las reglas que la base puede garantizar. No dejes invariantes centrales solo en código.
- Índices según consultas, relaciones y volumen. Evalúa el costo de escritura; no indexes todo por defecto. Considera índices compuestos y parciales por organización y estado.
- Conserva el historial necesario para explicar altas manuales, renovaciones, descuentos, devoluciones y cambios de permisos. Distingue el estado actual de las acciones que lo produjeron.
- Para eventos entre módulos y efectos externos, define identificadores de evento, reintentos e idempotencia. Con el patrón *outbox*, el hecho de negocio y su publicación pendiente se registran en la misma transacción.
- La desnormalización, la caché o las vistas materializadas requieren un caso medido, una fuente de verdad y un mecanismo de actualización o reconstrucción.

## 5. Migraciones y despliegue

1. Todo cambio de esquema se versiona en una migración del repositorio (`php artisan make:migration`). No se modifica producción a mano. **Una migración ya aplicada no se edita**: se añade otra.
2. Separa **migraciones de esquema** de **migraciones de datos** (transformaciones), y no las mezcles en un mismo archivo.
3. Revisa los datos existentes antes de imponer una restricción. Especifica respaldo, transformación, validación y comportamiento ante registros incompatibles.
4. Cambios incompatibles en secuencia (*expandir y contraer*): añadir estructura; adaptar lecturas y escrituras; migrar y verificar datos; retirar lo anterior en una entrega posterior. Considera que convivan versiones distintas de la aplicación.
5. Evita migraciones que bloqueen tablas grandes sin evaluar su impacto (índices con `CONCURRENTLY`, columnas nulas primero).
6. Describe cómo volver a una versión operativa; no prometas revertir automáticamente una transformación destructiva. Cada migración implementa `down()` cuando sea posible.
7. Actualiza modelos, fábricas, consultas, documentación y pruebas en el mismo cambio.
8. Funciones y *triggers* de PostgreSQL (`DB::unprepared`, p. ej. `prevent_organization_id_change()`): `migrate:fresh` borra las **tablas** pero **no las funciones**, así que una base «limpia» puede conservar una versión vieja de la función. Por eso las funciones se crean con `CREATE OR REPLACE FUNCTION` (el *trigger* de una tabla sí desaparece con ella) y `down()` las retira con `DROP FUNCTION IF EXISTS`; nunca se asuma un entorno sin restos de la ejecución anterior. Para vaciar de verdad una base local de desarrollo, recrear el esquema (`DROP SCHEMA public CASCADE; CREATE SCHEMA public;`) y no solo `migrate:fresh`. Una función compartida se redefine en una migración nueva, no editando la aplicada (regla 1).
9. El agente genera la migración; **solo el humano la aplica contra una base real** (en despliegue, con aprobación manual; `docs/operacion.md`).

## 6. Formato obligatorio de propuesta antes de implementar

Al añadir un tipo de dato o cambiar el esquema, presenta brevemente:

1. **Necesidad:** hecho de negocio, módulo dueño y ciclo de vida.
2. **Inspección:** tablas, columnas, migraciones y relaciones existentes que revisaste.
3. **Modelo propuesto:** tablas y columnas nuevas o reutilizadas, cardinalidades, **ámbito** (plataforma, organización, evento) y por qué columna, tabla o pivote.
4. **Integridad:** tipos, claves, restricciones, índices, permisos e historial.
5. **Cambio:** migración, tratamiento de datos actuales, compatibilidad durante el despliegue y verificación.
6. **Alternativa descartada:** al menos una, con la razón concreta.
7. **Preguntas de negocio:** únicamente las que impidan definir correctamente el esquema.

Tras la aprobación, implementa la migración y el código, verifica los casos significativos y resume los resultados. Para cambios pequeños la propuesta puede ser breve; la inspección del esquema y el respeto de las restricciones nunca se omiten.

## 7. Datos de prueba y de arranque

- **Fábricas** para pruebas: datos ficticios, siempre con `organization_id` aceptable. **Seeders** solo para datos de arranque (catálogos, roles); nunca datos reales ni personales.
- Los seeders y comandos establecen el contexto de organización con `CurrentOrganization::run()`.
- Nunca se copian datos reales de producción a desarrollo sin anonimizar (`docs/privacidad.md`).

## 8. Propuestas de datos aprobadas por plan

Cada propuesta sigue el formato de la sección 6 y viaja con su plan. Las anteriores viven dentro de sus planes; esta sección recoge las que se centralizan aquí.

### 8.1 Plan 021: ponentes y actividades del evento (aprobada el 2026-11-17 con el plan; DA-1 a DA-17)

1. **Necesidad:** el programa de un evento necesita a las personas que presentan (**ponentes**) y a lo que ocurre (**actividades**) como registros propios, creables a mano o al aceptar una propuesta, y enlazables entre sí. Dueño: módulo de programa (`app/Actions/Program/`). Ciclo de vida: nacen publicados; se ocultan por reversa o a mano; borrado lógico.
2. **Inspección:** `contacts` (organización: `full_name`, `email`, `country`), `submissions` (`origin`, `track_id`, estados con `accepted`), `submission_participants` (copia congelada al enviar, `full_name` único, ligada a `contacts`). No existía ninguna tabla de ponentes ni de actividades. `activity_log` es la bitácora (no el programa).
3. **Modelo propuesto** (todas con `id uuid`, `organization_id` y `event_id`, y claves foráneas compuestas `(event_id, organization_id)` hacia `events`, como `submissions`; ámbito **evento**; trigger `<tabla>_organization_id_immutable` en cada una):
   - `speakers`: `contact_id` nulo (vínculo opcional a la persona de la organización), `first_name varchar(100)` y `last_name varchar(100)` (`NOT NULL`), `email varchar(254)` nulo, `country` nulo, `company varchar(160)` nulo, `job_title varchar(160)` nulo, `tagline varchar(160)` nulo, `bio text` nulo (HTML saneado), `photo_path` nulo, `social_links jsonb` (`[]` por defecto), `is_published bool` (`true`), `hidden_by_reversal_at timestamptz` nulo, `created_by_submission_id` nulo, `deleted_at`, `timestamps`.
   - `event_activities`: `submission_id` nulo y único (una actividad por propuesta), `title varchar(200)`, `starts_on` y `ends_on` (`date`), `starts_at` y `ends_at` (`time`), nulos juntos, `icon_path` nulo, `image_path` nulo, `summary varchar(200)` nulo, `description text` nulo (HTML saneado), `is_published bool` (`true`), `hidden_by_reversal_at` nulo, `deleted_at`, `timestamps`.
   - `speaker_categories` y `activity_categories`: `name varchar(100)`, `position`; catálogos independientes entre sí y entre eventos. Pivotes `speaker_speaker_category` y `activity_activity_category` (clave primaria compuesta).
   - `activity_speaker`: `activity_id`, `speaker_id`, `organization_id`, `event_id`, `position` (smallint, 0 por defecto); clave primaria `(activity_id, speaker_id)`; ambos lados del mismo evento por clave foránea compuesta. Es un pivote con orden, no una entidad con reglas propias.
   - `submission_participants`: se agregan `first_name` y `last_name`; `full_name` se conserva como el texto «nombre apellido» de las ya enviadas.
   - Por qué columnas, tablas y pivotes: las categorías son muchos a muchos (DA-7) y ponente–actividad también (DA-8), así que van en pivotes y no en arreglos. `social_links` es `jsonb` porque es una lista corta, validada, sin consultas por red ni relaciones (excepción justificada a la sección 2). Ponente por evento (no `Contact`) porque editar la ficha de un evento no debe cambiar la de otro (DA-1).
4. **Integridad:**
   - Único `(event_id, lower(email))` en `speakers` donde `email` no es nulo y `deleted_at` es nulo (DA-5). Los alias `+` no se normalizan.
   - Único `(event_id, lower(name))` en cada tabla de categorías. Único `submission_id` en `event_activities`.
   - Propuesta y lo que nace de ella son del mismo evento (F5): `event_activities.submission_id` y `speakers.created_by_submission_id` van por clave foránea compuesta `(propuesta, evento, organización)` hacia `submissions`, que gana `unique(id, event_id, organization_id)`. Con la columna nula el vínculo es opcional.
   - `CHECK` en `event_activities`: `starts_on`, `ends_on`, `starts_at` y `ends_at` son todos nulos o todos no nulos, y el fin no es anterior al inicio (`ends_on >= starts_on`, y si es el mismo día `ends_at >= starts_at`). La obligatoriedad de las cuatro al dar de alta a mano la exige el Form Request (DA-4).
   - `CHECK` de `speakers.social_links`: es un arreglo JSON (`jsonb_typeof = 'array'`); red única y dominio por red los valida el Form Request (DA-17).
   - **Excepción a «fechas en UTC» (DA-12):** `starts_on`, `ends_on`, `starts_at` y `ends_at` son fecha y hora **locales del evento**, sin zona; se interpretan siempre con la zona horaria del evento (`events`). Un programa se lee en la hora del lugar y convertir a UTC obligaría a recalcular si el evento cambia de zona. Los demás instantes (`hidden_by_reversal_at`, `deleted_at`, `timestamps`) siguen en UTC.
   - Borrado lógico en `speakers` y `event_activities`; borrar uno no borra al otro (CA-021-16). Los pivotes se limpian al borrar físicamente.
   - Permisos y aislamiento: modelos con `BelongsToOrganization`; `speakers.manage`, `activities.manage` (`org_admin` y `organizer`) y `speakers.delete`, `activities.delete` (`org_admin`).
   - Historial: auditoría con ids, sin datos personales (alta, edición, publicación, eliminación, paso desde propuesta y reversa).
5. **Cambio:** migraciones de esquema separadas de datos; una por bloque (catálogos, ponentes, actividades y pivotes, `submission_participants`), cada una con su `down()`. Compatibilidad: todo lo nuevo es aditivo; en `submission_participants` las columnas nuevas son nulas en filas existentes y `NOT NULL` solo se exige a las nuevas en la aplicación (las anteriores se parten al aprobar con «primera palabra, resto» y el organizador corrige). Sin datos que transformar en tablas existentes. Verificación: pruebas de esquema (restricciones rechazan lo inválido), prueba de aislamiento y `OrganizationIdImmutableTest`. Las migraciones las aplica el humano.
6. **Alternativa descartada:** ponente a nivel de organización compartido entre eventos (el `Contact` actual): una edición de la ficha en un evento alteraría lo ya publicado en otro, y el aislamiento por evento se vuelve difícil de garantizar. Se conserva solo el vínculo opcional `contact_id` para sugerir datos. Otra descartada: una sola tabla de categorías compartida, porque los catálogos de ponentes y de actividades son distintos (DA-7).
7. **Preguntas de negocio:** ninguna abierta para el esquema. Disco `media` privado, servido por la aplicación (confirmado el 2026-11-18).

### 8.2 Plan 014 F1: verificación en dos pasos (aprobada el 2026-11-23 con el plan; alcance F0 y F1)

1. **Necesidad:** proteger con un segundo factor las cuentas con acceso a datos de autores (`org_admin`, `organizer`, `reviewer`, plataforma); dueño: plataforma. La RLS, `retention_policies`, `anonymization_log` y `anonymized_at` de la propuesta general del plan quedan para F2 a F5.
2. **Modelo (ámbito plataforma, sin `organization_id`):** tres columnas nulas en `users`: `app_authentication_secret` (`text`, cifrada por el modelo con `encrypted`), `app_authentication_recovery_codes` (`text`, arreglo de hashes cifrado con `encrypted:array`) y `two_factor_confirmed_at` (`timestamptz`, UTC). Permiso nuevo `two_factor.reset` para `org_admin` (migración de datos aparte). Sin tablas nuevas: la verificación fuera de banda del reinicio por plataforma vive en `activity_log` (propiedades del evento `two_factor_reset`).
3. **Integridad:** sin restricciones nuevas; las columnas son `hidden` en el modelo y nunca se registran ni se exportan; el modelo impide desactivarla por uno mismo; el reinicio es una Action autorizada, auditada y notificada.
4. **Cambio:** aditivo y reversible (`down()` quita las columnas; se pierden los secretos y todos tendrían que reconfigurarla). Al aplicarse, todo usuario existente queda sin 2FA y se le fuerza a configurarla (plan 014 §4.2). Las migraciones las aplica el humano, primero en `staging`.
5. **Alternativa descartada:** tabla `user_two_factor` aparte: añadiría un join en cada inicio de sesión y un modelo más para un dato 1:1 con `users`; Filament espera las columnas en el propio modelo.
