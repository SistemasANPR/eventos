# feat: producción y operación

> Plan de la épica E-10 (R0). Especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código o configurar infraestructura. Tocar configuración de despliegue, secretos del CI o infraestructura requiere permiso explícito (acción 12 de `AGENTS.md`).

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna propia (RC-1 RLS, RC-2 2FA y la restauración a fondo pasan al plan 014)

## 1. Objetivo y alcance

Dejar producción lista para recibir la convocatoria del 18-nov-2026 con operación segura desde el primer día: servicios, dominio, HTTPS y cabeceras, correo con dominio verificado, respaldos que se han restaurado al menos una vez, monitoreo y alertas, y un ensayo de lanzamiento con su runbook mínimo. Hoy el cpanel de origen corre en hosting compartido con credenciales en el repositorio y sin respaldos ni monitoreo verificables.

**Calendario de este plan** (referencia al plan maestro §6.2): los servicios de producción se crean vacíos el 12 de noviembre (con H-002-4) y se configuran el 16 (H-010-1); el respaldo y la restauración se ensayan en `staging` antes de la Puerta 1 (16-nov, 18:00), y la comprobación sobre producción se hace el 17, antes de la Puerta 2.

**Dentro:** Railway `production` con web, worker de colas, planificador, PostgreSQL, Redis y bucket privado; dominio y Cloudflare; HTTPS con HSTS; cabeceras de seguridad; IP real del cliente detrás de Cloudflare y Railway; SPF, DKIM y DMARC; respaldos diarios y restauración ensayada en `staging`; `/up`, errores sin PII, Horizon y alertas mínimas; accesos de administración para Antonio y Gabriel; ensayo de lanzamiento con una **organización de prueba aparte** y su purga definitiva; runbook mínimo; `.env.example` y `docs/operacion.md` al día.

**Fuera:** restauración a un punto en el tiempo ensayada a fondo y runbooks completos (H-014-3); RLS y 2FA (plan 014); escalado a GCP o AWS; marca blanca y dominios propios por organización (R5); el plan B y los parches del sitio actual (se ejecutan fuera del repositorio; ver plan maestro §6.4).

### Historias

| Historia | Tam. | Dependencias |
|---|---|---|
| H-010-1 Como líder, quiero producción en Railway con web, worker, planificador, PostgreSQL, Redis y bucket privado, con accesos compartidos entre Antonio y Gabriel, para operar la convocatoria | M | H-002-4, A4, A5 |
| H-010-2 Como líder, quiero dominio, HTTPS, cabeceras de seguridad, IP real del cliente y dominio de envío verificado, para que el sitio y el correo sean confiables | M | A2, A6, A7 |
| H-010-3 Como líder, quiero respaldos automáticos probados, monitoreo de salud, errores y colas con alertas, para detectar y recuperar fallos | M | H-010-1 |
| H-010-4 Como líder, quiero un ensayo de lanzamiento en producción con una organización de prueba que se purga por completo y un runbook mínimo, para lanzar con confianza | S | H-010-1, H-010-2, H-010-3 |

## 2. Punto de partida

Al 2026-11-09 no hay proyecto, Dockerfile ni despliegue. `docs/operacion.md` describe entornos, servicios y alertas mínimas con varios huecos por completar (plataforma, proveedor de correo, cliente de errores, retención de respaldos). Dependen de acciones que se lanzan hoy: A2 (Resend y DNS), A4 (Railway), A5 (bucket), A6 (Cloudflare), A7 (URL de la convocatoria) y A8 (primer push). Este plan cubre los criterios G4 y G5 del punto de control del plan maestro §6.3.

## 3. Decisiones

- **DA-1 · Plataforma** — Railway (decidido). Límites y costos `[PENDIENTE: verificar planes vigentes]`.
- **DA-2 · Bucket** — Cloudflare R2 (adoptado salvo objeción) o S3, privado y con versionado `[PENDIENTE: A5, verificar precio vigente]`.
- **DA-3 · Respaldos** — se recomienda respaldo diario automático del PostgreSQL gestionado más una copia en otro proveedor o región; retención `[PENDIENTE: días]`; objetivos de recuperación `[PENDIENTE: punto y tiempo]`.
- **DA-4 · Cliente de errores** — se recomienda uno con filtrado de PII (Sentry u otro, sujeto a ADR según `AGENTS.md`) `[PENDIENTE: elección y aprobación]`.
- **DA-5 · Migraciones** — las aplica una persona tras aprobar el pull request `staging` → `main`; el agente no las ejecuta contra producción (acción 1 de `AGENTS.md`).
- **DA-6 · Política de contenido (CSP)** — se recomienda empezar con una CSP estricta ajustada a los scripts reales del sitio público (Inertia, Turnstile) y vigilarla en modo de reporte durante el ensayo.
- **DA-7 · DMARC** — se recomienda empezar con política `p=none` y reportes, y endurecer a `quarantine` cuando G4 y los reportes lo respalden `[PENDIENTE: decisión]`.
- **DA-8 · IP del cliente** — la aplicación confía en `CF-Connecting-IP` **solo** cuando la petición llega desde los rangos publicados de Cloudflare y configura `TrustProxies` para el salto de Railway; cualquier otra petición usa la IP de la conexión y descarta la cabecera. Los límites de peticiones (plan 006) y las cuotas de subida (plan 007) usan esa IP.
- **DA-9 · Ensayo aislado** — el ensayo de lanzamiento usa una organización con `slug` de prueba, **nunca** la organización real ni su contenido; al terminar se purga con el comando `app:purge-organization`, que borra de forma definitiva (no `SoftDeletes`).
- **DA-10 · Accesos** — Antonio y Gabriel tienen acceso de administración a Railway, DNS y secretos **antes de H3** (13-nov); los códigos de recuperación de cada cuenta crítica viven en el gestor de contraseñas compartido del equipo `[PENDIENTE: herramienta]`.

## 4. Contrato

- **Infraestructura:** servicios Railway `web`, `worker`, `scheduler`, `postgres`, `redis`; bucket privado; Cloudflare delante. Variables de entorno (`APP_*`, `DB_*`, `REDIS_*`, `FILESYSTEM_DISK` y credenciales del bucket, `MAIL_*`, claves de Turnstile, cliente de errores) solo como variables del entorno y documentadas en `docs/operacion.md` §4; `.env.example` con valores ficticios.
- **Rutas:** `/up` (incluida en Laravel, sin límite) como comprobación de salud. No hay endpoints JSON nuevos.
- **Middleware y configuración:** cabeceras de seguridad (`Content-Security-Policy`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`), HSTS, proxies de confianza configurados según DA-8, cookies `Secure`, `HttpOnly`, `SameSite=Lax`, depuración apagada, arranque que falla con un error claro si falta una variable obligatoria.
- **Comando:** `php artisan app:purge-organization {slug}` (solo plataforma, con confirmación explícita, idempotente): borra de forma definitiva las filas de todos los modelos con tenant de esa organización (incluido `activity_log`), y los objetos del prefijo `organizations/{id}/` del bucket con sus versiones; deja constancia en el registro de aplicación (sin datos personales) y en `docs/operacion.md`.
- **Datos:** sin tablas nuevas. Los respaldos y su restauración se registran en la tabla de `docs/operacion.md` §5.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Antonio (líder) y Gabriel administran Railway, DNS, secretos y despliegues a producción (DA-10); ningún otro acceso. Ningún secreto en el repositorio. El comando de purga y `/horizon` son solo de plataforma. |
| ¿Qué Policy cubre cada acción y dónde se prueba? | No aplica Policy de aplicación; el control es de infraestructura (accesos y protección de ramas). El panel de colas (Horizon) se restringe a rol de plataforma; prueba de acceso permitido y denegado a `/horizon`. El comando de purga se prueba con y sin rol de plataforma. |
| ¿Hay modelos nuevos con datos de una organización? | No. |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No. |
| ¿Datos personales nuevos? | No se crean, pero producción almacena todos los existentes: respaldos y logs. Los respaldos contienen datos personales y se cifran y acceden bajo control; los logs y los errores no contienen datos personales ni secretos. La purga elimina por completo los datos del ensayo. Se actualiza la lista de subencargados y el alojamiento en `docs/privacidad.md` (Railway, bucket, correo, errores). |
| ¿Se suben archivos? | Se provisiona el bucket privado (tipos y límites en el plan 007). |
| ¿Hay dinero, pagos o webhooks? | No. |
| ¿Se envían correos o notificaciones? | Se verifica la entrega real con el dominio de envío (G4); las plantillas son del plan 008. Alertas operativas al equipo por correo o canal acordado `[PENDIENTE: canal]`. |
| ¿Qué se registra en la auditoría? | Despliegues, restauraciones, purgas y cambios de acceso se anotan en `docs/operacion.md`; la aplicación audita lo suyo (planes 001, 007, 009). |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Cuentas y accesos compartidos (A2, A4, A5, A6, A7, DA-10); variables y `.env.example` | S |
| F1 | H-010-1: servicios de producción y bucket (16-nov) | M |
| F2 | H-010-2: dominio, HTTPS, cabeceras, IP real, SPF, DKIM y DMARC | M |
| F3 | H-010-3: respaldos (empezando por `staging`, para G5 antes de la Puerta 1), restauración, monitoreo y alertas | M |
| F4 | H-010-4: comando de purga, ensayo con organización de prueba y runbook mínimo (17-nov) | S |

## 7. Criterios de aceptación

Casi todos son comprobaciones de infraestructura y se declaran manuales con pasos exactos; los que se pueden automatizar llevan prueba.

### H-010-1 · Servicios de producción y accesos

- **CA-010-1:** *Dado* el servicio web desplegado desde `main`, *cuando* se pide `/up`, *entonces* responde 200.
  Manual: `curl -i https://<dominio>/up` y comprobar el código 200 en producción y en `staging`.
- **CA-010-2:** *Dado* los servicios `worker` y `scheduler`, *cuando* se encola un correo de prueba y corre una tarea programada, *entonces* el worker lo procesa y el planificador ejecuta `schedule:run` cada minuto.
  Manual: enviar `auth.password_reset` a una cuenta de prueba; ver el job completado en Horizon y la ejecución del planificador en los registros.
- **CA-010-3:** *Dado* el bucket privado, *cuando* se pide un archivo sin URL firmada, *entonces* el proveedor responde 403 y no lo entrega.
  Manual: subir un archivo ficticio y abrir su ruta directa en el navegador, en `staging` y en producción.
- **CA-010-4:** *Dado* el repositorio y el entorno, *cuando* falta una variable obligatoria al arrancar, *entonces* la aplicación falla con un mensaje claro; y el repositorio no contiene secretos (búsqueda de secretos del CI en verde).
- **CA-010-5:** *Dado* las cuentas de Railway, DNS y secretos, *cuando* Gabriel realiza un redespliegue en `staging`, entra al DNS y rota un secreto de prueba, *entonces* lo logra sin pedir nada a Antonio, y los códigos de recuperación de cada cuenta crítica existen en el gestor compartido y uno de ellos se probó.
  Manual: ejecutar los tres pasos con la sesión de Gabriel antes del 13-nov y anotar el resultado en `docs/operacion.md`.

### H-010-2 · Dominio, HTTPS, cabeceras, IP real y correo

- **CA-010-6:** *Dado* el dominio de la convocatoria, *cuando* se abre por HTTP, *entonces* se redirige a HTTPS y las respuestas incluyen `Strict-Transport-Security`.
  Manual: `curl -I http://<dominio>` y `curl -I https://<dominio>`; comprobar la redirección y la cabecera.
- **CA-010-7:** *Dado* una respuesta de la aplicación, *cuando* se inspeccionan sus cabeceras, *entonces* incluye `Content-Security-Policy`, `X-Content-Type-Options: nosniff`, `Referrer-Policy` y `Permissions-Policy`.
- **CA-010-8:** *Dado* el formulario público de la convocatoria con la CSP activa, *cuando* se abre y se envía, *entonces* no hay errores de CSP en la consola y Turnstile funciona.
  Manual: abrir la convocatoria en `staging` a ~375 px y ~1280 px con la consola abierta y enviar una propuesta de prueba.
- **CA-010-9:** *Dado* el dominio de envío, *cuando* se consultan sus registros DNS, *entonces* SPF, DKIM y DMARC existen y el proveedor marca el dominio como verificado.
  Manual: consultar los registros TXT con `nslookup` o `dig` y revisar el panel de Resend.
- **CA-010-10:** *Dado* tres correos reales de prueba (por ejemplo `submission.received`) enviados a Gmail y Outlook, *cuando* se abre «ver original», *entonces* SPF, DKIM y DMARC están en *pass* y los tres llegan a la bandeja principal (criterio G4).
  Manual: enviar tres correos y anotar el resultado de cada uno.
- **CA-010-11:** *Dado* una petición cuya conexión viene de un rango de Cloudflare y trae `CF-Connecting-IP`, *cuando* la aplicación resuelve la IP del cliente, *entonces* usa esa IP; y *dado* la misma cabecera en una petición que no viene de Cloudflare (suplantación con `X-Forwarded-For` o `CF-Connecting-IP`), *entonces* se ignora y se usa la IP de la conexión.
- **CA-010-12:** *Dado* el límite de envíos por IP del formulario público, *cuando* una IP lo agota detrás de Cloudflare y Railway, *entonces* solo esa IP recibe 429 y otra IP distinta sigue enviando con normalidad.
  Manual: en `staging`, agotar el límite desde la red de la oficina y comprobar un envío desde datos móviles.

### H-010-3 · Respaldos, monitoreo y alertas

- **CA-010-13:** *Dado* el PostgreSQL de `staging` (para G5 antes de la Puerta 1) y el de producción (provisionado el 16-nov), *cuando* pasa un día desde su provisión, *entonces* existe un respaldo automático con su fecha y tamaño verificables.
  Manual: consultar el panel de respaldos de la plataforma y anotar fecha y tamaño en `docs/operacion.md` §5, primero en `staging` y después en producción.
- **CA-010-14:** *Dado* un respaldo de `staging`, *cuando* se restaura en un entorno de prueba, *entonces* el esquema y los datos ficticios de prueba se recuperan y la aplicación arranca contra ellos (criterio G5).
  Manual: restaurar el respaldo, correr las pruebas de humo y registrar fecha, qué se restauró, resultado y tiempo en la tabla de `docs/operacion.md` §5; antes de la Puerta 1 (16-nov, 18:00).
- **CA-010-15:** *Dado* un error no controlado en producción, *cuando* ocurre, *entonces* llega al cliente de errores agrupado y **sin** datos personales (correo, nombre, contenido de propuestas) ni secretos.
  Manual: provocar un error en `staging` con datos ficticios y revisar el evento recibido.
- **CA-010-16:** *Dado* el servicio caído o `/up` sin responder, *cuando* pasa el intervalo de comprobación, *entonces* el equipo recibe una alerta; lo mismo con cola atrasada o worker caído (alertas mínimas de `docs/operacion.md` §6).
  Manual: detener el worker en `staging`, esperar la alerta y registrar el tiempo que tardó en llegar (criterio G5).
- **CA-010-17:** *Dado* el acceso a `/horizon`, *cuando* lo abre un usuario sin rol de plataforma o sin sesión, *entonces* se deniega.

### H-010-4 · Ensayo de lanzamiento, purga y runbook

- **CA-010-18:** *Dado* producción con una organización de prueba aparte (no la real), *cuando* se ejecuta el recorrido completo (crear evento, ejes y formulario, abrir convocatoria, enviar propuesta con archivo, recibir acuse, verla y exportarla en el panel), *entonces* todos los pasos terminan sin errores bloqueantes.
  Manual: ejecutar la lista de pasos con una persona que no escribió el código y anotar el resultado de cada paso; el contenido real de la organización de la ANPR no se toca.
- **CA-010-19:** *Dado* una organización con datos de varios modelos con tenant (propuestas, participantes, archivos, plantillas, registros de actividad) y otra organización distinta, *cuando* un usuario de plataforma ejecuta `app:purge-organization` con confirmación, *entonces* no queda ninguna fila de la primera en ninguna tabla con `organization_id` (incluido `activity_log`) y la segunda queda intacta; sin rol de plataforma o sin confirmación, el comando se rechaza y no borra nada; ejecutarlo dos veces no falla.
- **CA-010-20:** *Dado* terminado el ensayo, *cuando* se purga la organización de prueba en producción, *entonces* el prefijo `organizations/{id}/` del bucket queda vacío incluidas las versiones, y los conteos de propuestas, participantes y archivos de la organización real son iguales antes y después.
  Manual: contar registros y objetos de la organización real y del prefijo de prueba antes y después de la purga; comprobar en el panel del bucket que no quedan versiones.
- **CA-010-21:** *Dado* el runbook mínimo en `docs/operacion.md` §9, *cuando* se lee, *entonces* cubre: sitio caído, cola atrasada o worker caído, correo que no llega, restauración de la base, secreto expuesto, limpieza manual de archivos huérfanos y cómo activar el plan B; cada caso con pasos y responsable.
  Manual: revisión de Antonio y Gabriel; cada uno ejecuta en `staging` un caso del runbook siguiéndolo al pie de la letra.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| DNS o cuenta de correo sin verificar a tiempo | A2 y A3 hoy; comprobación diaria; criterio G4 en el go / no-go |
| Pérdida de datos por mala configuración de Railway | Respaldos con restauración ensayada (CA-010-14); migraciones solo con aprobación humana |
| G5 sin producción disponible antes de la Puerta 1 | Se evalúa sobre `staging` y producción se configura el 16-nov; la comprobación en producción va el 17, antes de la Puerta 2 |
| CSP bloquea el formulario o Turnstile en producción | CA-010-8 en `staging`; modo de reporte durante el ensayo |
| Límites de peticiones que bloquean a todos los autores detrás del proxy | DA-8 y CA-010-11 y 010-12 |
| Respaldos con datos personales mal protegidos | Acceso restringido, cifrado y retención definida `[PENDIENTE: días]` |
| Ensayo que borra o ensucia el contenido real | Organización de prueba aparte y purga definitiva verificada (DA-9, CA-010-19 y CA-010-20) |
| Un solo administrador de infraestructura | DA-10 y CA-010-5: Gabriel con acceso y códigos de recuperación compartidos |
| Costos o límites de Railway distintos a los esperados | `[PENDIENTE: verificar planes]` y alertas de gasto; revisión mensual |
| Alertas sin destinatario claro | Canal y responsable definidos antes de H3 |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
