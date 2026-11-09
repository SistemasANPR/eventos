# Plan de desarrollo de la plataforma de eventos

> **Estado:** Borrador para aprobación (Fase B). Fecha: 2026-11-09. Responsable del producto y de las aprobaciones: **Antonio** (líder de proyecto). Equipo: Antonio y Gabriel (desarrolladores), con agentes de código y revisión humana obligatoria (`AGENTS.md`).
> Orden de autoridad de las fuentes: `AGENTS.md`/`CLAUDE.md`/ADR, luego `docs/producto/`, luego el plan 001, luego seguridad, privacidad, operación, datos y convenciones. Lo que falta se marca `[PENDIENTE: pregunta]`.
> Este documento planea; la especificación ejecutable de cada épica vive en su plan de `docs/plans/` (ADR-0001). Si un plan y este documento se contradicen, manda el plan aprobado y se corrige este documento.

## 1. Objetivos

| # | Resultado medible | Fecha |
|---|---|---|
| O1 | La convocatoria del Congreso Parques 2027 está **abierta en producción** y recibe propuestas de sesión individual, panel y póster, creadas por los organizadores desde la plataforma (formularios, ejes y aviso de privacidad propios del evento) | **18-nov-2026** |
| O2 | Un organizador crea un evento, su convocatoria, sus ejes y sus formularios **sin tocar código ni base de datos** (prueba: lo hace alguien que no programó el módulo, en menos de una jornada) | R0 |
| O3 | Evaluación por comité, decisiones y correos de resultados listos **antes del cierre de la convocatoria** (cierre el **jueves 21-ene-2027**; fecha de resultados `[PENDIENTE]`) | 18-dic-2026 (propuesta) |
| O4 | Cero fugas entre organizaciones: las guardianas de aislamiento en verde en cada commit de `staging` y `main`, y cada rol con prueba de permitido y denegado | continuo |
| O5 | Un evento completo de otra organización se puede crear desde cero con la misma plataforma (valida el SaaS), solo tras el punto de control de demanda del §5 | R5 |

## 2. Acciones para hoy (ruta crítica que no es código)

Tardan y no dependen del código. Responsable por defecto: **Antonio**, salvo que se indique.

| # | Acción | Por qué es ruta crítica | Qué necesito de ti |
|---|---|---|---|
| A1 | **Rotar las credenciales del cpanel actual** (base de datos y correo) y cerrar lo urgente del sitio actual (ver plan B, §6.4) | El Word las declara comprometidas; es el riesgo vivo más alto, independiente del proyecto nuevo | Decidido: se rotan hoy; Antonio confirma al cierre del día |
| A2 | **Resend**: crear cuenta y agregar el dominio de envío; publicar SPF, DKIM y DMARC en el DNS | La verificación de DNS puede tardar horas o días; sin ella no hay correo confiable el 18 | Acceso al DNS del dominio elegido en A7 |
| A3 | **AWS SES**: abrir la solicitud de salida del *sandbox* hoy, aunque no se use todavía (documentado en §14) | La aprobación de producción de SES tarda; queda como proveedor de crecimiento | Cuenta de AWS y región |
| A4 | **Railway**: cuenta, proyecto, entornos `staging` y `production`, servicios web, worker, PostgreSQL y Redis; límites y costos `[PENDIENTE: verificar planes y límites vigentes]` | Sin entorno real no hay esqueleto desplegado (H-002-4) | Tarjeta y presupuesto autorizado |
| A5 | **Almacenamiento de archivos privado** compatible con S3 (Cloudflare R2 o S3) y credenciales | Lo necesita la subida de archivos (plan 007) | Recomendación adoptada salvo objeción: Cloudflare R2 (compatible con S3 y ya usamos Cloudflare); `[PENDIENTE: verificar precio vigente]` |
| A6 | **Cloudflare**: DNS delante del sitio, y llave de *Turnstile* para el anti-robots (propuesta, §3 RC-3) | El anti-robots y el HTTPS dependen de ello | Acceso a Cloudflare del dominio |
| A7 | **URL de la convocatoria**: decidir el subdominio público (por ejemplo, uno del dominio actual del congreso) | Fija el dominio de envío, certificados y CORS | Recomendación: `convocatoria.` y `mail.` como subdominios del dominio actual del congreso, si el equipo tiene acceso a su DNS `[PENDIENTE: confirmar acceso al DNS]`; si no, un dominio nuevo registrado hoy |
| A8 | **GitHub**: autorizar el primer `push` de `main` y `staging`, activar protección de ramas y cargar secretos del CI | Sin push no hay CI ni staging desplegable; es una acción que el agente no puede hacer (AGENTS acción 12) | Tu orden de push |
| A9 | **Capacidad**: declarada en 6 horas por día por persona; falta confirmar si se trabaja sábado 14 y domingo 15 | Sin esto el calendario no es verificable | Respuesta sobre el fin de semana `[PENDIENTE]` |
| A10 | **Textos del evento**: nombre, fechas, sede, ejes y campos de los formularios 2027, y el texto del aviso de privacidad | Los capturan los organizadores en la plataforma desde el día 6 (no se siembran) | Dueño de cada texto `[PENDIENTE]` |

## 3. Alcance

### 3.1 Dentro de R0 (lanzamiento de la convocatoria)

R0 es el recorte vertical mínimo que abre la convocatoria **de forma segura y autónoma**: el organizador define todo lo del evento desde el panel. Se clasifica así (MoSCoW):

**Debe** (36 historias; sin esto no se abre):

- Aislamiento por organización con scope que falla cerrado y guardianas en verde; middleware que fija la organización desde la URL; roles, matriz de permisos y Policies; primer administrador creado por comando; acceso de organizadores endurecido, sin registro abierto, con aviso de inicio de sesión desde un dispositivo nuevo (E-02).
- Evento con zona horaria, idiomas y **aviso de privacidad propio por idioma y versión**; equipo con alta por correo (E-03).
- Convocatoria con fechas de apertura, cierre y resultados (abre y cierra por fecha) y **ejes temáticos creados por el organizador** (E-04).
- **Constructor de formularios**: el organizador crea formularios con campos de texto, número, selección, casilla, fecha, país y archivo, los ordena, los previsualiza y los publica en una convocatoria; cada propuesta guarda la instantánea del formulario con el que se envió (E-05).
- Envío público con participantes, consentimiento y autorización de terceros registrados, límite de peticiones y anti-robots en el envío y en las cargas, acuse y enlace de acceso de un solo uso (E-06).
- Archivos privados con validación por contenido, límites por campo y por propuesta y descargas auditadas (E-07).
- Correos transaccionales con **catálogo y plantillas predeterminadas es/en**, renderizado seguro, cola, reintentos e idempotencia (E-08).
- Panel para ver, filtrar, abrir y exportar propuestas (E-09).
- Producción en Railway con dominio, HTTPS, respaldos probados, monitoreo y correo con SPF/DKIM/DMARC (E-10).
- Esqueleto, Docker, CI y documentos de la plantilla adaptados (E-01).

**Debería** (9 historias; entran si caben, y si no se posponen sin bloquear el *go*): catálogo de correos en el panel (H-008-3), edición de la plantilla por organización con vista previa (H-008-4), desactivar notificaciones no críticas (H-008-5), edición y retiro de la propuesta por el autor (H-006-4, H-006-5), plantillas de formulario de sesión, panel y póster (H-005-5), control manual de la convocatoria: pausar, cerrar y reabrir (H-004-4), limpieza de archivos huérfanos (H-007-3) y resumen de propuestas (H-009-4).

### 3.2 Fuera de R0 (con fecha propuesta)

| Qué | Dónde | Fecha límite propuesta |
|---|---|---|
| Evaluación por comité, asignación, resultados | R1 (E-11) | 18-dic-2026 |
| Decisiones (aceptar, rechazar, lista de espera) y correos de resultados, recordatorios | R1 (E-12) | 18-dic-2026 |
| Plantillas de correo por evento, logotipo y colores, pt, versiones, envío de prueba, registro de envíos, SES | R1 (E-13) | 18-dic-2026 |
| 2FA, RLS, restauración ensayada, exportar y anonimizar datos de una persona | R1 (E-14) | 18-dic-2026 |
| Portal del conferencista, requisitos, acuerdos, carta de aceptación | R2 | `[PENDIENTE: fecha del evento 2027]` |
| Programa, registro, boletos y pagos, acreditación | R3 | `[PENDIENTE]` |
| Patrocinadores, voluntarios, talleres, logística, comunicación masiva, tareas, finanzas, contratos | R4 | `[PENDIENTE]` |
| CRM, reportes, encuestas, API, marca blanca, PWA, onboarding SaaS | R5 | `[PENDIENTE]`, tras el punto de control de demanda |
| Podiumeeting, datos históricos del cpanel, facturación del SaaS | no se contemplan en R0 ni R1 | — |

### 3.3 Supuestos y restricciones

- Una organización real (ANPR) y un evento real en R0; el multi-cliente existe en el diseño (tenant) pero no hay onboarding self-service.
- **Todo desde cero**: no se importa nada del cpanel. Ningún evento ni dato fijo se siembra; la organización se crea con un comando de plataforma y todo lo demás lo captura el equipo en la interfaz.
- Idiomas de R0: es y en; pt antes del cierre (R1).
- Dos personas, 6 horas por día cada una (declarado). `[PENDIENTE: trabajo en fin de semana]`; el calendario asume lunes a viernes y deja el fin de semana del 14 y 15 como holgura.
- Un solo proveedor de correo a la vez: Resend en R0, SES como proveedor de crecimiento (§14).
- Sin SSR en R0 (RC-5). La landing informativa del congreso puede seguir en el sitio actual y enlazar a la convocatoria.

### 3.4 Recortes propuestos y su riesgo

Cada recorte requiere tu aprobación y se registra como ADR corto o como punto de `docs/sprints/deuda-tecnica.md`. **Ninguno se aplica todavía.**

| ID | Recorte | Tipo | Riesgo aceptado | Compensación en R0 | Fecha límite | Dueño |
|---|---|---|---|---|---|---|
| RC-1 | RLS de PostgreSQL diferida (ADR-0003 DA-2; CA-001-12 pasa a E-14) | Seguridad | Un fallo del scope no tendría segunda barrera | Un solo tenant real, guardianas en verde, revisión humana de tenancy, binding acotado | 18-dic-2026 (deuda P1; obligatoria antes de la segunda organización) | Antonio |
| RC-2 | 2FA diferida | Seguridad | Una contraseña robada de un administrador da acceso a datos personales | Pocas cuentas (equipo), contraseñas fuertes con lista de expuestas, límite de intentos, sesión endurecida, registro y aviso por correo de inicio de sesión desde un dispositivo nuevo (CA-001-35 y CA-001-36; requiere la tabla `known_devices` y la notificación `auth.new_device_login`) | Antes de dar acceso a evaluadores externos y, como máximo, 18-dic-2026 (deuda P1) | Antonio |
| RC-3 | Sin antivirus en R0 | Seguridad | Un archivo malicioso aceptado | Lista corta de tipos (PDF, PPTX, imágenes), validación por contenido, nombre generado, descarga solo por organizadores como adjunto desde el bucket privado | Decisión el 18-dic-2026 (deuda P2) | Antonio |
| RC-4 | Marca blanca completa fuera (logotipo y colores solo en correos, R1) | Alcance | El sitio público usa la identidad base | — | R5 | Antonio |
| RC-5 | SSR de Inertia diferido (cambia ADR-0004 DA-1) | Alcance | Menor SEO de la página de convocatoria | Landing en el sitio actual; la página de convocatoria lleva metadatos básicos | Antes de R3 | Gabriel |
| RC-6 | Edición de plantillas de correo por organización como «Debería» | Alcance | El organizador usa las predeterminadas al lanzar | Predeterminadas es/en revisadas por el equipo | R1 | Gabriel |
| RC-7 | Sin panel de plataforma: las organizaciones se crean con un comando | Alcance | Solo soporte puede crear organizaciones | Comando probado y documentado | R5 | Antonio |
| RC-8 | La propuesta nace «enviada» (sin borrador) | Alcance | No se guarda a medias | Consulta y edición del autor mientras esté abierta | R2 | Gabriel |
| RC-9 | Participantes como bloque fijo (mínimo y máximo por convocatoria) más campos dinámicos; sin repetidores dinámicos | Alcance | Un formulario no puede repetir grupos arbitrarios | Cubre ponentes y coautores | R3 (E-21) | Gabriel |
| RC-10 | Tres historias pasan a «Debería» (H-004-4, H-007-3, H-008-3) y H-003-4 se absorbe en H-002-3; los límites por propuesta se quedan en H-007-1 | Alcance | Sin pausa manual de la convocatoria (se cierra moviendo `closes_at`), sin limpieza automática de huérfanos y sin pantalla de catálogo de correos | El cierre por fecha, los límites de subida y las plantillas predeterminadas siguen activos | R1 | Antonio |

## 4. Convención de trabajo

**Release → Épica → Historia → Criterio.**

| Nivel | Identificador | Ejemplo | Dónde vive |
|---|---|---|---|
| Release | `R0`..`R5` | `R0` Lanzamiento de la convocatoria | §5 |
| Épica | `E-NN` | `E-05` Constructor de formularios | §8; se ejecuta con un plan |
| Plan | `NNN` (consecutivo) | `005` | `docs/plans/AAAA-MM-DD-NNN-tipo-titulo.md` |
| Historia | `H-NNN-k` | `H-005-1` | dentro del plan NNN |
| Criterio | `CA-NNN-n` | `CA-005-3` | dentro del plan NNN; cada uno con su prueba (`composer check:criteria`) |
| Decisión abierta | `DA-n` | `DA-2` | dentro del plan |
| Recorte | `RC-n` | `RC-1` | §3.4 |
| Spike | `S-n` | `S-4` | §11 |

Tamaños: S, M, L (relativos). Ninguna historia es mayor que M; una épica puede ser L. Las historias son verticales: datos, lógica, interfaz y pruebas, y dejan algo usable. Los planes de épicas posteriores a R1 no se crean todavía.

## 5. Hoja de ruta

| Release | Objetivo | Épicas | Valor entregado | Criterio de entrada | Criterio de salida (go / no-go) | Riesgos principales |
|---|---|---|---|---|---|---|
| **R0** Lanzamiento de la convocatoria | Convocatoria abierta en producción el 18-nov | E-01..E-10 | Un organizador crea evento, ejes y formularios; el público envía propuestas; el equipo las ve y exporta | Planes 001..010 aprobados; acciones A1..A9 lanzadas | Puertas del §6.3: Puerta 1 el lunes 16-nov y Puerta 2 el martes 17-nov, a las 18:00 | Alcance frente a capacidad; entregabilidad del correo; constructor de formularios; subida de archivos |
| **R1** Evaluación, resultados y endurecimiento | Evaluar y decidir antes del cierre de la convocatoria | E-11..E-14 | Comité evalúa con rúbrica por evento; decisiones y correos de resultado; 2FA y RLS | R0 en producción y estable 5 días | **11 y 12 (evaluación y decisiones) terminadas el 18-dic-2026, antes del cierre del 21-ene-2027**; 13 y 14 pueden terminar después, salvo H-014-1 (2FA), que debe estar antes de invitar a evaluadores externos. Si R0 se retrasa al 4-dic, la prioridad es 011 y 012 | Receso de fin de año; volumen de propuestas; dependencia H-011-2 → H-012-1 (la acción «en revisión» se construye primero) |
| **R2** Conferencistas y portal | Ponente confirmado con requisitos cumplidos | E-15..E-18 | Portal por enlace, lista de requisitos, acuerdos con versión y PDF, carta de aceptación, perfil único, duplicar evento | R1 cerrado; propuestas aceptadas | Un ponente completa sus requisitos sin intervención del equipo | Firma digital y PDF; reutilización de `Contact` |
| **R3** Programa, registro, boletos y pagos, acreditación | Un evento se opera de principio a fin | E-19..E-24 | Sitio público con SSR, agenda sin choques, registro, boletos, pagos, QR y check-in | R2 cerrado; ADR-0007 cerrado | Una compra real de prueba conciliada y un check-in por QR | Pagos (ADR-0007), SSR, editor de agenda |
| **Punto de control de demanda del SaaS** | Decidir si se invierte en lo multi-cliente completo | — | Evidencia de interés de 2 o 3 organizaciones afines que pagarían | Salida de R3 | Al menos dos organizaciones con compromiso explícito `[PENDIENTE: criterio fino]`; si no, R5 se limita a CRM y reportes de la ANPR | Construir producto sin clientes |
| **R4** Ecosistema del evento | Todas las áreas del equipo trabajan en la plataforma | E-25..E-32 | Patrocinadores, voluntarios, talleres, logística, comunicación masiva, tareas, finanzas, contratos | R3 cerrado | Un área completa (por ejemplo patrocinadores) opera un evento sin herramientas externas | Volumen de módulos; dependencia de proveedores |
| **R5** CRM, reportes, API y preparación SaaS | La organización aprende de cada edición y puede ofrecerse a otras | E-33..E-40 | CRM de comunidad, reportes comparativos, encuestas, API, marca blanca, PWA, onboarding y facturación del SaaS | Punto de control de demanda superado (para E-37 y E-40) | Una organización ajena crea un evento completo sola | Privacidad entre organizaciones; soporte multi-cliente |

## 6. Plan de R0 día por día

### 6.1 Factibilidad (con cifras)

| Dato | Valor |
|---|---|
| Fecha de hoy | lunes 9-nov-2026 |
| Fecha dura | miércoles 18-nov-2026 (día de lanzamiento, no de construcción) |
| Días de construcción (lunes 9 a martes 17, sin fin de semana) | 7 por persona = **14 persona-días** |
| Capacidad declarada | 6 h por persona por día = **84 horas** (96 horas si se trabajan sábado 14 y domingo 15, `[PENDIENTE: A9]`) |
| Historias de R0 | 45: 36 «Debe» y 9 «Debería» (14 S y 31 M; ninguna mayor que M) |
| Criterios de aceptación de R0 | 314: 281 de las «Debe» y 33 de las «Debería»; cada uno con su prueba o comprobación manual |
| Ritmo exigido a las 36 «Debe» | **2.6 historias por persona-día = 2.3 horas por historia** (código, pruebas y revisión humana obligatoria de autenticación, permisos, archivos y tenancy), o **18 minutos por criterio** |
| Con fin de semana | 96 h: 2.7 horas por historia y 20 minutos por criterio |
| Cadenas de dependencia del mismo día | 14 en el calendario; una historia que se retrasa empuja a la siguiente |

**Juicio:** **el alcance «Debe» de R0 no es creíble para el 18-nov con dos personas de 6 horas diarias.** Para que quepa, cada historia (de 3 a 10 criterios con prueba y revisión) tendría que cerrarse en menos de media jornada, sin retrabajo y sin errores de ambiente, y el calendario no tiene holgura. Lo realista es llegar a la base: esqueleto, tenancy, acceso, eventos y correo. Por eso:

1. **El plan B es la vía principal de riesgo, no un trámite.** Se prepara en paralelo desde hoy y se deja listo para publicar (§6.4).
2. **Hay dos checkpoints de avance y dos puertas** (§6.3), con criterios medibles. La velocidad real de los primeros tres días decide.
3. Si la puerta final es *no-go*, la convocatoria abre el 18 en el sitio actual y R0 se lanza después; el objetivo es el **4-dic-2026** (confirmado el 9-nov), con importación de las propuestas recibidas entre tanto.
4. **Lo primero que se corta** (en este orden, con tu aprobación): las historias «Debería»; luego la exportación a Excel (CSV simple); luego el panel de resumen. **La seguridad no se recorta.**
5. Trabajar el fin de semana del 14 y 15 sube la holgura de 84 a 96 horas y adelanta la primera puerta al sábado.

### 6.2 Calendario hacia atrás desde el 18

*Antonio* concentra tenancy, autenticación, eventos, archivos, producción y revisión humana; *Gabriel* concentra correo, convocatoria, constructor de formularios, envío y panel. Cada fila es un objetivo, no una promesa: la carga es de 3 historias por persona-día, por encima del ritmo exigido de 2.6.

| Fecha | Día | Antonio | Gabriel | Hito |
|---|---|---|---|---|
| 9-nov | D-9 | Acciones A1..A10; aprobar planes; **H-002-1** (crear el proyecto Laravel con Docker; lo ejecuta una persona) | Verificar Docker y puertos; propuestas de datos §6 de `docs/database-design.md` para 001, 003, 004, 005, 006, 008; preparar el plan B | **H0:** `docker compose up` funcionando y planes aprobados |
| 10-nov | D-8 | H-001-1 aislamiento; H-001-3 roles y Policies | H-002-2 CI; H-002-5 documentos y ADR; H-008-1 catálogo | **Checkpoint 1 (fin del día):** al menos 5 de las 6 historias terminadas (H-002-1, H-001-1, H-001-3, H-002-2, H-002-5, H-008-1) y CI verde. Si no, se prepara el plan B en firme |
| 11-nov | D-7 | H-002-3 esqueleto y página pública del evento; H-003-1 evento; H-003-2 aviso de privacidad | H-008-2 plantillas; H-008-6 cola y proveedor; H-001-2 contexto y comando de organización | **H1:** esqueleto andante local; DNS de Resend verificado (A2) |
| 12-nov | D-6 | H-001-4 acceso; H-003-3 equipo; H-004-1 convocatoria | H-004-2 ejes; H-002-4 esqueleto en Railway `staging` (y servicios de producción creados vacíos); H-005-1 campos | **H2 y Checkpoint 2 (fin del día):** esqueleto en Railway `staging` y al menos 16 de las 19 historias acumuladas |
| 13-nov | D-5 | H-007-1 y H-007-2 archivos; H-006-1 envío (backend) | H-005-2 vista previa; H-005-4 validación; H-005-3 publicar | **H3:** envío de propuestas funcionando en `staging` |
| 14 y 15-nov | D-4, D-3 | Sin trabajo planeado. Si se trabaja el fin de semana, la Puerta 1 pasa al sábado 14 y se avanza con la lista del lunes | Ídem | — |
| 16-nov | D-2 | H-006-3 acuse y enlace; H-006-6 consentimiento; H-010-1 producción | H-004-3 página pública de la convocatoria; H-006-2 anti-robots; H-006-7 formulario público | **Puerta 1 (18:00)** |
| 17-nov | D-1 | H-010-2 dominio y cabeceras; H-010-3 respaldos y monitoreo; H-010-4 ensayo y runbook | H-009-1, H-009-2 y H-009-3 panel y exportación; captura del contenido real por los organizadores | **Puerta 2 (18:00)**; plan B publicable o descartado |
| 18-nov | D-0 | Abrir la convocatoria; monitoreo; guardia | Guardia; correcciones críticas | **R0 en producción** |

### 6.3 Puertas de decisión

Decide **Antonio**. Se proponen dos puertas porque, con 6 horas por día y sin fin de semana, el recorrido completo (G2) no existe antes del martes.

**Puerta 1: lunes 16-nov, 18:00 (dos días antes).** Decide si se sigue invirtiendo en el lanzamiento en la plataforma o si el plan B pasa a ser la vía.

- Al menos **29 de las 36** historias «Debe» terminadas (el calendario prevé 31) `[PENDIENTE: umbral a validar con la velocidad medida el 10-nov]`.
- G1, G3 (lista al 100 % de lo ya construido), G4, G7 y G8 cumplidos.
- Si falla, **no-go**: se activa el plan B ese mismo día.

**Puerta 2: martes 17-nov, 18:00 (la víspera).** Confirma el lanzamiento. Si falla, el plan B se publica el 18 por la mañana (ya preparado).

| # | Criterio | Cómo se mide |
|---|---|---|
| G1 | CI verde en `staging`, incluidas `ModelsAreScopedTest` y `TenantIsolationTest` | Salida real de `composer check` y del CI |
| G2 | Recorrido completo en `staging` y en producción, ejecutado por alguien que no escribió el código: crear evento, convocatoria, ejes y formulario con campos de texto, selección, casilla, archivo y consentimiento → enviar propuesta con archivo → recibir el acuse en una bandeja real → verla en el panel → exportarla | Lista de pasos con resultado de cada uno; 0 fallos bloqueantes |
| G3 | Seguridad mínima de R0 verificada: lista de `docs/seguridad.md` §10 al 100 % y revisión humana de autenticación, Policies, archivos y tenancy | Lista firmada por Antonio |
| G4 | Entregabilidad: SPF, DKIM y DMARC en *pass* en cabeceras reales | Tres mensajes a Gmail y tres a Outlook llegan a la bandeja principal |
| G5 | Producción: `/up` responde 200; existe un respaldo y se restauró uno en `staging`; una alerta de caída llegó al equipo en una prueba | Evidencia de cada uno (respaldo y restauración en `staging` antes de la Puerta 1; producción antes de la Puerta 2) |
| G6 | Rendimiento: 30 envíos consecutivos de prueba sin error 5xx y con respuesta inferior a 1 s en el percentil 95 (sin contar la subida del archivo) | Resultado del script de prueba `[PENDIENTE: umbral a validar con el primer ensayo]` |
| G7 | Aviso de privacidad del evento publicado y aprobado por su dueño; consentimiento registrado con versión | Captura y consulta de un registro |
| G8 | El plan B está listo para publicarse (textos, fechas, parches y credenciales rotadas) | Lista del §6.4 completa |

- **Go (Puerta 2):** G1..G5, G7 y G8 completos. Los «Debería» pendientes no bloquean.
- **No-go:** cualquiera de G1..G5, G7 o G8 incompleto, o una falla de seguridad abierta.
- **Si es no-go:** se publica el plan B; la plataforma sigue hacia R0 con una nueva fecha acordada con Antonio; se documenta qué faltó y se ajusta el alcance.

### 6.4 Plan B: convocatoria en el sitio actual

El sitio actual ya tiene formularios de sesiones y pósters en funcionamiento (`docs/producto/cpanel-actual.md`).

**Criterios de activación:** no-go de la Puerta 1 o de la Puerta 2 (§6.3); o el Checkpoint 1 (10-nov) con menos de 5 de 6 historias terminadas y sin explicación de una causa externa; o una falla de seguridad grave en la plataforma sin corrección posible antes del 18.

**Qué se actualiza en el sitio actual (alcance mínimo):**

1. Fechas y textos de la convocatoria 2027, ejes y aviso de privacidad `[PENDIENTE: textos]`.
2. Parches de seguridad urgentes, **antes** de abrir una convocatoria ahí: rotar credenciales (base de datos y correo); sustituir la consulta de inicio de sesión por una con parámetros; retirar `exportarExcel.php` y cualquier exportación sin sesión; impedir ejecución de archivos subidos (`.php`) en las carpetas públicas; quitar `var_dump` y credenciales comentadas.
3. Verificar el acuse por PHPMailer con las credenciales nuevas.

**Migración posterior a la plataforma** (solo propuestas 2027 recibidas por el sitio actual; no se importa nada histórico):

1. Exportar propuestas, autores, pósters y archivos a CSV y carpeta.
2. Crear en la plataforma un formulario equivalente a los campos del sitio actual y un script `app:import-legacy-submissions` que cree `Contact` y `Submission` en estado `submitted`, con los archivos pasados al almacenamiento privado.
3. Verificar: conteos iguales por tipo, huella (hash) de cada archivo, revisión manual de una muestra del 10 % y lectura de consentimientos.
4. Avisar a los autores con el correo `submission.access_link` y poner el sitio actual en solo lectura (migración y fecha objetivo confirmadas el 9-nov).

**Responsable:** Antonio. **Costo de preparar el plan B:** `[PENDIENTE: persona-días]`; se hace en paralelo desde el 9 de noviembre.

## 7. Módulo de correos (catálogo y plantillas)

Es transversal: todo módulo, presente y futuro, registra aquí sus notificaciones. Spec en los planes 008 (R0) y 013 (R1).

### 7.1 Catálogo

Un registro central **en código** (una clase por notificación o un descriptor) con: clave estable (`submission.received`), nombre, descripción, cuándo se dispara, destinatario, variables disponibles, idiomas, si es crítica y su plantilla predeterminada es/en (pt en R1). Una prueba guardiana falla si una notificación enviada no está registrada o no tiene su predeterminada en es y en. Una pantalla del panel las lista todas con su estado (activa, personalizada, desactivada).

### 7.2 Modelo de personalización

Resolución en este orden: **plantilla del evento → plantilla de la organización → predeterminada del sistema.**

| Nivel | Ámbito de datos | Release |
|---|---|---|
| Predeterminada del sistema | Global (declarada en `TenantModels::GLOBAL_MODELS`) | R0 |
| Organización: asunto, cuerpo, remitente, responder-a | Organización | R0 «Debería» |
| Evento (sobrescribe a la organización) | Evento | R1 |
| Logotipo, colores, pie, firma; texto por idioma (es, en, pt); historial de versiones; activar o desactivar las no críticas; envío de prueba | Organización y evento | R1 (la desactivación de no críticas, R0 «Debería») |

### 7.3 Pantallas

Catálogo con estado; edición con vista previa con datos de ejemplo y selector de idioma; restaurar la predeterminada; historial de versiones y restaurar (R1); envío de prueba (R1); registro de envíos con estado de entrega (R1).

### 7.4 Seguridad (no se recorta)

- Las variables salen de una **lista blanca** por notificación; una variable no listada se rechaza al guardar.
- **Nunca se ejecuta código** escrito por el usuario (ni Blade ni PHP). El cuerpo es Markdown con HTML de entrada deshabilitado, renderizado con CommonMark (ya incluido en Laravel), con enlaces seguros y sanitizado en el servidor.
- El logotipo se valida por contenido (PNG o JPG, tamaño máximo) y se guarda en almacenamiento controlado de la organización.
- **Ningún correo sale sin su predeterminada en es y en.** Un correo crítico (restablecer contraseña, invitación, acuse, enlace de acceso) no se desactiva.
- El destinatario nunca lo define el cuerpo de la plantilla. Los correos van por cola, con reintentos e idempotencia; sin datos personales en logs.

### 7.5 Correos mínimos de R0 y los de antes del cierre

| Clave | Cuándo | Destinatario | Crítica | Release |
|---|---|---|---|---|
| `auth.password_reset` | Se pide restablecer contraseña | Usuario | Sí | R0 |
| `team.set_password` | Se da de alta a un integrante del equipo | Usuario nuevo | Sí | R0 |
| `team.membership_added` | Se agrega a una organización a alguien que ya tiene cuenta | Usuario | Sí | R0 |
| `auth.new_device_login` | Inicio de sesión desde un dispositivo nuevo (compensación de RC-2) | Usuario | Sí | R0 |
| `submission.received` | Se envía una propuesta | Autor | Sí | R0 |
| `submission.access_link` | El autor pide su enlace de acceso (un solo uso) | Autor | Sí | R0 |
| `submission.new_for_organizers` | Llega una propuesta | Organizadores con permiso | No | R0 |
| `submission.updated` | El autor edita su propuesta | Autor y organizadores | No | R0 «Debería» |
| `submission.withdrawn` | El autor la retira | Autor y organizadores | No | R0 «Debería» |
| `call.closing_reminder` | Faltan N días para el cierre | Autores | No | R1 |
| `reviewer.invitation` y `reviewer.assignment` | Se invita o se asignan propuestas | Evaluador | Sí | R1 |
| `submission.accepted`, `submission.rejected`, `submission.waitlisted` | Se decide | Autor | Sí | R1 |

### 7.6 Regla de proceso nueva (propuesta, §Cambios propuestos)

Toda notificación nueva se registra en el catálogo con su plantilla predeterminada y su prueba; es parte de la definición de terminado.

## 8. Catálogo de épicas

| Épica | Nombre | Módulos del Word | Rel. | Tam. | Dependencias | Riesgo | Plan | Valor |
|---|---|---|---|---|---|---|---|---|
| E-01 | Entorno, CI y esqueleto andante | Plantilla (§13) | R0 | M | — | M | 002 | Todo corre igual en local, CI y Railway; recorrido mínimo de punta a punta |
| E-02 | Núcleo multi-tenant y acceso | Núcleo multi-evento y organizaciones; Usuarios y acceso | R0 | L | E-01 | A | 001 | Aislamiento comprobado y roles reales |
| E-03 | Eventos, equipo y aviso de privacidad | Eventos; Auditoría, respaldos y privacidad | R0 | M | E-02 | M | 003 | El organizador configura su evento y su aviso |
| E-04 | Convocatoria y ejes temáticos | Propuestas (convocatoria); Contenido (ejes) | R0 | M | E-03 | M | 004 | Convocatoria con fechas y ejes propios |
| E-05 | Constructor de formularios | Constructor de formularios | R0 | L | E-04 | A | 005 | El diferenciador: cada evento define sus campos |
| E-06 | Envío de propuestas y acceso del autor | Propuestas; Pósters | R0 | L | E-05, E-07, E-08 | A | 006 | El público envía y el autor consulta |
| E-07 | Archivos privados | Pósters (archivos privados) | R0 | M | E-02 | A | 007 | Subida y descarga seguras |
| E-08 | Correos: catálogo y plantillas | Correo; Comunicación | R0 | L | E-02 | A | 008 | Avisos automáticos y editables |
| E-09 | Panel de propuestas | Propuestas; Exportaciones | R0 | M | E-05, E-06 | M | 009 | El equipo ve, filtra y exporta |
| E-10 | Producción y operación | Auditoría, respaldos y privacidad | R0 | M | E-01 | A | 010 | Operación segura desde el día 1 |
| E-11 | Evaluación por comité | Comité: rúbrica y evaluación | R1 | L | E-06, E-08 | M | 011 | Rúbrica por evento y resultados |
| E-12 | Decisiones y resultados | Propuestas (flujo de estados); Correo | R1 | M | E-11 | M | 012 | Aceptar, rechazar, lista de espera y avisos |
| E-13 | Correos avanzados | Correo | R1 | L | E-08 | M | 013 | Plantillas por evento, marca, versiones y registro de envíos |
| E-14 | Endurecimiento y privacidad | Usuarios y acceso; Auditoría, respaldos y privacidad | R1 | L | E-02, E-10 | A | 014 | 2FA, RLS, restauración y derechos de las personas |
| E-15 | Portal del ponente | Conferencistas y portal | R2 | M | E-12 | M | — | Acceso por enlace y su sesión |
| E-16 | Requisitos, acuerdos y carta de aceptación | Conferencistas y portal; Documentos | R2 | L | E-15 | A | — | Acuerdos firmados con versión y PDF |
| E-17 | Perfil único de persona | Usuarios y acceso (persona y cuenta); CRM (base) | R2 | M | E-12 | M | — | Un `Contact` reutilizable entre eventos |
| E-18 | Plantillas de evento y duplicar | Eventos | R2 | M | E-05 | B | — | Un evento nuevo en minutos |
| E-19 | Sitio público del evento con SSR | Sitio y micrositio; Contenido público | R3 | L | E-12 | M | — | Programa, ponentes y sede indexables |
| E-20 | Programa y agenda | Programa; Pósters (sala) | R3 | L | E-12, E-19 | A | — | Agenda sin choques |
| E-21 | Constructor avanzado | Constructor de formularios | R3 | M | E-05 | M | — | Secciones, lógica condicional, repetidores |
| E-22 | Registro y boletos | Boletos; Registro y acreditación | R3 | L | E-21 | A | — | Registro de asistentes |
| E-23 | Pagos y facturación | Pagos y facturación; Boletos | R3 | L | E-22, ADR-0007 | A | — | Cobro conciliado |
| E-24 | Acreditación y check-in | Registro y acreditación | R3 | M | E-22 | M | — | QR, gafetes y constancias |
| E-25 | Patrocinadores y expositores | Patrocinadores y expositores | R4 | L | E-23 | M | — | Paquetes, contratos y portal |
| E-26 | Voluntarios | Voluntarios | R4 | M | E-21 | B | — | Turnos con cupo y constancia |
| E-27 | Talleres con cupo | Talleres y talleristas | R4 | M | E-22 | B | — | Inscripción y lista de espera |
| E-28 | Logística y hospedaje | Hoteles y recorridos; Logística | R4 | M | E-22 | B | — | Servicios del evento con cupo |
| E-29 | Comunicación masiva | Comunicación | R4 | M | E-13 | M | — | Campañas segmentadas |
| E-30 | Tareas y producción | Tareas y producción | R4 | M | E-03 | B | — | Tablero del equipo |
| E-31 | Presupuesto y finanzas | Presupuesto y finanzas | R4 | L | E-23 | M | — | Partidas y comparativo |
| E-32 | Documentos y contratos | Documentos y contratos; Conferencistas | R4 | M | E-16 | M | — | Plantillas con firma |
| E-33 | CRM de comunidad | CRM de comunidad | R5 | L | E-17 | M | — | Memoria entre ediciones |
| E-34 | Reportes y analítica | Reportes; Contenido (numeralia) | R5 | M | E-22 | B | — | Embudo y comparativos |
| E-35 | Encuestas | Encuestas y resultados | R5 | M | E-21 | B | — | Satisfacción y NPS |
| E-36 | API e integraciones | Integraciones y API | R5 | L | E-22 | M | — | API REST y webhooks |
| E-37 | Micrositio, dominio propio y marca blanca | Sitio y micrositio | R5 | L | E-19 | M | — | Identidad por organización |
| E-38 | App para asistentes (PWA) | App para asistentes | R5 | M | E-20 | B | — | Programa personalizado |
| E-39 | Eventos híbridos o virtuales | Eventos híbridos | R5 | S | E-20 | B | — | Enlaces por sesión |
| E-40 | Preparación para el SaaS | Núcleo (SaaS); Pagos (suscripción) | R5 | L | Punto de control de demanda | A | — | Onboarding y facturación |

## 9. Mapa de historias por recorrido

Las etiquetas indican el release de cada paso; el corte de cada release es el último paso con esa etiqueta.

| Rol | Recorrido |
|---|---|
| **Organizador** | R0: iniciar sesión → crear evento y aviso de privacidad → crear ejes → crear formulario → crear convocatoria y publicarla → ver, filtrar y exportar propuestas → editar plantillas de correo (Debería). R1: asignar evaluadores y rúbrica → ver resultados → decidir y avisar. R2: duplicar evento, revisar requisitos de ponentes. R3: armar programa, configurar boletos. R4: patrocinadores, voluntarios, tareas, finanzas. R5: reportes y comparativos |
| **Autor / ponente** | R0: ver convocatoria → enviar propuesta con archivos → recibir acuse → pedir enlace de acceso (Debería: editar o retirar). R1: recibir resultado. R2: portal con requisitos, acuerdos y carta. R3: ver su sesión en el programa |
| **Evaluador** | R1: recibir invitación → declarar conflicto → evaluar con rúbrica → ver su avance. R2: reutilizar su perfil |
| **Asistente** | R3: ver programa → registrarse → pagar → recibir boleto con QR → hacer check-in. R5: app y encuesta |
| **Patrocinador** | R4: elegir paquete → pagar → subir logotipos y lista de personal. R5: reporte de retorno |
| **Voluntario** | R4: postularse → elegir turno → registrar horas → recibir constancia |
| **Soporte de plataforma** | R0: crear organizaciones con comando; acceso a soporte auditado (H-001-4). R1: restauraciones y revisión de incidentes. R5: onboarding de organizaciones, facturación del SaaS |

## 10. Historias detalladas

Cada historia vive en su plan con sus criterios `CA-NNN-n`; aquí se resumen con su enunciado, tamaño, dependencias y notas de permisos (**P**), aislamiento (**A**) y datos personales (**D**). La columna *Criterios* remite a la sección 7 del plan.

### 10.1 R0

**Plan 002 · E-01 Entorno, CI y esqueleto andante**

| Historia | Tam. | Dep. | Notas | Criterios |
|---|---|---|---|---|
| H-002-1 Como desarrollador, quiero levantar todo con un comando y tener los scripts de calidad, para trabajar con paridad con producción | M | — | Puertos 8080 y 5433; el comando de creación lo ejecuta una persona | CA-002-1..7 |
| H-002-2 Como líder, quiero CI en verde en `staging` y `main` con ramas protegidas, para no integrar código roto | S | H-002-1, A8 | CI con secretos solo en GitHub | CA-002-8..12 |
| H-002-3 Como visitante, quiero ver la página pública de un evento publicado, para validar el recorrido completo (esta historia absorbe a la antigua H-003-4) | M | H-002-1, H-001-1 | **A:** consulta acotada a la organización; sin SSR | CA-002-13..19 |
| H-002-4 Como líder, quiero el esqueleto desplegado en Railway `staging`, para probar en un entorno real | S | H-002-2, H-002-3, A4 | Sin datos reales | CA-002-20..24 |
| H-002-5 Como líder, quiero los documentos de la plantilla y los ADR de R0 adaptados, para no salir con marcadores sin rellenar ni decisiones en «Propuesta» | S | H-002-1 | Marcadores de la plantilla y ADR 0003/0004/0005/0008 en «Aceptada» | CA-002-25..26 |

**Plan 001 · E-02 Núcleo multi-tenant y acceso**

| Historia | Tam. | Dep. | Notas | Criterios |
|---|---|---|---|---|
| H-001-1 Como organizador, quiero que mis datos estén aislados de otras organizaciones, para que nadie ajeno los vea | M | H-002-1 | **A:** scope global, excepción sin contexto, guardianas | CA-001-1..10 |
| H-001-2 Como equipo técnico, quiero contexto explícito en jobs y comandos, acceso de plataforma auditado y comandos para crear la primera organización y su administrador, para no perder el aislamiento fuera de HTTP y poder arrancar | S | H-001-1, H-008-6 (hasta entonces `Mail::fake()`) | **A, P:** `withoutOrganizationScope` solo con rol de plataforma | CA-001-11..17 |
| H-001-3 Como administrador de organización, quiero roles y Policies por organización, para que cada persona haga solo lo suyo | M | H-001-1 | **P:** una prueba por rol y acción | CA-001-18..24 |
| H-001-4 Como organizador, quiero iniciar sesión y recuperar mi contraseña con protección contra abuso, para entrar con seguridad | M | H-001-3, H-008-6 (hasta entonces `Mail::fake()`) | **D:** correo y nombre; límite de intentos, sesión endurecida | CA-001-25..36 |

**Plan 003 · E-03 Eventos, equipo y aviso de privacidad**

| Historia | Tam. | Dep. | Notas | Criterios |
|---|---|---|---|---|
| H-003-1 Como organizador, quiero crear y editar un evento (nombre, tipo, fechas, sede, zona horaria, idiomas, moneda y estado) y elegir con qué evento trabajo en el panel, para configurar mi edición sin código | M | H-001-3, H-002-3 | **A:** modelo con tenant; `unique(organization_id, slug)` | CA-003-1..10 |
| H-003-2 Como organizador, quiero escribir y versionar el aviso de privacidad de mi evento por idioma, para cumplir con la ley desde la plataforma | M | H-003-1 | **D:** el texto lo define la organización (`docs/privacidad.md`); contenido sanitizado | CA-003-11..20 |
| H-003-3 Como administrador de organización, quiero dar de alta a mi equipo con rol y enlace para establecer contraseña, para trabajar con permisos reales | M | H-001-3, H-008-6 (hasta entonces `Mail::fake()`) | **P, D:** auditoría de cambios de rol | CA-003-21..30 |

**Plan 004 · E-04 Convocatoria y ejes temáticos**

| Historia | Tam. | Dep. | Notas | Criterios |
|---|---|---|---|---|
| H-004-1 Como organizador, quiero crear una convocatoria con tipo de propuesta, fechas de apertura, cierre y resultados e idiomas, y publicarla, para recibir propuestas | M | H-003-1, H-003-2 | Fechas en UTC; zona del evento | CA-004-1..11 |
| H-004-2 Como organizador, quiero crear, ordenar y traducir los ejes temáticos de mi evento, para clasificar propuestas | S | H-003-1 | Textos traducibles (`spatie/laravel-translatable`) | CA-004-12..16 |
| H-004-3 Como visitante, quiero ver la convocatoria con fechas, ejes y estado en mi idioma, para decidir si envío | M | H-004-1, H-004-2 | Estado calculado por fecha en la petición (sin depender del planificador) | CA-004-17..23 |
| H-004-4 Como organizador, quiero pausar, cerrar y reabrir la convocatoria a mano, para controlar el envío | S | H-004-1 | **Debería.** **P:** permiso específico; auditoría | CA-004-24..27 |

**Plan 005 · E-05 Constructor de formularios**

| Historia | Tam. | Dep. | Notas | Criterios |
|---|---|---|---|---|
| H-005-1 Como organizador, quiero crear un formulario con campos (texto corto y largo, número, selección única y múltiple, casilla, fecha, país, archivo) con etiqueta, ayuda, obligatoriedad y límites, para pedir lo que mi evento necesita | M | H-004-1 | Tipos de lista blanca; etiquetas por idioma | CA-005-1..10 |
| H-005-2 Como organizador, quiero ordenar los campos y ver una vista previa en cada idioma, para revisar antes de publicar | M | H-005-1 | — | CA-005-11..15 |
| H-005-3 Como organizador, quiero publicar el formulario en una convocatoria con reglas claras sobre qué puedo cambiar cuando ya hay propuestas, para no romper lo recibido | M | H-005-1, H-004-1 | Con respuestas no se elimina un campo ni se cambia su tipo | CA-005-16..24 |
| H-005-4 Como sistema, quiero validar cada respuesta en el servidor contra la definición del formulario, para no confiar en el cliente | M | H-005-1 | Reglas dinámicas en un Form Request; respuestas en `jsonb` | CA-005-25..34 |
| H-005-5 Como organizador, quiero partir de una plantilla (sesión, panel, póster) y duplicarla, para ahorrar tiempo | S | H-005-1 | **Debería.** | CA-005-35..37 |

**Plan 006 · E-06 Envío de propuestas y acceso del autor**

| Historia | Tam. | Dep. | Notas | Criterios |
|---|---|---|---|---|
| H-006-1 Como autor, quiero enviar mi propuesta con el formulario del evento, mis datos y los de los participantes, para participar (backend de `SubmitSubmission`) | M | H-005-3, H-005-4, H-007-1 | **A, D:** nace en el contexto del evento; idempotencia ante doble envío | CA-006-1..19 |
| H-006-2 Como organizador, quiero límite de peticiones y anti-robots en los formularios públicos y en las cargas, para evitar spam y abuso | S | H-006-1 | Turnstile verificado en servidor | CA-006-20..28 |
| H-006-3 Como autor, quiero recibir un acuse y pedir un enlace de acceso de un solo uso, para consultar mi propuesta | M | H-006-1, H-008-6 | **D:** el enlace no revela si un correo existe; ligado a correo y propuesta | CA-006-29..40 |
| H-006-4 Como autor, quiero editar mi propuesta mientras la convocatoria esté abierta, para corregir errores | M | H-006-3 | **Debería.** auditoría de cambios | CA-006-41..45 |
| H-006-5 Como autor, quiero retirar mi propuesta, para no participar | S | H-006-3 | **Debería.** estado `withdrawn` reversible por organizador | CA-006-46..49 |
| H-006-6 Como organización, quiero registrar el consentimiento (versión del aviso, fecha) y la autorización de terceros con cada propuesta, para demostrarlos | S | H-003-2, H-006-1 | **D:** sin guardar datos de más | CA-006-50..54 |
| H-006-7 Como autor, quiero un formulario público accesible que conserve lo que escribí ante un error, para enviar mi propuesta sin perder el trabajo | M | H-006-1, H-005-2, H-004-3 | **D:** formulario accesible AA; conserva lo escrito ante un error | CA-006-55..58 |

**Plan 007 · E-07 Archivos privados**

| Historia | Tam. | Dep. | Notas | Criterios |
|---|---|---|---|---|
| H-007-1 Como autor, quiero adjuntar archivos permitidos a mi propuesta, con límites por campo y por propuesta, para sustentarla | M | H-001-1, H-004-1 | Disco privado, nombre generado, validación por contenido, tipos y tamaño | CA-007-1..16 |
| H-007-2 Como organizador, quiero descargar los archivos con enlaces temporales y auditados, para evaluarlos | M | H-007-1, H-001-3 | **A, P:** archivo de otra organización responde 404; descarga como adjunto | CA-007-17..24 |
| H-007-3 Como líder, quiero limpieza de archivos huérfanos, para no acumular basura | S | H-007-1 | **Debería.** Tarea programada | CA-007-25..28 |

**Plan 008 · E-08 Correos: catálogo y plantillas**

| Historia | Tam. | Dep. | Notas | Criterios |
|---|---|---|---|---|
| H-008-1 Como equipo, quiero un catálogo en código de notificaciones, para que cada módulo registre las suyas | M | H-001-1 | Guardiana: notificación sin registrar o sin predeterminada es/en falla | CA-008-1..3 |
| H-008-2 Como sistema, quiero plantillas predeterminadas es/en con renderizado seguro y variables de lista blanca, para enviar correos correctos | M | H-008-1 | Sin Blade ni PHP del usuario | CA-008-4..11 |
| H-008-3 Como organizador, quiero ver el catálogo con su estado en el panel, para saber qué envía el sistema | M | H-008-1, H-001-3 | **Debería.** **P:** permiso de lectura | CA-008-12..14 |
| H-008-4 Como organizador, quiero personalizar asunto, cuerpo, remitente y responder-a de mi organización con vista previa y restaurar la predeterminada, para adaptar la voz | M | H-008-2, H-008-3 | **Debería.** **A:** plantilla con tenant | CA-008-15..20 |
| H-008-5 Como organizador, quiero activar o desactivar las notificaciones no críticas, para controlar el ruido | S | H-008-3 | **Debería.** las críticas no se desactivan | CA-008-21..22 |
| H-008-6 Como sistema, quiero enviar los correos por cola con Resend y un adaptador intercambiable a SES, con reintentos e idempotencia, para entregarlos de forma fiable | M | H-008-1, H-008-2 | **D:** sin datos personales en logs | CA-008-23..30 |

**Plan 009 · E-09 Panel de propuestas**

| Historia | Tam. | Dep. | Notas | Criterios |
|---|---|---|---|---|
| H-009-1 Como organizador, quiero listar y filtrar propuestas por convocatoria, eje, tipo y fecha con paginación, para revisarlas | M | H-006-1, H-003-1 | **A, P:** Policy; máximo 100 por página | CA-009-1..5 |
| H-009-2 Como organizador, quiero abrir el detalle con respuestas, participantes y archivos, para leerla completa | M | H-009-1, H-007-2 | **D:** acceso auditado | CA-009-6..10 |
| H-009-3 Como organizador, quiero exportar las propuestas con columnas del formulario, con registro de quién exportó, para trabajar fuera | M | H-009-1 | Sin credenciales jamás; protección contra inyección de fórmulas | CA-009-11..19 |
| H-009-4 Como organizador, quiero un resumen por eje, tipo y día, para seguir el avance | S | H-009-1 | **Debería.** | CA-009-20..21 |

**Plan 010 · E-10 Producción y operación**

| Historia | Tam. | Dep. | Notas | Criterios |
|---|---|---|---|---|
| H-010-1 Como líder, quiero producción en Railway con web, worker, planificador, PostgreSQL, Redis y bucket privado, con accesos compartidos entre Antonio y Gabriel, para operar la convocatoria | M | H-002-4, A4, A5 | Secretos solo en variables de entorno | CA-010-1..5 |
| H-010-2 Como líder, quiero dominio, HTTPS, cabeceras de seguridad, IP real del cliente y dominio de envío verificado, para que el sitio y el correo sean confiables | M | A2, A6, A7 | HSTS, CSP ajustada | CA-010-6..12 |
| H-010-3 Como líder, quiero respaldos automáticos probados, monitoreo de salud, errores y colas con alertas, para detectar y recuperar fallos | M | H-010-1 | Sin PII en los eventos de error | CA-010-13..17 |
| H-010-4 Como líder, quiero un ensayo de lanzamiento en producción con una organización de prueba que se purga por completo y un runbook mínimo, para lanzar con confianza | S | H-010-1, H-010-2, H-010-3 | — | CA-010-18..21 |

### 10.2 R1

**Plan 011 · E-11 Evaluación por comité**

| Historia | Tam. | Dep. | Notas | Criterios |
|---|---|---|---|---|
| H-011-1 Como organizador, quiero crear una rúbrica por evento y tipo de propuesta con criterios, escala, peso y umbral de discrepancia, para evaluar con mis reglas | M | H-006-1 | Versionada | CA-011-1..4 |
| H-011-2 Como organizador, quiero asignar evaluadores a ejes y propuestas con un mínimo por propuesta, para repartir la carga | M | H-011-7, H-012-1 (Action `MarkSubmissionUnderReview`) | **P, A** | CA-011-5..10 |
| H-011-3 Como evaluador, quiero un panel propio con mis propuestas asignadas y calificar con guardado transaccional, para evaluar sin ver lo ajeno | M | H-011-1, H-011-2, H-014-1 | **P, A:** panel por rol; **D:** acceso mínimo | CA-011-11..16 |
| H-011-4 Como evaluador, quiero declarar conflicto de interés y, si el evento lo pide, evaluar en modo ciego, para una evaluación justa | M | H-011-3, H-005-1 (atributo `identifies_author`) | Ocultar datos del autor | CA-011-17..21 |
| H-011-5 Como organizador, quiero resultados con promedio ponderado, desviación y discrepancias, para decidir con evidencia | M | H-011-3 | — | CA-011-22..24 |
| H-011-6 Como organizador, quiero ver el avance por evaluador y cerrar el periodo de evaluación, para dar seguimiento | S | H-011-3 | — | CA-011-25..27 |
| H-011-7 Como sistema, quiero asignaciones de usuarios a eventos con rol (`event_assignments`), para que evaluadores y coordinadores vean solo su evento | M | H-001-3 (plan 001) | ADR-0003 DA-1; **P, A** | CA-011-28..32 |
| H-011-8 Como organizador, quiero invitar a un evaluador por correo con el rol `reviewer` y un enlace para establecer su contraseña, para que entre a su panel sin ver el de organizadores | M | H-011-7, H-008-6, H-014-1 | **P, D:** rol `reviewer` sin acceso al panel de organizadores; exige 2FA (H-014-1) | CA-011-33..37 |

**Plan 012 · E-12 Decisiones y resultados**

| Historia | Tam. | Dep. | Notas | Criterios |
|---|---|---|---|---|
| H-012-1 Como organizador, quiero aceptar, rechazar, poner en lista de espera y revertir una propuesta, para decidir con historial | M | H-006-1 | **P:** una Action por transición; auditoría | CA-012-1..8 |
| H-012-2 Como organizador, quiero decidir sobre una selección filtrada, para ahorrar tiempo | M | H-012-1 | Confirmación y reversa | CA-012-9..13 |
| H-012-3 Como autor, quiero recibir el resultado de mi propuesta, para saber qué sigue | M | H-012-1, H-008-2 | **D:** correos críticos | CA-012-14..22 |
| H-012-4 Como autor, quiero un recordatorio antes del cierre, para corregir mi propuesta a tiempo | S | H-006-1; H-006-4 solo de forma condicional (si no existe, el recordatorio es informativo) | Planificador | CA-012-23..26 |
| H-012-5 Como organizador, quiero añadir un comentario opcional para el autor al decidir, para dar retroalimentación | S | H-012-1 | **D** | CA-012-27..29 |

**Plan 013 · E-13 Correos avanzados**

| Historia | Tam. | Dep. | Notas | Criterios |
|---|---|---|---|---|
| H-013-1 Como organizador, quiero que la plantilla del evento sobrescriba la de la organización, para adaptar cada evento | M | H-008-4 (si no entró en R0, se hace primero en F1 de este plan) | Orden evento → organización → sistema | CA-013-1..5 |
| H-013-2 Como organizador, quiero logotipo, colores, pie y firma, para que el correo lleve mi marca | M | H-013-1 | Logotipo validado por contenido | CA-013-6..10 |
| H-013-3 Como organizador, quiero textos por idioma (es, en, pt), para comunicarme en el idioma del autor | S | H-013-1 | pt con predeterminada | CA-013-11..13 |
| H-013-4 Como organizador, quiero historial de versiones y restaurar una anterior, para deshacer errores | M | H-013-1 | **A** | CA-013-14..16 |
| H-013-5 Como organizador, quiero enviar un correo de prueba, para ver el resultado real | S | H-013-1 | Solo a mi propio correo | CA-013-17..19 |
| H-013-6 Como organizador, quiero un registro de envíos con estado de entrega, para saber qué recibió cada persona | M | H-008-6 | **D:** retención definida; webhooks con firma | CA-013-20..26 |
| H-013-7 Como líder, quiero enviar también con SES, para no depender de un solo proveedor | S | H-008-6, A3 | Cambio por configuración | CA-013-27..29 |

**Plan 014 · E-14 Endurecimiento y privacidad**

| Historia | Tam. | Dep. | Notas | Criterios |
|---|---|---|---|---|
| H-014-1 Como administrador, quiero verificación en dos pasos obligatoria para todo rol con acceso a datos de autores, para proteger las cuentas con más acceso | M | H-001-4 | Cierra RC-2 | CA-014-1..5 |
| H-014-2 Como líder, quiero RLS de PostgreSQL como segunda barrera de aislamiento, para protegerme de un fallo del scope | M | H-001-1, H-001-2 | Cierra RC-1; **A:** pruebas de acceso permitido y denegado | CA-014-6..15 |
| H-014-3 Como líder, quiero ensayar la restauración de un respaldo y documentar los runbooks, para recuperar el servicio | S | H-010-3 | Registro en `docs/operacion.md` | CA-014-16..19 |
| H-014-4 Como organización, quiero exportar y anonimizar los datos de una persona, para atender sus derechos | M | H-006-1 | **D:** auditoría; respeta lo que debe conservarse | CA-014-20..30 |
| H-014-5 Como líder, quiero decidir sobre el escaneo de archivos, para cerrar RC-3 | S | H-007-1 | Spike con decisión | CA-014-31..32 |
| H-014-6 Como organización, quiero retención y anonimización programadas, para no guardar datos de más | M | H-014-4 | **D** | CA-014-33..37 |

### 10.3 R2 (épicas sin plan todavía)

Los criterios se numerarán al crear cada plan. Se esbozan aquí en *Dado / cuando / entonces*.

**E-15 Portal del ponente.**

- *Como ponente, quiero entrar con un enlace de un solo uso, para acceder sin contraseña.* Dado un ponente con propuesta aceptada, cuando pide su enlace, entonces recibe un correo que caduca y solo funciona una vez; con un enlace usado o caducado ve un mensaje genérico. **P, A, D.**
- *Como ponente, quiero ver mi sesión y su estado, para saber qué sigue.* Dado un ponente de un evento, cuando entra, entonces ve solo sus sesiones; un identificador ajeno responde 404.

**E-16 Requisitos, acuerdos y carta de aceptación.**

- *Como ponente, quiero una lista de requisitos con avance, para saber qué me falta.* Dado un requisito pendiente, cuando lo cumplo, entonces el avance sube y el organizador lo ve.
- *Como ponente, quiero firmar los acuerdos con registro de la versión del texto, para dejar constancia.* Dado un acuerdo publicado, cuando lo acepto, entonces se guarda fecha, versión y un PDF; un acuerdo modificado exige nueva aceptación.
- *Como organizador, quiero una carta de aceptación generada con variables, para no usar plantillas Word.* Dado un ponente confirmado, cuando genero la carta, entonces sale en PDF con sus datos y la sesión.
- *Como sistema, quiero recordar a quienes tienen pendientes, para reducir el seguimiento manual.* Dado un requisito vencido, cuando corre el planificador, entonces se envía el recordatorio una sola vez por periodo.

**E-17 Perfil único de persona.**

- *Como ponente, quiero un perfil reutilizable entre eventos de la organización, para no repetir mis datos.* Dado un `Contact` con dos eventos, cuando edito mi perfil, entonces ambos lo reflejan; otra organización no lo ve. **A, D.**

**E-18 Plantillas de evento y duplicar.**

- *Como organizador, quiero duplicar un evento sin datos de personas, para empezar el siguiente rápido.* Dado un evento con formularios, ejes y rúbrica, cuando lo duplico, entonces se copia la configuración y ninguna propuesta ni persona.
- *Como organizador, quiero activar o desactivar módulos por evento, para no ver lo que no uso.* Dado un webinar, cuando desactivo hospedaje, entonces el módulo no aparece ni responde.

### 10.4 R3 a R5

Solo épicas (§8), sin historias detalladas todavía. Se esbozan al abrir cada release.

## 11. Spikes y decisiones pendientes

| # | Pregunta | Cierra | Debe resolverse antes de | Cuándo |
|---|---|---|---|---|
| S-1 | Filament multi-tenant con `spatie/laravel-permission` y la organización como *team*: ¿encajan sin conflictos? | ADR-0003 aceptada (DA-1, DA-3, DA-4) | E-02 (H-001-3) | 10-nov |
| S-2 | Entregabilidad real de Resend con el dominio (SPF, DKIM, DMARC) y límites del plan | ADR-0009 (correo transaccional y catálogo) | E-08 (H-008-6) | 11-nov |
| S-3 | Subida de archivos al bucket privado en Railway con validación por contenido y URL temporal | Nota en el plan 007 | E-07 | 12-nov |
| S-4 | Modelo de formulario dinámico: definición, versiones o reglas de cambio, respuestas en `jsonb`, validación de servidor | ADR-0010 (formularios dinámicos) | E-05 | 11-nov |
| S-5 | Inertia sin SSR en R0 y migración a SSR en R3 | ADR-0004 DA-1 y DA-4 actualizadas | E-02 (H-002-3) | 10-nov |
| S-6 | Turnstile frente a otras opciones anti-robots, y límite de peticiones | Nota en el plan 006 | E-06 (H-006-2) | 12-nov |
| S-7 | Enlace de acceso del autor: un solo uso y caducidad | Nota en el plan 006; ajuste de `docs/seguridad.md` §2 si aplica | E-06 (H-006-3) | 12-nov |
| S-8 | Recortes de seguridad RC-1..RC-3 | ADR-0011 (recortes de seguridad de R0) | Inicio de E-02 | 9-nov |
| S-9 | Escaneo de archivos y RLS | ADR-0011 cerrada, deuda P1/P2 | E-14 | 11-dic |
| S-10 | Pasarela, modelo de ingresos y CFDI | ADR-0007 aceptada (DA-1..DA-5) | E-23 | `[PENDIENTE]` |
| S-11 | Editor de agenda: Livewire o React dentro de Filament | Plan de E-20 | E-20 | `[PENDIENTE]` |
| S-12 | Contacto único por organización o persona global | ADR-0003 DA-3 y glosario | E-17 | — |
| S-13 | Limpieza de metadatos de PDF y PPTX para la revisión ciega | Plan 007 | E-11 (H-011-4) | antes de R1 |
| S-14 | Límites de subida y tiempo máximo de petición en Railway | Nota en el plan 007 y 010 | E-07 | 12-nov |

## 12. Estrategia de calidad

- **Pirámide:** muchas pruebas unitarias (Actions, reglas, renderizado de plantillas, validación dinámica); pruebas de funcionalidad por rol y acción (permitido y denegado); **pocas** de extremo a extremo para los dos flujos críticos: *enviar una propuesta y recibir el correo*, y *crear un evento, formulario y convocatoria como organizador*.
- **Guardianas** (`tests/Arch`, `tests/Feature/Tenancy`): todo modelo es con tenant o global declarado; una organización nunca ve datos de otra; toda notificación está en el catálogo con su predeterminada es/en; ninguna ruta pública sin límite de peticiones; ningún modelo con `$guarded = []`; `composer check:criteria` en el CI.
- **Datos:** fábricas con `organization_id`; sin red ni `.env` real (`Mail::fake()`, `Storage::fake()`, `Http::fake()` para Turnstile y Resend).
- **Seguridad por release:** puerta con la lista de `docs/seguridad.md` §10, `composer audit` y `npm audit`, búsqueda de secretos en el CI, y revisión humana de autenticación, Policies, archivos y tenancy.
- **Accesibilidad:** AA en el formulario público (etiquetas, foco, errores asociados a campos, contraste); prueba manual con teclado y lector de pantalla del formulario generado dinámicamente, que es la pantalla de mayor riesgo.
- **Rendimiento:** envío público con prueba de carga ligera (criterio G6); consultas con `preventLazyLoading` fuera de producción; paginación en el servidor.
- **Cobertura:** umbral en el CI, fijado al valor medido al terminar R0 y que no baja.

## 13. Datos y migración desde el cpanel

- **No se importa nada del cpanel.** La plataforma arranca vacía; ningún evento ni dato histórico se siembra.
- **Convivencia:** el sitio y el cpanel actuales siguen como están. Solo se corrige lo urgente de seguridad (A1 y §6.4). No se integran con la plataforma.
- **Única migración posible:** las propuestas 2027 recibidas por el sitio actual si se activa el plan B (§6.4), con verificación por conteos, huellas de archivos y muestra del 10 %.
- **Después de R1:** se archiva el sistema anterior como solo lectura `[PENDIENTE: fecha]`.
- **CRM e historial** (E-17 y E-33) crecen con los datos nuevos; no se alimentan de ediciones previas.

## 14. Entornos, despliegue y operación desde el primer día

| Entorno | Para qué | Datos | Despliegue |
|---|---|---|---|
| Local (Docker, VS Code) | Desarrollo en `staging` | Ficticios | Manual; puertos 8080 y 5433; Mailpit en 8025 |
| CI | Pull requests, `staging` y `main` | Efímeros | Automático |
| **Railway `staging`** | Revisión del equipo y de la ANPR | Ficticios o anonimizados | Automático desde `staging` (se desplegará el día 3, H-002-4) |
| **Railway `production`** | Uso real | Reales | Desde `main`, por pull request con aprobación humana y migraciones aplicadas por una persona |

Servicios: aplicación web, *worker* de colas, planificador, PostgreSQL gestionado con respaldos, Redis, bucket privado (A5) y Cloudflare delante. Errores con un cliente sin PII (decisión pendiente de ADR) y Horizon para colas.

**Correo transaccional (documentado para la decisión):**

| Tema | Resend (R0) | Amazon SES (crecimiento) |
|---|---|---|
| Por qué | Alta rápida y poca configuración para la fecha dura | Menor costo por volumen y más control cuando crezca |
| Estado | A2: dominio y DNS hoy | A3: salida del *sandbox* solicitada hoy, aunque no se use todavía |
| Límites y precio | `[PENDIENTE: verificar límites y precio vigentes]`; el plan gratuito puede no alcanzar para los acuses de una convocatoria | `[PENDIENTE: verificar cuotas iniciales]` |
| Integración | Controlador de correo de Laravel con un adaptador propio detrás de una interfaz (H-008-6) | Mismo adaptador intercambiable por configuración (H-013-7) |
| Cambio de proveedor | Sin cambios de código en los módulos: el catálogo y las plantillas no dependen del proveedor | Requiere dominio verificado y webhooks de entrega propios |

Variables nuevas: las del proveedor de correo, almacenamiento y Turnstile, siempre en `.env.example` con valores ficticios y en `docs/operacion.md`. Respaldos: diarios con restauración ensayada en `staging` antes de la Puerta 1 (G5) y a fondo en R1 (H-014-3).

## 15. Riesgos y supuestos

Probabilidad (P) e impacto (I): A alta, M media, B baja.

| # | Riesgo | P | I | Mitigación | Dueño |
|---|---|---|---|---|---|
| 1 | El alcance de R0 no cabe en la capacidad (exige 2.3 horas por historia y 18 minutos por criterio) | A | A | Checkpoints el 10 y 12-nov y puertas el 16 y 17-nov; lista «Debería» como palanca; plan B real | Antonio |
| 2 | Fuga entre organizaciones | B | A | Scope que falla cerrado; guardianas; revisión humana; RLS en R1 (RC-1) | Antonio |
| 3 | El correo no llega o cae en spam | M | A | A2 hoy; S-2; prueba en Gmail y Outlook; segundo proveedor (SES) preparado | Antonio |
| 4 | El constructor de formularios genera datos o esquemas inconsistentes | M | A | ADR-0010; reglas de cambio con respuestas; validación en servidor; spike S-4 | Gabriel |
| 5 | Subida de archivos maliciosos | M | A | Validación por contenido, disco privado, descarga como adjunto; decisión de escaneo (RC-3) | Antonio |
| 6 | Spam o abuso de los formularios públicos | A | M | Límite de peticiones y Turnstile (H-006-2) | Gabriel |
| 7 | Credenciales del cpanel comprometidas | A | A | A1 hoy; parches del plan B | Antonio |
| 8 | Retraso del DNS o de la cuenta de correo | M | A | A2 y A3 hoy; verificación diaria hasta H2 | Antonio |
| 9 | Aviso de privacidad sin aprobar | M | M | Dueño asignado (A10); G7 bloquea el *go* | Antonio |
| 10 | Mala configuración de Railway o pérdida de datos | M | A | Respaldos y restauración ensayada (G5); migraciones solo con aprobación | Antonio |
| 11 | Dependencia de dos personas (vacaciones, enfermedad) | M | A | Documentar en los planes; runbooks mínimos | Antonio |
| 12 | El receso de fin de año retrasa R1 | M | M | R1 con meta 18-dic-2026; recortes de R1 si hace falta | Antonio |
| 13 | Producto sin demanda de otras organizaciones | M | M | Punto de control de demanda antes de R5 | Antonio |
| 14 | Una propuesta enviada por un enlace de acceso reutilizado | B | M | Un solo uso, consumo atómico y caducidad corta (S-7) | Gabriel |
| 15 | Cadenas de dependencia del mismo día (14 en el calendario): un retraso se propaga | A | M | Ordenar por dependencia en cada reunión diaria; mover «Debería» fuera de la ruta crítica | Antonio |
| 16 | El constructor de formularios cambia mientras se envían propuestas (carrera) | M | A | Bloqueo de la definición y instantánea por propuesta (ADR-0010); CA de envío concurrente | Gabriel |
| 17 | Metadatos de PDF y PPTX delatan al autor en la revisión ciega | M | M | Advertencia al organizador; decisión de limpiar metadatos antes de R1 (plan 007) | Antonio |

Supuestos: capacidad del §6.1; una sola organización activa; correo con un solo proveedor a la vez; el equipo acepta aprobar los planes el 9-nov.

## 16. Definiciones de Listo y Terminado

Coherentes con ADR-0001 y `AGENTS.md`.

**Historia lista para empezar:** tiene su plan **aprobado**; enunciado «Como, quiero, para»; criterios `CA-NNN-n` con casos de error y límites; dependencias resueltas; propuesta de datos de `docs/database-design.md` §6 aprobada si toca el esquema; decisiones abiertas cerradas.

**Historia terminada:** cada criterio tiene su prueba (o `Manual:`) y pasa; `composer check` y, si toca el frontend, `npm run typecheck`, `lint`, `test` y `build` pasan con salida real; verificada en el navegador a ~375 px y ~1280 px cuando cambia la interfaz; Policy y prueba de permitido y denegado si hay acción; prueba de aislamiento si hay modelo con tenant; datos personales nuevos en `docs/privacidad.md`; si es una notificación, está en el catálogo con su predeterminada es/en y su prueba (regla propuesta); diff revisado y `git diff --check` limpio.

**Épica terminada:** todas sus historias «Debe» terminadas; su plan en estado `Terminado` con el registro de avance y el hash de cada fase; deuda dejada anotada en `docs/sprints/deuda-tecnica.md`; ADR escritos para lo costoso de revertir.

**Release terminado:** punto de control cumplido con evidencia; la lista de seguridad (§10 de `docs/seguridad.md`) al 100 % y revisión humana hecha; CI verde en `staging`; promoción a `main` por pull request con aprobación humana; despliegue sin errores durante 15 minutos de observación; respaldos verificados.

## 17. Gobernanza

- **Aprobación:** Antonio aprueba cada plan (estado `Aprobado`) antes de escribir código, y decide cada *go / no-go*. La revisión humana de autenticación, permisos, archivos, tenancy y pagos queda en una persona distinta de quien escribió el código cuando sea posible (Antonio revisa a Gabriel y viceversa).
- **Cambios de alcance:** se proponen como recorte o adición (`RC-n`), con riesgo y fecha; se registran en este documento y, si son decisiones costosas, en un ADR.
- **Cadencia:** hasta el 18-nov, revisión diaria de 15 minutos al inicio y checkpoints formales (10 y 12-nov) y puertas (16 y 17-nov). Después, revisión semanal de la hoja de ruta y una revisión de cierre por release.
- **Métricas:** cobertura (con umbral que no baja); deuda técnica (puntos P1 abiertos y su antigüedad); defectos escapados a producción; tiempo de ciclo (de «aprobado» a «terminado» por historia); y, para el negocio, propuestas recibidas por día y porcentaje de correos entregados.

## 18. Trazabilidad: módulo del Word → épica → plan → release

Secciones 9 y 10 del Word.

| Módulo del Word | Épica | Plan | Release | Nota |
|---|---|---|---|---|
| Eventos / Congresos (entidad central, estados) | E-03 | 003 | R0 | Evento con tipo, sede, fechas, zona, idiomas |
| Eventos: plantillas, duplicar, módulos por evento | E-18 | — | R2 | |
| Eventos: asignar usuarios y roles a cada evento | E-11 | 011 | R1 | `event_assignments` |
| Usuarios y acceso: roles, contraseñas, recuperación | E-02 | 001 | R0 | |
| Usuarios: 2FA y auditoría | E-14 | 014 | R1 | RC-2 |
| Usuarios: persona frente a cuenta | E-17 | — | R2 | `Contact` |
| Propuestas: convocatoria configurable | E-04, E-05 | 004, 005 | R0 | Dinámica por organizador |
| Propuestas: flujo de estados y revertir | E-12 | 012 | R1 | |
| Propuestas: autor con acceso propio | E-06 | 006 | R0 | Enlace de acceso de un solo uso |
| Propuestas: aceptación y rechazo por correo | E-12, E-08 | 012 | R1 | |
| Propuestas: detección de duplicadas | — | — | **Descartado por ahora** | Hipótesis sin evidencia de necesidad; se reevalúa con el volumen real de 2027 |
| Comité: rúbrica y evaluación | E-11 | 011 | R1 | |
| Comité: asignación automática balanceada | — | — | **Pospuesto a R2** | Se empieza con asignación manual; hipótesis a validar |
| Conferencistas y portal | E-15, E-16, E-17 | — | R2 | |
| Programa / calendario | E-20 | — | R3 | |
| Pósters (flujo y rúbrica) | E-05, E-06, E-11 | 005, 006, 011 | R0 y R1 | Un póster es un tipo de propuesta con su formulario |
| Pósters (sala de pósters) | E-20 | — | R3 | |
| Talleres y talleristas | E-27 | — | R4 | |
| Boletos | E-22, E-23 | — | R3 | Podiumeeting fuera de alcance |
| Patrocinadores y expositores | E-25 | — | R4 | |
| Hoteles y recorridos | E-28 | — | R4 | |
| Voluntarios | E-26 | — | R4 | |
| Contenido público (FAQ, testimoniales, ejes) | E-04 (ejes), E-19 | 004 | R0 y R3 | Ejes ahora; el resto con el sitio público |
| Contenido: numeralia calculada | E-34 | — | R5 | |
| Exportaciones | E-09 | 009 | R0 | Sin credenciales; con auditoría |
| Correo: plantillas, disparadores, historial | E-08, E-13 | 008, 013 | R0 y R1 | |
| Núcleo multi-evento y organizaciones | E-02, E-03 | 001, 003 | R0 | |
| CRM de comunidad | E-33 | — | R5 | |
| Registro y acreditación | E-22, E-24 | — | R3 | |
| Pagos y facturación | E-23 | — | R3 | ADR-0007 |
| Comunicación (campañas) | E-29 | — | R4 | |
| Constructor de formularios | E-05, E-21 | 005 | R0 y R3 | Básico en R0; avanzado en R3 |
| Sitio y micrositio por evento | E-19, E-37 | — | R3 y R5 | |
| Tareas y producción | E-30 | — | R4 | |
| Presupuesto y finanzas | E-31 | — | R4 | |
| Documentos y contratos | E-32, E-16 | — | R4 y R2 | |
| Logística y proveedores | E-28 | — | R4 | |
| Encuestas y resultados | E-35 | — | R5 | |
| Reportes y analítica | E-34 | — | R5 | |
| App para asistentes (PWA) | E-38 | — | R5 | |
| Eventos híbridos o virtuales | E-39 | — | R5 | |
| Integraciones y API | E-36 | — | R5 | |
| Auditoría, respaldos y privacidad | E-03, E-10, E-14 | 003, 010, 014 | R0 y R1 | |
| Preparación SaaS (Word §11.5) | E-40 | — | R5 | Tras el punto de control de demanda |

## 19. Preguntas abiertas y [PENDIENTE]

1. `[PENDIENTE: si se trabaja el sábado 14 y el domingo 15]` (capacidad base: 6 h por día por persona).
2. `[PENDIENTE: fecha de resultados]`; el cierre es el jueves 21-ene-2027.
3. `[PENDIENTE: confirmar acceso al DNS del dominio actual del congreso]` (recomendación en A7).
4. `[PENDIENTE: verificar precio vigente de Cloudflare R2]` (recomendado en A5).
5. `[PENDIENTE: textos del evento: ejes, campos de formulario y aviso de privacidad, y quién aprueba el aviso]`.
6. `[PENDIENTE: confirmar al cierre del 9-nov que las credenciales del cpanel se rotaron]`.
7. Resuelta el 9-nov: se confirma la migración de propuestas del plan B y la fecha objetivo 4-dic-2026.
8. `[PENDIENTE: límites y precios vigentes de Resend, SES y Railway]`.
9. `[PENDIENTE: criterio fino del punto de control de demanda del SaaS]`.
10. `[PENDIENTE: fechas de R2 a R5, que dependen del calendario del evento 2027]`.
11. `[PENDIENTE: cliente de errores (Sentry u otro)]`.
12. `[PENDIENTE: tiempo máximo de petición en Railway y herramienta del gestor de contraseñas compartido]`.
13. `[PENDIENTE: aprobar los valores propuestos en los planes (límites de subida y cuotas, tope de exportación, intentos de acceso, caducidades)]`.
14. `[PENDIENTE: umbral de la Puerta 1, medido con la velocidad real del 10-nov]`.

## Cambios propuestos (no aplicados; requieren tu aprobación)

| Cambio | Documento |
|---|---|
| ADR-0009: correo transaccional (Resend y luego SES), catálogo de notificaciones y regla de proceso | `docs/adr/` (nuevo) |
| ADR-0010: formularios dinámicos (definición, reglas de cambio con respuestas, `jsonb`) | `docs/adr/` (nuevo) |
| ADR-0011: recortes de seguridad de R0 (RC-1, RC-2, RC-3) con fecha y dueño | `docs/adr/` (nuevo) y `docs/sprints/deuda-tecnica.md` |
| ADR-0003: pasar a Aceptada con DA-1..DA-4 según las recomendaciones | `docs/adr/0003` |
| ADR-0004: DA-1 SSR diferido hasta R3; DA-4 marca blanca fuera de R0 | `docs/adr/0004` |
| ADR-0005: DA-1 (`currency` por evento) y DA-2 (activitylog) aceptadas | `docs/adr/0005` |
| ADR-0008: DA-2 (repo en `C:\`), DA-3 (`staging` desplegado en Railway) | `docs/adr/0008` |
| Regla de notificaciones en la definición de terminado | `AGENTS.md` |
| Términos nuevos: `NotificationType`, niveles de `MailTemplate`, `FormDefinition`, `FormField`, `SubmissionParticipant`, `PrivacyNotice`, `SubmissionAccessToken`, `Convocatoria` (ya existe) | `docs/producto/glosario.md` |
| Enlace de acceso del autor y su excepción a «un solo uso» si aplica | `docs/seguridad.md` §2 |
| Proveedor de correo, alojamiento y subencargados | `docs/operacion.md` y `docs/privacidad.md` |
| Dependencia nueva: cliente de Resend (acción 4 de `AGENTS.md`); Turnstile se verifica con el cliente HTTP de Laravel, sin paquete | `AGENTS.md` §Dependencias |
| Puntos de deuda: RLS (P1), 2FA (P1), antivirus (P2), metadatos de archivos, SSR, marca blanca, retención de datos | `docs/sprints/deuda-tecnica.md` |
| ADR-0010 debe incluir la instantánea del formulario por propuesta, el bloqueo de la definición y la vista previa con React dentro del panel (excepción acotada a ADR-0004, que se registra en uno de los dos) | `docs/adr/` |
| URL pública única `/{organization:slug}/events/{event:slug}/...` y middleware que fija la organización | ADR-0003 (DA-4) |
| Tablas y notificaciones nuevas que aprobar con su propuesta de datos: `known_devices`, `mail_suppressions`, `auth.new_device_login`, `team.membership_added` | `docs/database-design.md` §6 y plan 008 |
| Permisos y roles (`org_admin`, `organizer`, `reviewer`, `platform`) y los 13 permisos de la matriz del plan 001 | plan 001 y `docs/seguridad.md` §3 |
