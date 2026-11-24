# Operación

> Cómo se despliega, se vigila y se recupera el sistema. Rellenar al elegir la plataforma (ADR-0002) y revisar con cada cambio de infraestructura.

## 1. Entornos

| Entorno | Para qué | Datos | Rama | Despliegue |
|---|---|---|---|---|
| Local (Docker, VS Code) | Desarrollo | Ficticios (fábricas y seeders) | `staging` | Manual (`docs/entorno-local.md`) |
| CI | Pipeline | Efímeros (PostgreSQL de servicio) | pull request, `staging`, `main` | Automático |
| Pruebas (`staging`) | Validar antes de producción | Anonimizados o ficticios; **nunca** datos reales sin anonimizar | `staging` | `[RELLENAR: solo la rama, o un servidor desplegado, ADR-0008 DA-3]` |
| Producción | Uso real | Reales | `main` | Manual con aprobación |

Todo el desarrollo ocurre en `staging`; `main` solo recibe lo ya probado (ADR-0008). `staging` puede empezar como una rama sin servidor propio; cuando haga falta que el equipo o la organización cliente prueben en un entorno compartido, se despliega.

[RELLENAR: plataforma elegida (Railway, Laravel Cloud, GCP, AWS), región y dominios.]

## 2. Servicios requeridos

| Servicio | Notas |
|---|---|
| Aplicación web | Laravel; SSR de Inertia si se activa (ADR-0004) |
| Worker de colas | Procesa correos, webhooks, exportaciones y PDF. **Hosting compartido no sirve** |
| Planificador | `php artisan schedule:run` cada minuto |
| PostgreSQL | Gestionado; respaldos automáticos |
| Redis | Colas, caché y bloqueos |
| Almacenamiento de archivos | Compatible con S3; bucket **privado**; versionado activado |
| Correo transaccional | Resend en R0, con Amazon SES como alternativa detrás de `TransactionalMailer` (ADR-0009); dominio con SPF, DKIM y DMARC |
| CDN y protección | Cloudflare delante del sitio público |
| Errores | `[RELLENAR: Sentry u otro]`; sin PII en los eventos |
| Monitoreo de colas | Laravel Horizon |

## 3. Despliegue

1. Cada pull request pasa el CI (`.github/workflows/ci.yml`).
2. Integrar en `staging` despliega al entorno de pruebas (si existe, ADR-0008 DA-3).
3. Producción se despliega desde `main`, que solo recibe `staging` por pull request, con **aprobación manual**.
4. **Migraciones:** el paso de despliegue las aplica solo tras la aprobación de una persona (regla 1 de `AGENTS.md`). Cambios incompatibles siguen «expandir y contraer» (`docs/database-design.md` §5).
   **En Railway el despliegue no ejecuta `migrate`:** tras desplegar una versión con migraciones nuevas quedan pendientes hasta que una persona las aplica. Pasos, en este orden:
   1. Ver cuáles faltan: `railway ssh --service eventos -- php artisan migrate:status` (las filas `Pending`). Es solo lectura.
   2. Revisar que sean aditivas (`create table`, columnas nuevas con valor por defecto); una destructiva sigue «expandir y contraer» y lleva su plan de reversa.
   3. Aplicarlas: `railway ssh --service eventos -- php artisan migrate --force`. Es idempotente: reintentarla no repite las ya aplicadas.
   4. Comprobar que `migrate:status` ya no muestra `Pending`.
   Hacerlo en cuanto termine el despliegue: mientras tanto, las pantallas que usan las tablas nuevas fallan con «tabla inexistente».
5. Después del despliegue: comprobar `/up`, reiniciar los workers y revisar errores durante 15 minutos.
6. **Recursos de Filament en el build:** `railway.json` fija el constructor `DOCKERFILE`. Los scripts y fuentes de Filament (`public/js/filament`, `public/fonts/filament`) no están en Git: los publica `filament:upgrade` (script `post-autoload-dump`) cuando el `Dockerfile` ejecuta `composer dump-autoload` con scripts. Sin ese paso responden 404 y en el acceso desaparecen el botón «Entrar» y el icono del ojo (con Railpack ocurrió). Tras desplegar, comprobar que `/js/filament/filament/app.js` responde 200.

**Reversa:** redesplegar la versión anterior. Si la migración es incompatible hacia atrás, el plan de reversa se describe en la propuesta de datos antes de aprobarla.

## 4. Variables de entorno

Solo nombres; nunca valores en el repositorio. Toda variable nueva se añade a `.env.example` con valor ficticio y a esta tabla.

| Variable | Para qué | Obligatoria |
|---|---|---|
| `APP_KEY`, `APP_URL`, `APP_ENV` | Aplicación | Sí |
| `TRUSTED_PROXIES` | Lista separada por comas de IPs o CIDR de los balanceadores en los que se confía para leer `X-Forwarded-For/Host/Port/Proto`. Vacía (por defecto) = no se confía en ninguno. `*` confía en cualquier origen: solo con intención explícita | Sí, detrás de un balanceador |
| `DB_*` | PostgreSQL | Sí |
| `REDIS_*` | Colas y caché | Sí |
| `FILESYSTEM_DISK` y credenciales del bucket | Archivos | Sí |
| `PRIVATE_UPLOADS_DISK` | Disco privado de los archivos de propuestas (por defecto `local`). En staging y producción apunta a un bucket S3 compatible **privado**; nunca a `public` | Sí |
| `PLATFORM_PANEL_ENABLED` | Habilita el panel de plataforma (`/platform`, plan 016). Por defecto solo en `local` y pruebas; en staging y producción queda apagado (404) y **no debe activarse hasta que exista la 2FA del plan 014** (DA-1) | No |
| `TWO_FACTOR_REQUIRED` | Exige la verificación en dos pasos a `org_admin`, `organizer`, `reviewer` y plataforma (plan 014 F1). **Encendida por defecto**; solo se apaga (`false`) en pruebas y desarrollo. Al aplicar las migraciones del plan con ella encendida, todo usuario existente debe configurarla en su siguiente apertura de página: ver plan 014 §4.2 (paso de despliegue y cuenta de recuperación). Con ella apagada, el plan 011 rechaza invitar a evaluadores externos | No |
| `MAIL_*` | Correo transaccional | Sí |
| `NOTIFICATIONS_MAILER` | Proveedor del correo transaccional: `resend` en staging y producción, `smtp` en local (Mailpit). Un valor desconocido falla al enviar; no cae a otro proveedor (ADR-0009, plan 008) | Sí |
| `RESEND_API_KEY` | Clave de la API de Resend; solo cuando `NOTIFICATIONS_MAILER=resend`. Es un secreto: solo en variables del entorno, nunca en el repositorio | Sí, con `resend` |
| `RUN_QUEUE_WORKER` | Con `true`, el contenedor web procesa también la cola de correos (acuses de propuesta, avisos): para un despliegue de un solo servicio. Con un servicio `worker` aparte (`php artisan queue:work`) se deja sin definir. Sin ningún worker los correos se quedan en la cola y no salen | No |
| `RUN_MIGRATIONS` | Con `true`, el contenedor web aplica las migraciones pendientes (`php artisan migrate --force`) al arrancar, sin entrar al servidor tras cada despliegue. Si una migración falla, el contenedor no arranca y Railway conserva la versión anterior. Es una decisión explícita de quien administra el entorno (AGENTS.md, regla 1): se activa a propósito; por defecto apagado | No |
| `TURNSTILE_SITE_KEY` | Llave pública de Cloudflare Turnstile; va al formulario público (plan 006). En local, la llave de prueba que siempre aprueba | Sí, en producción |
| `TURNSTILE_SECRET_KEY` | Llave secreta de Turnstile; solo la usa el servidor para verificar el envío. Es un secreto: solo en variables del entorno | Sí, en producción |
| `SUBMISSIONS_LIMIT_MULTIPLIER` | Multiplica los intentos de los límites de los formularios públicos de propuestas (5 envíos por IP cada 10 min, 3 acuses por correo por hora, enlaces de acceso y cuotas de carga). Por defecto 1, que son los valores del plan 006; en staging se sube (por ejemplo 100) para no quedar bloqueado en las pruebas. En producción se deja en 1: bajarlo de 1 no es posible | No |
| `ANONYMIZATION_SALT` | Sal de la huella no reversible de las personas anonimizadas (plan 014, F4; `config/privacy.php`). Un valor aleatorio largo (`openssl rand -hex 32`), distinto en cada entorno y guardado como secreto. **Sin ella no se puede anonimizar a nadie. Cambiarla invalida las huellas ya registradas** (`app:reapply-anonymizations` dejaría de reconocer a las personas anonimizadas): se define una vez, antes de la primera anonimización, y se conserva con los respaldos de secretos. En `.env.example` solo hay un valor ficticio | Sí, para anonimizar |
| `[RELLENAR: proveedor de pagos]` | Pagos (ADR-0007) | Según módulo |
| `[RELLENAR: errores]` | Registro de errores | Recomendada |

### Proxies de confianza y límites por IP

Detrás de un balanceador (Railway u otro), sin `TRUSTED_PROXIES` la aplicación ve la IP del proxy en `request()->ip()`: el límite de intentos del login (correo + IP) y la huella de dispositivo (red /24) dejarían de distinguir clientes. Con `*` o con un rango demasiado amplio ocurre lo contrario: cualquiera puede falsificar `X-Forwarded-For` y eludir esos límites. **Los límites de intentos dependen de que `request()->ip()` sea correcta.**

- Se lee de `config('app.trusted_proxies')` (`config/app.php`) y lo aplica `App\Http\Middleware\TrustConfiguredProxies`; solo se aceptan las cabeceras `X-Forwarded-For`, `-Host`, `-Port` y `-Proto` (no las de AWS ELB).
- En Railway se fija al rango o valor de su red interna `[RELLENAR: valor real]` y se **verifica con una petición real**: comprobar en la bitácora de accesos (`activity_log`, registro `auth`) que la IP registrada es la del cliente y no la del proxy.
- Local y CI: dejarla vacía.

## 5. Respaldos y recuperación

- **Base de datos:** respaldo diario automático con retención de `[RELLENAR: días]`, más recuperación a un punto en el tiempo si la plataforma lo ofrece.
- **Archivos:** versionado del bucket y copia a otra región o proveedor `[RELLENAR]`.
- **Prueba de restauración** en staging cada trimestre; se anota la fecha y el resultado aquí:

| Fecha | Qué se restauró | Resultado | Tiempo |
|---|---|---|---|

- Objetivos: punto de recuperación `[RELLENAR]` y tiempo de recuperación `[RELLENAR]`.
- **Retención máxima de los respaldos** (plan 014, DA-5, CA-014-37): `[PENDIENTE: días]`. Es el plazo tras el cual un dato anonimizado deja de existir también en los respaldos. Se declara por organización en la categoría `backups` de «Retención de datos» (solo declarativa: ningún trabajo borra respaldos) y se configura en el proveedor. **Revisión manual:** entrar al proveedor de la base y al bucket de respaldos, comprobar que ningún respaldo supera ese plazo y anotar abajo la fecha de la revisión y quién la hizo.

| Fecha de la revisión de retención | Quién | Retención configurada en la base | Retención en el bucket | Resultado |
|---|---|---|---|---|

### 5.1 Restaurar un respaldo (runbook)

Quién lo hace: una persona de operación, con acceso al proveedor y al servicio. Tiempo estimado: `[RELLENAR tras el primer ensayo]`. **Un respaldo de producción es dato real**: solo se restaura en el propio entorno de producción (emergencia) o en `staging` **con los datos anonimizados**; nunca se deja un respaldo real sin anonimizar en un entorno que no sea producción (CA-014-18).

**Por qué hay que reaplicar las anonimizaciones.** Un respaldo anterior a una anonimización trae de vuelta los datos de esa persona. Cada anonimización deja una huella no reversible en `anonymization_log`, pero ese registro está en la misma base que se restaura, así que también se pierde si el respaldo es anterior. Por eso se guarda antes de restaurar y se vuelve a cargar después.

**Antes de restaurar** (en el entorno que se va a sobrescribir, mientras sigue funcionando):

1. Anotar la hora y el motivo. Avisar a las organizaciones si es producción.
2. Confirmar que `ANONYMIZATION_SALT` está en las variables del entorno y que es **la misma** que tendrá el entorno restaurado (la sal no está en la base: no cambia al restaurar). Sin la misma sal, la reaplicación no reconoce a nadie.
3. Guardar el registro de anonimizaciones: `railway ssh --service eventos -- php artisan app:export-anonymization-log /tmp/anonymization-log.json` (solo huellas, fechas e identificadores de organización; sin correos). Copiar el archivo a un lugar privado de la persona operadora (nunca a `public/` ni a un bucket público).
4. Poner la aplicación en mantenimiento (`php artisan down`) y detener los workers, para que nadie escriba durante la restauración.

**Restaurar:**

5. Restaurar la base con el método del proveedor `[RELLENAR: restauración a un punto en el tiempo o desde el archivo de respaldo]`. Restaurar también el bucket de archivos solo si hace falta recuperar archivos (los de las personas anonimizadas se borraron a propósito: no reaparecerán salvo que el bucket también se restaure; en ese caso, el paso 7 los vuelve a borrar).
6. Comprobar que arranca (`/up`) y que las migraciones coinciden con las del código desplegado: `php artisan migrate:status`. Si faltan migraciones, aplicarlas con el procedimiento de §3.

**Reaplicar las anonimizaciones:**

7. Cargar el registro y reaplicar: `php artisan app:reapply-anonymizations --from-file=/tmp/anonymization-log.json`. El comando carga las huellas que falten, recorre cada organización y vuelve a anonimizar a quien reaparece; imprime por organización cuántas personas están registradas, cuántas reaparecieron con datos y cuántas se reaplicaron, y al final el total. Es idempotente: se puede repetir. Antes de ejecutarlo de verdad, `--dry-run` (sin `--from-file`) cuenta cuántas se reaplicarían sin cambiar nada.
8. Si el comando termina con error, **no abrir el servicio**: lo que imprima dice qué quedó pendiente. Una persona «omitida por `last_administrator`» es el último administrador activo de una organización (se designa otro y se repite); «omitida por `platform_account`» es una cuenta de plataforma (se atiende a mano); «con error» es un archivo que el almacenamiento no pudo borrar (revisar el bucket y repetir).
9. Borrar el archivo del registro del lugar donde se guardó.

**Verificar:**

10. Conteos: el número de propuestas de cada organización coincide con el del origen (`php artisan tinker --execute='echo App\Models\Submission::withoutGlobalScopes()->count();'` en ambos, o la consulta equivalente en la base).
11. Anonimizaciones: elegir una persona anonimizada conocida y comprobar en el panel («Datos de una persona») que ya no figura con sus datos; el comando debe volver a decir `Personas reaplicadas: 0 de 0` al repetirlo.
12. Salir de mantenimiento (`php artisan up`), reiniciar los workers y revisar errores durante 15 minutos.
13. Anotar en la tabla de ensayos de arriba: fecha, qué se restauró, resultado y tiempo.

### 5.2 Ensayo de restauración en `staging` (lista de verificación para una persona)

El ensayo real **lo hace una persona**: no está automatizado ni se hizo en el desarrollo del plan 014 (CA-014-16 y CA-014-18 son manuales). Marcar cada paso al hacerlo:

- [ ] Elegir un respaldo de producción reciente y restaurarlo **en `staging`** (pasos 5 y 6 de §5.1).
- [ ] Antes de usar `staging`, anonimizar todo el respaldo restaurado: CA-014-18 prohíbe datos reales sin anonimizar fuera de producción. `[PENDIENTE: procedimiento de anonimización masiva de un respaldo para staging; hoy solo existe la anonimización por persona]`.
- [ ] Antes de restaurar, guardar el registro de anonimizaciones de **producción** (paso 3) y cargarlo en `staging` con `--from-file` (paso 7): así el ensayo prueba la reaplicación con lo real.
- [ ] Comprobar que la aplicación arranca, que las migraciones coinciden y que el conteo de propuestas coincide (paso 10).
- [ ] Verificar una persona anonimizada después de la fecha del respaldo (paso 11).
- [ ] Anotar fecha, qué se restauró, resultado y tiempo en la tabla de §5. Si algo falló o fue confuso, corregir este documento.

## 6. Observabilidad

- **Salud:** `/up` (incluido en Laravel) y una comprobación externa cada minuto.
- **Registros** estructurados con identificador de correlación; sin datos personales ni secretos. Retención `[RELLENAR]`.
- **Errores:** agrupados y con alerta al equipo; los de pagos y webhooks alertan de inmediato.
- **Colas:** Horizon con alertas por trabajos fallidos, tiempo de espera largo y worker caído.
- **Alertas mínimas:** sitio caído, tasa de errores 5xx, cola atrasada, espacio de disco o conexiones de la base, certificado por caducar, fallo del planificador.
- **Métricas de negocio** (registros, pagos, envíos) en el panel de reportes, no aquí.

## 7. Rendimiento y costos

- Revisar consultas lentas y N+1 (`Model::preventLazyLoading()` fuera de producción).
- Caché con clave que incluye la organización.
- Revisión mensual de costos por servicio; alertas de gasto en la plataforma.

## 8. Mantenimiento

- **Tarea programada `files:purge-orphans`** (plan 007, H-007-3): diaria a las 03:00 (`routes/console.php`, `withoutOverlapping`). Borra del disco privado y marca como eliminados los archivos de propuesta `pending` con más de 24 h sin ligar a una propuesta, organización por organización; nunca toca los `attached`. Cada organización con borrados deja un registro de auditoría `submission_files_purged` con su conteo (sin nombres de archivo). **Vigilancia:** si el disco falla, el fallo de cada organización se reporta al gestor de errores (`OrphanFilesPurgeFailed`) y el comando termina con código distinto de 0 (las demás organizaciones se limpian igualmente); alertar por ese código de salida o por ese error. La tarea es idempotente: reintentarla a mano con `php artisan files:purge-orphans` es seguro.
- **Tarea programada `submission-exports:purge`** (plan 009, H-009-3, DA-3): cada hora (`routes/console.php`, `withoutOverlapping`). Borra del disco privado los archivos de exportación de propuestas (`organizations/{id}/exports/`) con más de 24 h: los de exportaciones auditadas vencidas y, por si el job falló antes de auditar, cualquier archivo de esa carpeta con fecha de modificación anterior al plazo. **Vigilancia:** si no puede borrar un archivo, el comando termina con código distinto de 0; alertar por ese código de salida. Es idempotente. **Cola:** las exportaciones corren en el job `ExportSubmissionsJob` (3 intentos, espera de 30 s y 120 s); si agota los intentos deja la auditoría `submissions_export_failed` y un error en el registro, sin datos personales: vigilar los trabajos fallidos de la cola.
- Modo de mantenimiento de Laravel para ventanas planificadas; aviso previo a las organizaciones.
- Actualizaciones de dependencias: Dependabot, una por pull request; actualizaciones de seguridad de inmediato.
- Rotación de secretos: al menos cada `[RELLENAR]` y siempre que se sospeche una exposición.

## 9. Guías de actuación (runbooks)

Cada guía está escrita para una persona de operación que no escribió el código. Se ensayan en `staging` leyéndolas con alguien que no las escribió (CA-014-17, manual): si un paso no se entiende o falla, se corrige aquí. Los comandos usan `railway ssh --service eventos -- …` (el servicio web, como en §3); en local, `docker compose exec app …`. Lo que depende del proveedor y todavía no se eligió queda como `[RELLENAR]`.

### 9.1 Sitio caído

1. Comprobar `/up` desde fuera (navegador o `curl -i https://<dominio>/up`). Si responde 200, el problema es de una página concreta: pasar a revisar errores (paso 4).
2. Si no responde: ver el estado del despliegue y del servicio en el proveedor `[RELLENAR]`; ¿hubo un despliegue en los últimos 30 minutos? Si sí, **redesplegar la versión anterior** (reversa de §3).
3. Si no hubo despliegue: revisar la base (¿responde? conexiones agotadas, disco lleno) y Redis; reiniciar el servicio web.
4. Revisar el gestor de errores `[RELLENAR]` y la bitácora del servicio en busca del primer error de la caída.
5. Con el sitio de vuelta: reiniciar los workers, comprobar la cola (9.2) y avisar a las organizaciones si hubo pérdida de servicio visible.
6. Anotar causa, duración y qué se hizo.

### 9.2 Cola atrasada o worker caído

1. Ver si hay un worker vivo: Horizon `[RELLENAR: URL]` o, sin Horizon, el servicio `worker` en el proveedor (o `RUN_QUEUE_WORKER=true` en el web, §4).
2. Si el worker está caído: reiniciarlo. Si se cae de nuevo, mirar el primer error de la bitácora.
3. Si hay trabajos fallidos: `railway ssh --service eventos -- php artisan queue:failed`; revisar el error de cada tipo (sin pegar datos personales en ningún mensaje) y, una vez corregida la causa, `php artisan queue:retry all`. Los trabajos son idempotentes: reintentar es seguro.
4. Si la cola sigue creciendo con workers vivos: subir la cantidad de workers y revisar si un trabajo lento la bloquea.
5. Comprobar que los correos pendientes salen (9.4).

### 9.3 Webhook de pagos que falla

No hay módulo de pagos en R1 (ADR-0007, R3). Esta guía se escribe con el módulo: firma verificada, idempotencia por identificador del evento y reintento desde el panel del proveedor.

### 9.4 Correo que no llega

1. Confirmar si el correo se **intentó enviar**: panel de la organización → «Correos» (catálogo) y la auditoría; un envío `throttled` o `disabled` no se manda por diseño (ADR-0009).
2. Si está en cola: ver 9.2. Si falló: revisar el proveedor (Resend) `[RELLENAR: enlace al panel]`: clave `RESEND_API_KEY` vigente, dominio verificado (SPF, DKIM y DMARC) y límites de envío.
3. Enviar una prueba a una dirección propia del equipo con `php artisan app:mail-check <correo-del-equipo>`: confirma que la configuración del proveedor funciona de extremo a extremo (nunca a una persona real).
4. Si el destinatario es una persona anonimizada, el correo ya no corresponde a nadie: es lo esperado.
5. Si el problema es de entrega (spam, rebote): pedir a la persona que revise la carpeta de no deseados y que añada el remitente; reenviar por la función de la plantilla solo si el catálogo lo permite.

### 9.5 Restauración de la base

Ver §5.1 (restaurar y reaplicar anonimizaciones) y §5.2 (ensayo en `staging`).

### 9.6 Sospecha de fuga entre organizaciones

1. **Contener:** si hay indicios de que una organización ve datos de otra, activar el modo de mantenimiento de las pantallas afectadas o de todo el sitio (`php artisan down`) hasta entender el alcance. Avisar al responsable de seguridad.
2. **Conservar evidencia:** no borrar nada; exportar la auditoría (`activity_log`) del período y guardar los registros del servicio.
3. **Acotar:** ¿qué organización, qué pantalla o ruta, qué datos? Reproducir en `staging` con dos organizaciones de prueba. Revisar primero lo que `docs/seguridad.md` y el ADR-0003 señalan: consulta sin scope (`withoutOrganizationScope`), route-model binding sin acotar, caché sin clave de organización, política ausente.
4. **Corregir** con una prueba de regresión de aislamiento (`tests/Feature/Tenancy/`) y desplegar.
5. **Notificar:** si hubo acceso real a datos personales de otra organización, aplicar el procedimiento de incidente de `docs/privacidad.md` §5 (a quién se avisa y en qué plazo `[RELLENAR]`).

### 9.7 Secreto expuesto

1. Identificar qué secreto es (`APP_KEY`, credenciales de base o del bucket, `RESEND_API_KEY`, `TURNSTILE_SECRET_KEY`, `ANONYMIZATION_SALT`, llaves del proveedor de pagos) y desde cuándo está expuesto.
2. **Rotarlo en el proveedor primero** y actualizar la variable del entorno; reiniciar el servicio y los workers.
3. Consecuencias propias de algunos secretos: cambiar `APP_KEY` invalida sesiones, enlaces firmados y datos cifrados (los secretos de 2FA: todos tendrían que reconfigurarla) y cambia la huella de los envíos de correo; cambiar `ANONYMIZATION_SALT` invalida las huellas del registro de anonimizaciones (antes de rotarla, evaluar el efecto: ver §4). Decidir con el responsable y registrar la decisión.
4. Revisar la auditoría y los registros del proveedor desde la fecha de exposición en busca de uso indebido.
5. Si estuvo en el repositorio, quitarlo del historial es una acción con permiso (AGENTS.md, regla 12); rotar de todos modos.
6. Anotar causa, alcance y qué se rotó.
