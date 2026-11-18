# feat: asignar el formulario desde la convocatoria

- **Fecha:** 2026-11-18
- **Estado:** Aprobado (DA-1 a DA-4 cerradas por Antonio el 2026-11-18, con las recomendaciones)
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

Hoy un formulario se asigna a una convocatoria **al publicarlo** desde su pantalla de edición. La convocatoria no muestra qué formulario tiene, se puede publicar sin formulario (y nadie puede enviar propuestas) y no hay forma de desasignar. El plan invierte el flujo: la persona asigna el formulario **desde la convocatoria**, con visibilidad en los dos lados.

**Dentro:** columna «Formulario» y acción «Asignar / Cambiar formulario» en la lista de convocatorias; campo opcional «Formulario» al crear; desasignar en borrador; `PublishCall` exige formulario; columna «Usado en» en la lista de formularios; el modal del formulario pasa a «Publicar y asignar».

**Fuera:** crear el formulario dentro del modal (se enlaza al constructor); cambios en el modelo de datos; el envío público de propuestas; reasignar propuestas ya enviadas (conservan su `form_snapshot`).

## 2. Punto de partida

Medido el 2026-11-18 sobre `staging` (c176bca):

- `call_for_submissions.form_definition_id` es nulo hasta que `PublishFormDefinition` lo fija (`app/Actions/Forms/PublishFormDefinition.php`); es la única vía de asignación y también publica el formulario.
- La acción «Publicar» vive solo en `EditFormDefinition::publishAction` y ofrece las convocatorias del mismo evento.
- `CallsRelationManager` no tiene columna ni acción de formulario. `PublishCall` no comprueba el formulario (solo el aviso de privacidad).
- `PrepareSubmissionForm` y `SubmitSubmission` rechazan el envío si `form_definition_id` es nulo.
- No existe forma de desasignar.

## 3. Decisiones

- **DA-1** · Publicar una convocatoria sin formulario: **se rechaza**. Cerrada el 2026-11-18.
- **DA-2** · Un mismo formulario en varias convocatorias del mismo evento: **se permite**. Cerrada el 2026-11-18.
- **DA-3** · Desasignar: **solo en convocatorias en borrador**; en una publicada solo se cambia. Cerrada el 2026-11-18.
- **DA-4** · Crear el formulario desde la convocatoria: **enlace al constructor**, sin formulario embebido. Cerrada el 2026-11-18.

## 4. Contrato

Sin rutas JSON ni datos nuevos.

- `PublishFormDefinition` se reutiliza tal cual para asignar (publica el borrador y fija `form_definition_id`).
- Action nueva `App\Actions\Calls\UnassignFormFromCall::handle(User $actor, CallForSubmissions $call): CallForSubmissions`: pone `form_definition_id` en nulo solo si la convocatoria está en borrador; audita `call_form_unassigned` con el formulario anterior.
- `PublishCall` lanza `ValidationException` con clave `form` y mensaje `calls.form_required_to_publish` si la convocatoria no tiene formulario (se evalúa tras la comprobación de estado y antes del aviso de privacidad).
- Claves de traducción nuevas en `lang/es` y `lang/en` (misma paridad).

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| Roles y acciones | Asignar o cambiar: `forms.manage` (Policy `FormDefinition::update`, que ya exige `PublishFormDefinition`). Desasignar: `calls.manage` (`CallForSubmissions::update`). Ver la columna: `calls.view`. |
| Policy y pruebas | `FormDefinitionPolicy::update` y `CallForSubmissionsPolicy::update`; una prueba por rol (permitido y denegado) en `tests/Feature/Calls/`. |
| Modelos nuevos | Ninguno. |
| Cruce de eventos | El selector y el servidor limitan a formularios del evento de la convocatoria; un formulario de otro evento se rechaza (validación); de otra organización responde 404. |
| Datos personales | No se guardan nuevos. |
| Archivos, dinero, correos | No aplica. |
| Auditoría | `form_definition_published` (existente), `call_form_unassigned` (nueva). |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F1 | Backend: `UnassignFormFromCall`, exigencia en `PublishCall`, ajuste de las pruebas y factorías que publicaban sin formulario | S |
| F2 | Lista de convocatorias: columna «Formulario», acciones asignar/cambiar y desasignar, aviso de propuestas existentes, campo opcional al crear | M |
| F3 | Lista de formularios: columna «Usado en» y modal «Publicar y asignar» | S |

## 7. Criterios de aceptación

- **CA-022-1:** *Dado* un evento con convocatorias, *cuando* se abre su lista, *entonces* cada una muestra el nombre de su formulario o la insignia «Sin formulario».
- **CA-022-2:** *Dado* una convocatoria y formularios del mismo evento, *cuando* quien tiene `forms.manage` elige uno en «Asignar formulario», *entonces* `form_definition_id` apunta a él y queda publicado.
- **CA-022-3:** *Dado* un formulario en borrador, *cuando* se asigna, *entonces* el modal avisó que se publicará y queda publicado; si no tiene campos válidos, se rechaza con el mensaje de `PublishFormDefinition`.
- **CA-022-4:** *Dado* un selector de formularios, *cuando* se abre, *entonces* solo ofrece los del mismo evento (no los de otro evento ni de otra organización).
- **CA-022-5:** *Dado* un formulario de otro evento de la misma organización, *cuando* se fuerza la asignación, *entonces* el servidor rechaza con error de validación; si es de otra organización, responde 404.
- **CA-022-6:** *Dado* un usuario sin `forms.manage`, *cuando* intenta asignar, *entonces* la acción no se muestra y la Action responde con denegación.
- **CA-022-7:** *Dado* una convocatoria en borrador sin formulario, *cuando* se intenta publicar, *entonces* se rechaza con `calls.form_required_to_publish` y sigue en borrador.
- **CA-022-8:** *Dado* una convocatoria en borrador con formulario, *cuando* quien tiene `calls.manage` desasigna, *entonces* `form_definition_id` queda nulo y se audita `call_form_unassigned`; sin `calls.manage` se deniega.
- **CA-022-9:** *Dado* una convocatoria publicada, pausada o cerrada, *cuando* se intenta desasignar, *entonces* se rechaza y la acción no se muestra; «Cambiar» sigue disponible.
- **CA-022-10:** *Dado* una convocatoria con propuestas enviadas, *cuando* se cambia el formulario, *entonces* el modal avisa que las propuestas conservan su copia y las enviadas no cambian (`form_snapshot` e `form_definition_id` de la propuesta intactos).
- **CA-022-11:** *Dado* el alta de una convocatoria, *cuando* se elige un formulario del evento en el campo opcional, *entonces* queda asignado al crearla; si se deja vacío, queda sin formulario.
- **CA-022-12:** *Dado* un mismo formulario, *cuando* se asigna a dos convocatorias del evento, *entonces* ambas lo usan (DA-2).
- **CA-022-13:** *Dado* la lista de formularios, *cuando* un formulario está asignado, *entonces* la columna «Usado en» muestra la o las convocatorias, o un guion si ninguna.
- **CA-022-14:** *Dado* la edición de un formulario, *cuando* se abre «Publicar», *entonces* el modal se titula «Publicar y asignar a convocatoria» y funciona como antes.
- **CA-022-15:** Las claves de `lang/es` y `lang/en` coinciden (prueba de paridad existente).
- **CA-022-16:** Pantallas de convocatorias y formularios a ~375 y ~1280 px sin errores de consola.
  Manual: abrir `/admin/.../events/{evento}` (pestaña Convocatorias) y `/form-definitions` a 375 y 1280 px; asignar, cambiar y desasignar; mirar la consola.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| DA-1 rompe pruebas y flujos que publican convocatorias sin formulario | F1 ajusta factorías y pruebas; ningún dato real cambia (solo se endurece la publicación). |
| Convocatorias ya publicadas sin formulario | No se tocan; la columna las marca «Sin formulario» y se les puede asignar uno. |
| Cambiar el formulario con propuestas en curso | Aviso en el modal; las enviadas conservan su `form_snapshot`. |

## 9. Registro de avance

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F1 | 0900b0b | 2026-11-18 | Pint OK en lo tocado; 59 pruebas de calls (CallFormAssignmentTest, CallActionsTest, CallPanelTest) en verde; suite completa en contenedor sin .env: 41 fallos que coinciden con la linea base de staging sin .env (25 en SubmitSubmission/PublicSubmissionForm/Track/SubmissionType por SMTP y locale, CA-003-11/14, 13 exportaciones sin maatwebsite, Arch security por assert); F1 no anade fallos. TDD parcial: las pruebas fallaron por el helper antes que por la regla. |
