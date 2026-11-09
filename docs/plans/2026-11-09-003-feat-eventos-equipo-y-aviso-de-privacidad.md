# feat: eventos, equipo y aviso de privacidad

> Épica E-03 de `docs/plan-de-desarrollo.md` (R0). El organizador configura su evento, escribe su propio aviso de privacidad por idioma y da de alta a su equipo, todo desde el panel y sin tocar código ni base de datos. Ningún evento ni texto se siembra: "Congreso Parques 2027" se captura en la interfaz.

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

Dar a cada organización un evento propio (tipo, fechas, sede, zona horaria, idiomas, moneda y estado), un aviso de privacidad versionado por evento y un equipo con roles reales, de modo que los planes de convocatoria, formularios y envío (004 a 006) tengan dónde colgar sus datos y un aviso que enlazar y registrar como consentimiento.

**Dentro:** modelo `Event` con tenant; recurso de Filament para crear, editar, publicar y archivar eventos; `PrivacyNotice` versionado por evento y traducible (es, en, pt), con publicación inmutable y página pública; gestión del equipo de la organización (alta con rol, cambio de rol, desactivación) con enlace para establecer contraseña; página pública del evento publicado con fechas y sede.

**Fuera:** convocatorias, ejes y formularios (planes 004 y 005); consentimiento por propuesta (H-006-6); `event_assignments` y roles por evento (plan 011, R1); plantillas y duplicado de evento (E-18, R2); módulos activables por evento (R2); sede y salas como entidades reutilizables (`Venue`, `Room`, R3); varias zonas o estados avanzados del evento (en curso, cerrado) y multimoneda real (el campo existe, solo `MXN` en R0); 2FA (plan 014).

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-003-1 | Como organizador, quiero crear y editar un evento (nombre, tipo, fechas, sede, zona horaria, idiomas, moneda y estado), para configurar mi edición sin código | M | H-001-3 |
| H-003-2 | Como organizador, quiero escribir y versionar el aviso de privacidad de mi evento por idioma, para cumplir con la ley desde la plataforma | M | H-003-1 |
| H-003-3 | Como administrador de organización, quiero dar de alta a mi equipo con rol y enlace para establecer contraseña, para trabajar con permisos reales | M | H-001-3, H-008-6 |
| H-003-4 | Como visitante, quiero ver el evento publicado con sus fechas y sede, para conocerlo | S | H-003-1 |

## 2. Punto de partida

Medido el 2026-11-09: no existe el proyecto Laravel ni modelos. El glosario define `Event` (ámbito: organización) y `MailTemplate`; faltan los términos `PrivacyNotice` y la nota de que el aviso lo define cada evento (se propone añadirlos al glosario). `docs/privacidad.md` exige aviso de privacidad enlazado en cada formulario público, definido por la organización, y consentimiento registrado con texto y versión. Este plan depende de la organización, los roles y las Policies del plan 001 y del envío por cola del plan 008 (H-008-6) para el correo del equipo; mientras no exista, las pruebas usan `Mail::fake()`.

## 3. Decisiones

### DA-1 · Idiomas del evento — se recomienda **lista cerrada es, en y pt, con idioma base**

El evento declara los idiomas que ofrece y uno por defecto (respaldo en español, ADR-0005). Los textos traducibles usan `spatie/laravel-translatable`. Se guarda como `jsonb` validado (valores de configuración, no una relación).

### DA-2 · Estados del evento en R0 — se recomienda **`draft`, `published` y `archived`**

Enum de PHP sobre columna `string` con `CHECK` (ADR-0005). `en curso` y `cerrado` llegan con los módulos que los necesitan. Un evento en borrador o archivado no se ve en público.

### DA-3 · Aviso de privacidad y publicación del evento — se recomienda **no exigirlo para publicar el evento, sí para abrir una convocatoria**

El plan 004 (H-004-1) exige un aviso publicado antes de abrir una convocatoria; este plan solo lo crea y versiona.

### DA-4 · Versionado del aviso — se recomienda **una fila por versión, inmutable al publicarse**

Cada versión contiene todos los idiomas. Editar un aviso publicado crea una versión nueva; las propuestas guardan la versión aceptada (plan 006). Alternativa descartada: editar en sitio con historial de auditoría, porque no permite demostrar qué texto aceptó cada persona.

### DA-5 · Usuario existente en otra organización — se recomienda **adjuntar la membresía sin revelar que existe**

Al dar de alta un correo que ya es usuario de otra organización se crea la membresía y se avisa por correo; la respuesta al administrador es idéntica en ambos casos, para no filtrar datos entre organizaciones (ADR-0003 DA-3).

### DA-6 · Sede — se recomienda **texto en el evento en R0**

Nombre, dirección y ciudad como campos del evento; `Venue` y `Room` reutilizables llegan con el programa (R3).

## 4. Contrato

- **Clases:** `App\Models\Event`, `App\Models\PrivacyNotice`; enums `EventType` (`Congress`, `Forum`, `Workshop`, `Webinar`, `Expo`), `EventStatus` (`Draft`, `Published`, `Archived`) y `OrganizationRole`; Actions `CreateEvent`, `UpdateEvent`, `PublishEvent`, `ArchiveEvent`, `CreatePrivacyNoticeDraft`, `UpdatePrivacyNoticeDraft`, `PublishPrivacyNotice`, `InviteTeamMember`, `ChangeMemberRole`, `DeactivateMember`; `EventPolicy`, `PrivacyNoticePolicy`, `MembershipPolicy`.
- **Form Requests** (reutilizados por las Actions y por Filament): `StoreEventRequest`, `UpdateEventRequest`, `StorePrivacyNoticeRequest`, `InviteTeamMemberRequest`, `ChangeMemberRoleRequest`.
- **Interfaz:** panel de Filament (zona 1): `EventResource`, gestión del aviso dentro del evento y gestión del equipo de la organización. **Rutas web públicas** (Inertia, sin SSR): `GET /{organization:slug}/events/{event:slug}` y `GET /{organization:slug}/events/{event:slug}/privacy`, con binding acotado. Sin endpoints JSON ni webhooks nuevos (nada que añadir a `docs/api/endpoints.md`).
- **Notificaciones:** `team.set_password` (crítica) y el aviso de membresía a un usuario existente; se registran en el catálogo del plan 008 con su predeterminada es/en.
- **Propuesta de datos (`docs/database-design.md` §6):**
  - *Necesidad:* un evento por edición, un aviso versionado por evento y membresías con rol; dueños: eventos y privacidad; ciclo de vida: el evento se archiva, el aviso publicado se conserva como evidencia.
  - *Inspección:* sin tablas previas; reutiliza `organizations`, `organization_user`, roles y `activity_log` del plan 001.
  - *Modelo:* `events` (UUID; `organization_id`; `name` y `description` traducibles; `slug`; `type`; `starts_at` y `ends_at` en UTC; `timezone` IANA; `venue_name`, `venue_address`, `city`; `locales` jsonb; `default_locale`; `currency`; `status`; `deleted_at`). `privacy_notice_versions` (UUID; `organization_id`; `event_id`; `version`; `body` traducible; `published_at`; `published_by`). Ámbito de ambas: organización, con `event_id` explícito en el aviso (sin scope global de evento).
  - *Integridad:* `organization_id uuid NOT NULL` con clave foránea; `unique(organization_id, slug)` en `events`; `unique(event_id, version)` en el aviso; `CHECK` de `status`, `type`, `currency` (3 letras) y `ends_at >= starts_at`; clave foránea compuesta para que aviso y evento compartan `organization_id`; `SoftDeletes` en `events` (tiene evidencia); el aviso publicado no se borra ni se edita.
  - *Cambio:* migraciones nuevas sin datos previos; las genera el agente y solo una persona las aplica.
  - *Alternativa descartada:* un único aviso por organización, porque cada evento define el suyo.
  - *Preguntas de negocio:* ninguna bloqueante; `[PENDIENTE: texto del aviso del Congreso Parques 2027 y quién lo aprueba]` es contenido que captura el equipo en la interfaz.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Administrador de organización: crear, editar, publicar y archivar eventos; crear y publicar el aviso; gestionar el equipo. Miembro: ver eventos y avisos; editar eventos y borradores de aviso solo si su rol lo permite `[PENDIENTE: permisos finos del miembro]`; no gestiona el equipo ni publica el aviso. Plataforma: sin acceso por defecto. Visitante: solo ve eventos publicados y el aviso publicado |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `EventPolicy`, `PrivacyNoticePolicy`, `MembershipPolicy`; una prueba por rol y acción, permitido y denegado, en `tests/Feature/Events/`, `tests/Feature/Privacy/` y `tests/Feature/Team/` |
| ¿Hay modelos nuevos con datos de una organización? ¿Usan `BelongsToOrganization`? | Sí: `Event` y `PrivacyNotice`; ambos usan `BelongsToOrganization` y están cubiertos por las guardianas y por una prueba de aislamiento propia |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No en R0: los roles son de organización. El aviso se consulta por evento de forma explícita |
| ¿Se guardan datos personales nuevos? | Nombre y correo de integrantes del equipo (base: relación de trabajo; retención mientras dure la membresía y se anonimizan al darse de baja según `docs/privacidad.md`, que se actualiza en el mismo cambio). El texto del aviso no es dato personal |
| ¿Se suben archivos? | No aplica |
| ¿Hay dinero, pagos o webhooks? | No. El campo `currency` solo guarda `MXN` en R0 (ADR-0005 DA-1) |
| ¿Se envían correos o notificaciones? | `team.set_password` (crítica, por cola) y aviso de membresía a un usuario existente, ambos con predeterminada es/en |
| ¿Qué se registra en la auditoría? | Creación, edición, publicación y archivo de eventos; creación y publicación de versiones del aviso; alta, cambio de rol y baja de integrantes; envío del enlace de contraseña |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Propuesta de datos aprobada; enums y Policies con pruebas en rojo | S |
| F1 | `Event`, Actions, Form Requests y `EventResource` de Filament (H-003-1) | M |
| F2 | `PrivacyNotice` versionado, sanitización y página pública del aviso (H-003-2) | M |
| F3 | Gestión del equipo: alta, rol, baja y enlace para establecer contraseña (H-003-3) | M |
| F4 | Página pública del evento publicado (H-003-4) | S |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-003-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

### H-003-1 · Crear y editar un evento

- **CA-003-1:** *Dado* un administrador de la organización A, *cuando* crea un evento con nombre, tipo, fechas, zona horaria, idiomas y moneda válidos, *entonces* se guarda con `organization_id` de A en estado `draft` y queda auditado.
- **CA-003-2:** *Dado* un evento existente en A, *cuando* se crea otro con el mismo `slug` en A, *entonces* se rechaza; *cuando* se crea con ese `slug` en B, *entonces* se permite.
- **CA-003-3:** *Dado* un formulario con `ends_at` anterior a `starts_at`, una zona horaria que no es IANA, un idioma fuera de es, en y pt, un idioma por defecto que no está entre los idiomas del evento o una moneda inválida, *cuando* se envía, *entonces* se rechaza con el error de cada campo y no se guarda nada.
- **CA-003-4:** *Dado* un evento con fechas, *cuando* se guarda, *entonces* los instantes se almacenan en UTC y la interfaz los muestra en la zona horaria del evento.
- **CA-003-5:** *Dado* un identificador de un evento de B, *cuando* un usuario de A lo abre o lo edita, *entonces* la respuesta es 404.
- **CA-003-6:** *Dado* un miembro sin permiso de gestión de eventos, *cuando* intenta crear, editar, publicar o archivar un evento, *entonces* recibe 403 y no cambia nada.
- **CA-003-7:** *Dado* un evento en `draft` completo (nombre en el idioma por defecto, fechas y zona), *cuando* se publica, *entonces* pasa a `published`; *cuando* le falta alguno de esos datos, *entonces* se rechaza con el campo faltante.
- **CA-003-8:** *Dado* un evento publicado, *cuando* se archiva, *entonces* pasa a `archived`, desaparece del acceso público y conserva sus datos (borrado lógico, nunca físico).
- **CA-003-9:** *Dado* un evento con `slug` válido, *cuando* se intenta cambiar el `slug` de un evento ya publicado, *entonces* se pide confirmación explícita y se audita, para no romper enlaces publicados.
  Manual: publicar un evento, cambiar su `slug` y comprobar la advertencia y que la ruta anterior responde 404 (ancho ~1280 px).

### H-003-2 · Aviso de privacidad por evento

- **CA-003-10:** *Dado* un evento de A, *cuando* un administrador crea el borrador del aviso con texto en el idioma por defecto, *entonces* se guarda como versión 1 sin `published_at` y es editable.
- **CA-003-11:** *Dado* un borrador sin texto en el idioma por defecto, *cuando* se intenta publicar, *entonces* se rechaza con el error correspondiente y no se publica.
- **CA-003-12:** *Dado* un borrador completo, *cuando* se publica, *entonces* queda inmutable con `published_at` y `published_by`, y se audita.
- **CA-003-13:** *Dado* una versión publicada, *cuando* se intenta editarla o borrarla, *entonces* se rechaza; *cuando* se crea una versión nueva a partir de ella, *entonces* se crea la versión siguiente como borrador y la anterior sigue vigente hasta publicar la nueva.
- **CA-003-14:** *Dado* un texto con HTML, scripts o enlaces peligrosos (`javascript:`), *cuando* se guarda, *entonces* se sanitiza o rechaza en el servidor y nunca se ejecuta al mostrarse.
- **CA-003-15:** *Dado* un aviso publicado con versión en es y en, *cuando* un visitante abre la página del aviso en inglés, *entonces* ve el texto en inglés; si falta un idioma, ve el idioma base.
- **CA-003-16:** *Dado* un evento sin aviso publicado, *cuando* un visitante abre la página del aviso, *entonces* responde 404.
- **CA-003-17:** *Dado* un miembro sin permiso de publicar el aviso o un usuario de B, *cuando* intenta publicarlo, *entonces* recibe 403 (o 404 si el evento es de B) y no cambia nada.

### H-003-3 · Equipo de la organización

- **CA-003-18:** *Dado* un administrador de A, *cuando* da de alta un correo nuevo con un rol, *entonces* se crea el usuario con membresía en A y se encola `team.set_password` con un enlace firmado, de un solo uso y de caducidad corta.
- **CA-003-19:** *Dado* un correo que ya es usuario de B, *cuando* un administrador de A lo da de alta, *entonces* se crea la membresía en A sin copiar datos de B, se avisa por correo y la respuesta al administrador es idéntica a la de un correo nuevo.
- **CA-003-20:** *Dado* un correo con formato inválido o ya miembro de A, *cuando* se envía el alta, *entonces* se rechaza con el error correspondiente y no se envía correo.
- **CA-003-21:** *Dado* un administrador de A, *cuando* lista, cambia el rol o desactiva a un integrante, *entonces* solo ve y modifica integrantes de A; un identificador de un usuario de B responde 404.
- **CA-003-22:** *Dado* el último administrador de A, *cuando* se intenta quitarle el rol de administrador o desactivarlo, *entonces* se rechaza.
- **CA-003-23:** *Dado* un miembro sin permiso de administración, *cuando* intenta dar de alta, cambiar el rol o desactivar a alguien, *entonces* recibe 403 y no cambia nada.
- **CA-003-24:** *Dado* un integrante desactivado, *cuando* intenta iniciar sesión o usar una sesión abierta, *entonces* pierde el acceso a A.
- **CA-003-25:** *Dado* un alta, un cambio de rol o una baja, *cuando* ocurre, *entonces* queda registro de auditoría con quién, a quién y qué cambió, sin contraseñas ni tokens.
- **CA-003-26:** *Dado* un número alto de altas en poco tiempo desde un mismo usuario, *cuando* excede el límite, *entonces* responde 429. `[PENDIENTE: límite de altas por minuto]`

### H-003-4 · Página pública del evento

- **CA-003-27:** *Dado* un evento `published`, *cuando* un visitante abre su ruta pública, *entonces* ve nombre, descripción, fechas en la zona del evento y sede, en su idioma, con respuesta 200 sin sesión.
- **CA-003-28:** *Dado* un evento `draft`, `archived` o de otra organización, *cuando* se abre su ruta pública, *entonces* responde 404.
- **CA-003-29:** *Dado* un evento con aviso publicado, *cuando* se abre la página, *entonces* enlaza al aviso; sin aviso no muestra el enlace.
- **CA-003-30:** *Dado* la página pública, *cuando* se inspecciona la respuesta, *entonces* no expone identificadores internos, correos del equipo ni datos de otras organizaciones.
- **CA-003-31:** *Dado* la página pública, *cuando* se visualiza a ~375 px y ~1280 px, *entonces* es legible, accesible con teclado, con contraste AA y sin errores en la consola.
  Manual: abrir la ruta a 375 px y 1280 px, recorrerla con el teclado y revisar la consola; comprobar fechas y números con `Intl` en es y en.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| El aviso de privacidad no está aprobado a tiempo | Dueño asignado (A10); G7 del punto de control bloquea el *go* |
| Filtrar la existencia de usuarios entre organizaciones al dar altas | Respuesta idéntica (DA-5, CA-003-19) y prueba de aislamiento |
| Fechas mal interpretadas por zona horaria | UTC en la base, zona del evento en los bordes, prueba con zonas distintas (CA-003-4) |
| Texto del aviso con contenido peligroso | Sanitización en el servidor con lista blanca (CA-003-14) |
| Quedarse sin administradores en la organización | Regla del último administrador (CA-003-22) |
| El correo del equipo depende del plan 008 | Pruebas con `Mail::fake()` mientras tanto; H-003-3 no se cierra hasta tener H-008-6 |

## 9. Registro de avance

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
