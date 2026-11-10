# ADR-0009: Correo transaccional y catálogo de notificaciones

- **Fecha:** 2026-11-10
- **Estado:** Propuesta (pendiente de aprobación; plan 008, DA-1)
- **Alcance:** todo correo que dispara el sistema (módulo transversal)

## Contexto

El producto envía correos desde varios módulos: restablecer contraseña (plan 001), alta de equipo (plan 003), acuse y enlace de acceso del autor (plan 006), y más adelante evaluación y resultados (planes 011 y 012). Si cada módulo envía a su manera, se duplican plantillas, se dispersan los riesgos de seguridad (inyección en plantillas, phishing con valores controlados por un autor anónimo) y cambiar de proveedor obliga a tocar todos los módulos. El sitio de origen (cpanel) no enviaba ningún correo desde el panel. La entregabilidad es riesgo de lanzamiento: un acuse que cae en spam rompe la confianza del autor.

## Decisión

1. **Proveedor.** Resend en R0 y Amazon SES como proveedor de crecimiento, ambos detrás de la interfaz `TransactionalMailer`. Cambiar de proveedor es un cambio de configuración, sin tocar módulos ni plantillas.
2. **Catálogo en código como única fuente.** `App\Support\Notifications\NotificationCatalog` registra cada notificación con su clave, nombre, descripción, disparador, destinatario, variables permitidas, idiomas, si es crítica y su límite por destinatario. Ninguna notificación existe fuera del catálogo.
3. **Regla de proceso.** Toda notificación nueva se registra en el catálogo **con su plantilla predeterminada (es y en) y su prueba**. Una guardiana de pruebas falla si una clase enviable no está registrada o si falta una plantilla. Se añade a la definición de terminado de `AGENTS.md` al aprobar este ADR.
4. **Plantillas seguras.** Cuerpo en Markdown con HTML de entrada deshabilitado y variables `{{ variable }}` de lista blanca por notificación. Se renderiza primero el Markdown con marcadores opacos y después se sustituyen los valores ya escapados.
5. **Destinatario fijado solo por el sistema.** Únicamente la Action `SendNotification` decide a quién se envía; ninguna plantilla ni variable puede cambiarlo ni añadir copias.
6. **Envío por cola, idempotente.** Job con 5 intentos y espera creciente; clave de idempotencia `notification_key` + identificador del hecho de negocio; límite de envíos por destinatario y hora declarado en el catálogo.

## Alternativas consideradas

| Alternativa | Por qué se descartó |
|---|---|
| Cada módulo envía sus propios correos | Duplicación, plantillas inconsistentes y sin una guardiana central |
| Plantillas en Blade o HTML libre con sanitizador | Amplía la superficie de ataque (ejecución de código y HTML inyectado) |
| Catálogo en base de datos | Permitiría registrar notificaciones sin código ni prueba; se pierde la guardiana |
| Un solo proveedor sin interfaz | Cambiar a SES obligaría a tocar los módulos |

## Consecuencias

**Positivas:** un solo lugar para auditar qué envía el sistema; plantillas con renderizado seguro por diseño; proveedor intercambiable; envíos sin duplicados ante reintentos.

**Negativas o riesgos:** el cliente de Resend es una dependencia nueva que exige permiso (`AGENTS.md`, acción 4); la entregabilidad depende de verificar el dominio (SPF, DKIM y DMARC) y se comprueba con envío real a Gmail y Outlook (CA-008-29); los límites y el precio del plan gratuito de Resend están por verificar antes del hito H2.

**Qué cambia para el equipo:** quien añada un correo lo registra en el catálogo con sus plantillas y pruebas; nadie envía correo directamente con `Mail::` fuera de `SendNotification`.

## Decisiones abiertas

- Aprobación de la dependencia del cliente de Resend (plan 008, DA-6).
- Límites y precio vigentes de Resend (plan 008, riesgos).

## Referencias

- `docs/plans/2026-11-09-008-feat-correos-catalogo-y-plantillas.md`, `docs/seguridad.md`, `docs/privacidad.md`, `docs/entorno-local.md` §6 (Mailpit)
