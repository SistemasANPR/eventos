# Deuda técnica

Registro vivo. [RELLENAR: origen, por ejemplo «sale del diagnóstico de alineación con la plantilla (AAAA-MM-DD)».] Cada punto se trabaja **uno a uno y con aprobación previa**: propuesta (archivos, antes y después, riesgo, verificación) → «adelante» → cambio mínimo con pruebas → salida real de las validaciones → commit → mover a «Resueltos» con su hash en un commit `docs(debt)`.

Prioridades: **P1** seguridad y datos · **P2** contratos · **P3** convenciones · **P4** estilo. Esfuerzo: S (menos de 1 h), M (media jornada), L (una jornada o más). Un punto L se trabaja con un plan en `docs/plans/` (ADR-0001).

Lo que NO está aquí porque se decidió no cambiarlo: [RELLENAR: decisiones de «se queda así». Anotarlas evita reabrirlas.]

---

## Pendientes

### P1 · Seguridad y datos

| # | Punto | Archivos | Esfuerzo |
|---|---|---|---|
| DT-02 | Sin límite global por IP ni 2FA frente a fuerza bruta distribuida (hoy el límite es por correo + IP, 5 intentos / 15 min). Se aborda en el plan 014. Dueño: [PENDIENTE]. Fecha límite sugerida: según el calendario del plan 014; [PENDIENTE] fecha | `app/Support/Auth/AccessThrottle.php`, plan 014 | L |

### P2 · Contratos

| # | Punto | Archivos | Esfuerzo |
|---|---|---|---|
| DT-04 | Si el encolado del correo de alta falla después de confirmar la transacción, no hay reenvío automático ni outbox. Se mitiga con el reenvío de invitación (tarea en curso); el outbox transaccional queda pendiente. Dueño: [PENDIENTE]. Fecha límite sugerida: tras cerrar el reenvío de invitación; [PENDIENTE] fecha | `app/Actions/Organizations/InviteTeamMember.php`, notificación `membership_added` | M |
| DT-12 | Plan 007 (H-007-1): `submission_files.submission_id` es un uuid sin clave foránea y `FileFieldRegistry` / `UploadTokenVerifier` tienen una implementación que deniega todo. Al llegar el plan 006 (`submissions`) añadir la FK compuesta `(submission_id, organization_id)` en su migración, y con los planes 005 y 006 sustituir ambas por las reales (hasta entonces la subida responde 403). Documentar `PRIVATE_UPLOADS_DISK` en `.env.example` y `docs/operacion.md`. Dueño: [PENDIENTE]. Fecha límite sugerida: con el plan 006; [PENDIENTE] fecha | `database/migrations/2026_11_10_500000_create_submission_files_table.php`, `app/Support/Files/` | M |

| DT-13 | Plan 007 (H-007-2): la descarga existe como ruta `submission-files.download` y Action, pero ninguna pantalla la enlaza hasta el detalle de propuesta del plan 009 (acción visible solo con `files.download`, CA-009-6 y CA-009-10). Además, el driver `local` (solo desarrollo) sirve el archivo con su ruta firmada propia y no aplica `Content-Disposition`; solo el bucket S3 de `staging` y producción lo fuerza (CA-007-21, comprobación manual). Dueño: [PENDIENTE]. Fecha límite sugerida: con el plan 009; [PENDIENTE] fecha | `app/Actions/Submissions/DownloadSubmissionFile.php`, `routes/web.php` | S |

### P3 · Convenciones

| # | Punto | Archivos | Esfuerzo |
|---|---|---|---|
| DT-06 | Catálogo de componentes incompleto: falta la ruta interna `/components` con demos y las guardianas `tokens.test.ts` y `classes.test.ts` de `design.md` §2.6 (hoy solo existen registry, conventions, no-hex y contraste). Dueño: [PENDIENTE]. Fecha límite sugerida: antes de añadir componentes nuevos al catálogo; [PENDIENTE] fecha | `resources/js/`, `design.md` §2.6 | M |

### P4 · Estilo

| # | Punto | Archivos | Esfuerzo |
|---|---|---|---|
| DT-09 | ESLint fijado en 9 por compatibilidad con `eslint-plugin-jsx-a11y` (marcado como no soportado en 10). Subir a 10 cuando el plugin lo soporte. Dueño: [PENDIENTE]. Fecha límite sugerida: revisar al actualizar dependencias; [PENDIENTE] fecha | `package.json`, `eslint.config.*` | S |
| DT-11 | Informativo: el aviso de privacidad no cubre portugués en el panel de eventos hasta que un evento declare ese idioma. Sin acción hasta entonces. Dueño: [PENDIENTE]. Fecha límite sugerida: cuando el primer evento declare `pt`; [PENDIENTE] fecha | `lang/pt`, gestor del aviso de privacidad | S |

---

## Resueltos

> Cada fila cita lo que se hizo, cuántas pruebas nuevas y cómo se verificó. Un punto que cierra en varios commits los lista todos.

| # | Punto | Commit | Fecha |
|---|---|---|---|
| DT-01 | `organization_id` propio en la bitácora `activity_log`: columna nullable con clave foránea e índice, modelo de actividad propio que la fija desde `properties` o el contexto y relleno de lo existente. Se hizo en el plan 016 (F1) y 6 pruebas nuevas (`ActivityOrganizationTest`, incluida la de la migración). Queda pendiente, y no es parte de este punto, acotar la bitácora por organización antes de mostrarla a las organizaciones | `e1a0f02` | 2026-11-10 |
| DT-07 | `COVERAGE_MIN` del CI estaba en 0. Se midió la cobertura real (95.0 %, 842 pruebas) y se fijó en 94 en `ci.yml` y en `composer test:coverage` (con `XDEBUG_MODE=coverage`: con Xdebug cargado en modo `develop` la medición da 0 %). Sin pruebas nuevas: es configuración | `e683076` | 2026-11-11 |
| DT-08 | Documentado en `docs/database-design.md` §5 que `migrate:fresh` no borra las funciones de PostgreSQL (se usa `CREATE OR REPLACE` y `down()` las retira) y cómo vaciar de verdad una base local. Solo documentación | `e683076` | 2026-11-11 |
| DT-03 | Reactivación de un integrante desactivado: acción explícita del administrador (`ReactivateMember`) con el rol anterior precargado y confirmado, auditada (`member_reactivated`), y el alta por correo de un desactivado se rechaza apuntando a «Reactivar» (plan 003, DA-10, CA-003-32 a 38). 27 pruebas nuevas entre acción, política y panel; 233 pasan en el filtro Team/Membership/Roles/Lang | `7a29b8a` | 2026-11-11 |
| DT-10 | Tabla de eventos del panel desplazable en móvil: la lista de eventos pasó a tarjetas (`Stack`/`Split` con `contentGrid` de 1 columna en móvil, 2 en `md` y 3 en `xl`), así que se apila en pantallas pequeñas. Lo hizo el plan 015 (F2) y lo cubre `EventCardsTest`. No verificado por mí en navegador | `d9056d0` | 2026-11-11 |
| DT-05 | El 429 del acceso en Livewire mostraba el modal de error genérico. Ahora una notificación avisa «Demasiados intentos» con el tiempo de espera (de `Retry-After`, en minutos) en el login y en «olvidé mi contraseña»; el servidor no cambia (429, `Retry-After` y auditoría intactos). Cliente con `Livewire.interceptRequest` en un script del panel. CA-001-37, 2 pruebas nuevas; verificado en navegador a 375 y 1280 px | `29adc42` | 2026-11-11 |
