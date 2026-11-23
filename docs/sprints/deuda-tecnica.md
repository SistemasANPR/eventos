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
| DT-14 | Plan 008 (H-008-6): `SendNotification` exige una organización porque `mail_dispatches.organization_id` es `NOT NULL`, así que `auth.password_reset` y `auth.new_device_login` (sin organización) no pasan por él. Las cuatro notificaciones que ya existen (`PasswordReset`, `NewDeviceLogin`, `SetPassword`, `MembershipAdded`) siguen siendo clases de Laravel que no usan `SendNotification`, ni el límite ni la idempotencia del plan 008. Decisión (Gabriel, 2026-11-12): no hacer la columna opcional (tocaría el aislamiento); migrarlas en H-001-4 y resolver entonces qué organización llevan las `auth.*`. Dueño: Gabriel. Fecha límite sugerida: con H-001-4 y antes del lanzamiento del 18-nov | `app/Notifications/`, `app/Actions/Notifications/SendNotification.php` | M |
| DT-15 | Plan 005 (constructor de formularios): el botón «Retirar campo» del repetidor no tiene prueba de panel (el `TestAction` de una acción de ítem del repetidor no se resuelve en Livewire); la `Action` `RetireFormField` sí está probada. Tampoco se ha comprobado en navegador el estilo de `form-preview.css` dentro del panel ni CA-005-15 (Tab a 375 y 1280 px). Comprobación manual de Gabriel antes de pasar el plan a Terminado. Dueño: Gabriel. Fecha límite sugerida: antes del lanzamiento del 18-nov | `app/Filament/Resources/Forms/`, `resources/css/form-preview.css` | S |
| DT-17 | Restos del plan 018 (reemplazado por el plan 021, DA-15) que siguen en la base y el código tras retirar `ResolveInvitationForm`, `SuggestInvitationForm`, `InvitationTarget` y `submissions.invite`: la columna `submissions.origin` (`call`/`invited`) con su `CHECK`, el enum `SubmissionOrigin` (valor `invited`) y su etiqueta en `lang`, las columnas `registered_by_user_id`, `authorization_declared_at`, `form_version` y `form_snapshot`, las claves de idempotencia y los `CHECK` de coherencia `invited`/`call` de la migración `create_submissions_table`, más `SubmissionFactory::invited()` y sus pruebas (`SubmissionSchemaTest`, `SubmissionListTest`). Por qué sigue abierto: la migración ya está aplicada en bases reales y quitar columnas es un cambio de datos que exige propuesta aprobada (`docs/database-design.md` §6, expandir y contraer); antes de decidir, comprobar cuáles de esas columnas usa el flujo normal de propuestas del plan 006 (`form_version` y `form_snapshot` podrían ser de todas, no solo de invitados) y que ninguna fila real tenga `origin = invited`. Además, el permiso `submissions.invite` pudo quedar **sembrado** en bases existentes (tabla de permisos de Spatie y asignaciones a roles): ya no lo asigna `RolePermissions`, pero la fila no se limpia sola; no hay migración que lo borre, así que si hace falta se retira con una migración de datos aprobada o a mano en cada base. Decisión (2026-11-18): no se eliminan columnas ni datos ahora; la columna `origin` y las demás se mantienen inertes y se revisan al contraer el esquema, con propuesta aprobada. Dueño: [PENDIENTE]. Fecha límite sugerida: tras cerrar el plan 021; [PENDIENTE] fecha | `app/Enums/SubmissionOrigin.php`, `database/migrations/2026_11_13_100000_create_submissions_table.php`, `database/factories/SubmissionFactory.php`, `app/Models/Submission.php`, `lang/*/submissions.php`, tabla `permissions` | M |
| DT-18 | Plan 012 F1: el retiro de una propuesta por el equipo organizador (`withdrawn` desde `under_review`, `accepted`, `waitlisted`, `rejected` o `confirmed`, DA-3) no está implementado. La tabla de transiciones ya lo permite, pero `WithdrawSubmission` sigue siendo solo el retiro del autor desde `submitted` (plan 006, DA-7, CA-006-46 a 49), donde no hay nada en el programa que ocultar. Cuando el equipo pueda retirar una aceptada, esa Action debe llamar a `HideProgramOfSubmission` (plan 021) y la pantalla avisar de lo ocultado; requiere decidir quién lo hace (permiso) y su especificación. Dueño: [PENDIENTE]. Fecha límite sugerida: antes de R2 (confirmación del ponente); [PENDIENTE] fecha | `app/Actions/Submissions/WithdrawSubmission.php`, `app/Support/Submissions/SubmissionTransitions.php` | M |

### P3 · Convenciones

| # | Punto | Archivos | Esfuerzo |
|---|---|---|---|

### P4 · Estilo

| # | Punto | Archivos | Esfuerzo |
|---|---|---|---|
| DT-09 | ESLint fijado en 9 por compatibilidad con `eslint-plugin-jsx-a11y` (marcado como no soportado en 10). Subir a 10 cuando el plugin lo soporte, y hacerlo con `eslint` y `@eslint/js` a la vez: `@eslint/js@10` exige `eslint@10` como peer, y por eso falló en `npm ci` el PR #9 de dependabot (2026-11-17), cerrado con «ignore this major version». Confirmar también `typescript-eslint` y `eslint-plugin-react-hooks`. Dueño: [PENDIENTE]. Fecha límite sugerida: revisar al actualizar dependencias; [PENDIENTE] fecha | `package.json`, `eslint.config.*` | S |
| DT-16 | TypeScript fijado en 6: `typescript-eslint@8.72.2` declara `typescript >=4.8.4 <6.1.0`, y por eso falló en `npm ci` el PR #11 de dependabot (2026-11-17). Subir a 7 cuando `typescript-eslint` lo soporte; TS 7 es el compilador nativo, así que además comprobar `npm run typecheck` y `build`. Dueño: [PENDIENTE]. Fecha límite sugerida: revisar al actualizar dependencias; [PENDIENTE] fecha | `package.json`, `tsconfig*.json` | S |
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
| DT-06 | Catálogo de componentes completo: ruta interna `/components` (solo local o usuario de plataforma, 404 en otro caso, `noindex`, sin enlace en menús) con una demo por componente, y guardianas `tokens.test.ts` (contraste AA contra el CSS real) y `classes.test.ts` (cadenas de `lib/classes.ts` sin reescribir en línea). CA-002-27 a 31; 9 pruebas Pest y Vitest 108 pasan; verificado en navegador a 375 y 1280 px. `no-hex.test.ts` no existe como archivo: la regla vive en `conventions.test.ts` | `525e691` | 2026-11-11 |
