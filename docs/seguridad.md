# Seguridad

> Prevalece sobre cualquier otro documento en seguridad. Se lee antes de tocar autenticación, permisos, archivos, pagos o aislamiento entre organizaciones.
> **Revisión humana obligatoria** en: autenticación, Policies y permisos, pagos y webhooks, subida y entrega de archivos, filtro de organización y migraciones.

## 1. Lecciones del producto de origen

El cpanel que dio origen al proyecto sufría estos fallos. Cada uno tiene aquí su regla; ninguno debe reaparecer.

| Fallo en el cpanel de origen | Regla en este proyecto |
|---|---|
| Solo una página verificaba la sesión; ~85 de ~130 respondían sin sesión; los roles solo cambiaban el menú | Toda acción se autoriza **en el servidor** con una Policy; denegar por defecto (§3) |
| Acciones destructivas por GET sin CSRF (borrar, aceptar, rechazar) | Cambios de estado solo por `POST`, `PATCH` o `DELETE` con CSRF y Policy |
| Inicio de sesión con SQL concatenado y contraseñas en texto plano comparadas en la consulta | Autenticación del framework, hash de contraseñas, sin SQL manual (§2, §4) |
| La respuesta del login devolvía la fila completa, contraseña incluida | Nunca se devuelve ni se registra una contraseña ni un hash |
| Un ponente asumía la identidad de otro cambiando `?id_usuario=` | La identidad sale de la sesión, jamás de un parámetro; bindings acotados y Policies (§3) |
| Exportación a Excel pública con contraseñas | Las exportaciones exigen permiso y auditoría y nunca incluyen credenciales |
| Subida de archivos con el nombre original en una carpeta pública (riesgo de ejecutar un `.php`) | Disco privado, nombre generado, validación por contenido (§5) |
| Credenciales de producción comentadas en archivos versionados | Secretos solo en variables de entorno; búsqueda de secretos en CI; rotar lo expuesto (§6) |
| Ediciones mezcladas por valores de evento escritos en el código | Contexto de organización y evento explícitos; sin valores fijos (ADR-0003) |
| Sin trazabilidad de quién cambió qué | Bitácora de auditoría de acciones sensibles (§9) |

## 2. Autenticación

- Autenticación del framework o del kit de inicio; **no escribir una propia**. Contraseñas con el hash de Laravel; reglas mínimas de longitud y lista de contraseñas expuestas.
- Verificación de correo y recuperación de contraseña con enlaces firmados y de un solo uso, con caducidad corta.
- Verificación en dos pasos disponible; **obligatoria** para administradores de organización y roles de plataforma. El panel de plataforma (`/platform`, plan 016) permanece apagado fuera de local hasta que exista (`PLATFORM_PANEL_ENABLED`, `docs/operacion.md`).
- Límite de intentos en inicio de sesión, recuperación y registro; mensajes que no revelan si un correo existe.
- Regenerar la sesión al iniciar sesión; cookies `Secure`, `HttpOnly`, `SameSite=Lax` como mínimo; caducidad de sesión definida.
- Las invitaciones por «enlace mágico» para ponentes o evaluadores son de un solo uso, con caducidad y ligadas a un correo y a una organización.

## 3. Autorización

- **Una Policy por modelo.** Cada Action, controlador, página y acción de Filament llama a `authorize()`. Sin Policy no hay acceso.
- La identidad y la organización salen de la sesión y del contexto, nunca de un parámetro del cliente.
- **Bindings acotados:** un identificador de otra organización responde 404, no 403, para no confirmar que existe.
- Prueba por rol y acción: permitido y denegado. Un rol nuevo o un cambio de permisos exige especificación.
- Ocultar un botón o un menú **no** es autorizar.
- Los cambios de rol y de permisos se auditan.

## 4. Entrada y salida

- Un Form Request por entrada; `validated()`; nunca `$request->all()`. Modelos con `$fillable` explícito; prohibido `$guarded = []`.
- SQL solo con enlaces; sin variables interpoladas en `DB::raw` ni `whereRaw`.
- Blade escapa por defecto (`{{ }}`); `{!! !!}` solo con contenido sanitizado. En React, nada de `dangerouslySetInnerHTML` con contenido de usuario sin sanitizar.
- Texto con formato (Markdown o HTML) se sanitiza en el servidor con una lista blanca de etiquetas.
- Redirecciones: solo a rutas internas o a una lista blanca; nunca a `HTTP_REFERER` sin validar.

## 5. Archivos

- Disco **privado**; nunca bajo `public/`. Nombre generado (`Str::uuid()`); jamás el nombre original ni una ruta del cliente.
- Validar por **contenido** (tipo MIME real), extensión permitida y tamaño máximo. Rechazar siempre ejecutables y `.php`, `.phtml`, `.phar`, `.html` y `.svg` con scripts.
- Entregar con URL temporal firmada y `Content-Disposition`; no servir archivos de usuarios desde el dominio de la aplicación si puede evitarse (dominio o CDN aparte).
- La ruta incluye la organización; un archivo de otra organización responde 404.
- Análisis antivirus opcional según el tipo de archivo `[RELLENAR: decisión]`.

## 6. Secretos, configuración y dependencias

- **Ningún secreto en el repositorio**, ni siquiera comentado. `.env` no se versiona; `.env.example` solo con valores ficticios. `.claude/settings.json` impide al agente leerlo.
- Todo secreto expuesto alguna vez se **rota**. Búsqueda de secretos en CI (gitleaks).
- `env()` solo en `config/`; el arranque falla con un error claro si falta una variable obligatoria.
- Dependencias: Dependabot semanal; `composer audit` y `npm audit` en CI; no se añaden paquetes fuera de la lista autorizada de `AGENTS.md`.
- Modo de depuración apagado fuera de local. Sin `dd()`, `var_dump` ni trazas en producción; sin información interna en los mensajes de error.

## 7. Red, cabeceras y abuso

- HTTPS obligatorio con HSTS; proxies de confianza configurados.
- CSRF activo en todo lo que cambie estado. CORS restringido a los orígenes necesarios.
- Cabeceras de seguridad: `Content-Security-Policy`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`. La CSP se ajusta a los scripts reales del sitio público.
- **Límite de peticiones** en formularios públicos (convocatoria, registro, voluntarios) y en la API; protección anti-robots en los formularios públicos `[RELLENAR: proveedor]`.
- Sin enumeración: identificadores UUID, respuestas homogéneas ante recursos inexistentes y no autorizados.

## 8. Pagos y webhooks

Complementa el ADR-0007.

- Ningún dato de tarjeta toca nuestros servidores: se usa el formulario o la integración del proveedor.
- **Webhooks:** verificar la firma antes de procesar; rechazar con 4xx si no coincide; responder rápido y procesar en cola; guardar el identificador del evento del proveedor con restricción única para no repetir; tolerar la repetición y el desorden.
- Importes en unidades menores con moneda; nunca confiar en el importe que envía el cliente: se recalcula en el servidor.
- Hechos financieros (cargo, pago, devolución, comisión) en tablas propias y de solo añadir; los estados derivados se calculan.
- Reembolsos y cambios de precio con permiso específico y auditoría.
- Conciliación periódica con el proveedor `[RELLENAR: frecuencia]`.

## 9. Auditoría y registros

- `spatie/laravel-activitylog` registra: inicios de sesión y fallos relevantes, cambios de rol o permiso, invitaciones, exportaciones, uso de `withoutOrganizationScope()`, cambios de configuración de pago y accesos de soporte. Cada registro lleva su `organization_id` (nulo en los hechos de plataforma); el visor del panel de plataforma es de solo lectura y no muestra IP ni hash de correo de los registros de acceso (`auth`).
- Los registros no contienen contraseñas, tokens ni datos personales completos. Se conservan según `docs/privacidad.md`.
- Los registros de aplicación llevan un identificador de correlación por petición.

## 10. Lista de verificación por módulo

Antes de dar por terminado un módulo:

- [ ] Cada ruta, acción y página tiene Policy y una prueba por rol (permitido y denegado).
- [ ] Los modelos con datos de organización usan `BelongsToOrganization`; las guardianas pasan.
- [ ] Toda entrada pasa por un Form Request; sin `$request->all()`.
- [ ] Sin SQL con interpolación; sin asignación masiva sin `$fillable`.
- [ ] Archivos en disco privado, con validación por contenido y URL temporal.
- [ ] Cambios de estado solo por POST/PATCH/DELETE con CSRF.
- [ ] Formularios públicos con límite de peticiones y protección anti-robots.
- [ ] Datos personales nuevos reflejados en `docs/privacidad.md`.
- [ ] Acciones sensibles auditadas.
- [ ] Sin secretos ni datos personales en logs, respuestas o exportaciones.
- [ ] Revisión humana hecha si toca autenticación, permisos, pagos, archivos o tenancy.
