# Backend — reglas para `app/`

> Complementa `AGENTS.md`; no repite sus reglas generales. Se aplica a todo archivo bajo `app/`.

## 1. Estructura y responsabilidades

| Pieza | Responsabilidad | No debe |
|---|---|---|
| `Http/Controllers` | Adaptar HTTP: recibir un Form Request, llamar a una Action, devolver una respuesta | Contener lógica de negocio ni consultas |
| `Http/Requests` | Validar y autorizar la entrada (`rules()`, `authorize()`) | Tener efectos secundarios |
| `Actions` | **Un caso de uso por clase**, método `handle()`. Contiene el negocio y las transacciones | Conocer `Request`, `Response` ni Filament |
| `Models` | Estructura, relaciones, casts, scopes | Tener lógica de negocio compleja ni llamadas externas |
| `Policies` | Autorización por modelo y acción | Consultar datos fuera de su alcance |
| `Filament/<Panel>` | Pantallas del panel. Delgadas: llaman a Actions | Duplicar reglas de negocio |
| `Support/Tenancy` | Contexto de organización, scope y trait (ADR-0003) | — |
| `Support/Money` | Dinero en unidades menores + moneda | — |
| `Jobs`, `Notifications`, `Mail` | Trabajo en cola y comunicación | Hacer algo no idempotente |

Reglas de separación:

- La lógica de negocio vive **solo en Actions**. Controladores, comandos de Artisan, páginas de Filament y jobs las invocan.
- Consultas de Eloquent: en Actions, scopes del modelo o clases de consulta; nunca en vistas, controladores ni Livewire.
- Una Action que necesita otra la recibe por inyección, no la instancia con `new`.
- Un módulo no modifica los datos de otro: llama a su Action.
- Si la carpeta crece demasiado, se modulariza por dominio mediante un ADR; no antes.

## 2. Tenancy (ADR-0003)

- Todo modelo con datos de una organización usa `BelongsToOrganization`: columna `organization_id` `NOT NULL` con clave foránea, **scope global** y asignación automática desde el contexto al crear.
- **Sin contexto de organización, la consulta lanza `MissingOrganizationContext`.** No devuelve vacío y no devuelve todo.
- Jobs, comandos y seeders no tienen contexto: lo establecen de forma explícita con `CurrentOrganization::run($org, fn () => ...)`.
- `withoutOrganizationScope()` solo se usa en los casos que lista el ADR-0003 (soporte, agregados de plataforma), con autorización y registro de auditoría. Es la acción 10 de `AGENTS.md`.
- Route-model binding: usar **bindings acotados** (`scopeBindings()`); un identificador de otra organización responde 404.
- Claves únicas por ámbito: `unique(['organization_id', 'slug'])`, no `unique('slug')`.
- Caché, colas y almacenamiento incluyen la organización en su clave o ruta.
- El evento es ámbito explícito (ADR-0003): los modelos de un evento llevan `event_id` y se consultan con `->forEvent($event)`. Nunca se deduce el evento de un valor fijo.
- Toda clase de modelo nueva se cubre con las pruebas de `tests/Feature/Tenancy/`.

## 3. Autorización

- Una Policy por modelo; **denegar por defecto**. Cada Action o página de Filament comprueba `authorize()` o `Gate::authorize()`.
- Los permisos viven en `spatie/laravel-permission`; la organización es el *team*. El acceso a un evento concreto se decide en las Policies (ADR-0003, DA-1).
- No se usa `Gate::before` para dar permiso total, salvo el rol de plataforma, documentado y auditado.
- Ocultar un botón o un menú no es autorizar.
- Cada rol nuevo o cambio de permisos lleva su especificación y una prueba por rol (permitido y denegado).

## 4. Validación y entrada

- Un Form Request por entrada. Usar `$request->validated()`; nunca `$request->all()`.
- Modelos con `$fillable` explícito. Prohibido `$guarded = []`.
- Los identificadores, roles, importes, monedas y URLs que llegan del cliente no son de confianza: se validan o se recalculan en el servidor.
- Los textos con formato (Markdown, HTML) se sanitizan al guardar o al mostrar; nunca se imprimen sin escapar.

## 5. Base de datos y Eloquent

- Migraciones según `docs/database-design.md`. Una migración aplicada no se edita: se añade otra.
- Claves primarias con `HasUuids`; claves foráneas con `foreignUuid()->constrained()`.
- `SoftDeletes` en entidades con historial o evidencia; filtrar eliminados siempre.
- Estados como `enum` de PHP con cast, nunca varios booleanos que se contradigan.
- `Model::shouldBeStrict()` fuera de producción (detecta carga perezosa, atributos faltantes y asignación masiva); activar en `AppServiceProvider`.
- Sin SQL con variables interpoladas. `DB::raw` y `whereRaw` solo con enlaces (`?`).
- Evitar N+1: `with()` explícito y paginación obligatoria (máximo 100 por página).
- Dinero: entero en unidades menores + código de moneda (`Support/Money`). Sin `float`.
- Fechas: UTC en base y en `config('app.timezone')`; se formatean con la zona del evento o del usuario en los bordes.
- Escrituras de varios pasos dentro de `DB::transaction()`. Datos y efectos externos (correo, pagos) fuera de la transacción o con *outbox* (ver `docs/database-design.md`).

## 6. Errores

- Excepciones de dominio propias en `App\Exceptions\Domain\*`, con código estable (`SUBMISSION_ALREADY_CLOSED`). Nunca `throw new \Exception('...')` genérico.
- **Los fallos se ven:** un error de base o de proveedor se propaga; no se captura para devolver `[]`, `null` ni «sin datos». Solo el recurso concreto inexistente responde 404.
- Rutas JSON (API pública, webhooks) responden el sobre `{ "success": false, "error": { "code", "message", "field?" } }` y `{ "success": true, "data", "meta?" }`. Las páginas Inertia y Filament usan sus mecanismos (validación, notificaciones).
- Un solo manejador de errores en `bootstrap/app.php`; registra sin PII ni secretos.

## 7. Colas, correo y programación

- Todo envío de correo y toda llamada a un proveedor externo va por cola.
- Jobs **idempotentes**: con `ShouldBeUnique` o con una clave de idempotencia; `tries` y `backoff` definidos; `failed()` registra y avisa.
- Webhooks: verificar la firma antes de procesar, responder rápido y procesar en cola; guardar el identificador del evento del proveedor para no repetir.
- Plantillas de correo y notificaciones en `lang/`; nunca textos fijos en la clase. Registrar quién recibió qué (historial por persona).
- Las tareas programadas se declaran en `routes/console.php` y se vigilan según `docs/operacion.md`.

## 8. Archivos

- Archivos de usuarios en un disco **privado** (`local` o S3 privado); nunca en `public/`.
- Nombre generado por el servidor (`Str::uuid()`); jamás el nombre original ni rutas que vengan del cliente.
- Validar el tipo **por contenido** (`mimetypes`, `File::types()`), el tamaño y la extensión permitida; los ejecutables y los `.php` se rechazan siempre.
- Entregar con URL temporal firmada y `Content-Disposition`. Spatie Media Library con colecciones por tipo.
- Rutas con la organización: `organizations/{id}/...`.

## 9. Filament

- Un panel por rol (Admin, Committee, Speaker...). Cada recurso tiene su Policy; Filament las aplica, no se desactivan.
- Un panel usa el *tenancy* de Filament con `Organization` como tenant. La consulta de cada recurso pasa por el scope global.
- Los formularios y tablas son delgados: acciones con Actions; sin consultas ad hoc que salten el scope.
- Textos de interfaz por `lang/`. Estilos mediante el tema de Filament, no CSS suelto.
- Acciones destructivas con confirmación y registro de auditoría.

## 10. i18n

- Idiomas: `es` (base), `en`, `pt`. Textos en `lang/`; contenido traducible de modelos con `spatie/laravel-translatable` (un solo registro, no una columna por idioma). El idioma base es el *fallback*.
- Una prueba comprueba que las claves de `lang/es` y `lang/en` coinciden.

## 11. Configuración y entorno

- `env()` **solo** dentro de `config/`; en el resto, `config()`. Una prueba Arch lo vigila.
- El arranque falla con un error claro si falta una variable obligatoria.
- No registrar tokens, contraseñas ni datos personales completos en logs.
- Variables nuevas: nombre en `.env.example` con valor ficticio, y documentadas en `docs/operacion.md`.

## 12. Documentación inline

PHPDoc en las Actions públicas y en lógica no obvia: qué hace, invariantes (por ejemplo «los importes están en centavos»), qué lanza. Comentarios en español, explicando el porqué.
