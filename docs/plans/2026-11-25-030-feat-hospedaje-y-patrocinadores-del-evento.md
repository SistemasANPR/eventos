# feat: Hospedaje y patrocinadores del evento

- **Fecha:** 2026-11-25
- **Estado:** En curso
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

El equipo del evento necesita registrar dónde puede hospedarse la gente y qué organizaciones patrocinan el evento. Se agregan dos secciones al menú lateral del evento, «Hospedaje» y «Patrocinadores», cada una con su lista y su alta, edición y eliminación, igual que «Ponentes» (plan 021).

**Dentro:** hospedaje (nombre, dirección, descripción, enlace de reserva, tarifa o código de descuento, orden); patrocinadores (nombre, logotipo opcional, nivel, sitio web, orden); permisos y aislamiento por organización; auditoría; textos en español e inglés.

**Fuera:** mostrar hospedaje o patrocinadores en la landing o en el sitio público; foto del hotel; paquetes de patrocinio, contratos, cobranza y portal del patrocinador (R4, E-25); bloques de hotel con cupo (R4, E-28).

## 2. Punto de partida

El 2026-11-25 no existe ninguna tabla, modelo ni pantalla de hospedaje ni de patrocinadores. `docs/producto/glosario.md` ya nombra `Hotel` y `Sponsor` como conceptos del evento; la versión completa está prevista en R4. La sección «Ponentes» (plan 021) es el patrón de lista, modal y permisos que se reutiliza.

## 3. Decisiones

### DA-1 · Nombres y ámbito — se recomienda **`Lodging` y `Sponsor`, ámbito evento**

Cada ficha es de UN evento (con `organization_id` y `event_id`), como los ponentes. Cerrada el 2026-11-25: se usa `Lodging` para el hospedaje porque `Hotel` del glosario se reserva para el bloque con cupo de R4.

### DA-2 · Permisos — se recomienda **`lodgings.manage`, `sponsors.manage` y sus `.delete`**

Ver, crear y editar con `*.manage` (`org_admin` y `organizer`); eliminar con `*.delete` (solo `org_admin`), igual que `speakers`. El `reviewer` no ve nada. Cerrada el 2026-11-25.

### DA-3 · Logotipo — se recomienda **el disco `media` y `StoreProgramImage`**

Mismo manejo que la foto del ponente y el logotipo del evento: se decide por el contenido (JPEG, PNG o WebP; sin SVG), con tope de 2 MB, bajo la carpeta del evento. Cerrada el 2026-11-25: el hotel no lleva foto en esta versión.

### DA-4 · Borrado — se recomienda **físico**

No hay datos personales ni historia que conservar; se elimina la fila y, en patrocinadores, su logotipo. La auditoría conserva identificadores. Cerrada el 2026-11-25.

## 4. Contrato

Sin rutas JSON ni webhooks. Cambios de datos: tablas `lodgings` y `sponsors` (migración `2026_11_28_100000`), con RLS (`tenant_isolation`), clave foránea compuesta `(event_id, organization_id)` hacia `events` y disparador de `organization_id` inmutable. Páginas del panel: `/{evento}/lodgings` y `/{evento}/sponsors`. Migración de datos aparte para sembrar los permisos nuevos en las organizaciones existentes.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Ver, crear y editar: `org_admin` y `organizer`. Eliminar: `org_admin`. `reviewer`: nada. |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `LodgingPolicy` y `SponsorPolicy`; `LodgingSponsorPolicyTest`, `LodgingActionsTest`, `SponsorActionsTest` y las pruebas de página. |
| ¿Hay modelos nuevos con datos de una organización? | Sí, `Lodging` y `Sponsor`, con `BelongsToOrganization`, RLS y clave foránea compuesta. |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No; el evento sale de la ruta y uno de otra organización responde 404. |
| ¿Se guardan datos personales nuevos? | No: nombres comerciales, direcciones de negocio y enlaces. No se pide contacto de personas. |
| ¿Se suben archivos? | Solo el logotipo del patrocinador: JPEG, PNG o WebP, hasta 2 MB, disco `media` privado. |
| ¿Hay dinero, pagos o webhooks? | No. La tarifa es un texto informativo. |
| ¿Se envían correos o notificaciones? | No. |
| ¿Qué se registra en la auditoría? | `lodging_created|updated|deleted` y `sponsor_created|updated|deleted` con identificadores y nombres de campos cambiados, sin valores. |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F1 | Tablas, modelos, permisos, Policies y Actions con pruebas | M |
| F2 | Páginas del panel, entradas del menú y textos es/en con pruebas | M |

## 7. Criterios de aceptación

- **CA-030-1:** *Dado* un administrador en un evento, *cuando* agrega un hospedaje con nombre, *entonces* aparece en la lista del evento con su orden y quedan auditados sus identificadores.
- **CA-030-2:** *Dado* un hospedaje, *cuando* se guarda con un enlace de reserva que no es http o https, o con el nombre vacío, *entonces* se informa el error junto al campo y no se guarda nada.
- **CA-030-3:** *Dado* un hospedaje, *cuando* el administrador lo edita o lo elimina, *entonces* el cambio se guarda y se audita, y los demás hospedajes no cambian.
- **CA-030-4:** *Dado* un administrador en un evento, *cuando* agrega un patrocinador con nombre, nivel, sitio web y logotipo, *entonces* aparece en la lista y el logotipo queda en el disco `media` del evento.
- **CA-030-5:** *Dado* un patrocinador, *cuando* el logotipo no es una imagen aceptable o el sitio web no es http o https, *entonces* se informa el error junto al campo y no se guarda nada ni queda un archivo huérfano.
- **CA-030-6:** *Dado* un patrocinador con logotipo, *cuando* se reemplaza o se quita el logotipo, o se elimina el patrocinador, *entonces* el archivo anterior se borra del disco.
- **CA-030-7:** *Dado* un `organizer`, *cuando* intenta eliminar un hospedaje o un patrocinador, *entonces* se le niega; un `reviewer` no puede ver ni gestionar ninguno de los dos.
- **CA-030-8:** *Dado* un evento de otra organización, *cuando* se abre su lista de hospedaje o patrocinadores, *entonces* responde 404 y ninguna Action modifica sus fichas.
- **CA-030-9:** *Dado* un usuario con permiso, *cuando* abre un evento, *entonces* el menú lateral muestra «Hospedaje» y «Patrocinadores» y cada uno lleva a su lista; sin permiso no aparecen.
- **CA-030-10:** *Dado* la matriz de roles, *cuando* se siembra una organización, *entonces* `lodgings.manage` y `sponsors.manage` van a `org_admin` y `organizer`, y `lodgings.delete` y `sponsors.delete` solo a `org_admin`.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Una URL con esquema peligroso (`javascript:`) guardada como enlace | La regla `url:http,https` la rechaza en el servidor. |
| Un archivo huérfano si falla el guardado | Se borra en el `catch`; el archivo anterior se borra solo tras confirmar la transacción. |
| Organizaciones existentes sin los permisos nuevos | Migración de datos que vuelve a sembrar los roles (idempotente). |

## 9. Registro de avance

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F1 | — | 2026-11-25 | Pendiente de commit |
| F2 | — | 2026-11-25 | Pendiente de commit |
