# Glosario del dominio

> Fuente de verdad del vocabulario (ADR-0006). **Se consulta antes de nombrar un modelo, una tabla, una ruta o un texto.** Si falta un término, se propone aquí antes de usarlo en código. Los nombres de código están en inglés; las definiciones, en español.
> Estado: **Propuesto**; ajustar con el equipo antes de crear el primer modelo.

## Términos

| Término (ES) | Código (EN) | Definición | Ámbito de datos |
|---|---|---|---|
| Organización | `Organization` | Cliente de la plataforma y *tenant* (ANPR, otra asociación). Dueña de todos sus datos | — (raíz) |
| Usuario | `User` | Cuenta de acceso. Puede pertenecer a varias organizaciones | Plataforma |
| Membresía | `Membership` | Relación usuario–organización con su rol | Organización |
| Contacto | `Contact` | Persona dentro de la memoria de una organización (ponente, asistente, patrocinador, voluntario): una sola ficha reutilizable entre eventos | Organización |
| Evento | `Event` | Una edición concreta (Congreso Parques 2026): fechas, sede, zona horaria, moneda, idiomas | Organización |
| Serie de eventos | `EventSeries` | Agrupa ediciones del mismo evento recurrente. *(Opcional: decidir en el plan de eventos.)* | Organización |
| Convocatoria | `CallForSubmissions` | Periodo en que un evento recibe propuestas de un tipo, con fechas de apertura, cierre y resultados | Evento |
| Propuesta | `Submission` | Envío de un autor a una convocatoria | Evento |
| Lote de decisión | `DecisionBatch` | Una decisión masiva (aceptar, lista de espera o rechazar) sobre varias propuestas en una sola operación, con quién la hizo, cuándo, el filtro y el total; se puede revertir entero (plan 012) | Evento |
| Publicación de resultados | `results_published_at` (convocatoria y propuesta) | Momento en que los resultados de una convocatoria dejan de ser internos y se avisa por correo a cada autor; hasta entonces el autor ve «en evaluación» (plan 012, DA-2) | Evento |
| Ponente invitado | `Speaker` sin propuesta | Persona que el organizador da de alta directamente en Ponentes, sin convocatoria y sin propuesta; la actividad es opcional y se enlaza después (plan 021, DA-8). **Sustituye** al «conferencista invitado» del plan 018 (`Submission` con `origin = invited`): ese plan quedó reemplazado y el valor `invited` de `SubmissionOrigin` queda inactivo (deuda técnica DT-17) | Evento |
| Categoría de propuesta (antes «Tipo de propuesta»; en pantalla, «Categoría») | `SubmissionType` | Sesión individual, panel, póster o taller | — (enum) |
| Tema (antes «Eje temático») | `Track` | Tema que organiza propuestas y programa | Evento |
| Ponente | `Speaker` | Persona que presenta en un evento: su ficha (nombre, apellido, correo, país, organización, cargo, biografía, fotografía, redes). Nace al aceptar una propuesta (uno por participante) o se da de alta a mano. Editar la ficha de un evento no cambia la de otro. Vínculo **opcional** a un `Contact` para sugerir y copiar datos de eventos anteriores (plan 021, DA-1) | Evento (`Contact` opcional) |
| Categoría de ponente | `SpeakerCategory` | Etiqueta propia de cada evento para clasificar ponentes (magistral, invitado, panelista); catálogo independiente del de actividades y de los ejes; un ponente admite varias (plan 021, DA-7) | Evento |
| Categoría de actividad | `ActivityCategory` | Etiqueta propia de cada evento para clasificar actividades; catálogo independiente del de ponentes y de los ejes; una actividad admite varias (plan 021, DA-7) | Evento |
| Categoría de patrocinador | `SponsorCategory` | Etiqueta propia de cada evento (Oro, Plata, Bronce...) que se muestra como «Nivel» del patrocinador; catálogo aparte del de ponentes y actividades (plan 030) | Evento |
| Comité evaluador | `ReviewCommittee` | Grupo de evaluadores de uno o más ejes | Evento |
| Evaluador | `Reviewer` | Persona que califica propuestas | Evento |
| Rúbrica | `Rubric` | Conjunto de criterios con su escala y peso. Versionada | Evento |
| Criterio | `RubricCriterion` | Una pregunta de la rúbrica | Evento |
| Evaluación | `Review` | Calificación de un evaluador a una propuesta | Evento |
| Actividad | `EventActivity` (tabla `event_activities`) | Cada charla, panel, taller o evento social del programa. Nace al aceptar una propuesta (una por propuesta, sin fecha ni hora: «sin programar») o se da de alta a mano; se enlaza con ponentes de forma opcional y muchos a muchos (plan 021, DA-8 y DA-14). **Sustituye** a `EventSession`. No confundir con `Activity` (`App\Models\Activity`), que es la bitácora de auditoría | Evento |
| Programa | `Schedule` | Conjunto de actividades del evento, publicado por versiones | Evento |
| Bloque | `ScheduleBlock` | Rango de horario con un tipo (conferencias, magistral, talleres) | Evento |
| Landing del evento | `EventLanding` (tabla `event_landings`) | Página pública del evento (`/{organización}/events/{evento}`) armada con bloques: una por evento, con borrador y versión publicada. Nace con el evento a partir de una plantilla (portada, datos del evento, convocatorias y ejes) y se edita en un editor visual (plan 022, DA-1, DA-13). No confundir con la página de una convocatoria (`calls.show`) | Evento |
| Bloque de la landing | `LandingBlock` (tipos en el enum `LandingBlockType`) | Pieza de la landing de una lista blanca de 9 tipos: `hero`, `rich_text`, `event_info`, `calls`, `tracks`, `faq`, `image_gallery`, `spacer` y `columns`. Se guarda como objeto `jsonb` con identificador, tipo y contenido validado por su tipo (`LandingBlockRules`); no es un modelo. No confundir con `ScheduleBlock` (bloque del horario) (plan 022, DA-3, DA-13) | Evento (dentro de `EventLanding`) |
| Imagen de la landing | `LandingImage` (tabla `landing_images`) | Imagen subida por el organizador para una landing, en el disco privado `media`; los bloques guardan su identificador, nunca su ruta (plan 022, DA-7) | Evento |
| Sede | `Venue` | Lugar del evento | Organización |
| Sala | `Room` | Espacio del evento donde ocurren actividades (salón, auditorio, aula): catálogo propio de cada evento, con nombre y orden; una actividad programada tiene a lo sumo una, o ninguna. Dos actividades no comparten sala a la misma hora (plan 031, DA-2, DA-3, DA-5). Las sedes y las salas compartidas entre eventos quedan para después | Evento |
| Agenda | — (vista, no es un modelo) | Segunda vista de las actividades en el panel: un día por pantalla, una columna por sala y las horas en vertical; se alterna con la lista y lee las mismas actividades. No es `Schedule` (el programa publicado por versiones) (plan 031, DA-1) | Evento |
| Taller | `Workshop` | Sesión con cupo e inscripción | Evento |
| Asistente | `Attendee` | Persona que asiste al evento | Evento (`Contact`) |
| Inscripción | `Registration` | Registro de un asistente con sus boletos | Evento |
| Tipo de boleto | `TicketType` | Lo ofrecido: precio por etapa y moneda, vigencia, cupo | Evento |
| Boleto | `Ticket` | Lo contratado por un asistente; tiene código QR | Evento |
| Cupón | `Coupon` | Descuento aplicable a boletos | Evento |
| Pago | `Payment` | Hecho financiero con su referencia del proveedor (ADR-0007) | Evento |
| Patrocinador | `Sponsor` | Organización que patrocina un evento. Desde el plan 030 es una ficha simple del evento (nombre, nivel —una categoría de patrocinador del evento—, sitio web, logotipo y orden); el vínculo con `Contact` llega en R4 | Evento (`Contact`) |
| Paquete de patrocinio | `SponsorshipPackage` | Beneficios y precio de un nivel de patrocinio | Evento |
| Expositor | `Exhibitor` | Organización que tiene un espacio en la expo | Evento |
| Voluntario | `Volunteer` | Persona que apoya la operación | Evento (`Contact`) |
| Turno | `VolunteerShift` | Franja con cupo para voluntarios | Evento |
| Hotel | `Hotel` | Alojamiento recomendado o con bloque | Evento |
| Hospedaje | `Lodging` | Alojamiento recomendado de un evento, ficha simple del plan 030 (nombre, dirección, descripción, enlace de reserva, tarifa, hasta 10 imágenes y orden); el bloque con cupo `Hotel` llega en R4 | Evento |
| Plantilla de correo | `MailTemplate` | Personalización de un correo del catálogo para un idioma, con variables de lista blanca; por organización y, desde R1, por evento (`event_id`). La predeterminada del sistema no es un modelo: vive en `lang/` (plan 008) | Organización |
| Notificación del catálogo | `NotificationType` | Un correo que el sistema puede enviar, registrado en código en `NotificationCatalog` con su clave (`submission.received`), variables, criticidad y límite por destinatario. No es un modelo ni una tabla (ADR-0009) | — (código) |
| Ajuste de notificación | `NotificationSetting` | Si una organización tiene activa una notificación no crítica; sin fila, está activa (plan 008, H-008-5) | Organización |
| Envío de correo | `MailDispatch` | Registro de un envío del catálogo: hecho de negocio, estado (`queued`, `sent`, `failed`, `throttled`, `disabled`) y huella del destinatario, nunca su correo en claro (plan 008) | Organización |
| Asignación a evento | `EventAssignment` | Acceso de un usuario a un evento con un rol (ADR-0003, DA-1) | Evento |

## Estados de una propuesta (`SubmissionStatus`)

```
draft → submitted → under_review → accepted → confirmed
                                 ↘ waitlisted → accepted
                                 ↘ rejected
(cualquiera, hasta confirmed) → withdrawn
```

| Estado | Significado |
|---|---|
| `draft` | El autor la guarda sin enviar; editable |
| `submitted` | Enviada; bloqueada para edición salvo regla de la convocatoria |
| `under_review` | Asignada a evaluadores |
| `accepted` | Aceptada por la organización; el autor debe confirmar |
| `waitlisted` | En lista de espera |
| `rejected` | Rechazada; **reversible** por un administrador (a diferencia del cpanel de origen, donde desaparecía) |
| `confirmed` | El ponente aceptó y cumplió los requisitos |
| `withdrawn` | El autor la retiró |

Las transiciones válidas viven en una sola Action por transición; se registran en la auditoría con quién y cuándo.

## Equivalencias con el producto de origen

| En el cpanel de origen | En la plataforma nueva |
|---|---|
| Congreso, evento | `Event` (dentro de una `Organization`) |
| Ponencia, conferencia (propuesta) | `Submission` |
| Conferencia aceptada y agendada | `EventActivity` |
| Conferencista | `Speaker` (del evento; `Contact` opcional) |
| Aspirante | autor de una `Submission` en estado no confirmado |
| Comité, consejo, evaluador (`id_categoria` 3 y 4) | `ReviewCommittee`, `Reviewer` |
| Eje temático (`temas`) | `Track` |
| Pregunta de la rúbrica (`preguntas`, `valor_respuestas`) | `RubricCriterion` con escala |
| Calificación (`calificar_propuestas`) | `Review` |
| Bloque de programa | `ScheduleBlock` |
| Expositor, patrocinador | `Exhibitor`, `Sponsor` |
| Boleto | `TicketType` (catálogo) y `Ticket` (emitido) |
| Credencial, usuario | `User` y `Membership` |

## Decisiones abiertas de vocabulario

- ¿`EventSeries` desde el inicio o solo cuando una organización lo pida?
- ¿`Contact` único por organización o `Person` global entre organizaciones? Se recomienda `Contact` por organización (ADR-0003, DA-3).
- Idioma de los textos de estados en pantalla: por `lang/` (es, en, pt); el valor de código es el literal en inglés.
