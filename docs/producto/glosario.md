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
| Tipo de propuesta | `SubmissionType` | Sesión individual, panel, póster o taller | — (enum) |
| Eje temático | `Track` | Tema que organiza propuestas y programa | Evento |
| Ponente | `Speaker` | Persona que presenta (autor o coautor) | Organización (`Contact`) |
| Comité evaluador | `ReviewCommittee` | Grupo de evaluadores de uno o más ejes | Evento |
| Evaluador | `Reviewer` | Persona que califica propuestas | Evento |
| Rúbrica | `Rubric` | Conjunto de criterios con su escala y peso. Versionada | Evento |
| Criterio | `RubricCriterion` | Una pregunta de la rúbrica | Evento |
| Evaluación | `Review` | Calificación de un evaluador a una propuesta | Evento |
| Sesión del programa | `EventSession` | Actividad programada (conferencia, panel, póster, taller, social) | Evento |
| Programa | `Schedule` | Conjunto de sesiones del evento, publicado por versiones | Evento |
| Bloque | `ScheduleBlock` | Rango de horario con un tipo (conferencias, magistral, talleres) | Evento |
| Sede | `Venue` | Lugar del evento | Organización |
| Sala | `Room` | Espacio dentro de una sede | Organización |
| Taller | `Workshop` | Sesión con cupo e inscripción | Evento |
| Asistente | `Attendee` | Persona que asiste al evento | Evento (`Contact`) |
| Inscripción | `Registration` | Registro de un asistente con sus boletos | Evento |
| Tipo de boleto | `TicketType` | Lo ofrecido: precio por etapa y moneda, vigencia, cupo | Evento |
| Boleto | `Ticket` | Lo contratado por un asistente; tiene código QR | Evento |
| Cupón | `Coupon` | Descuento aplicable a boletos | Evento |
| Pago | `Payment` | Hecho financiero con su referencia del proveedor (ADR-0007) | Evento |
| Patrocinador | `Sponsor` | Organización que patrocina un evento | Evento (`Contact`) |
| Paquete de patrocinio | `SponsorshipPackage` | Beneficios y precio de un nivel de patrocinio | Evento |
| Expositor | `Exhibitor` | Organización que tiene un espacio en la expo | Evento |
| Voluntario | `Volunteer` | Persona que apoya la operación | Evento (`Contact`) |
| Turno | `VolunteerShift` | Franja con cupo para voluntarios | Evento |
| Hotel | `Hotel` | Alojamiento recomendado o con bloque | Evento |
| Plantilla de correo | `MailTemplate` | Mensaje editable con variables, por organización | Organización |
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
| Conferencia aceptada y agendada | `EventSession` |
| Conferencista | `Speaker` (con `Contact`) |
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
