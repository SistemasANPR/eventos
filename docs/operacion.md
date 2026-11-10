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
| Correo transaccional | `[RELLENAR: proveedor]`; dominio con SPF, DKIM y DMARC |
| CDN y protección | Cloudflare delante del sitio público |
| Errores | `[RELLENAR: Sentry u otro]`; sin PII en los eventos |
| Monitoreo de colas | Laravel Horizon |

## 3. Despliegue

1. Cada pull request pasa el CI (`.github/workflows/ci.yml`).
2. Integrar en `staging` despliega al entorno de pruebas (si existe, ADR-0008 DA-3).
3. Producción se despliega desde `main`, que solo recibe `staging` por pull request, con **aprobación manual**.
4. **Migraciones:** el paso de despliegue las aplica solo tras la aprobación de una persona (regla 1 de `AGENTS.md`). Cambios incompatibles siguen «expandir y contraer» (`docs/database-design.md` §5).
5. Después del despliegue: comprobar `/up`, reiniciar los workers y revisar errores durante 15 minutos.

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
| `MAIL_*` | Correo transaccional | Sí |
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
- Modo de mantenimiento de Laravel para ventanas planificadas; aviso previo a las organizaciones.
- Actualizaciones de dependencias: Dependabot, una por pull request; actualizaciones de seguridad de inmediato.
- Rotación de secretos: al menos cada `[RELLENAR]` y siempre que se sospeche una exposición.

## 9. Guías de actuación (runbooks)

Anotar aquí, al ocurrir o al ensayar, cómo se resuelve:

- Sitio caído · Cola atrasada o worker caído · Webhook de pagos que falla · Correo que no llega · Restauración de la base · Sospecha de fuga entre organizaciones · Secreto expuesto.

[RELLENAR: un enlace o un párrafo por caso.]
