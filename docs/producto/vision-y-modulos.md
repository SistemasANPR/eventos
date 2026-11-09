# Visión y mapa de módulos

> Qué se construye y en qué orden. Cada módulo se especifica con su propio plan en `docs/plans/` (ADR-0001) antes de implementarse. Prioridad: **Alta** (base del sistema), **Media**, **Baja**.

## Visión

Una plataforma para gestionar **cualquier tipo de evento** de la ANPR (congresos, foros, talleres, webinars, exposiciones) y que pueda ofrecerse como servicio (SaaS) a otras organizaciones. Categoría: plataforma de gestión de eventos con elementos de CRM: guarda la memoria de personas y organizaciones entre ediciones.

## Principios de producto

- **Todo cuelga de un evento**, y todo evento de una organización.
- **Una persona, una ficha** por organización, con su historial en cada evento (ponente, asistente, voluntario, evaluador).
- **Estados explícitos y con historial** en propuestas, registros y pagos.
- **Los organizadores trabajan sin tocar código ni base de datos.**
- **Avisos automáticos** por cambio de estado, con plantillas editables.
- **Multi-idioma de origen** (es, en, pt).

## Mapa de módulos

| Módulo | Qué resuelve | Prioridad | Fase |
|---|---|---|---|
| Núcleo multi-tenant | Organizaciones, usuarios, roles, auditoría, contexto y aislamiento | Alta | 1 |
| Eventos y plantillas | Evento con tipo, sede, fechas, zona horaria, moneda, módulos activos; plantillas y «duplicar evento» | Alta | 1 |
| Correo y notificaciones | Plantillas con variables, disparadores por cambio de estado, historial por persona | Alta | 1–2 |
| Constructor de formularios | Convocatorias, registro, voluntarios y encuestas configurables, sin programar | Alta | 2 |
| Convocatoria y propuestas | Tipos de propuesta, fechas, ejes, flujo de estados completo, cuenta del autor | Alta | 2 |
| Evaluación por comité | Rúbricas versionadas, asignación balanceada, conflictos de interés, revisión ciega, resultados ponderados | Alta | 2 |
| Conferencistas y portal | Acceso por enlace mágico, lista de requisitos, carta de aceptación, acuerdos con firma y PDF | Alta | 2 |
| Programa y agenda | Editor visual por día, sala y horario con detección de choques; versiones; exportación | Alta | 3 |
| Registro, boletos y pagos | Tipos y etapas de precio, cupones, grupos, pasarela, reembolsos, CFDI (ADR-0007) | Alta | 3 |
| Acreditación y check-in | QR personal, gafetes, acceso por sesión, constancias | Alta | 3 |
| Sitio y micrositio por evento | Páginas públicas desde los datos, multi-idioma, dominio propio | Media | 3–5 |
| Patrocinadores y expositores | Paquetes, contratos, cobranza, portal y stands | Media | 4 |
| Voluntarios | Convocatoria, turnos con cupo, horas y constancia | Media | 4 |
| Talleres con cupo | Inscripción, lista de espera y asistencia | Media | 4 |
| Logística y hospedaje | Bloques de hotel, transporte, recorridos como actividades con cupo | Media | 4 |
| Tareas y producción | Tablero del equipo con checklists por tipo de evento | Media | 4 |
| Presupuesto y finanzas | Partidas, gastos, flujo y comparativo entre ediciones | Media | 4 |
| Documentos y contratos | Plantillas con variables y firma electrónica | Media | 4 |
| CRM de comunidad | Historial de personas y organizaciones, etiquetas, segmentos | Alta | 5 |
| Reportes y analítica | Embudo, propuestas por eje, ingresos y asistentes, comparativos entre eventos | Media | 5 |
| Encuestas | Satisfacción por evento y por sesión; NPS | Media | 5 |
| Integraciones y API | API REST, webhooks, calendarios, contabilidad, Podiumeeting mientras exista | Media | 5 |
| App para asistentes (PWA) | Programa personalizado, notificaciones y evaluación de sesiones | Baja | 5 |
| Eventos híbridos o virtuales | Enlaces de transmisión por sesión y grabaciones | Baja | 5 |
| Privacidad, auditoría y respaldos | Consentimientos, exportación y borrado de datos, bitácora | Alta | Transversal |

## Fases

| Fase | Contenido | Resultado esperado |
|---|---|---|
| 0. Definición | Esquema real del origen, datos históricos a migrar, roles y procesos con el equipo, marca | Requisitos y modelo de datos aprobados |
| 1. Núcleo | Plan `001-nucleo-multitenant`; eventos y plantillas; correo | Se crea un evento y se gestiona un equipo con permisos reales |
| 2. Convocatoria y evaluación | Formularios, propuestas, comité, conferencistas y portal | Se reemplaza el flujo del cpanel con control de acceso y avisos |
| 3. Programa y asistentes | Agenda, registro, boletos y pagos, acreditación | Un evento completo se opera sin plataformas externas |
| 4. Ecosistema del evento | Patrocinadores, voluntarios, talleres, logística, tareas, finanzas | Todas las áreas del equipo trabajan en la plataforma |
| 5. Inteligencia y apertura | CRM, reportes, encuestas, micrositio, API, PWA | La organización aprovecha lo aprendido de cada edición |

No se estiman duraciones: dependen del tamaño del equipo.

## Estrategia

- Mantener el cpanel actual en producción mientras se construye el nuevo; corregir de inmediato lo urgente (rotar credenciales, cerrar el acceso a las acciones y al login inyectable).
- Probar la plataforma con un evento pequeño de la ANPR antes del siguiente Congreso Parques.
- Importar personas, organizaciones, ponentes y propuestas de ediciones anteriores al CRM, deduplicando por correo.
- **Validar la demanda del SaaS** con dos o tres organizaciones afines antes de construir todo lo multi-cliente: la ventaja diferencial es el flujo de convocatoria, evaluación por comité y conferencistas.

## Preguntas abiertas

- ¿Se reemplaza Podiumeeting con registro y pagos propios o se integra primero? (ADR-0007)
- ¿Qué organizaciones validan el interés en el producto?
- ¿Cuántos eventos y de qué tipos se gestionan al año?
- ¿Qué datos históricos del congreso se conservan?
- ¿Quién es el dueño del producto y quién mantiene el sistema?
