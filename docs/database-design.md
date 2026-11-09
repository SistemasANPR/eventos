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
8. El agente genera la migración; **solo el humano la aplica contra una base real** (en despliegue, con aprobación manual; `docs/operacion.md`).

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
