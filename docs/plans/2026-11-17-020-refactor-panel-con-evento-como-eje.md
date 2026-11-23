# refactor: panel de organizadores con el evento como eje

> Copiar como `AAAA-MM-DD-NNN-tipo-titulo-en-kebab-case.md` (ejemplo: `2026-11-09-003-feat-convocatoria-de-propuestas.md`). `NNN` es el número del plan, consecutivo, y prefija todos sus criterios: `CA-NNN-n`. Es la especificación de SDD (ADR-0001) para esfuerzo M o L. Se presenta y se espera aprobación antes de escribir código. Tipos: los mismos de los commits (`feat`, `fix`, `refactor`...).
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-17
- **Estado:** Terminado (2026-11-23: F0 a F2 construidas y registradas en §9)
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna directa. DT-15 (el botón «Retirar campo» sin prueba de panel) toca `FormDefinitionForm`, uno de los archivos de este plan; no se cierra aquí.

> Estados válidos: `Borrador`, `Aprobado`, `En curso`, `Terminado`. Escribir uno solo.

## 1. Objetivo y alcance

El modelo de datos ya tiene al evento como eje (ADR-0003: Organización → Evento → todo lo demás), pero el menú del panel de organizadores todavía no lo refleja: **Formularios** aparece como una lista suelta de toda la organización, con el evento como una columna más, mientras que **Propuestas** ya cuelga del evento. Este plan alinea la interfaz con el modelo: el menú de la organización queda ordenado y agrupado, y los formularios pasan a vivir dentro del evento.

**Dentro:**
- **A.** Orden y agrupación explícitos del menú lateral de la organización (`navigationGroup`, `navigationSort`) y una guardiana que impide recursos de primer nivel sin orden declarado.
- **B.** `FormDefinitionResource` pasa a recurso anidado bajo `EventResource` (`$parentResource`), como `SubmissionResource`: rutas `/{tenant}/events/{event}/form-definitions/...`, evento tomado de la ruta (ya no se elige en un selector), acotado por organización **y** por evento.
- La puerta de entrada desde el evento: sub-navegación del registro del evento (Resumen, Formularios y, según DA-2, Propuestas).
- Pruebas del plan 005 actualizadas a las rutas nuevas; sus criterios `CA-005-*` y sus nombres no cambian.

**Fuera:**
- **C.** El menú completo del evento para programa, hospedaje y patrocinadores: esos módulos no existen todavía. Este plan solo deja el mecanismo (sub-navegación del evento) listo para que los demás se añadan.
- Cambios de Policies, roles o permisos (acción 9 de `AGENTS.md`); no se crea ningún permiso.
- Migraciones o cambios de esquema; ninguna Action de `app/Actions/Forms/` cambia de firma.
- El panel de plataforma (`app/Filament/Platform/`) y los demás paneles por rol.
- Una lista transversal de formularios de toda la organización (ver DA-4).

## 2. Punto de partida

Medido el 2026-11-17 sobre la rama `feat/plan-011-evaluacion-comite` (sin cambios míos en estos archivos):

- **Panel y menú.** `AdminPanelProvider` usa `Organization` como tenant (`/admin/{slug}`) y descubre los recursos de `app/Filament/Resources`. Ningún recurso de ese panel declara `navigationGroup` ni `navigationSort`: el orden es el que Filament decide. Solo el panel de plataforma define `navigationSort` (10 a 40).
- **Menú medido** con una prueba de sonda (descartada) sobre `Filament::getNavigation()`: un solo grupo sin nombre con `Dashboard` (orden -2), `Emails`, `Events`, `Forms` y `Team` (todos en -1, es decir, en el orden por defecto). El organizador no ve `Team` (la Policy exige `team.manage`) y el evaluador solo ve `Dashboard`.
- **Recursos y páginas de primer nivel:** `EventResource`, `FormDefinitionResource`, `TeamMemberResource` y la página `MailCatalog` (Correos, `app/Filament/Pages/Mail/`). `SubmissionResource` ya es anidado (`$parentResource = EventResource::class`, plan 009 DA-6) y solo tiene la página `index`.
- **Evento.** `ViewEvent` muestra únicamente los relation managers `Calls`, `SubmissionTypes`, `Tracks` y `PrivacyNotices`. El modelo `Event` tiene las relaciones `calls()` y `tracks()`; **no** tiene `formDefinitions()` ni `submissions()`. No existe ningún `getRecordSubNavigation` en `app/`.
- **Formularios.** Rutas actuales: `forms/form-definitions` (índice), `/create`, `/{record}/edit` y `/{record}/preview`. La tabla `form_definitions` ya tiene `event_id` y `organization_id` obligatorios, con clave foránea compuesta `(event_id, organization_id)` hacia `events` e índice `(organization_id, event_id, status)`.
- **El evento se elige en un selector en tres sitios:** `FormDefinitionForm` (campo `event_id`, solo editable al crear), `CreateFormDefinition::handleRecordCreation` y la acción «desde plantilla» de `ListFormDefinitions`. Los enlaces internos a `edit` y `preview` se arman con `FormDefinitionResource::getUrl(...)` en `FormDefinitionsTable` (líneas 52 y 71), `EditFormDefinition` (37) y `ListFormDefinitions` (68).
- **Autorización.** `FormDefinitionPolicy` decide por permiso de organización (`forms.manage`; `forms.view` basta para consultar y para la vista previa). `forms.view` no está en ningún rol de la matriz. `event_assignments` existe (plan 011, F1) pero ninguna Policy de formularios lo consulta.
- **Pruebas con rutas fijas** (hay que actualizarlas): `FormDefinitionPanelTest` (líneas 127, 132 y 133) y `FormPreviewTest` (línea 40).
- **Filament** 5.11.2 (`composer.lock`). Comprobado en `vendor/filament/filament/src` (documentación v5 y código): un recurso anidado **no entra al menú global** (`HasNavigation` lo omite si tiene recurso padre) y **no se alcanza solo**; necesita un relation manager o una *relation page* en el recurso padre, y las *relation pages* pueden entrar en `getRecordSubNavigation()`. La consulta se acota con la relación inversa `event` (que `FormDefinition` ya tiene, `BelongsTo`) y el alta por defecto usa la relación `formDefinitions` del padre (que `Event` aún no tiene); ambas se pueden declarar con `getParentResourceRegistration()`.
- **Planes que tocan la misma zona:** 009 (Propuestas, recurso anidado en F0), 018 (CA-018-16 espera «la navegación del evento» con dos entradas de propuestas) y 011 (la Policy se limitará a eventos asignados).

## 3. Decisiones

Resumen: las seis quedaron cerradas el 2026-11-17 con la opción recomendada: sub-navegación del evento (DA-1) con Propuestas incluida (DA-2), sin redirección de las rutas viejas (DA-3), perdiendo la lista transversal de formularios (DA-4), menú mínimo con un solo grupo (DA-5) y F0 ya, con F1 y F2 sin frenar la ruta crítica del envío (DA-6).

### DA-1 · Cómo se llega a Formularios desde el evento — se recomienda **sub-navegación del registro del evento**

Filament exige un punto de entrada en el padre. Opciones:
- **Sub-navegación** (`getRecordSubNavigation` con una *relation page* de formularios): el evento tiene una barra de secciones (Resumen, Formularios...). Es el patrón que sirve cuando entren programa, hospedaje y patrocinadores, y es lo que el plan 018 llama «la navegación del evento».
- **Pestaña (relation manager) en `ViewEvent`**: es lo que ya usan Convocatorias y Ejes, pero un relation manager es una tabla embebida, no una sección con rutas, filtros y vista previa propios; el editor de formularios no cabe bien ahí.

Recomendación: sub-navegación. Los relation managers actuales (Convocatorias, Tipos, Ejes, Aviso de privacidad) **no se mueven** en este plan.

**Cerrada el 2026-11-17: sub-navegación del registro del evento.**

**Revisada el 2026-11-17 (a petición de Antonio, tras verlo funcionando): el menú del evento va como submenú dentro del mismo menú lateral azul**, anidado bajo «Eventos», y ya no como columna dentro de la página. Filament 5 no ofrece esa posición para la sub-navegación de un registro (solo inicio, fin o arriba), así que `EventResource::getNavigationItems()` añade tres ítems hijos de «Eventos» (`EventSectionsNavigation`) que solo se muestran cuando la ruta trae un evento y a quien puede acceder a cada sección. Se elimina `getRecordSubNavigation`. Criterio nuevo: CA-020-17; CA-020-15 y CA-020-16 se leen ahora sobre el menú lateral.

### DA-2 · ¿Propuestas entra en la sub-navegación del evento? — se recomienda **sí**

Hoy el listado de propuestas solo se alcanza por URL (no hay relación `submissions()` ni entrada de menú). Es el mismo mecanismo y un cambio pequeño, pero el recurso es del plan 009. Se recomienda incluirlo, con la Policy y el 403 de CA-009-5 intactos, y avisar en los planes 009 y 018 para que no dupliquen la entrada. Alternativa: dejarlo para el plan 009.

**Cerrada el 2026-11-17: Propuestas entra en la sub-navegación.**

### DA-3 · Qué pasa con las rutas viejas — se recomienda **404 sin redirección**

`/admin/{org}/forms/form-definitions/...` deja de existir. Es una URL interna del panel, sin API ni enlaces en correos, y R0 aún no tiene uso real en producción. Una redirección exigiría adivinar el evento y añade una ruta que mantener. Alternativa: redirigir las URLs `edit` y `preview` leyendo el `event_id` del formulario.

**Cerrada el 2026-11-17: 404 sin redirección.**

### DA-4 · La lista de todos los formularios de la organización — se recomienda **no conservarla**

Se pierde la vista transversal (nombre, evento, estado, campos de todos los eventos) y la creación «eligiendo evento». A cambio, crear y duplicar siempre ocurren dentro de un evento sin selector (menos errores: un formulario no se crea en el evento equivocado). Alternativa: una lista de solo lectura en el grupo «Organización» que enlace a cada formulario dentro de su evento; añade un recurso y su Policy de lectura.

**Cerrada el 2026-11-17: no se conserva la lista transversal.**

### DA-5 · Agrupación del menú de la organización — se recomienda **Eventos sin grupo y «Organización» con Equipo**

Con Formularios fuera del menú, quedan dos entradas. Se recomienda: Eventos primero y sin grupo, y un grupo «Organización» (Equipo, y luego cuentas, marca, facturación cuando existan). Alternativa: declarar ya un grupo «Evento» vacío; no se recomienda porque un grupo sin entradas no se muestra y no se puede probar.

**Cerrada el 2026-11-17: Eventos sin grupo y «Organización» con Equipo.**

### DA-6 · Momento de ejecución — se recomienda **F0 ahora; F1 y F2 sin quitar horas al envío de propuestas**

Con el 18-nov perdido y el 4-dic como objetivo, la ruta crítica es plan 006 (envío), 009 (panel y exportación) y 010 (producción). F0 es configuración (poco riesgo). F1 toca rutas y pruebas del plan 005 y coincide en archivos con 009 y 018: conviene programarla cuando 006 F1 esté cerrada, o antes si Antonio lo decide así.

**Cerrada el 2026-11-17: F0 ahora; F1 y F2 sin quitar horas al envío de propuestas.**

## 4. Contrato

- **Rutas (panel, no JSON):** `FormDefinitionResource` pasa de `/{tenant}/forms/form-definitions/...` a `/{tenant}/events/{event}/form-definitions/...` (`create`, `{record}/edit`, `{record}/preview`). La lista ya no es una página del recurso: es la página `ManageEventFormDefinitions` del evento, en `/{tenant}/events/{record}/form-definitions` (Filament usa esa página como índice del recurso anidado). No hay rutas JSON ni webhooks: `docs/api/endpoints.md` no cambia.
- **Ruptura:** las URLs viejas dejan de existir (DA-3); es interna del panel y se documenta aquí.
- **Modelo:** `Event` gana el método de relación `formDefinitions(): HasMany`. No es un cambio de esquema ni de firma pública existente.
- **Form Requests / Actions:** sin cambios de firma. `CreateFormDefinition` y `CreateFormFromTemplate` ya reciben el `Event`; ahora lo entrega la ruta y no un selector.
- **Datos:** sin migraciones; no aplica la propuesta de `docs/database-design.md` §6.
- **Traducciones:** claves nuevas en `lang/es` y `lang/en` (grupo «Organización», secciones del evento), con paridad vigilada por `LangParity`. Hoy `lang/` solo tiene `es` y `en`.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? (una fila por acción) | Ver lista y vista previa con `forms.view` o `forms.manage`; crear, editar, publicar, duplicar y eliminar con `forms.manage`; hoy lo traen `org_admin` y `organizer`. **Único cambio:** al ser un recurso anidado, además hace falta poder acceder al evento (`events.manage`, `EventPolicy::viewAny`); ningún rol actual pierde acceso, pero `forms.view` solo ya no basta. Si algún día existe un rol de consulta de formularios sin `events.manage`, habrá que decidirlo en su plan. |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `FormDefinitionPolicy` (sin cambios) y `SubmissionPolicy`. Se prueba en `FormDefinitionPanelTest` y `FormPreviewTest` con las rutas nuevas (CA-020-6 a CA-020-12). |
| ¿Hay modelos nuevos con datos de una organización? ¿Usan `BelongsToOrganization`? | No. `FormDefinition` ya lo usa. |
| ¿El acceso cruza eventos o depende de `event_assignments`? | La ruta acota al evento, pero la autorización sigue siendo por rol de organización. Un usuario con acceso solo por `event_assignments` y sin permiso de organización **no** ve formularios (denegar por defecto, igual que hoy). Limitar las Policies a eventos asignados es del plan 011. |
| ¿Se guardan datos personales nuevos? | No. |
| ¿Se suben archivos? | No. |
| ¿Hay dinero, pagos o webhooks? | No. |
| ¿Se envían correos o notificaciones? | No. |
| ¿Qué se registra en la auditoría? | Sin cambios: se conserva la auditoría existente de las Actions de formularios. |

El riesgo de aislamiento es nuevo en este plan: dos ámbitos acotan a la vez la misma consulta (organización por el scope global y evento por la ruta). Se cubre con pruebas de 404 por cada combinación (CA-020-6).

## 6. Estrategia por fases

Cada fase deja el repo en verde y termina en su propio commit.

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | **A.** `navigationSort` y `navigationGroup` en los recursos de primer nivel del panel de la organización; guardiana de orden declarado; textos del grupo; pruebas CA-020-1 a CA-020-5 (primero en rojo). | S |
| F1 | **B.** `Event::formDefinitions()`, relation page de formularios en `EventResource`, `FormDefinitionResource` anidado, evento tomado de la ruta (sin selector) en alta, plantilla y duplicado, enlaces internos, sub-navegación del evento con Resumen y Formularios. Pruebas CA-020-6 a CA-020-13; las pruebas de 005 se actualizan a las rutas nuevas. | M |
| F2 | Entrada de Propuestas en la sub-navegación (si DA-2 = sí), verificación manual a ~375 y ~1280 px con la consola abierta, y sincronía de documentos: nota en plan 005, CA-009-5 (plan 009) y CA-018-16 (plan 018). | S |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-NNN-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

- **CA-020-1:** *Dado* un administrador de la organización (`org_admin`), *cuando* se arma el menú lateral del panel de la organización, *entonces* el menú muestra, en este orden: el inicio del panel (sin cambios), «Eventos» y el grupo «Organización» con «Equipo» y «Correos». «Formularios» **no** aparece (desde F1); en F0 seguía en el menú, sin grupo, entre «Eventos» y el grupo, porque su entrada desde el evento llegó en F1.
- **CA-020-2:** *Dado* un organizador (`organizer`, que no tiene `team.manage`) o un evaluador (`reviewer`, sin permisos), *cuando* se arma el menú, *entonces* el organizador ve «Eventos» y el grupo «Organización» solo con «Correos» (sin «Equipo»), y el evaluador solo ve el inicio del panel, sin ningún grupo vacío.
- **CA-020-3:** *Dado* un recurso o página con entrada de menú de primer nivel en el panel de la organización, *cuando* se revisan las clases de `app/Filament/Resources/` y `app/Filament/Pages/`, *entonces* cada una declara `navigationSort` (una guardiana falla si se añade una sin orden). Los recursos anidados (`$parentResource`), las páginas sin entrada de menú y las del propio Filament quedan exentos.
- **CA-020-4:** *Dado* el menú del panel, *cuando* se cambia el idioma entre `es` y `en`, *entonces* el grupo y las entradas tienen etiqueta en ambos (paridad de `lang`).
- **CA-020-5:** *Dado* el orden declarado, *cuando* se ejecutan las guardianas de Arch y de Tenancy, *entonces* siguen en verde (A no cambia datos ni consultas).
  Manual: correr `tests/Arch` y `tests/Feature/Tenancy` (forman parte de la suite completa) y anotar su salida en el registro de avance.
- **CA-020-6:** *Dado* un evento A de la organización 1, otro evento B de la misma organización y un evento X de la organización 2, *cuando* el administrador abre `/events/{A}/form-definitions`, *entonces* la lista solo muestra formularios del evento A; `/events/{X}/form-definitions` responde 404; y `edit` y `preview` de un formulario de B o de X bajo la ruta de A responden 404.
- **CA-020-7:** *Dado* un administrador dentro del evento A, *cuando* crea un formulario, *entonces* nace en el evento A con la organización de la ruta; el formulario no ofrece selector de evento; y si el cuerpo de la petición trae otro `event_id`, se ignora.
- **CA-020-8:** *Dado* un administrador dentro del evento A, *cuando* crea un formulario desde una plantilla del sistema, *entonces* el borrador nace en el evento A (CA-005-35 sigue vigente).
- **CA-020-9:** *Dado* un formulario del evento A, *cuando* se duplica desde la lista, *entonces* la copia es un borrador independiente del **mismo** evento y la redirección apunta a la ruta anidada (CA-005-36 sigue vigente).
- **CA-020-10:** *Dado* un miembro sin `forms.view` ni `forms.manage`, *cuando* abre la ruta de formularios de un evento de su organización, *entonces* recibe 403; sin sesión, redirige al inicio de sesión. Un recurso anidado **hereda el acceso al evento padre** (`EventPolicy::viewAny`, que exige `events.manage`): con solo `forms.view` también recibe 403, y con `forms.view` y acceso al evento ve la lista y la vista previa pero no puede abrir el alta (CA-005-6 y CA-005-13 siguen vigentes; hoy ningún rol tiene `forms.view`, así que no cambia el acceso de ningún rol existente).
- **CA-020-11:** *Dado* un miembro de la organización sin rol ni permiso de formularios, con o sin el evento asignado por `event_assignments`, *cuando* abre la ruta de formularios de un evento, *entonces* recibe 403 (denegar por defecto; no se amplía ningún permiso).
- **CA-020-12:** *Dado* un usuario sin permiso de formularios, *cuando* ve la sub-navegación del evento, *entonces* no aparece «Formularios»; y ocultarlo no sustituye a CA-020-10, que sigue negando la ruta.
- **CA-020-13:** *Dada* la ruta antigua `/{tenant}/forms/form-definitions` (y sus `create`, `edit` y `preview`), *cuando* se abre, *entonces* responde 404 y no queda ninguna entrada de menú que apunte a ella (DA-3).
- **CA-020-14:** *Dado* el editor de un formulario en la ruta anidada, *cuando* se guarda, se publica con una convocatoria del mismo evento o se edita con una versión desactualizada, *entonces* el comportamiento es el de CA-005-16 y CA-005-22, y la vista previa de CA-005-12 muestra los mismos datos.
- **CA-020-15:** *Dado* un evento, *cuando* se abre su vista, *entonces* el menú lateral ofrece, bajo «Eventos», «Resumen», «Formularios» y «Propuestas», cada una con su Policy, y las pestañas actuales del resumen (Convocatorias, Tipos, Ejes y Aviso de privacidad) siguen donde están.
- **CA-020-17:** *Dado* un usuario con acceso al evento, *cuando* la ruta trae un evento (su vista, edición, formularios o propuestas), *entonces* el menú lateral anida «Resumen», «Formularios» y «Propuestas» bajo «Eventos», en ese orden y con la sección actual marcada; fuera de un evento no hay ítems anidados, y quien no puede acceder a los eventos no ve ninguno (cada sección además se oculta si su Policy la niega).
- **CA-020-16:** *Dado* el panel completo, *cuando* se revisa a ~375 px y ~1280 px, *entonces* el submenú del evento en el menú lateral se usa con teclado y con toque (a 375 px dentro del cajón), el menú lateral conserva sus 16 rem máximos y el contraste AA (CA-015-14), y la consola no muestra errores.
  Manual: con el usuario organizador del seeder, abrir un evento a 375 px y a 1280 px; recorrer Resumen, Formularios (crear, editar, vista previa, duplicar) y, si aplica, Propuestas; confirmar que los enlaces de «volver» y «vista previa» conservan el evento y que la consola está limpia. Esperar a que terminen las animaciones de entrada antes de concluir un fallo.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| El recurso anidado no se alcanza sin punto de entrada en el padre y la sub-navegación puede comportarse distinto a lo que dice la documentación | Comprobar con una prueba de panel antes de migrar las demás (CA-020-6 y CA-020-15) y, en F1, una primera pasada solo con la relation page antes de tocar el alta y el duplicado |
| Los enlaces internos (`getUrl('edit')`, `'preview'`) pierden el evento al estar anidados | Revisar los cuatro sitios del §2 y cubrirlos con CA-020-9 y CA-020-14 |
| Un formulario se acota por la ruta pero se consulta con solo el scope de organización (fuga entre eventos de la misma organización) | Prueba de 404 por cada combinación de evento y organización (CA-020-6); revisar con `/revisar-aislamiento` antes del commit de F1 |
| Colisión de archivos y de menú con los planes 009, 011 y 018, que también editan el panel del evento | Coordinar el orden (DA-6); F2 sincroniza CA-009-5 y CA-018-16 en el mismo cambio |
| Pérdida de la lista transversal de formularios | Se acepta de forma consciente (DA-4); se anota como deuda si Gabriel o Antonio la reclaman |
| Quedan marcadores o rutas fijas en pruebas de 005 | Buscar las rutas viejas en `tests/` y `docs/` al cerrar F1 (CA-020-13) |
| El plan se ejecuta mientras el plan 011 sigue sin integrar (esta rama) | Implementar desde una rama basada en `staging`, no sobre `feat/plan-011-evaluacion-comite` |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F0 | d5fffac | 2026-11-17 | `NavigationOrderTest` (CA-020-1 a 4) escrita primero: 4 en rojo y 1 ya verde (evaluador); verde tras el código (14 aserciones). Pint y Larastan limpios. Medido antes de escribir: el menú tenía Escritorio, Correos, Eventos, Formularios y Equipo en un solo grupo, orden por defecto; el plan no contemplaba la página Correos ni que el organizador no ve Equipo, y se corrigieron CA-020-1 a 3. CA-020-5: `tests/Arch` y `tests/Feature/Tenancy` en verde dentro de la suite completa. |
| F1 | 87b4bec | 2026-11-17 | Prueba primero: `FormsUnderEventTest` 9 en rojo, luego en verde; las pruebas de panel del plan 005 pasan a rutas anidadas (`withEventRoute` simula la ruta del padre en `Livewire::test`). Pint y Larastan limpios. Desviaciones: (1) los formularios **heredan el acceso al evento** (`events.manage`), `forms.view` solo ya no basta; ningún rol actual lo tiene (CA-020-10). (2) `FormDefinitionsTable` pierde la columna Evento. (3) `ListFormDefinitions` se elimina; sus acciones pasan a `ManageEventFormDefinitions`. |
| F2 | 5b58ab9 | 2026-11-17 | `ManageEventSubmissions` y entrada Propuestas en la sub-navegación (DA-2). Revisado en navegador a 1280 y 375 px con la app del worktree sobre una base ficticia: menú (Escritorio, Eventos, grupo Organización con Equipo y Correos), sub-navegación (selector a 375 px), alta real de un formulario (nace en el evento de la ruta), edición, vista previa, duplicado (copia en el mismo evento), Propuestas y cajón móvil; consola sin errores (CA-020-16). Defectos hallados al verlo y corregidos: el alta daba 404 por la ruta `/forms/form-definitions` (se fija el slug y una prueba pinta la ruta) y los títulos decían «Administrar … Evento». No tocado: el breadcrumb dice «Evento» y no el nombre; a 375 px las acciones de fila quedan fuera de pantalla (ya era así). |
| Integración con `staging` | 673cbfe, a2717ea, af209fe | 2026-11-17 | `staging` avanzó mientras se trabajaba (plan 009 F1, 006 F1-F3, 011 F0-F2). Conflictos resueltos: la lista del 009 pasa a la página del evento (la relación no pasa por `getEloquentQuery`, se aplican las reglas con `SubmissionResource::scopeToList`), se porta la validación de filtros (CA-009-4), se quita el botón «Propuestas» de `ViewEvent` y se adapta `SubmissionListTest`. **Suite completa sobre la rama integrada:** 3 fallos, 28 pasadas, 1364 con advertencia (el contenedor de pruebas no tiene `.env`); dos fallos son `CA-003-11` y `CA-003-14`, que fallan igual en `staging` sin estos cambios; el tercero, la guardiana `preset → security`, era mío (`assert()` en `ManageEventSubmissions`) y se corrigió con una excepción explícita. **Tras el arreglo (no se repitió la suite completa):** Pint (539 archivos), Larastan sin errores y 640 pruebas de Arquitectura, Propuestas, Eventos, Panel, Formularios, Tenancy y paridad de idiomas, sin fallos. CA-020-11 se cubrió también con `event_assignments`. |
| Ajuste: submenú en el menú lateral | 7676a8e | 2026-11-17 | A petición de Antonio, el menú del evento pasa de columna dentro de la página a ítems anidados bajo «Eventos» en el menú lateral azul (DA-1 revisada, CA-020-17). Prueba primero: 2 de las 4 pruebas nuevas en rojo y las otras 2 ya verdes como guardas; verdes tras el código. Revisado en navegador a 1280 y 375 px (lista sin submenú; evento, alta de formulario y Propuestas con su sección marcada; cajón móvil; consola sin errores). Pint (540 archivos), Larastan sin errores y 645 pruebas de Arquitectura, Panel, Eventos, Formularios, Propuestas, Tenancy y paridad de idiomas, sin fallos; no se repitió la suite completa. |
