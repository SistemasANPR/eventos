# feat: panel de propuestas del organizador

> Plan de la épica E-09 (R0). Especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código.

- **Fecha:** 2026-11-09
- **Estado:** Aprobado
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna
- **Responsable:** Gabriel (`docs/plan-de-desarrollo.md` §6.2: H-009-1, H-009-2 y H-009-3 el 17-nov). Pendiente: no existe aún ningún visualizador de propuestas; depende de `Submission` (plan 006) y del formulario (plan 005), que tampoco existen en el repositorio

## 1. Objetivo y alcance

Que el equipo organizador vea, filtre, abra y exporte las propuestas recibidas en su convocatoria, en un panel de Filament con autorización real en el servidor. En el cpanel de origen la lista no filtraba por evento de forma fiable, la exportación a Excel era del lado del navegador y existía una exportación pública con contraseñas.

**Dentro:** recurso de Filament de propuestas **dentro del evento actual** (parámetro de ruta con binding acotado), con lista paginada y filtros; vista de detalle con las respuestas dinámicas del formulario, los participantes y los archivos; exportación **siempre por cola** con columnas del formulario, tope de filas y archivo temporal; resumen por eje, tipo y día (Debería).

**Coordinación con el plan 018 (conferencistas invitados):** la lista y la exportación aceptan un filtro `origin`; la vista «Propuestas» se acota a `origin = call` y los invitados nunca entran en su resumen ni en sus conteos; el detalle muestra la marca «Invitado» cuando corresponde, y la exportación añade la columna «Origen». La vista «Invitados» la construye el plan 018.

**Fuera:** cambiar el estado de una propuesta, aceptar o rechazar (plan 012); evaluar (plan 011); edición de las respuestas por el organizador; reportes comparativos entre eventos (E-34); vista previa de documentos dentro del panel.

### Historias

| Historia | Tam. | Dependencias |
|---|---|---|
| H-009-1 Como organizador, quiero listar y filtrar propuestas por convocatoria, eje, tipo y fecha con paginación, para revisarlas | M | H-006-1, H-003-1 |
| H-009-2 Como organizador, quiero abrir el detalle con respuestas, participantes y archivos, para leerla completa | M | H-009-1, H-007-2 |
| H-009-3 Como organizador, quiero exportar las propuestas con columnas del formulario, con registro de quién exportó, para trabajar fuera | M | H-009-1 |
| H-009-4 Como organizador, quiero un resumen por eje, tipo y día, para seguir el avance (Debería) | S | H-009-1 |

## 2. Punto de partida

Al 2026-11-09 no existe el proyecto Laravel. Este plan consume `Submission`, `SubmissionParticipant` y las respuestas en `jsonb` del plan 006, la definición del formulario del plan 005 y las descargas del plan 007. La exportación usa `maatwebsite/excel`, ya autorizado en `AGENTS.md`. El «evento actual» lo define el plan 003: el panel usa la organización como tenant y el evento como parámetro de ruta con binding acotado.

## 3. Decisiones

- **DA-1 · Formato de exportación** — se recomienda **XLSX** (`maatwebsite/excel`) y **CSV** como alternativa, ambos desde el servidor; nunca desde el navegador.
- **DA-2 · Qué columnas** — columnas fijas (identificador, convocatoria, tipo, eje, **estado**, fecha de envío en la zona del evento, autor y correo) más **una columna por campo del formulario**, con la etiqueta vigente; un campo retirado con respuestas conserva su etiqueta (ADR-0010). Los archivos se exportan como cantidad de adjuntos, nunca como URL.
- **DA-3 · Exportaciones** — **siempre por cola** (un job con `CurrentOrganization::run()`), con un **tope duro de 5 000 filas**: si el filtro lo supera, se rechaza con un mensaje que pide afinar filtros. El archivo se guarda en el disco privado y se entrega con una URL temporal de 5 minutos; se borra a las 24 horas.
- **DA-4 · Datos del autor visibles** — se recomienda mostrar nombre y correo a quien tenga el permiso de ver propuestas; el modo ciego es del plan 011.
- **DA-5 · Qué estados se exportan** — todos los de la propuesta, incluido `withdrawn`, con la columna de estado; el filtro por estado puede acotarlos.
- **DA-6 · Evento actual** — el recurso vive en `/{tenant}/events/{event}/submissions` y `{event}` se resuelve con binding acotado a la organización (404 si es de otra); sin evento no hay lista.

## 4. Contrato

- **Panel (Filament):** `SubmissionResource` (lista y detalle) en `app/Filament/Admin/`, anidado bajo el evento; sin rutas JSON públicas, nada que añadir a `docs/api/endpoints.md`.
- **Form Requests / filtros:** los filtros se validan (convocatoria, eje y tipo existentes en el evento actual; rango de fechas válido; estado dentro del enum). Un valor inválido o ajeno produce el mismo error de validación genérico.
- **Actions:** `ExportSubmissions::handle($event, $filters, $user)` valida el permiso, recalcula la consulta desde la base (nunca confía en la selección del cliente), aplica el tope y encola `ExportSubmissionsJob`, que genera el archivo y registra la exportación. Tarea programada que borra los archivos de exportación de más de 24 horas.
- **Permisos (matriz del plan 001):** `submissions.view`, `submissions.export` y `files.download`.
- **Propuesta de datos (`docs/database-design.md` §6):**
  1. *Necesidad:* auditar exportaciones y entregar el archivo exportado; ámbito organización; ciclo de vida: la auditoría según retención y el archivo 24 horas.
  2. *Inspección:* se reutiliza `activity_log` (`spatie/laravel-activitylog`, ADR-0005 DA-2); no se crea tabla propia. El archivo vive en el disco privado del plan 007, bajo `organizations/{organization_id}/exports/{uuid}`.
  3. *Modelo:* sin tablas nuevas; cada exportación es un registro de actividad con filtros, número de filas y usuario.
  4. *Integridad:* el registro no contiene el contenido exportado.
  5. *Cambio:* ninguna migración propia.
  6. *Alternativa descartada:* tabla `exports` propia; duplicaría la bitácora sin necesidad en R0.
  7. *Preguntas de negocio:* ninguna.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Administrador y organizador con `submissions.view`: listar y abrir. Con `submissions.export`: exportar. Con `files.download`: ver el enlace de descarga de archivos en el detalle. Miembro sin permisos: nada. |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `SubmissionPolicy` (`viewAny`, `view`, `export`); una prueba por rol y acción, permitido y denegado, en `tests/Feature/Submissions/SubmissionPanelTest.php`. |
| ¿Hay modelos nuevos con datos de una organización? | No; usa `Submission`, que ya usa `BelongsToOrganization` (plan 006). |
| ¿El acceso cruza eventos o depende de `event_assignments`? | En R0 el rol es de organización y se acota por el evento de la ruta (DA-6). Con `event_assignments` (H-011-7) la Policy se limitará a los eventos asignados. |
| ¿Datos personales nuevos? | No se crean; se **exponen** nombre, correo, cargo, empresa y país de autores. Cada apertura de detalle y cada exportación se audita. La exportación nunca incluye credenciales ni tokens, y el archivo exportado se borra a las 24 horas. |
| ¿Se suben archivos? | No; solo enlaza la descarga del plan 007 y guarda temporalmente el archivo exportado en el disco privado. |
| ¿Hay dinero, pagos o webhooks? | No. |
| ¿Se envían correos o notificaciones? | Una notificación del panel al terminar la exportación; no se envían correos. |
| ¿Qué se registra en la auditoría? | Apertura de detalle, exportaciones (usuario, filtros, filas, formato) y denegaciones relevantes. |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Recurso vacío bajo el evento con Policy y prueba de aislamiento | S |
| F1 | H-009-1: lista, filtros y paginación | M |
| F2 | H-009-2: detalle con respuestas dinámicas, participantes y archivos | M |
| F3 | H-009-3: exportación por cola con auditoría | M |
| F4 | H-009-4: resumen (Debería) | S |

## 7. Criterios de aceptación

### H-009-1 · Lista y filtros

- **CA-009-1:** *Dado* propuestas de las organizaciones A y B y de dos eventos de A, *cuando* un organizador de A abre `/{tenant}/events/{event}/submissions`, *entonces* solo ve las del evento de la ruta y de A; y con el `{event}` de la organización B, la respuesta es 404.
- **CA-009-2:** *Dado* filtros por convocatoria, eje, tipo, estado y rango de fechas, *cuando* se aplican combinados, *entonces* la lista devuelve solo las propuestas que cumplen todos.
- **CA-009-3:** *Dado* más de 100 propuestas, *cuando* se pide un tamaño de página mayor a 100, *entonces* el servidor limita a 100 y pagina.
- **CA-009-4:** *Dado* un filtro con el identificador de un eje de otro evento, de otra organización o inexistente, *cuando* se aplica, *entonces* la respuesta es el mismo error de validación genérico en los tres casos, sin revelar si el eje existe.
- **CA-009-5:** *Dado* un usuario sin el permiso `submissions.view`, *cuando* abre la lista, *entonces* recibe 403 y el menú no la muestra.

### H-009-2 · Detalle

- **CA-009-6:** *Dado* una propuesta con respuestas dinámicas, participantes y archivos, *cuando* se abre el detalle con el permiso `files.download`, *entonces* se muestran las respuestas con la etiqueta de cada campo, los participantes y los archivos con su acción de descarga del plan 007.
- **CA-009-7:** *Dado* el identificador de una propuesta de otra organización, *cuando* se abre su URL, *entonces* la respuesta es 404.
- **CA-009-8:** *Dado* una respuesta con HTML o `<script>`, *cuando* se muestra, *entonces* se escapa y no se ejecuta.
- **CA-009-9:** *Dado* un campo que se retiró del formulario después de recibir respuestas, *cuando* se abre el detalle, *entonces* la respuesta antigua se sigue mostrando con su etiqueta conservada.
- **CA-009-10:** *Dado* un usuario con `submissions.view` pero sin `files.download`, *cuando* abre el detalle, *entonces* ve que hay archivos adjuntos pero no la acción de descarga.

### H-009-3 · Exportación

- **CA-009-11:** *Dado* una lista filtrada de un evento, *cuando* el organizador con permiso exporta, *entonces* se encola el job y el archivo resultante contiene solo las propuestas del filtro y de su organización, con las columnas fijas, la columna de estado y una por campo del formulario.
- **CA-009-12:** *Dado* una respuesta o un valor que empieza con `=`, `+`, `-`, `@`, un tabulador o un retorno de carro (por ejemplo `=HYPERLINK(...)` o `\t=cmd|...`), *cuando* se exporta, *entonces* la celda se prefija para que no se interprete como fórmula.
- **CA-009-13:** *Dado* cualquier exportación, *cuando* se genera, *entonces* no contiene contraseñas, tokens de acceso ni URL de archivos.
- **CA-009-14:** *Dado* una exportación realizada, *cuando* se consulta la auditoría, *entonces* aparece quién exportó, cuándo, con qué filtros y cuántas filas.
- **CA-009-15:** *Dado* un usuario sin el permiso `submissions.export`, *cuando* intenta exportar por la interfaz o por la ruta directa, *entonces* recibe 403 y no se encola ni se genera archivo.
- **CA-009-16:** *Dado* un filtro que devuelve más de 5 000 filas, *cuando* se pide la exportación, *entonces* se rechaza con un mensaje que pide afinar los filtros y no se encola nada.
- **CA-009-17:** *Dado* propuestas `submitted` y `withdrawn` en el filtro, *cuando* se exporta, *entonces* ambas aparecen y su columna de estado dice `submitted` o `withdrawn`.
- **CA-009-18:** *Dado* el job de exportación ejecutado por la cola sin sesión, *cuando* corre, *entonces* establece el contexto con `CurrentOrganization::run()`, no lanza `MissingOrganizationContext` y no incluye datos de otra organización.
- **CA-009-19:** *Dado* un archivo de exportación, *cuando* se entrega, *entonces* se descarga con una URL temporal de 5 minutos solo para quien lo pidió, y a las 24 horas la tarea programada lo borra del disco.

### H-009-4 · Resumen (Debería)

- **CA-009-20:** *Dado* una convocatoria con propuestas, *cuando* se abre el resumen, *entonces* muestra el conteo por eje, tipo y día (en la zona del evento) acotado a la organización y al evento de la ruta.
- **CA-009-21:** *Dado* una convocatoria sin propuestas, *cuando* se abre el resumen, *entonces* muestra un estado vacío explicativo y no un error.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Inyección de fórmulas al abrir el Excel | Prefijo en celdas peligrosas, incluidos tabulador y retorno de carro (CA-009-12) |
| Fuga de datos personales por exportación | Permiso específico, auditoría, alcance acotado, tope de filas y archivo que se borra a las 24 horas |
| Exportación grande que agota el proceso web | Siempre por cola y tope de 5 000 filas (DA-3) |
| Respuestas dinámicas con esquemas cambiantes | Etiquetas conservadas con la respuesta (CA-009-9); ADR-0010 |
| Lista lenta con muchas propuestas | Paginación en servidor, índices por `(organization_id, event_id, submitted_at)` |
| Mezclar eventos en la lista o en la exportación | El evento viene de la ruta con binding acotado (DA-6) y toda consulta lo filtra |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
