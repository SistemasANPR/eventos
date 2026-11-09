# Prompt maestro de arranque: plan de desarrollo de la plataforma

Se usa **una vez**, al iniciar el proyecto, para que Claude Code produzca el plan de desarrollo a partir de la plantilla y del análisis en Word. Es una sesión de **planeación**: no crea el proyecto Laravel ni escribe código. Cuando el plan se aprueba, se ejecuta la Fase 0 con `PROMPT-ARRANQUE.md`.

Esta versión incluye dos requisitos del negocio: la **fecha dura del 18 de noviembre de 2026** (lanzamiento de la convocatoria del Congreso Parques 2027) y el **módulo de catálogo y plantillas de correo** editables por organización.

## Preparación (antes de pegar el prompt)

1. La plantilla debe estar **en la raíz** del proyecto, no en una subcarpeta (si quedó en `plantilla-laravel/`, copiar su contenido a la raíz sin `README.md`). Después, dejar el repo en la rama de trabajo: un primer commit en `main` con la plantilla y luego `git switch -c staging` (ADR-0008). El desarrollo va en `staging`.
2. El Word ya está convertido en `docs/producto/analisis-cpanel-y-propuesta.md` y viaja con la plantilla (la copia suelta en la raíz sobra). Si se actualiza el Word, regenerarlo y volver a quitar el índice y la portada:
   ```powershell
   pandoc "C:\Users\iscgo\Documents\CPanel_Congreso_Parques_base_nuevo_gestor_eventos.docx" -t gfm --wrap=none -o docs/producto/analisis-cpanel-y-propuesta.md
   ```
3. Tener a mano: quién trabaja y cuántas horas al día hasta el 18 de noviembre, las fechas de cierre y de resultados de la convocatoria 2027, y quién controla el dominio y el correo.
4. **Hoy, en paralelo al prompt** (tardan y no dependen del código): pedir acceso a producción del proveedor de correo, apuntar el dominio de envío (SPF, DKIM y DMARC) y abrir las cuentas de hosting y base de datos.

## Prompt

```
Vas a elaborar el PLAN DE DESARROLLO de una plataforma SaaS de gestión de
eventos (origen: el cpanel del Congreso Parques de la ANPR). Esta sesión es SOLO
de planeación: no escribas código, no crees el proyecto Laravel, no instales
dependencias, no ejecutes migraciones, no crees commits y no hagas push.
Trabaja en tres fases y NO pases a la siguiente sin mi aprobación explícita.

FUENTES, EN ESTE ORDEN DE AUTORIDAD
1. AGENTS.md, CLAUDE.md y los ADR de docs/adr/ (decisiones tomadas y abiertas).
2. docs/producto/: glosario.md, cpanel-actual.md, vision-y-modulos.md y
   analisis-cpanel-y-propuesta.md (el Word convertido: análisis del cpanel,
   mejoras por módulo, módulos nuevos, stack y hoja de ruta).
3. docs/plans/2026-11-09-001-feat-nucleo-multitenant.md (primer plan, borrador).
4. docs/seguridad.md, docs/privacidad.md, docs/operacion.md,
   docs/database-design.md y docs/convenciones.md.
Si dos fuentes se contradicen, aplica ese orden, documenta la discrepancia y
pregúntame solo si cambia materialmente el plan. No inventes datos: lo que falte
va como [PENDIENTE: pregunta concreta].
Si AGENTS.md y docs/ no están en la raíz del repo (por ejemplo, la plantilla
quedó en una subcarpeta), dímelo y espera: no muevas archivos.

RESTRICCIÓN DURA: LANZAMIENTO DE LA CONVOCATORIA
- El 18 de noviembre de 2026 se lanza la convocatoria del Congreso Parques 2027,
  el primer evento de la plataforma. Ese día el módulo de convocatorias debe
  estar EN PRODUCCIÓN recibiendo propuestas de sesiones y pósters. Confirma la
  fecha de hoy con `date`: lo que reste hasta el 18 es tu presupuesto de tiempo.
- Planea hacia atrás desde esa fecha. El plan se reordena alrededor de
  "R0 — Lanzamiento de la convocatoria": el recorte vertical MÍNIMO que permite
  abrir la convocatoria con seguridad. Lo que R0 no necesite se pospone, aunque
  en el Word o en vision-y-modulos.md tenga prioridad Alta.
- R0 incluye lo TRANSVERSAL que la convocatoria necesita para existir, no solo
  los formularios. Como mínimo evalúa: organización y evento sembrados (la ANPR
  y Congreso Parques 2027) con el contrato de aislamiento y su guardiana;
  acceso de organizadores con roles y Policies; convocatoria con los formularios
  de sesión (individual y panel) y de póster, sus ejes temáticos, fechas de
  apertura y cierre y consentimiento y aviso de privacidad; subida de archivos
  segura; correos transaccionales con su catálogo y plantillas editables (ver el
  requisito siguiente); panel de organizadores para ver, filtrar, abrir y
  exportar propuestas; límite de peticiones y protección anti-robots; despliegue
  a producción con respaldos, monitoreo básico y dominio y correo configurados.
- R0 NO incluye, salvo que demuestres que es imprescindible: evaluación del
  comité, portal del conferencista, programa, registro y boletos, patrocinadores,
  voluntarios, CRM, reportes, onboarding self-service de otras organizaciones,
  facturación del SaaS. Propón la fecha límite de cada uno (la evaluación y los
  resultados deben estar listos antes del cierre de la convocatoria).
- LA SEGURIDAD NO SE RECORTA. Mínimo irrenunciable en R0: Policies en el
  servidor, aislamiento por organization_id con su guardiana en verde, Form
  Requests, archivos privados con validación por contenido, límite de peticiones
  y anti-robots en formularios públicos, contraseñas con hash, CSRF, respaldos y
  cero secretos en el repo. Lo único que puedes proponer diferir, como riesgo
  aceptado con fecha y dueño, es: RLS de PostgreSQL, 2FA (si no cabe), marca
  blanca completa y lo ya listado como fuera de R0. Cada recorte se propone como
  ADR corto o punto en docs/sprints/deuda-tecnica.md; no lo apliques sin mi
  aprobación.
- RUTA CRÍTICA QUE NO ES CÓDIGO y tarda: dominio y DNS (SPF, DKIM, DMARC) del
  correo, alta y acceso a producción del proveedor de correo, cuentas de hosting
  y base de datos, certificados y el texto del aviso de privacidad. Al inicio de
  la Fase B listalos como "ACCIONES PARA HOY", con responsable y qué necesitas de
  mí.
- Entrega un calendario día por día hasta el 18 con hitos, un punto de control
  go / no-go con criterios medibles (propón la fecha; sugerencia: cuatro días
  antes del lanzamiento) y qué se hace si el resultado es no-go.
- PLAN B obligatorio: el sitio actual ya tiene formularios de sesiones y pósters
  funcionando (ver cpanel-actual.md). Define los criterios para activarlo, qué
  se actualizaría ahí (fechas y textos 2027, parches de seguridad urgentes) y
  cómo se migrarían después esas propuestas a la plataforma.
- Sé honesto con la factibilidad: si con la capacidad que te declare el alcance
  de R0 no cabe, dilo con cifras y propón el recorte o el plan B. No maquilles
  el plan.

REQUISITO TRANSVERSAL: CATÁLOGO Y PLANTILLAS DE CORREO (entra en R0)
La plataforma debe tener un módulo donde cada organización vea TODOS los correos
que dispara el sistema y los adapte a su evento. Es transversal: cada módulo,
presente y futuro, registra ahí sus notificaciones.
- Catálogo: registro central en código de cada notificación (clave estable,
  nombre, descripción, cuándo se dispara, destinatario, variables disponibles,
  idiomas y si es crítica) y una pantalla del panel que las lista todas con su
  estado.
- Personalización por organización y, opcionalmente, por evento (la del evento
  sobrescribe a la de la organización, y esta a la predeterminada del sistema):
  asunto, cuerpo, logotipo, colores, pie y firma, nombre del remitente y
  responder-a, y el texto por idioma (es, en, pt).
- Herramientas: vista previa con datos de ejemplo, envío de prueba, restaurar la
  predeterminada, historial de versiones y activar o desactivar solo las que no
  sean críticas (por ejemplo, restablecer contraseña no se desactiva).
- Seguridad: las variables salen de una lista blanca; NUNCA se ejecuta código
  (ni Blade ni PHP) escrito por el usuario; el contenido se sanitiza; el logotipo
  se valida y se guarda en un almacenamiento controlado; ningún correo puede salir
  sin su plantilla predeterminada en español e inglés.
- Registro de envíos (a quién, cuándo, estado de entrega): puede ir en R1 si no
  cabe en R0.
- Regla de proceso nueva: toda notificación nueva se registra en el catálogo con
  su plantilla predeterminada y su prueba. Proponla como adición a AGENTS.md
  (definición de terminado) y a un ADR; no la apliques sin mi aprobación.
- Enumera los correos mínimos de R0 (por ejemplo: acuse de recepción al autor,
  enlace para consultar o editar su propuesta, aviso a los organizadores, retiro
  o edición de una propuesta) y los que deben existir antes del cierre
  (recordatorios, resultados: aceptada, rechazada, lista de espera).

ENTORNO Y RAMAS (ya decididos, ADR-0008)
- Todo se desarrolla en local con VS Code y Docker (Docker Desktop con WSL2).
  El PHP, Composer y Node del equipo no se usan: los comandos van con el prefijo
  `docker compose exec app`.
- Todo el desarrollo va en la rama `staging`; `main` es producción y solo recibe
  lo ya probado por pull request con aprobación humana.
- El plan debe incluir, en R0, la puesta en marcha de este entorno (Docker, CI en
  `staging` y `main`, protección de ramas) y tratar las decisiones abiertas del
  ADR-0008 (DA-1 a DA-4) como parte de la ruta crítica de los primeros días.

PRINCIPIOS QUE DEBES APLICAR AL PLANEAR
- Rebanadas verticales: cada historia atraviesa datos, lógica, interfaz y
  pruebas, y deja algo usable. Prohibido planear por capas.
- Esqueleto andante primero: en los primeros días, un recorrido mínimo de punta a
  punta (organización → evento → convocatoria → página pública), corriendo en
  local con Docker y con CI en verde en `staging`; desplegado en un entorno de
  pruebas en cuanto exista (pregunta de la Fase A).
- Riesgo primero: los riesgos más altos (aislamiento entre organizaciones,
  autenticación, correo y su entregabilidad, subida de archivos, SSR, pagos,
  migración de datos) se atacan con spikes o historias tempranas.
- Planeación por olas: detalle completo SOLO de R0 (día por día), R1 y las
  épicas de R2; el resto queda a nivel de épica con historias esbozadas.
- Historias INVEST. Formato: "Como [rol], quiero [acción], para [beneficio]",
  con criterios CA-NNN-n en Dado / cuando / entonces (casos de error y límites
  incluidos). Tamaño relativo S, M o L; ninguna historia mayor que M. Sin
  estimaciones en horas inventadas: las duraciones solo con la capacidad que te
  declare. La fecha del 18 de noviembre sí es dato.
- Cada épica se ejecuta con un plan SDD en docs/plans/ (ADR-0001), con su
  número NNN; las historias viven dentro del plan y sus criterios llevan el
  prefijo de ese número.
- Seguridad, aislamiento entre organizaciones, privacidad, observabilidad y
  accesibilidad son criterios de cada historia que los toque, no una fase final.
- Cada release termina en un punto de control (go / no-go) con criterios
  medibles.
- Lo que el Word propone como "mejora" o "módulo nuevo" se trata como hipótesis:
  cada módulo del Word debe aparecer en el plan, ubicado o descartado con razón.

FASE A — LECTURA Y PREGUNTAS (sin crear ni editar archivos)
1. Lee las fuentes completas y comprueba su ubicación.
2. Entrégame:
   a. Resumen de 10 líneas: qué producto es, para quién y qué lo hace diferente.
   b. Contradicciones o huecos entre el Word, los ADR, el glosario y la fecha
      del 18 de noviembre.
   c. Supuestos que usarás si no te los corrijo.
   d. Una primera lectura de factibilidad de R0 para el 18 de noviembre, con lo
      que crees que es el camino crítico.
   e. Máximo 15 preguntas, primero las BLOQUEANTES, luego las diferibles. Para
      cada una, tu recomendación por defecto, de modo que pueda responder "ok a
      todo". Cubre al menos: capacidad del equipo hasta el 18 (personas y horas
      al día), fechas de cierre y de resultados de la convocatoria 2027, si los
      campos y ejes de los formularios 2027 son los de 2025, idiomas de la
      convocatoria y de los correos, quién controla el dominio y el correo y qué
      proveedor se usará, quién aprueba el aviso de privacidad, si el plan B es
      aceptable, si `staging` será solo una rama o también un servidor desplegado
      (ADR-0008 DA-3) y dónde vive el repo en Windows (DA-2), si el 2FA debe estar en R0, qué hacer con los datos históricos
      del cpanel, si se mantiene Podiumeeting al inicio y las decisiones abiertas
      de los ADR 0003, 0004, 0005 y 0007.
Detente aquí y espera mis respuestas.

FASE B — PLAN MAESTRO
Con mis respuestas, crea docs/plan-de-desarrollo.md (un solo archivo; no crees
carpetas nuevas) con estas secciones:
 1. Objetivos: 3 a 5 resultados medibles. El primero: convocatoria 2027 abierta
    en producción el 18 de noviembre de 2026.
 2. Acciones para hoy: la ruta crítica que no es código, con responsable.
 3. Alcance: dentro y fuera de R0 y de la primera versión; supuestos y
    restricciones; recortes propuestos con su riesgo.
 4. Convención de trabajo: Release → Épica → Historia → Criterio y sus
    identificadores (R0..R5, E-01.., plan NNN, CA-NNN-n).
 5. Hoja de ruta: tabla por release con objetivo, épicas, valor entregado,
    criterio de entrada, criterio de salida (go / no-go) y riesgos principales.
    Punto de partida sugerido, ajustable: R0 Lanzamiento de la convocatoria
    (18-nov); R1 Evaluación, resultados y endurecimiento (antes del cierre de la
    convocatoria); R2 Conferencistas y portal; R3 Programa, registro, boletos y
    pagos, acreditación; R4 Ecosistema del evento; R5 CRM, reportes, API y
    preparación para ofrecerlo como SaaS. Incluye un punto de control de
    validación de demanda del SaaS antes de invertir en lo multi-cliente
    completo.
 6. Plan de R0 día por día: calendario hacia atrás desde el 18, hitos, punto de
    control go / no-go con criterios y fecha, y plan B con criterios de
    activación, alcance y migración posterior.
 7. Módulo de correos: catálogo de notificaciones, modelo de personalización
    (sistema → organización → evento), pantallas, seguridad, correos mínimos de
    R0 y los que hacen falta antes del cierre.
 8. Catálogo de épicas: id, nombre, módulos del Word, release, tamaño,
    dependencias, riesgo, plan asociado y valor.
 9. Mapa de historias por recorrido de usuario (organizador, autor/ponente,
    evaluador, asistente, patrocinador, voluntario, soporte de plataforma), con
    el corte de cada release marcado.
10. Historias detalladas de R0, R1 y de las épicas de R2, con criterios
    CA-NNN-n, tamaño, dependencias y notas de permisos, aislamiento y datos
    personales.
11. Spikes y decisiones pendientes: pregunta, ADR que cierra y ANTES de qué
    épica debe estar resuelto.
12. Estrategia de calidad: pirámide de pruebas, guardianas, pruebas de extremo a
    extremo de los flujos críticos (enviar propuesta, recibir correo), puerta de
    seguridad por release, accesibilidad y rendimiento.
13. Datos y migración desde el cpanel: qué se migra, cómo, cuándo y cómo se
    verifica; convivencia con el sistema actual.
14. Entornos, despliegue y operación desde el primer día.
15. Riesgos y supuestos: matriz de probabilidad e impacto con mitigación y dueño.
16. Definiciones de Listo y Terminado de historia, de épica y de release,
    coherentes con ADR-0001 y AGENTS.md.
17. Gobernanza: aprobación de planes, cambios de alcance, cadencia de revisión y
    métricas (cobertura, deuda, defectos escapados, tiempo de ciclo).
18. Trazabilidad: módulo del Word → épica → plan → release.
19. Preguntas abiertas y [PENDIENTE].
Además:
- Ajusta docs/plans/2026-11-09-001-feat-nucleo-multitenant.md para que sea el
  mínimo de R0 y mueve el resto a un plan posterior. Crea, en estado Borrador, los
  planes de las épicas de R0 y R1 con numeración consecutiva (formato de la
  plantilla), incluido el del módulo de correos. Las épicas posteriores NO llevan
  plan todavía.
- No cambies ADR, glosario ni documentos de reglas: lista los cambios que
  propones (recortes, regla de notificaciones, términos nuevos) y espera mi
  aprobación.
Detente aquí y espera mi aprobación.

FASE C — REVISIÓN ADVERSARIAL Y CIERRE
1. Revisa tu trabajo como un crítico exigente y corrige: toda historia cumple
   INVEST y ninguna es mayor que M ni "por capas"; cada una tiene al menos un
   CA-NNN-n verificable con casos de error; no hay ciclos de dependencia y el
   orden respeta dependencias y riesgo; TODO lo que está en R0 es necesario para
   abrir la convocatoria y nada necesario quedó fuera; el calendario hacia atrás
   cabe en la capacidad declarada o lo dice; la ruta crítica externa tiene
   responsable; el plan B está definido; cada recorte de seguridad está
   registrado con fecha y dueño; cada módulo del Word está en la trazabilidad;
   cada riesgo tiene mitigación; nada contradice AGENTS.md ni los ADR; no hay
   duraciones inventadas.
2. Verifica: `grep -rn "RELLENAR\|CONFIRMAR" docs/plan-de-desarrollo.md` solo
   devuelve [PENDIENTE]; ejecuta `git diff --check`.
3. Entrégame: resumen del plan en 15 líneas, el calendario de R0, la decisión
   go / no-go propuesta, cambios propuestos a ADR y glosario, los [PENDIENTE] y
   qué revisaste y encontraste.
Detente aquí. Cuando apruebe, el siguiente paso es la Fase 0 con
PROMPT-ARRANQUE.md.

Empieza por la Fase A.
```
