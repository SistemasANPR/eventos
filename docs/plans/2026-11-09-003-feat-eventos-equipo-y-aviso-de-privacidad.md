# feat: eventos, equipo y aviso de privacidad

> Épica E-03 de `docs/plan-de-desarrollo.md` (R0). El organizador configura su evento, escribe su propio aviso de privacidad por idioma y da de alta a su equipo, todo desde el panel y sin tocar código ni base de datos. Ningún evento ni texto se siembra: "Congreso Parques 2027" se captura en la interfaz.

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

Dar a cada organización la gestión de su evento (tipo, fechas, sede, zona horaria, idiomas, moneda y estado), un aviso de privacidad versionado por evento y un equipo con roles reales, de modo que los planes de convocatoria, formularios y envío (004 a 006) tengan dónde colgar sus datos y un aviso que enlazar y registrar como consentimiento.

**Dentro:** Actions, Policies y recurso de Filament para crear, editar, publicar y archivar eventos sobre el modelo y la migración de `events` que crea el plan 002; selector de evento en el panel y URL del panel con el evento como parámetro; `PrivacyNotice` versionado por evento y traducible (es, en, pt), con publicación inmutable y página pública; gestión del equipo de la organización (alta con rol, cambio de rol, desactivación) con enlace para establecer contraseña.

**Fuera:** la página pública del evento (la hace H-002-3 del plan 002, que absorbió a la antigua H-003-4); convocatorias, ejes y formularios (planes 004 y 005); consentimiento por propuesta (H-006-6); `event_assignments` y roles por evento (plan 011, R1); plantillas y duplicado de evento (E-18, R2); módulos activables por evento (R2); sede y salas como entidades reutilizables (`Venue`, `Room`, R3); varias zonas o estados avanzados del evento (en curso, cerrado) y multimoneda real (el campo existe, solo `MXN` en R0); 2FA (plan 014).

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-003-1 | Como organizador, quiero crear y editar un evento (nombre, tipo, fechas, sede, zona horaria, idiomas, moneda y estado) y elegir con qué evento trabajo en el panel, para configurar mi edición sin código | M | H-001-3, H-002-3 |
| H-003-2 | Como organizador, quiero escribir y versionar el aviso de privacidad de mi evento por idioma, para cumplir con la ley desde la plataforma | M | H-003-1 |
| H-003-3 | Como administrador de organización, quiero dar de alta a mi equipo con rol y enlace para establecer contraseña, para trabajar con permisos reales | M | H-001-3, H-008-6 (hasta entonces `Mail::fake()`) |

**H-003-4 (página pública del evento) se absorbió en H-002-3 del plan 002**; sus criterios CA-003-27 a CA-003-31 ya no existen aquí. El enlace de la página al aviso de privacidad se prueba en H-003-2 (CA-003-19).

## 2. Punto de partida

Medido el 2026-11-09: no existe el proyecto Laravel ni modelos. El glosario define `Event` (ámbito: organización) y `MailTemplate`; faltan los términos `PrivacyNotice` y la nota de que el aviso lo define cada evento (se propone añadirlos al glosario). `docs/privacidad.md` exige aviso de privacidad enlazado en cada formulario público, definido por la organización, y consentimiento registrado con texto y versión. Este plan depende de la organización, la matriz rol × permiso y las Policies del plan 001, del modelo y la migración de `events` del plan 002 y del envío por cola del plan 008 (H-008-6) para el correo del equipo; mientras no exista, las pruebas usan `Mail::fake()`.

## 3. Decisiones

### DA-1 · Idiomas del evento — se recomienda **lista cerrada es, en y pt, con idioma base**

El evento declara los idiomas que ofrece y uno por defecto (respaldo en español, ADR-0005). Los textos traducibles usan `spatie/laravel-translatable`. Se guarda como `jsonb` validado (valores de configuración, no una relación).

### DA-2 · Estados del evento en R0 — se recomienda **`draft`, `published` y `archived`**

Enum de PHP sobre columna `string` con `CHECK` (ADR-0005). `en curso` y `cerrado` llegan con los módulos que los necesitan. Un evento en borrador o archivado no se ve en público.

### DA-3 · Aviso de privacidad y publicación del evento — se recomienda **no exigirlo para publicar el evento, sí para abrir una convocatoria**

El plan 004 (H-004-1) no permite abrir una convocatoria si el evento no tiene un aviso publicado. Este plan solo crea y versiona el aviso y expone la consulta de la versión vigente (la última publicada).

### DA-4 · Versionado del aviso — se recomienda **una fila por versión, inmutable al publicarse**

Cada versión (una fila de `privacy_notices`) contiene todos los idiomas. Editar un aviso publicado crea una versión nueva; las propuestas guardan la versión aceptada (plan 006). Alternativa descartada: editar en sitio con historial de auditoría, porque no permite demostrar qué texto aceptó cada persona.

### DA-5 · Usuario existente en otra organización — se recomienda **adjuntar la membresía sin revelar que existe**

Al dar de alta un correo que ya es usuario de otra organización se crea la membresía y se avisa por correo (`team.membership_added`); la respuesta al administrador es idéntica en ambos casos, para no filtrar datos entre organizaciones (ADR-0003 DA-3).

### DA-6 · Sede — se recomienda **texto en el evento en R0**

Nombre, dirección y ciudad como campos del evento; `Venue` y `Room` reutilizables llegan con el programa (R3).

### DA-7 · Límite de altas de equipo — se recomienda **10 altas por minuto por usuario**

Valor propuesto que Antonio aprueba con el plan; protege contra el envío masivo de correos de contraseña.

### DA-8 · Evento actual en el panel — se recomienda **evento como parámetro de ruta con binding acotado y un selector de evento**

Filament usa la organización como tenant; dentro de ella, las pantallas por evento (propuestas, convocatorias, formularios) viven bajo una URL con `{event}`, resuelto con binding acotado a la organización, y un selector de evento cambia de uno a otro. No existe un «evento por defecto» implícito: sin evento elegido, las pantallas por evento piden elegirlo. Los planes 004 a 009 heredan este mecanismo.

### DA-9 · Texto del aviso — se recomienda **sanitizar con lista blanca y guardar el texto limpio**

Se conservan etiquetas básicas de formato (párrafos, énfasis, listas, enlaces `http` y `https`); todo lo demás se elimina en el servidor antes de guardar. No se rechaza el texto ni se guarda el original.

### DA-10 · Reactivar a un integrante desactivado — se recomienda **acción explícita del administrador con el rol anterior precargado y confirmado**

La reactivación es una acción propia (`ReactivateMember`) del administrador con `team.manage`, no un efecto de volver a invitar. El formulario muestra el rol que tenía y permite confirmarlo o cambiarlo; no se concede acceso en silencio. Queda auditada con el rol anterior y el nuevo y no toca las membresías de otras organizaciones. Invitar por correo a un integrante desactivado sigue rechazándose, con un mensaje que apunta a «Reactivar». Aprobada por Antonio el 2026-11-11 (cierra DT-03).


## 4. Contrato

- **Clases:** `App\Models\Event` (creado en el plan 002), `App\Models\PrivacyNotice`; enums `EventType` (`Congress`, `Forum`, `Workshop`, `Webinar`, `Expo`), `EventStatus` (`Draft`, `Published`, `Archived`) y `OrganizationRole` (solo enumera los nombres de los roles de spatie `OrgAdmin`, `Organizer` y `Reviewer`; **no es una fuente de verdad**); Actions `CreateEvent`, `UpdateEvent`, `PublishEvent`, `ArchiveEvent`, `CreatePrivacyNoticeDraft`, `UpdatePrivacyNoticeDraft`, `PublishPrivacyNotice`, `InviteTeamMember`, `ChangeMemberRole`, `DeactivateMember`; `PrivacyNotice::currentPublished(Event)`; `EventPolicy`, `PrivacyNoticePolicy`, `MembershipPolicy`.
- **Permisos** (de la matriz del plan 001): `events.manage`, `events.publish`, `privacy_notices.manage` y `team.manage`.
- **Form Requests** (reutilizados por las Actions y por Filament): `StoreEventRequest`, `UpdateEventRequest`, `StorePrivacyNoticeRequest`, `InviteTeamMemberRequest`, `ChangeMemberRoleRequest`.
- **Interfaz:** panel de Filament (zona 1): `EventResource`, selector de evento, gestión del aviso dentro del evento y gestión del equipo de la organización. **Ruta web pública** (Inertia, sin SSR): `GET /{organization:slug}/events/{event:slug}/privacy`, con binding acotado; la ruta del evento es la del plan 002. Sin endpoints JSON ni webhooks nuevos (nada que añadir a `docs/api/endpoints.md`).
- **Notificaciones nuevas del catálogo (plan 008, predeterminada es/en):** `team.set_password` (crítica) y `team.membership_added` (no crítica, aviso a un usuario que ya existe en otra organización).
- **Propuesta de datos (`docs/database-design.md` §6):**
  - *Necesidad:* un aviso versionado por evento y membresías con rol y estado; el evento ya lo crea el plan 002. Dueños: privacidad y equipo; ciclo de vida: el evento se archiva, el aviso publicado se conserva como evidencia.
  - *Inspección:* `events` (plan 002), `organizations`, `organization_user`, roles y `activity_log` (plan 001).
  - *Modelo:* `privacy_notices` (UUID; `organization_id`; `event_id`; `version`; `body` traducible; `published_at`; `published_by`), una fila por versión. Ámbito: organización, con `event_id` explícito (sin scope global de evento).
  - *Integridad:* `organization_id uuid NOT NULL` con clave foránea; `unique(event_id, version)`; `unique(id, organization_id)` y clave foránea compuesta `(event_id, organization_id)` hacia `events` para que aviso y evento compartan organización; `CHECK` de `version >= 1`; el aviso publicado no se borra ni se edita.
  - *Convención para los planes 004 y 005:* calls, tracks y forms declaran también `unique(id, organization_id)` y claves compuestas hacia `events`.
  - *Cambio:* migración nueva sin datos previos; la genera el agente y solo una persona la aplica.
  - *Alternativa descartada:* un único aviso por organización, porque cada evento define el suyo.
  - *Preguntas de negocio:* ninguna bloqueante; `[PENDIENTE: texto del aviso del Congreso Parques 2027 y quién lo aprueba]` es contenido que captura el equipo en la interfaz.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Según la matriz del plan 001. `org_admin`: `events.manage`, `events.publish`, `privacy_notices.manage` y `team.manage`. `organizer`: `events.manage` (crea y edita eventos y ve los avisos); no publica eventos, no publica el aviso y no gestiona el equipo. `reviewer`: ninguno. Plataforma: sin acceso por defecto. Visitante: solo ve eventos publicados y el aviso publicado |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `EventPolicy`, `PrivacyNoticePolicy`, `MembershipPolicy`; una prueba por rol y acción, permitido y denegado, en `tests/Feature/Events/`, `tests/Feature/Privacy/` y `tests/Feature/Team/` |
| ¿Hay modelos nuevos con datos de una organización? ¿Usan `BelongsToOrganization`? | Sí: `PrivacyNotice` (usa `BelongsToOrganization`; cubierto por las guardianas y por una prueba de aislamiento propia). `Event` ya está cubierto desde el plan 002 |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No en R0: los roles son de organización. El evento se elige de forma explícita en la ruta (DA-8) |
| ¿Se guardan datos personales nuevos? | Nombre y correo de integrantes del equipo (base: relación de trabajo; retención mientras dure la membresía y se anonimizan al darse de baja según `docs/privacidad.md`, que se actualiza en el mismo cambio). El texto del aviso no es dato personal |
| ¿Se suben archivos? | No aplica |
| ¿Hay dinero, pagos o webhooks? | No. El campo `currency` solo guarda `MXN` en R0 (ADR-0005 DA-1) |
| ¿Se envían correos o notificaciones? | `team.set_password` (crítica, por cola) y `team.membership_added` (no crítica, a un usuario existente), ambos con predeterminada es/en |
| ¿Qué se registra en la auditoría? | Creación, edición, publicación y archivo de eventos; creación y publicación de versiones del aviso; alta, cambio de rol y baja de integrantes; envío del enlace de contraseña |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Propuesta de datos aprobada; enums y Policies con pruebas en rojo | S |
| F1 | Actions, Form Requests y `EventResource` de Filament sobre el `Event` del plan 002; selector de evento y URL con `{event}` (H-003-1) | M |
| F2 | `PrivacyNotice` versionado, sanitización, consulta de la versión vigente y página pública del aviso (H-003-2) | M |
| F3 | Gestión del equipo: alta, rol, baja y enlace para establecer contraseña (H-003-3) | M |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-003-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

### H-003-1 · Crear y editar un evento

- **CA-003-1:** *Dado* un usuario de la organización A con `events.manage`, *cuando* crea un evento con nombre, tipo, fechas, zona horaria, idiomas y moneda válidos, *entonces* se guarda con `organization_id` de A en estado `draft` y queda auditado.
- **CA-003-2:** *Dado* un evento existente en A, *cuando* se crea otro con el mismo `slug` en A, *entonces* se rechaza; *cuando* se crea con ese `slug` en B, *entonces* se permite.
- **CA-003-3:** *Dado* un formulario con `ends_at` anterior a `starts_at`, una zona horaria que no es IANA, un idioma fuera de es, en y pt, un idioma por defecto que no está entre los idiomas del evento o una moneda inválida, *cuando* se envía, *entonces* se rechaza con el error de cada campo y no se guarda nada.
- **CA-003-4:** *Dado* un evento con fechas, *cuando* se guarda, *entonces* los instantes se almacenan en UTC y la interfaz los muestra en la zona horaria del evento.
- **CA-003-5:** *Dado* un identificador de un evento de B, *cuando* un usuario de A lo abre o lo edita, *entonces* la respuesta es 404.
- **CA-003-6:** *Dado* un usuario sin `events.manage` (por ejemplo, un `reviewer`), *cuando* intenta crear o editar un evento, *entonces* recibe 403 y no cambia nada; *dado* un `organizer` sin `events.publish`, *cuando* intenta publicar o archivar, *entonces* recibe 403 y no cambia nada.
- **CA-003-7:** *Dado* un evento en `draft` completo (nombre en el idioma por defecto, fechas y zona), *cuando* un usuario con `events.publish` lo publica, *entonces* pasa a `published`; *cuando* le falta alguno de esos datos, *entonces* se rechaza con el campo faltante.
- **CA-003-8:** *Dado* un evento publicado, *cuando* un usuario con `events.publish` lo archiva, *entonces* pasa a `archived`, desaparece del acceso público y conserva sus datos (borrado lógico, nunca físico).
- **CA-003-9:** *Dado* un evento con `slug` válido ya publicado, *cuando* se intenta cambiar su `slug`, *entonces* se pide confirmación explícita y se audita, para no romper enlaces publicados.
  Manual: publicar un evento, cambiar su `slug` y comprobar la advertencia y que la ruta anterior responde 404 (ancho ~1280 px).
- **CA-003-10:** *Dado* un usuario de A con dos eventos, *cuando* abre el panel de un evento por su identificador en la URL, *entonces* las pantallas por evento trabajan con ese evento; el selector lista solo eventos de A; el identificador de un evento de B responde 404; y *cuando* no hay evento elegido, *entonces* las pantallas por evento piden elegirlo en vez de tomar uno al azar.

### H-003-2 · Aviso de privacidad por evento

- **CA-003-11:** *Dado* un evento de A, *cuando* un usuario con `privacy_notices.manage` crea el borrador del aviso con texto en el idioma por defecto, *entonces* se guarda como versión 1 sin `published_at` y es editable.
- **CA-003-12:** *Dado* un borrador sin texto en el idioma por defecto, *cuando* se intenta publicar, *entonces* se rechaza con el error correspondiente y no se publica.
- **CA-003-13:** *Dado* un borrador completo, *cuando* se publica, *entonces* queda inmutable con `published_at` y `published_by`, y se audita.
- **CA-003-14:** *Dado* una versión publicada, *cuando* se intenta editarla o borrarla, *entonces* se rechaza; *cuando* se crea una versión nueva a partir de ella, *entonces* se crea la versión siguiente como borrador y la anterior sigue vigente hasta publicar la nueva.
- **CA-003-15:** *Dado* un texto con HTML, scripts, manejadores de eventos o enlaces peligrosos (`javascript:`), *cuando* se guarda, *entonces* el servidor lo sanitiza con la lista blanca de DA-9 y guarda el texto limpio; el texto guardado y la página pública no contienen lo eliminado y nunca se ejecuta al mostrarse.
- **CA-003-16:** *Dado* un aviso publicado con versión en es y en, *cuando* un visitante abre la página del aviso en inglés, *entonces* ve el texto en inglés; si falta un idioma, ve el idioma base.
- **CA-003-17:** *Dado* un evento sin aviso publicado, *cuando* un visitante abre la página del aviso, *entonces* responde 404.
- **CA-003-18:** *Dado* un usuario sin `privacy_notices.manage` (por ejemplo, un `organizer`) o un usuario de B, *cuando* intenta publicar el aviso, *entonces* recibe 403 (o 404 si el evento es de B) y no cambia nada.
- **CA-003-19:** *Dado* un evento con aviso publicado, *cuando* un visitante abre la página pública del evento (plan 002), *entonces* enlaza al aviso; sin aviso publicado no muestra el enlace.
- **CA-003-20:** *Dado* un evento con la versión 1 publicada y la versión 2 en borrador, *cuando* se consulta `PrivacyNotice::currentPublished`, *entonces* devuelve la versión 1; sin ninguna versión publicada devuelve nada, y de esa consulta depende la regla del plan 004 de no abrir una convocatoria sin aviso.

### H-003-3 · Equipo de la organización

- **CA-003-21:** *Dado* un administrador de A con `team.manage`, *cuando* da de alta un correo nuevo con un rol de la lista (`org_admin`, `organizer`, `reviewer`), *entonces* se crea el usuario con membresía activa en A y se encola `team.set_password` con un enlace firmado, de un solo uso y de caducidad corta.
- **CA-003-22:** *Dado* un correo que ya es usuario de B, *cuando* un administrador de A lo da de alta, *entonces* se crea la membresía en A sin copiar datos de B, se encola `team.membership_added` y la respuesta al administrador es idéntica a la de un correo nuevo.
- **CA-003-23:** *Dado* un correo con formato inválido o ya miembro de A, *cuando* se envía el alta, *entonces* se rechaza con el error correspondiente y no se envía correo.
- **CA-003-24:** *Dado* un administrador de A, *cuando* lista, cambia el rol o desactiva a un integrante, *entonces* solo ve y modifica integrantes de A; un identificador de un usuario de B responde 404.
- **CA-003-25:** *Dado* el último administrador de A, *cuando* se intenta quitarle el rol de administrador o desactivarlo, *entonces* se rechaza.
- **CA-003-26:** *Dado* un usuario sin `team.manage` (`organizer` o `reviewer`), *cuando* intenta dar de alta, cambiar el rol o desactivar a alguien, *entonces* recibe 403 y no cambia nada.
- **CA-003-27:** *Dado* un integrante a quien `DeactivateMember` fijó `status` en `deactivated` y `deactivated_at`, *cuando* intenta iniciar sesión o usar una sesión abierta, *entonces* pierde el acceso a A por el corte de `EnsureActiveMembership` (plan 001).
- **CA-003-28:** *Dado* un alta, un cambio de rol o una baja, *cuando* ocurre, *entonces* queda registro de auditoría con quién, a quién y qué cambió, sin contraseñas ni tokens.
- **CA-003-29:** *Dado* más de 10 altas en un minuto desde el mismo usuario, *cuando* se intenta otra, *entonces* responde 429 y no se envía correo.
- **CA-003-30:** *Dado* un alta o un cambio de rol, *cuando* se intenta asignar el rol `platform`, la marca `is_platform_admin` o un rol que no está en la lista, *entonces* se rechaza y no cambia nada.

**Reactivación de un integrante desactivado (DT-03; DA-10)**

- **CA-003-32:** *Dado* un administrador de A con `team.manage` y un integrante desactivado de A, *cuando* lo reactiva confirmando un rol de la lista, *entonces* `status` vuelve a `active`, `deactivated_at` queda en nulo, el rol del integrante en A es el confirmado y recupera el acceso a A en la siguiente petición.
- **CA-003-33:** *Dado* el formulario de reactivación, *cuando* se abre, *entonces* trae precargado el rol que el integrante tenía al desactivarse; *cuando* se envía un rol fuera de la lista (`org_admin`, `organizer`, `reviewer`), incluido `platform`, *entonces* se rechaza y no cambia nada.
- **CA-003-34:** *Dado* un integrante activo de A o un usuario que no es integrante de A, *cuando* se intenta reactivar, *entonces* se rechaza con `MEMBER_NOT_DEACTIVATED` y no cambia nada; un identificador de un usuario ajeno a la organización responde 404.
- **CA-003-35:** *Dado* un usuario sin `team.manage` (`organizer` o `reviewer`), *cuando* intenta reactivar a alguien, *entonces* recibe 403 y no cambia nada.
- **CA-003-36:** *Dado* un usuario con membresías en A y en B, *cuando* el administrador de A reactiva su membresía en A, *entonces* su membresía y sus roles en B no cambian.
- **CA-003-37:** *Dado* una reactivación, *cuando* ocurre, *entonces* queda auditada (`member_reactivated`) con quién, a quién, el rol anterior y el nuevo, sin contraseñas ni tokens.
- **CA-003-38:** *Dado* un correo que ya es integrante desactivado de A, *cuando* un administrador intenta darlo de alta como si fuera nuevo, *entonces* se rechaza con un mensaje que indica usar «Reactivar» y no se envía correo.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| El aviso de privacidad no está aprobado a tiempo | Dueño asignado (A10); G7 del punto de control bloquea el *go* |
| Filtrar la existencia de usuarios entre organizaciones al dar altas | Respuesta idéntica (DA-5, CA-003-22) y prueba de aislamiento |
| Fechas mal interpretadas por zona horaria | UTC en la base, zona del evento en los bordes, prueba con zonas distintas (CA-003-4) |
| Texto del aviso con contenido peligroso | Sanitización en el servidor con lista blanca (CA-003-15) |
| Quedarse sin administradores en la organización | Regla del último administrador (CA-003-25) |
| El correo del equipo depende del plan 008 | Pruebas con `Mail::fake()` mientras tanto; H-003-3 no se cierra hasta tener H-008-6 |
| Las pantallas por evento toman un evento equivocado y mezclan datos de dos eventos | DA-8 y CA-003-10: el evento va en la ruta y no hay valor por defecto |
| Se abre una convocatoria sin aviso y el consentimiento queda sin versión | CA-003-20 y la regla del plan 004 sobre H-004-1 |

## 9. Registro de avance

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| H-003-1 | `fd7d689` | 2026-11-10 | Actions `CreateEvent`, `UpdateEvent`, `PublishEvent` y `ArchiveEvent` con `EventPolicy`; fechas en hora local del evento guardadas en UTC; `EventResource` de Filament. Cubre CA-003-1 a 10 (CA-003-9 y 10 también verificados en navegador). Incluye la corrección del contexto de organización del panel en peticiones de Livewire |
| H-003-3 | `9b5ae24` | 2026-11-10 | `InviteTeamMember` (respuesta idéntica exista o no el usuario, límite de 10 altas por minuto), `DeactivateMember`, regla del último administrador con bloqueo de fila, `MembershipPolicy` y `TeamMemberResource`. Cubre CA-003-21 a 30 |
| DT-03 (DA-10) | sin commit aún | 2026-11-11 | `ReactivateMember` + `MemberNotDeactivated` (`MEMBER_NOT_DEACTIVATED`), habilidad `reactivate` en `MembershipPolicy`, acción «Reactivar» en la tabla del equipo con el rol anterior precargado, y mensaje de `InviteTeamMember` que apunta a «Reactivar» para un integrante desactivado. Cubre CA-003-32 a 38. `composer test --filter="Team\|Membership\|Roles\|Lang"`: 233 pasadas; `lint` y `check:criteria` limpios (0 errores, CA-003-32..38 con prueba). `analyse` falla solo por `LoginResponse.php` (cambios ajenos del plan 017) |
| H-003-2 | `8db7e0a` | 2026-11-10 | Tabla `privacy_notices` con versiones inmutables al publicarse (trigger), sanitizador de lista blanca, Actions con `PrivacyNoticePolicy` (solo org_admin), página pública `/{org}/events/{evento}/privacy`. Cubre CA-003-11 a 20. Verificado en navegador a 375 px |
