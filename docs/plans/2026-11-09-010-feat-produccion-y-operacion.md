# feat: producción y operación

> Plan de la épica E-10 (R0). Especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código o configurar infraestructura. Tocar configuración de despliegue, secretos del CI o infraestructura requiere permiso explícito (acción 12 de `AGENTS.md`).

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna propia (RC-1 RLS, RC-2 2FA y la restauración a fondo pasan al plan 014)

## 1. Objetivo y alcance

Dejar producción lista para recibir la convocatoria del 18-nov-2026 con operación segura desde el primer día: servicios, dominio, HTTPS y cabeceras, correo con dominio verificado, respaldos que se han restaurado al menos una vez, monitoreo y alertas, y un ensayo de lanzamiento con su runbook mínimo. Hoy el cpanel de origen corre en hosting compartido con credenciales en el repositorio y sin respaldos ni monitoreo verificables.

**Dentro:** Railway `production` con web, worker de colas, planificador, PostgreSQL, Redis y bucket privado; dominio y Cloudflare; HTTPS con HSTS; cabeceras de seguridad; SPF, DKIM y DMARC; respaldos diarios y restauración ensayada en `staging`; `/up`, errores sin PII, Horizon y alertas mínimas; ensayo de lanzamiento con datos ficticios; runbook mínimo; `.env.example` y `docs/operacion.md` al día.

**Fuera:** restauración a un punto en el tiempo ensayada a fondo y runbooks completos (H-014-3); RLS y 2FA (plan 014); escalado a GCP o AWS; marca blanca y dominios propios por organización (R5); el plan B y los parches del sitio actual (se ejecutan fuera del repositorio; ver plan maestro §6.4).

### Historias

| Historia | Tam. | Dependencias |
|---|---|---|
| H-010-1 Como líder, quiero producción en Railway con web, worker, planificador, PostgreSQL, Redis y bucket privado, para operar la convocatoria | M | H-002-4, A4, A5 |
| H-010-2 Como líder, quiero dominio, HTTPS, cabeceras de seguridad y dominio de envío verificado, para que el sitio y el correo sean confiables | M | A2, A6, A7 |
| H-010-3 Como líder, quiero respaldos automáticos probados, monitoreo de salud, errores y colas con alertas, para detectar y recuperar fallos | M | H-010-1 |
| H-010-4 Como líder, quiero un ensayo de lanzamiento en producción con datos ficticios que se limpian y un runbook mínimo, para lanzar con confianza | S | H-010-1, H-010-2, H-010-3 |

## 2. Punto de partida

Al 2026-11-09 no hay proyecto, Dockerfile ni despliegue. `docs/operacion.md` describe entornos, servicios y alertas mínimas con varios huecos por completar (plataforma, proveedor de correo, cliente de errores, retención de respaldos). Dependen de acciones que se lanzan hoy: A2 (Resend y DNS), A4 (Railway), A5 (bucket), A6 (Cloudflare), A7 (URL de la convocatoria) y A8 (primer push). Este plan cubre los criterios G4 y G5 del punto de control del plan maestro §6.3.

## 3. Decisiones

- **DA-1 · Plataforma** — Railway (decidido). Límites y costos `[PENDIENTE: verificar planes vigentes]`.
- **DA-2 · Bucket** — Cloudflare R2 o S3, privado y con versionado `[PENDIENTE: A5]`.
- **DA-3 · Respaldos** — se recomienda respaldo diario automático del PostgreSQL gestionado más una copia en otro proveedor o región; retención `[PENDIENTE: días]`; objetivos de recuperación `[PENDIENTE: punto y tiempo]`.
- **DA-4 · Cliente de errores** — se recomienda uno con filtrado de PII (Sentry u otro, sujeto a ADR según `AGENTS.md`) `[PENDIENTE: elección y aprobación]`.
- **DA-5 · Migraciones** — las aplica una persona tras aprobar el pull request `staging` → `main`; el agente no las ejecuta contra producción (acción 1 de `AGENTS.md`).
- **DA-6 · Política de contenido (CSP)** — se recomienda empezar con una CSP estricta ajustada a los scripts reales del sitio público (Inertia, Turnstile) y vigilarla en modo de reporte durante el ensayo.
- **DA-7 · DMARC** — se recomienda empezar con política `p=none` y reportes, y endurecer a `quarantine` cuando G4 y los reportes lo respalden `[PENDIENTE: decisión]`.

## 4. Contrato

- **Infraestructura:** servicios Railway `web`, `worker`, `scheduler`, `postgres`, `redis`; bucket privado; Cloudflare delante. Variables de entorno (`APP_*`, `DB_*`, `REDIS_*`, `FILESYSTEM_DISK` y credenciales del bucket, `MAIL_*`, claves de Turnstile, cliente de errores) solo como variables del entorno y documentadas en `docs/operacion.md` §4; `.env.example` con valores ficticios.
- **Rutas:** `/up` (incluida en Laravel, sin límite) como comprobación de salud. No hay endpoints JSON nuevos.
- **Middleware y configuración:** cabeceras de seguridad (`Content-Security-Policy`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`), HSTS, proxies de confianza configurados, cookies `Secure`, `HttpOnly`, `SameSite=Lax`, depuración apagada, arranque que falla con un error claro si falta una variable obligatoria.
- **Datos:** sin tablas nuevas. Los respaldos y su restauración se registran en la tabla de `docs/operacion.md` §5.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Solo Antonio (líder) administra Railway, DNS, secretos y despliegues a producción; Gabriel con acceso de lectura y soporte según se acuerde `[PENDIENTE: accesos de Gabriel]`. Ningún secreto en el repositorio. |
| ¿Qué Policy cubre cada acción y dónde se prueba? | No aplica Policy de aplicación; el control es de infraestructura (accesos y protección de ramas). El panel de colas (Horizon) se restringe a rol de plataforma; prueba de acceso permitido y denegado a `/horizon`. |
| ¿Hay modelos nuevos con datos de una organización? | No. |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No. |
| ¿Datos personales nuevos? | No se crean, pero producción almacena todos los existentes: respaldos y logs. Los respaldos contienen datos personales y se cifran y acceden bajo control; los logs y los errores no contienen datos personales ni secretos. Se actualiza la lista de subencargados y el alojamiento en `docs/privacidad.md` (Railway, bucket, correo, errores). |
| ¿Se suben archivos? | Se provisiona el bucket privado (tipos y límites en el plan 007). |
| ¿Hay dinero, pagos o webhooks? | No. |
| ¿Se envían correos o notificaciones? | Se verifica la entrega real con el dominio de envío (G4); las plantillas son del plan 008. Alertas operativas al equipo por correo o canal acordado `[PENDIENTE: canal]`. |
| ¿Qué se registra en la auditoría? | Despliegues, restauraciones y cambios de acceso se anotan en `docs/operacion.md`; la aplicación audita lo suyo (planes 001, 007, 009). |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Cuentas y accesos (A2, A4, A5, A6, A7); variables y `.env.example` | S |
| F1 | H-010-1: servicios de producción y bucket | M |
| F2 | H-010-2: dominio, HTTPS, cabeceras, SPF, DKIM y DMARC | M |
| F3 | H-010-3: respaldos, restauración en `staging`, monitoreo y alertas | M |
| F4 | H-010-4: ensayo de lanzamiento y runbook mínimo | S |

## 7. Criterios de aceptación

Casi todos son comprobaciones de infraestructura y se declaran manuales con pasos exactos; los que se pueden automatizar llevan prueba.

### H-010-1 · Servicios de producción

- **CA-010-1:** *Dado* el servicio web desplegado desde `main`, *cuando* se pide `/up`, *entonces* responde 200.
  Manual: `curl -i https://<dominio>/up` y comprobar el código 200 en producción y en `staging`.
- **CA-010-2:** *Dado* los servicios `worker` y `scheduler`, *cuando* se encola un correo de prueba y corre una tarea programada, *entonces* el worker lo procesa y el planificador ejecuta `schedule:run` cada minuto.
  Manual: enviar `auth.password_reset` a una cuenta de prueba; ver el job completado en Horizon y la ejecución del planificador en los registros.
- **CA-010-3:** *Dado* el bucket privado, *cuando* se pide un archivo sin URL firmada, *entonces* el proveedor responde 403 y no lo entrega.
  Manual: subir un archivo ficticio y abrir su ruta directa en el navegador.
- **CA-010-4:** *Dado* el repositorio y el entorno, *cuando* falta una variable obligatoria al arrancar, *entonces* la aplicación falla con un mensaje claro; y el repositorio no contiene secretos (búsqueda de secretos del CI en verde).

### H-010-2 · Dominio, HTTPS, cabeceras y correo

- **CA-010-5:** *Dado* el dominio de la convocatoria, *cuando* se abre por HTTP, *entonces* se redirige a HTTPS y las respuestas incluyen `Strict-Transport-Security`.
  Manual: `curl -I http://<dominio>` y `curl -I https://<dominio>`; comprobar la redirección y la cabecera.
- **CA-010-6:** *Dado* una respuesta de la aplicación, *cuando* se inspeccionan sus cabeceras, *entonces* incluye `Content-Security-Policy`, `X-Content-Type-Options: nosniff`, `Referrer-Policy` y `Permissions-Policy`.
- **CA-010-7:** *Dado* el formulario público de la convocatoria con la CSP activa, *cuando* se abre y se envía, *entonces* no hay errores de CSP en la consola y Turnstile funciona.
  Manual: abrir la convocatoria en `staging` a ~375 px y ~1280 px con la consola abierta y enviar una propuesta de prueba.
- **CA-010-8:** *Dado* el dominio de envío, *cuando* se consultan sus registros DNS, *entonces* SPF, DKIM y DMARC existen y el proveedor marca el dominio como verificado.
  Manual: consultar los registros TXT con `nslookup` o `dig` y revisar el panel de Resend.
- **CA-010-9:** *Dado* tres correos reales de prueba (por ejemplo `submission.received`) enviados a Gmail y Outlook, *cuando* se abre «ver original», *entonces* SPF, DKIM y DMARC están en *pass* y los tres llegan a la bandeja principal (criterio G4).
  Manual: enviar tres correos y anotar el resultado de cada uno.

### H-010-3 · Respaldos, monitoreo y alertas

- **CA-010-10:** *Dado* el PostgreSQL de producción, *cuando* pasa un día, *entonces* existe un respaldo automático con su fecha y tamaño verificables.
  Manual: consultar el panel de respaldos de la plataforma y anotar fecha y tamaño en `docs/operacion.md` §5.
- **CA-010-11:** *Dado* un respaldo de producción, *cuando* se restaura en `staging`, *entonces* el esquema y los datos ficticios de prueba se recuperan y la aplicación arranca contra ellos (criterio G5).
  Manual: restaurar el respaldo en `staging`, correr las pruebas de humo y registrar fecha, qué se restauró, resultado y tiempo en la tabla de `docs/operacion.md` §5.
- **CA-010-12:** *Dado* un error no controlado en producción, *cuando* ocurre, *entonces* llega al cliente de errores agrupado y **sin** datos personales (correo, nombre, contenido de propuestas) ni secretos.
  Manual: provocar un error en `staging` con datos ficticios y revisar el evento recibido.
- **CA-010-13:** *Dado* el servicio caído o `/up` sin responder, *cuando* pasa el intervalo de comprobación, *entonces* el equipo recibe una alerta; lo mismo con cola atrasada o worker caído (alertas mínimas de `docs/operacion.md` §6).
  Manual: detener el worker en `staging`, esperar la alerta y registrar el tiempo que tardó en llegar (criterio G5).
- **CA-010-14:** *Dado* el acceso a `/horizon`, *cuando* lo abre un usuario sin rol de plataforma o sin sesión, *entonces* se deniega.

### H-010-4 · Ensayo de lanzamiento y runbook

- **CA-010-15:** *Dado* producción con datos ficticios, *cuando* se ejecuta el recorrido completo (crear evento, ejes y formulario, abrir convocatoria, enviar propuesta con archivo, recibir acuse, verla y exportarla en el panel), *entonces* todos los pasos terminan sin errores bloqueantes.
  Manual: ejecutar la lista de pasos con una persona que no escribió el código y anotar el resultado de cada paso.
- **CA-010-16:** *Dado* terminado el ensayo, *cuando* se limpian los datos ficticios, *entonces* no queda ninguna propuesta, archivo ni usuario de prueba en producción, y el bucket queda sin los archivos de prueba.
  Manual: contar registros de propuestas, participantes y archivos antes y después de la limpieza y comprobar que coinciden con el estado inicial.
- **CA-010-17:** *Dado* el runbook mínimo en `docs/operacion.md` §9, *cuando* se lee, *entonces* cubre: sitio caído, cola atrasada o worker caído, correo que no llega, restauración de la base, secreto expuesto y cómo activar el plan B; cada caso con pasos y responsable.
  Manual: revisión de Antonio y Gabriel; cada uno ejecuta en `staging` un caso del runbook siguiéndolo al pie de la letra.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| DNS o cuenta de correo sin verificar a tiempo | A2 y A3 hoy; comprobación diaria; criterio G4 en el go / no-go |
| Pérdida de datos por mala configuración de Railway | Respaldos con restauración ensayada (CA-010-11); migraciones solo con aprobación humana |
| CSP bloquea el formulario o Turnstile en producción | CA-010-7 en `staging`; modo de reporte durante el ensayo |
| Respaldos con datos personales mal protegidos | Acceso restringido, cifrado y retención definida `[PENDIENTE: días]` |
| Costos o límites de Railway distintos a los esperados | `[PENDIENTE: verificar planes]` y alertas de gasto; revisión mensual |
| Alertas sin destinatario claro | Canal y responsable definidos antes de H3 |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
