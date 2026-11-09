# feat: panel de propuestas del organizador

> Plan de la épica E-09 (R0). Especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código.

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

Que el equipo organizador vea, filtre, abra y exporte las propuestas recibidas en su convocatoria, en un panel de Filament con autorización real en el servidor. En el cpanel de origen la lista no filtraba por evento de forma fiable, la exportación a Excel era del lado del navegador y existía una exportación pública con contraseñas.

**Dentro:** recurso de Filament de propuestas con lista paginada y filtros; vista de detalle con las respuestas dinámicas del formulario, los participantes y los archivos; exportación con columnas del formulario; resumen por eje, tipo y día (Debería).

**Fuera:** cambiar el estado de una propuesta, aceptar o rechazar (plan 012); evaluar (plan 011); edición de las respuestas por el organizador; reportes comparativos entre eventos (E-34); vista previa de documentos dentro del panel.

### Historias

| Historia | Tam. | Dependencias |
|---|---|---|
| H-009-1 Como organizador, quiero listar y filtrar propuestas por convocatoria, eje, tipo y fecha con paginación, para revisarlas | M | H-006-1 |
| H-009-2 Como organizador, quiero abrir el detalle con respuestas, participantes y archivos, para leerla completa | M | H-009-1, H-007-2 |
| H-009-3 Como organizador, quiero exportar las propuestas con columnas del formulario, con registro de quién exportó, para trabajar fuera | M | H-009-1 |
| H-009-4 Como organizador, quiero un resumen por eje, tipo y día, para seguir el avance (Debería) | S | H-009-1 |

## 2. Punto de partida

Al 2026-11-09 no existe el proyecto Laravel. Este plan consume `Submission`, `SubmissionParticipant` y las respuestas en `jsonb` del plan 006, la definición del formulario del plan 005 y las descargas del plan 007. La exportación usa `maatwebsite/excel`, ya autorizado en `AGENTS.md`.

## 3. Decisiones

- **DA-1 · Formato de exportación** — se recomienda **XLSX** (`maatwebsite/excel`) y **CSV** como alternativa, ambos desde el servidor; nunca desde el navegador.
- **DA-2 · Qué columnas** — se recomienda columnas fijas (identificador, convocatoria, tipo, eje, estado, fecha de envío en la zona del evento, autor y correo) más **una columna por campo del formulario**, con la etiqueta del idioma base. Los archivos se exportan como cantidad de adjuntos, nunca como URL.
- **DA-3 · Exportaciones grandes** — se recomienda exportar en cola cuando superen `[PENDIENTE: umbral de filas]` y entregar el archivo con enlace temporal; en R0 el volumen esperado cabe en una exportación directa.
- **DA-4 · Datos del autor visibles** — se recomienda mostrar nombre y correo a quien tenga el permiso de ver propuestas; el modo ciego es del plan 011.

## 4. Contrato

- **Panel (Filament):** `SubmissionResource` (lista y detalle) en `app/Filament/Admin/`; sin rutas JSON públicas, nada que añadir a `docs/api/endpoints.md`.
- **Form Requests / filtros:** los filtros de la lista se validan (identificador de convocatoria, eje y tipo existentes en el evento actual; rango de fechas válido).
- **Actions:** `ExportSubmissions::handle($event, $filters, $user)` que recalcula la consulta desde la base (nunca confía en la selección del cliente) y registra la exportación.
- **Propuesta de datos (`docs/database-design.md` §6):**
  1. *Necesidad:* auditar exportaciones; ámbito organización; ciclo de vida: según retención de auditoría.
  2. *Inspección:* se reutiliza `activity_log` (`spatie/laravel-activitylog`, ADR-0005 DA-2); no se crea tabla propia.
  3. *Modelo:* sin tablas nuevas; cada exportación es un registro de actividad con filtros, número de filas y usuario.
  4. *Integridad:* el registro no contiene el contenido exportado.
  5. *Cambio:* ninguna migración propia.
  6. *Alternativa descartada:* tabla `exports` propia; duplicaría la bitácora sin necesidad en R0.
  7. *Preguntas de negocio:* ninguna.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Administrador y organizador con `submissions.view`: listar y abrir. Con `submissions.export`: exportar. Miembro sin permisos: nada. |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `SubmissionPolicy` (`viewAny`, `view`, `export`); una prueba por rol y acción, permitido y denegado, en `tests/Feature/Submissions/SubmissionPanelTest.php`. |
| ¿Hay modelos nuevos con datos de una organización? | No; usa `Submission`, que ya usa `BelongsToOrganization` (plan 006). |
| ¿El acceso cruza eventos o depende de `event_assignments`? | En R0 el rol es de organización y se acota por el evento seleccionado. Con `event_assignments` (H-011-7) la Policy se limitará a los eventos asignados. |
| ¿Datos personales nuevos? | No se crean; se **exponen** nombre, correo, cargo, empresa y país de autores. Cada apertura de detalle y cada exportación se audita. La exportación nunca incluye credenciales ni tokens. |
| ¿Se suben archivos? | No; solo enlaza la descarga del plan 007. |
| ¿Hay dinero, pagos o webhooks? | No. |
| ¿Se envían correos o notificaciones? | No. |
| ¿Qué se registra en la auditoría? | Apertura de detalle, exportaciones (usuario, filtros, filas, formato) y denegaciones relevantes. |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Recurso vacío con Policy y prueba de aislamiento | S |
| F1 | H-009-1: lista, filtros y paginación | M |
| F2 | H-009-2: detalle con respuestas dinámicas, participantes y archivos | M |
| F3 | H-009-3: exportación con auditoría | M |
| F4 | H-009-4: resumen (Debería) | S |

## 7. Criterios de aceptación

### H-009-1 · Lista y filtros

- **CA-009-1:** *Dado* propuestas de las organizaciones A y B, *cuando* un organizador de A abre la lista, *entonces* solo ve las de A y del evento actual.
- **CA-009-2:** *Dado* filtros por convocatoria, eje, tipo y rango de fechas, *cuando* se aplican combinados, *entonces* la lista devuelve solo las propuestas que cumplen todos.
- **CA-009-3:** *Dado* más de 100 propuestas, *cuando* se pide un tamaño de página mayor a 100, *entonces* el servidor limita a 100 y pagina.
- **CA-009-4:** *Dado* un filtro con un identificador de eje de otro evento u otra organización, *cuando* se aplica, *entonces* se rechaza o devuelve vacío sin revelar la existencia de ese eje.
- **CA-009-5:** *Dado* un usuario sin el permiso `submissions.view`, *cuando* abre la lista, *entonces* recibe 403 y el menú no la muestra.

### H-009-2 · Detalle

- **CA-009-6:** *Dado* una propuesta con respuestas dinámicas, participantes y archivos, *cuando* se abre el detalle, *entonces* se muestran las respuestas con la etiqueta de cada campo, los participantes y los archivos con su enlace de descarga del plan 007.
- **CA-009-7:** *Dado* el identificador de una propuesta de otra organización, *cuando* se abre su URL, *entonces* la respuesta es 404.
- **CA-009-8:** *Dado* una respuesta con HTML o `<script>`, *cuando* se muestra, *entonces* se escapa y no se ejecuta.
- **CA-009-9:** *Dado* un campo que se retiró del formulario después de recibir respuestas, *cuando* se abre el detalle, *entonces* la respuesta antigua se sigue mostrando con su etiqueta conservada.

### H-009-3 · Exportación

- **CA-009-10:** *Dado* una lista filtrada, *cuando* el organizador con permiso exporta, *entonces* el archivo contiene solo las propuestas del filtro y de su organización, con las columnas fijas y una por campo del formulario.
- **CA-009-11:** *Dado* una respuesta que empieza con `=`, `+`, `-` o `@` (por ejemplo `=HYPERLINK(...)`), *cuando* se exporta, *entonces* la celda se prefija para que no se interprete como fórmula.
- **CA-009-12:** *Dado* cualquier exportación, *cuando* se genera, *entonces* no contiene contraseñas, tokens de acceso ni URL de archivos.
- **CA-009-13:** *Dado* una exportación realizada, *cuando* se consulta la auditoría, *entonces* aparece quién exportó, cuándo, con qué filtros y cuántas filas.
- **CA-009-14:** *Dado* un usuario sin el permiso `submissions.export`, *cuando* intenta exportar por la interfaz o por la ruta directa, *entonces* recibe 403 y no se genera archivo.

### H-009-4 · Resumen (Debería)

- **CA-009-15:** *Dado* una convocatoria con propuestas, *cuando* se abre el resumen, *entonces* muestra el conteo por eje, tipo y día (en la zona del evento) acotado a la organización.
- **CA-009-16:** *Dado* una convocatoria sin propuestas, *cuando* se abre el resumen, *entonces* muestra un estado vacío explicativo y no un error.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Inyección de fórmulas al abrir el Excel | Prefijo en celdas peligrosas (CA-009-11) |
| Fuga de datos personales por exportación | Permiso específico, auditoría y alcance acotado a la organización |
| Respuestas dinámicas con esquemas cambiantes | Etiquetas conservadas con la respuesta (CA-009-9); ADR-0010 |
| Lista lenta con muchas propuestas | Paginación en servidor, índices por `(organization_id, event_id, submitted_at)` |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
