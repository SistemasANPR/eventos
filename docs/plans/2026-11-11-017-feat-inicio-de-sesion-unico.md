# feat: inicio de sesión único con redirección al portal

> Especificación de SDD (ADR-0001). Toca autenticación: exige revisión humana (`docs/seguridad.md`). Se presenta y se espera aprobación antes de escribir código.

- **Fecha:** 2026-11-11
- **Estado:** En curso
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna directa (DT-05, el 429 del login en Livewire con modal genérico, vive en la misma página pero queda fuera)

> Estados válidos: `Borrador`, `Aprobado`, `En curso`, `Terminado`. Escribir uno solo.

## 1. Objetivo y alcance

Hoy hay dos formularios de acceso: `/admin/login` (organizaciones) y `/platform/login` (plataforma), con **una sola sesión compartida**. Quien entra a un panel y luego abre el otro recibe un 403 sin salida, y un usuario de plataforma que escribe sus credenciales en `/admin/login` recibe el mismo mensaje que una contraseña incorrecta. Este plan deja **un solo inicio de sesión** que, al autenticar, envía a cada persona a su portal: el organizador a su panel de organización y el usuario de plataforma al panel de plataforma.

**Dentro:** una sola página de acceso (`/admin/login`) que acepta a quien pueda entrar a algún panel; destino posterior al acceso según el tipo de usuario; `/platform/login` y la recuperación de contraseña de plataforma redirigen a las únicas; regla de la URL «intended»; enlace para cambiar de panel a quien tiene acceso a ambos; `canAccessPanel('platform')` considera si el panel está habilitado; pruebas por tipo de usuario.

**Fuera:** 2FA (plan 014); inicio de sesión social o SSO; recordar el último panel usado; una pantalla intermedia para elegir portal; registro de usuarios (`/register` sigue en 404); los accesos por enlace de autores y ponentes; el modal genérico del 429 (DT-05); cambiar límites o mensajes del acceso (se conservan tal cual).

## 2. Punto de partida

Medido el 2026-11-11 en la rama `staging`:

- **Dos paneles, dos accesos:** `admin` (`AdminPanelProvider`, `/admin`, con *tenant*) y `platform` (`PlatformPanelProvider`, `/platform`, sin *tenant*; plan 016). Cada uno registra `->login(Login::class)` y `->passwordReset(RequestPasswordReset::class, ResetPassword::class)` con las mismas clases (`App\Filament\Pages\Auth`).
- **Ruta con nombre `login`:** `routes/web.php` la redirige a `/admin/login` (CA-001-34). `/register` responde 404.
- **Acceso por panel:** `User::canAccessPanel(Panel)` decide por id: `admin` exige una membresía activa en una organización activa (una suspendida no cuenta, plan 016); `platform` exige `is_platform_admin`, **sin mirar** si el panel está habilitado (`config('platform.enabled')`); cualquier otro id, `false`. `User::preferredPanelId()` elige `platform` solo para quien es solo de plataforma y el panel está habilitado.
- **Comportamiento de Filament (versión instalada):** `Login::authenticate()` llama `canAccessPanel(Filament::getCurrentOrDefaultPanel())`; si es falso, cierra la sesión y lanza **el mismo error de credenciales** (por eso un usuario de plataforma no entra por `/admin/login`). La respuesta de éxito (`LoginResponse`) hace `redirect()->intended(Filament::getUrl())`, es decir, al inicio **del panel actual**. El middleware `Authenticate` del panel responde **403** si el usuario autenticado no puede acceder, y redirige a los invitados al login **de ese panel**.
- **Avisos que apuntan a un panel:** `SetPasswordNotification` y `PasswordResetNotification` generan el enlace de restablecimiento con `Filament::getPanel($user->preferredPanelId())`; `RegisterKnownDevice` usa el mismo panel para decidir si registra el dispositivo.
- **Protecciones del acceso (se conservan):** `Login` limita 5 intentos por 15 minutos por correo e IP con 429 y auditoría (`AccessThrottle`); mismo mensaje ante contraseña incorrecta, correo inexistente y falta de acceso (CA-001-26); sesión regenerada (CA-001-25); aviso de dispositivo nuevo; recuperación con respuesta idéntica exista o no el correo (CA-001-28).
- **Pruebas relacionadas:** `LoginTest`, `AccessThrottleTest`, `PasswordResetTest`, `KnownDeviceAccessTest`, `NewDeviceLoginTest`, `AdminPanelTenancyTest`, `tests/Feature/Platform/PlatformAccessTest`.
- **Síntoma reportado:** con una sesión de organizador abierta, `/platform/login` redirige a `/platform` y responde 403.

## 3. Decisiones

Resumen: un solo login en `/admin/login` (DA-1); a plataforma si tiene acceso a ambos, con enlace para cambiar (DA-2); con el panel de plataforma apagado, un usuario solo de plataforma recibe el mensaje genérico (DA-3); la URL «intended» solo se respeta si la persona puede entrar (DA-4); la recuperación de contraseña también es única (DA-5). Cerradas el 2026-11-11 con la recomendación de cada una (aprobadas por Antonio), con una variante en DA-5.

### DA-1 · Dónde vive el login único — se recomienda **`/admin/login`**

Opciones: (A) `/admin/login`, que ya es el acceso oficial y al que ya redirige `/login`; (B) una ruta nueva `/login` con su propia página. Se recomienda **A**: no cambia ninguna URL que ya se use, no crea un tercer panel ni una tercera página y reutiliza `Login`, el límite de intentos y las pruebas existentes. `/platform/login` pasa a redirigir (302) a `/admin/login`. Cerrada el 2026-11-11: opción recomendada.

### DA-2 · Quien tiene acceso a ambos paneles — se recomienda **enviarlo a plataforma, con un enlace visible para cambiar**

Opciones: (A) siempre plataforma; (B) siempre organización; (C) recordar el último usado; (D) pantalla para elegir. Se recomienda **A** más un elemento en el menú de usuario de cada panel («Ir al panel de plataforma» / «Ir a mi organización»), solo visible para quien tiene acceso a ambos. Es el caso raro y la persona de plataforma es la que menos veces opera una organización; C y D añaden estado o una pantalla nueva sin necesidad.

### DA-3 · Panel de plataforma apagado — se recomienda **que `canAccessPanel('platform')` incluya `config('platform.enabled')`**

Con el panel apagado (staging y producción hasta tener 2FA, plan 016 DA-1), un usuario solo de plataforma que inicia sesión recibe **el mismo mensaje que una contraseña incorrecta**, sin pista de que la cuenta existe. Quien además es miembro de una organización entra a su panel. Evita enviar a alguien a una ruta que responde 404 y no revela que existen cuentas de plataforma. Cerrada el 2026-11-11: opción recomendada.

### DA-4 · URL «intended» — se recomienda **respetarla solo si la persona puede entrar a ese panel**

Si un invitado abre `/platform/events` y se autentica, vuelve ahí **solo si puede acceder al panel de esa URL**; si no (un organizador que escribió `/platform`), va a su portal, nunca a un 403. Solo se aceptan rutas del propio sitio. Cerrada el 2026-11-11: opción recomendada.

### DA-5 · Recuperación de contraseña — se recomienda **también única**

Los avisos de contraseña y de dispositivo dejan de elegir panel: el enlace de restablecimiento apunta siempre al panel `admin` (donde vive el login único). Tras restablecer, la persona inicia sesión por el acceso único y llega a su portal. Elimina `User::preferredPanelId()` y la bifurcación de los avisos. **Cerrada el 2026-11-11 con una variante:** en lugar de redirigir `/platform/password-reset/*` (los enlaces de restablecimiento van firmados y no se pueden reenviar a otra ruta sin volver a firmarlos), el panel de plataforma **ya no registra recuperación de contraseña** y esas rutas responden 404. Es seguro porque el panel de plataforma existe solo desde el 2026-11-10 y solo en local, sin enlaces en circulación.

## 4. Contrato

**Rutas web** (sin endpoints JSON; no se toca `docs/api/endpoints.md`): `/admin/login` (único); `/platform/login` → 302 a `/admin/login`; `/platform/password-reset/*` dejan de existir (404; DA-5).

**Páginas y respuestas de Filament:** `App\Filament\Pages\Auth\Login` pasa a aceptar a quien pueda acceder **a algún panel** (con el mismo error ante cualquier negativa); `LoginResponse` propio (enlazado en el contenedor) que decide el destino; el panel `platform` conserva el `->login()` que exige el middleware, apuntando a una página que solo redirige (`UnifiedLoginRedirect`), y no registra recuperación de contraseña.

**Regla de destino** (`User::homePanelId()`, en lugar de `preferredPanelId()`): (1) con acceso a plataforma → `platform`; (2) con acceso a organizaciones → `admin` (Filament elige su organización); (3) sin acceso → nada (fallo genérico). La URL «intended» se aplica antes, si cumple DA-4.

**Reglas de acceso:** `User::canAccessPanel('platform')` = `is_platform_admin` **y** `config('platform.enabled')`.

**Menú de usuario:** un elemento por panel, visible solo si `canAccessPanel` de ambos es verdadero.

**Form Requests:** ninguno nuevo (se reutiliza el formulario de `Login`). **Actions:** ninguna (la lógica es de presentación y de redirección). **Datos:** sin cambios; no hay migración.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Nada cambia en lo que cada rol puede ver: `admin` sigue exigiendo membresía activa en una organización activa y `platform` sigue exigiendo `is_platform_admin`. El cambio es **por dónde se entra y adónde se llega**, no quién tiene acceso |
| ¿Qué Policy cubre cada acción y dónde se prueba? | Ninguna nueva. `canAccessPanel` y el middleware `Authenticate` de cada panel siguen decidiendo; se prueba por tipo de usuario en `tests/Feature/Auth/UnifiedLoginTest.php` |
| ¿Hay modelos nuevos con datos de una organización? | No |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No |
| ¿Se guardan datos personales nuevos? | No. Se conservan el hash del correo y la IP del registro `auth` y la huella del dispositivo (`docs/privacidad.md` §2) |
| ¿Se suben archivos? | No |
| ¿Hay dinero, pagos o webhooks? | No |
| ¿Se envían correos o notificaciones? | Los mismos (restablecimiento y alta de contraseña, dispositivo nuevo); solo cambia el enlace del restablecimiento (DA-5) |
| ¿Qué se registra en la auditoría? | Lo mismo que hoy: fallos limitados y restablecimientos (`auth`), dispositivo nuevo. No se añade el destino del acceso |

## 6. Estrategia por fases

Cada fase deja el repo en verde y termina en su propio commit.

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Acceso único: `Login` acepta a quien entre a algún panel, `canAccessPanel('platform')` con `config('platform.enabled')`, `LoginResponse` con la regla de destino y la URL «intended» (DA-3 y DA-4); pruebas por tipo de usuario | M |
| F1 | `/platform/login` redirige y la plataforma no registra recuperación de contraseña; avisos de contraseña y de dispositivo sin `preferredPanelId()` (DA-5); enlace para cambiar de panel (DA-2); documentación (`docs/seguridad.md`, comentario de `routes/web.php`) y verificación manual | S |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-017-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

- **CA-017-1:** *Dado* un miembro activo de una organización activa (`org_admin`, `organizer` o `reviewer`) sin `is_platform_admin`, *cuando* inicia sesión en `/admin/login` con credenciales válidas, *entonces* llega al panel de su organización y no al de plataforma.
- **CA-017-2:** *Dado* un usuario con `is_platform_admin` y sin membresías, con el panel de plataforma habilitado, *cuando* inicia sesión en `/admin/login`, *entonces* llega a `/platform` (hoy recibe el mensaje de credenciales incorrectas).
- **CA-017-3:** *Dado* un usuario con `is_platform_admin` y con membresía activa, *cuando* inicia sesión, *entonces* llega a `/platform`, y el menú de usuario de cada panel muestra el enlace al otro.
- **CA-017-4:** *Dado* un usuario que solo puede acceder a un panel, *cuando* abre el menú de usuario, *entonces* no ve el enlace a un panel al que no puede entrar.
- **CA-017-5:** *Dado* un usuario de plataforma sin membresías y el panel de plataforma **apagado** (`platform.enabled` falso), *cuando* intenta iniciar sesión, *entonces* recibe el mismo mensaje que una contraseña incorrecta y no queda con sesión; *dado* uno que además es miembro activo, *entonces* entra a su panel de organización.
- **CA-017-6:** *Dado* un usuario sin membresía activa y sin `is_platform_admin`, o cuya única organización está suspendida, *cuando* intenta iniciar sesión, *entonces* recibe el mismo mensaje que una contraseña incorrecta (CA-001-26), sin pista de que la cuenta existe.
- **CA-017-7:** *Dado* un invitado que abrió `/platform/events` y es usuario de plataforma, *cuando* inicia sesión, *entonces* vuelve a esa URL; *dado* un organizador que abrió `/platform`, *entonces* termina en su panel de organización y **no** en un 403.
- **CA-017-8:** *Dado* que la URL «intended» guardada en la sesión apunta a otro dominio o a una ruta no registrada, *cuando* la persona inicia sesión, *entonces* se ignora y se va al destino por tipo de usuario.
- **CA-017-9:** *Dado* un invitado, *cuando* abre `/platform/login`, *entonces* responde 302 a `/admin/login`; *dado* un usuario autenticado que puede acceder a plataforma, *entonces* llega a `/platform`; *dado* un organizador autenticado, *entonces* llega a su panel de organización y no recibe 403.
- **CA-017-10:** *Dado* el acceso único, *cuando* se superan 5 intentos fallidos en 15 minutos del mismo correo e IP, *entonces* se responde 429 y se audita, igual que hoy; la sesión se regenera al iniciar; el mensaje ante correo inexistente, contraseña incorrecta y falta de acceso es el mismo.
- **CA-017-11:** *Dado* un usuario de plataforma que inicia sesión desde un dispositivo nuevo, *cuando* termina el acceso, *entonces* se registra el dispositivo y se envía el aviso, igual que para un miembro de organización.
- **CA-017-12:** *Dado* un usuario de plataforma que pide restablecer su contraseña, *cuando* se envía el correo, *entonces* el enlace apunta a la recuperación de `/admin`; *cuando* abre `/platform/password-reset/request`, *entonces* responde 404 (el panel de plataforma no tiene recuperación propia); tras restablecer, al iniciar sesión llega a `/platform`.
- **CA-017-13:** *Dado* un organizador autenticado, *cuando* abre `/platform` o una ruta de recurso de plataforma, *entonces* sigue recibiendo 403 y sin datos (CA-016-2): el acceso único no abre el panel de plataforma.
- **CA-017-14:** El acceso único se usa con teclado, con contraste AA y sin errores en consola, a ~375 px y ~1280 px.
  Manual: con un organizador (`admin@demo.test`) y con `plataforma@ems.test`, iniciar sesión en `/admin/login` y comprobar el destino de cada uno; abrir `/platform/login` con y sin sesión; revisar la consola del navegador en ambos anchos.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Redirección abierta por la URL «intended» | Solo rutas del propio sitio y solo si la persona puede acceder (DA-4, CA-017-7 y CA-017-8) |
| Revelar que existe una cuenta de plataforma | Mismo mensaje ante cualquier negativa, incluido el panel apagado (DA-3, CA-017-5 y CA-017-6) |
| Un organizador llega a plataforma | `canAccessPanel` y el middleware de cada panel no cambian; CA-017-13 como regresión |
| Bucle de redirecciones entre `/platform/login` y `/admin/login` | El login de plataforma solo redirige y el destino se calcula una vez tras autenticar; prueba de que no hay más de una redirección |
| Regresión en los límites y avisos del acceso | Se reutiliza `Login` sin tocar `AccessThrottle`; las pruebas existentes (`LoginTest`, `AccessThrottleTest`, `PasswordResetTest`, `KnownDeviceAccessTest`) siguen en verde |
| Dos paneles con una sola sesión | Sigue igual de compartida; el enlace para cambiar de panel evita cerrar sesión (DA-2) |
| Enlaces de recuperación ya enviados a `/platform/...` | No hay en circulación: el panel de plataforma existe desde el 2026-11-10, solo en local; esas rutas responden 404 (CA-017-12) |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
