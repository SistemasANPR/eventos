# feat: constructor de formularios

> Plan de la épica E-05 (R0). Es la especificación de SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. Fuente: `docs/plan-de-desarrollo.md` §10.1. Es el diferenciador del producto: cada organizador define los campos que su evento necesita, sin programar.

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** L
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

Que el organizador cree formularios para sus convocatorias con campos propios, los revise en una vista previa por idioma, los publique en una convocatoria y que el servidor valide cada respuesta contra esa definición. Las respuestas se guardan en `jsonb` con clave estable por campo.

**Dentro:** modelo `FormDefinition` con su definición de campos; tipos de campo de lista blanca; editor en Filament; componente de renderizado `FormRenderer` (React) reutilizado por la vista previa y por el formulario público del plan 006; publicación en una convocatoria con reglas de cambio cuando hay respuestas; validación dinámica de servidor (`BuildSubmissionRules`); plantillas de formulario (sesión, panel, póster) como «Debería».

**Fuera:** el envío de propuestas y el guardado de respuestas (plan 006); la subida y almacenamiento de archivos (plan 007; aquí solo el campo y su configuración); secciones, lógica condicional y campos repetibles (E-21, R3, RC-9); formularios de registro, voluntarios y encuestas (R3 y R4).

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-005-1 | Como organizador, quiero crear un formulario con campos (texto corto y largo, número, selección única y múltiple, casilla, fecha, país, archivo) con etiqueta, ayuda, obligatoriedad y límites, para pedir lo que mi evento necesita | M | H-004-1 |
| H-005-2 | Como organizador, quiero ordenar los campos y ver una vista previa en cada idioma, para revisar antes de publicar | M | H-005-1 |
| H-005-3 | Como organizador, quiero publicar el formulario en una convocatoria con reglas claras sobre qué puedo cambiar cuando ya hay propuestas, para no romper lo recibido | M | H-005-1, H-004-1 |
| H-005-4 | Como sistema, quiero validar cada respuesta en el servidor contra la definición del formulario, para no confiar en el cliente | M | H-005-1 |
| H-005-5 | Como organizador, quiero partir de una plantilla (sesión, panel, póster) y duplicarla, para ahorrar tiempo («Debería») | S | H-005-1 |

## 2. Punto de partida

Al 2026-11-09 no existe el proyecto Laravel (sin `composer.json` ni `artisan`). Este plan parte de los planes 001, 003 y 004 (organización, evento con idiomas y convocatoria). En el cpanel de origen los formularios eran código fijo: dos scripts casi idénticos de propuestas y cambios de campos exigían programar (`docs/producto/analisis-cpanel-y-propuesta.md` §8.2).

## 3. Decisiones

### DA-1 · Dónde vive la definición y las respuestas — se recomienda **`jsonb` validado en ambos lados** (propone el ADR-0010)

La definición va en una columna `fields jsonb` de `form_definitions` y las respuestas en `submissions.answers jsonb` (plan 006), con la clave estable del campo como llave. ADR-0005 permite `jsonb` para datos realmente flexibles y validados; esto lo es. Se propone el **ADR-0010: formularios dinámicos** para fijar definición, reglas de cambio con respuestas y esta forma de almacenamiento. `[PENDIENTE: aprobación del ADR-0010]`

### DA-2 · Reglas de cambio con respuestas — se recomienda **solo añadir o retirar, nunca borrar ni cambiar tipo**

Con respuestas, un campo no se elimina ni cambia de tipo. Se permite: editar etiqueta y ayuda, añadir campos opcionales, añadir opciones, **retirar** un campo (se oculta en nuevos envíos y se conservan los datos) y subir o bajar límites sin invalidar lo ya guardado. No se quita una opción ya usada.

### DA-3 · Cómo sabe el plan que hay respuestas — se recomienda **un contrato `SubmissionAnswerCounter`**

La tabla de propuestas nace en el plan 006. Este plan define la interfaz `SubmissionAnswerCounter` con una implementación nula y la usa en sus pruebas con un doble; el plan 006 enlaza la implementación real. Así los planes no se bloquean entre sí.

### DA-4 · Vista previa — se recomienda **la misma ruta pública con un modo de vista previa**

La vista previa renderiza con `FormRenderer` (React, el mismo componente del formulario público) en una ruta autenticada y protegida por Policy, con el envío desactivado. Evita que la vista previa y el formulario real diverjan. `FormRenderer` se construye en este plan (H-005-2) y el plan 006 lo reutiliza.

### DA-5 · Límites de tamaño — se recomienda **máximo 40 campos por formulario, 50 opciones por campo y 64 KB por definición**

Valores propuestos como protección contra abuso y respuestas desproporcionadas. `[PENDIENTE: ajustar con el formulario real de la convocatoria 2027]`

## 4. Contrato

**Tipos de campo (`FieldType`, lista blanca):** `short_text`, `long_text`, `number`, `single_choice`, `multiple_choice`, `checkbox`, `date`, `country`, `file`.

**Forma de un campo en `fields`:**

```
{ "key": "f_k3x9a2", "type": "single_choice", "required": true, "position": 2,
  "label": {"es": "...", "en": "..."}, "help": {"es": "..."},
  "config": { "options": [{"value": "o_1", "label": {"es": "..."}}], "minLength": 0, "maxLength": 200,
              "min": 0, "max": 10, "maxSelections": 3, "accept": ["pdf","pptx"], "maxFiles": 1 },
  "retiredAt": null }
```

`key` y los `value` de opciones los genera el servidor (inmutables, únicos por formulario); el cliente nunca los define.

**Rutas web** (sin endpoints JSON nuevos): recursos de Filament `FormDefinitionResource`; ruta de vista previa `/events/{event}/call-for-submissions/{call}/preview` (autenticada, Policy). El formulario público lo sirve el plan 006.

**Form Requests:** `StoreFormDefinitionRequest`, `UpdateFormDefinitionRequest`, `PublishFormDefinitionRequest`, `ReorderFormFieldsRequest`. **Actions:** `CreateFormDefinition`, `UpdateFormDefinition`, `PublishFormDefinition`, `RetireFormField`, `DuplicateFormDefinition`, `BuildSubmissionRules` (devuelve las reglas de validación a partir de la definición y es la **única fuente** para crear y editar propuestas), `SanitizeFormText`. **Contratos:** `SubmissionAnswerCounter` (DA-3); `PendingUploadRepository` lo define el plan 007 para validar el campo `file`.

**Cambios a otros contratos:** la migración añade `call_for_submissions.form_definition_id` (nulo hasta publicar). Los tipos TypeScript de `FormField` se escriben a mano en `resources/js/types/`.

**Propuesta de datos** (`docs/database-design.md` §6):

1. **Necesidad:** cada evento define qué datos pide a quien envía una propuesta. Módulo dueño: formularios. Ciclo de vida: borrador, publicado, con campos retirados; se conserva mientras existan respuestas.
2. **Inspección:** sin esquema previo. Del cpanel se toma la lista de campos de propuesta (título, descripción, objetivos, justificación, eje) solo como referencia para las plantillas.
3. **Modelo:** `form_definitions`: `id uuid`, `organization_id`, `event_id`, `name`, `status` (`draft`, `published`, con `CHECK`), `fields jsonb NOT NULL`, `version integer` (bloqueo optimista), `published_at`, `timestamps`, `SoftDeletes`. Ámbito: evento. `form_templates` (global): plantillas del sistema con la misma forma; se declara en `TenantModels::GLOBAL_MODELS`.
4. **Integridad:** `organization_id NOT NULL` con clave foránea; clave foránea compuesta `(event_id, organization_id)`; `CHECK (jsonb_typeof(fields) = 'array')`; la forma de cada campo se valida en la aplicación con una clase de esquema probada, porque PostgreSQL no valida la estructura interna; índice `(organization_id, event_id, status)`.
5. **Cambio:** migraciones nuevas sin datos previos; `down()` las elimina. La aplica el humano.
6. **Alternativa descartada:** modelo EAV (`form_fields` y `answers` como filas) o una columna por campo. Se descartaron: el EAV multiplica las filas y las consultas para exportar y filtrar, y pierde la validación por tipo en la base; una columna por campo exige migrar el esquema por cada formulario, lo contrario del objetivo del producto. El `jsonb` con definición versionada da una instantánea simple y exportable.
7. **Preguntas de negocio:** DA-5 (límites); `[PENDIENTE: lista de campos del formulario de sesión, panel y póster 2027]`.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Administrador: crear, editar, ordenar, retirar campos, publicar, duplicar (`forms.manage`). Miembro: ver y usar la vista previa (`forms.view`). Soporte: solo auditado |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `FormDefinitionPolicy`; `tests/Feature/Forms/` por rol y acción (permitido y denegado). La vista previa usa la misma Policy |
| ¿Hay modelos nuevos con datos de una organización? | `FormDefinition` usa `BelongsToOrganization`. `FormTemplate` es global y se declara; ambos cubiertos por las guardianas |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No en R0; consultas con `forEvent($event)`. Una plantilla del sistema se copia al evento, no se comparte |
| ¿Datos personales nuevos? | El formulario no guarda personas; **define** qué datos se piden. Cada campo con datos personales debe poder justificarse según `docs/privacidad.md` (minimización); no se añade fila al inventario hasta el plan 006 |
| ¿Se suben archivos? | Solo se define el campo `file` (tipos de la lista blanca, número máximo). El tamaño máximo, disco privado y validación por contenido son del plan 007 |
| ¿Dinero, pagos o webhooks? | No |
| ¿Correos o notificaciones? | No |
| ¿Qué se registra en la auditoría? | Alta, cambios de campos, retiro de campos, publicación y duplicado (quién, cuándo, diferencias); no se registran respuestas |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Spike S-4 y ADR-0010 propuesto; enum `FieldType`; clase de esquema de campos con pruebas; sanitizador de texto | S |
| F1 | Migración y modelo `FormDefinition`; Action de alta y edición; recurso de Filament con editor de campos (H-005-1) | M |
| F2 | `BuildSubmissionRules` y validación dinámica con todas las clases de error (H-005-4) | M |
| F3 | Orden de campos, `FormRenderer` y vista previa por idioma (H-005-2) | M |
| F4 | Publicación, reglas de cambio con respuestas, retiro de campos y bloqueo optimista (H-005-3) | M |
| F5 | Plantillas del sistema y duplicado (H-005-5, «Debería») | S |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-005-n: ...`) o declara su comprobación manual.

**H-005-1 · Crear el formulario y sus campos**

- **CA-005-1:** *Dado* un administrador de A con un evento y una convocatoria, *cuando* crea un formulario con un campo de cada tipo (texto corto y largo, número, selección única y múltiple, casilla, fecha, país y archivo), con etiqueta en es y en, ayuda, obligatoriedad y límites, *entonces* se guarda y cada campo recibe una clave estable generada por el servidor.
- **CA-005-2:** *Dado* un campo con un tipo fuera de la lista blanca, *cuando* se guarda, *entonces* se rechaza con error de validación.
- **CA-005-3:** *Dado* una etiqueta, ayuda u opción con etiquetas HTML o un script, *cuando* se guarda, *entonces* se almacena como texto plano sin etiquetas y se muestra escapado; una etiqueta vacía en el idioma base del evento se rechaza.
- **CA-005-4:** *Dado* los límites de un campo, *cuando* el mínimo supera al máximo, una selección tiene menos de dos opciones o valores repetidos, `maxSelections` excede el número de opciones, o `accept` de un campo `file` incluye una extensión fuera de la lista (`pdf`, `pptx`, `jpg`, `png`), *entonces* se rechaza.
- **CA-005-5:** *Dado* un formulario, *cuando* supera 40 campos, 50 opciones en un campo o 64 KB de definición, *entonces* se rechaza (límites de DA-5).
- **CA-005-6:** *Dado* un miembro sin `forms.manage`, *cuando* intenta crear o editar un formulario, *entonces* recibe 403; *cuando* un administrador de A pide el formulario de B por identificador, *entonces* recibe 404.
- **CA-005-7:** *Dado* un campo existente, *cuando* se edita, *entonces* su `key` no cambia aunque el cliente envíe otra; *cuando* el cliente envía una `key` repetida o la de otro campo, *entonces* se ignora o se rechaza sin alterar los demás.

**H-005-2 · Orden y vista previa**

- **CA-005-8:** *Dado* un formulario con varios campos, *cuando* el organizador los reordena, *entonces* las posiciones quedan contiguas y el orden se conserva al recargar.
- **CA-005-9:** *Dado* una convocatoria con idiomas es y en, *cuando* se abre la vista previa en cada idioma, *entonces* etiquetas, ayudas y opciones aparecen en ese idioma y, si falta la traducción, en el idioma base.
- **CA-005-10:** *Dado* la ruta de vista previa, *cuando* la abre un visitante sin sesión o un usuario de otra organización, *entonces* no se muestra (redirección al acceso o 404), y *cuando* la abre un usuario con `forms.view`, *entonces* el envío está desactivado y no se crea ninguna propuesta.
- **CA-005-11:** La vista previa es idéntica al formulario público que usará el plan 006, se maneja con teclado y los errores se asocian a sus campos.
  Manual: abrir la vista previa con un formulario de los nueve tipos de campo a ~375 px y ~1280 px, recorrer con Tab y revisar la consola del navegador.

**H-005-3 · Publicar y cambiar con respuestas**

- **CA-005-12:** *Dado* un formulario sin campos o con definición inválida, *cuando* se intenta publicar, *entonces* se rechaza; *cuando* tiene al menos un campo válido, *entonces* queda `published` y su identificador se asocia a la convocatoria.
- **CA-005-13:** *Dado* un administrador de A, *cuando* intenta asociar a su convocatoria un formulario de otro evento o de B, *entonces* se rechaza y la respuesta es 404 para el de B.
- **CA-005-14:** *Dado* un formulario con respuestas (según `SubmissionAnswerCounter`), *cuando* se intenta eliminar un campo, *entonces* se rechaza con `FIELD_HAS_ANSWERS`.
- **CA-005-15:** *Dado* un formulario con respuestas, *cuando* se intenta cambiar el tipo de un campo, *entonces* se rechaza; *cuando* solo se edita su etiqueta o ayuda, *entonces* se permite.
- **CA-005-16:** *Dado* un formulario con respuestas, *cuando* se añade un campo obligatorio, *entonces* se rechaza (los nuevos campos deben ser opcionales); *cuando* se añade una opción, *entonces* se permite; *cuando* se quita una opción ya usada, *entonces* se rechaza.
- **CA-005-17:** *Dado* un campo con respuestas, *cuando* se retira, *entonces* deja de ofrecerse en nuevos envíos, sus datos se conservan y no es obligatorio para nadie.
- **CA-005-18:** *Dado* dos organizadores que editan a la vez un formulario publicado, *cuando* el segundo guarda con una `version` anterior, *entonces* se rechaza con conflicto y no se pisa el cambio del primero.
- **CA-005-19:** *Dado* cualquier cambio de campos, publicación o retiro, *cuando* se aplica, *entonces* queda auditado con quién, cuándo y las diferencias, sin respuestas ni datos personales.

**H-005-4 · Validación dinámica en el servidor**

- **CA-005-20:** *Dado* un formulario con campos obligatorios, *cuando* llegan respuestas sin uno de ellos o vacío, *entonces* `BuildSubmissionRules` produce un error 422 asociado a la clave de ese campo.
- **CA-005-21:** *Dado* un campo de texto con `maxLength` o un campo numérico con rango, *cuando* la respuesta excede el límite, no es numérica o está fuera de rango, *entonces* se rechaza.
- **CA-005-22:** *Dado* un campo de selección, *cuando* el valor no está entre las opciones, o una selección múltiple supera `maxSelections` o repite valores, *entonces* se rechaza.
- **CA-005-23:** *Dado* un campo casilla obligatorio, *cuando* llega falso o ausente, *entonces* se rechaza; si es opcional, falso se acepta.
- **CA-005-24:** *Dado* un campo de fecha, *cuando* el valor no es una fecha civil válida en formato ISO, *entonces* se rechaza.
- **CA-005-25:** *Dado* un campo de país, *cuando* el valor no es un código ISO 3166-1 alfa-2 de la lista del sistema, *entonces* se rechaza.
- **CA-005-26:** *Dado* un cuerpo de respuestas, *cuando* incluye una clave que no existe en la definición o pertenece a un campo retirado, *entonces* se rechaza; no se guardan claves ajenas.
- **CA-005-27:** *Dado* un campo `file`, *cuando* el valor no es una lista de identificadores de cargas pendientes de la misma organización (comprobado con el contrato del plan 007, con doble en la prueba) o supera `maxFiles`, *entonces* se rechaza.
- **CA-005-28:** *Dado* respuestas con texto de más del límite del campo, caracteres de control o etiquetas, *cuando* se validan, *entonces* se eliminan los caracteres de control, el total no supera el tope de tamaño y el texto se escapa siempre al mostrarse.
- **CA-005-29:** *Dado* el mismo conjunto de respuestas, *cuando* se validan para crear y para editar una propuesta, *entonces* ambas rutas usan `BuildSubmissionRules` y producen el mismo resultado (una sola fuente de reglas).

**H-005-5 · Plantillas de formulario («Debería»)**

- **CA-005-30:** *Dado* un administrador, *cuando* elige la plantilla de sesión, panel o póster, *entonces* se crea en su evento un formulario `draft` independiente con campos propios; editar la copia no altera la plantilla.
- **CA-005-31:** *Dado* un formulario propio, *cuando* se duplica, *entonces* la copia queda en `draft`, sin respuestas ni asociación a convocatoria, con sus campos y opciones.
- **CA-005-32:** *Dado* un organizador, *cuando* intenta editar una plantilla del sistema o duplicar el formulario de B, *entonces* se deniega (403 y 404 respectivamente).

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Un formulario dinámico genera respuestas inconsistentes o vulnerables | Lista blanca de tipos, esquema probado, validación única en `BuildSubmissionRules`, texto plano y escapado al mostrar |
| Cambiar un campo con respuestas corrompe lo recibido | Reglas de DA-2 con prueba por cada regla |
| La vista previa y el formulario público divergen | Un solo `FormRenderer` (DA-4) |
| Los planes 005 y 006 se bloquean entre sí | Contratos `SubmissionAnswerCounter` y `PendingUploadRepository` con dobles (DA-3) |
| Complejidad que no cabe en R0 | Fuera de R0: secciones, lógica condicional y repetibles; las plantillas son «Debería» |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
