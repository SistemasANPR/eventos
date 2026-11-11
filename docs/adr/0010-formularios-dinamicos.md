# ADR-0010: Formularios dinámicos

- **Fecha:** 2026-11-11
- **Estado:** Propuesta (pendiente de aprobación; plan 005, DA-1)
- **Alcance:** definición, publicación y validación de los formularios que cada evento usa para recibir propuestas

## Contexto

Cada organizador define los campos que su evento necesita, sin programar. En el sitio de origen (cpanel) los formularios eran código fijo: dos scripts casi idénticos de propuestas, y cada cambio de campos exigía programar. El producto necesita que el organizador cree y publique un formulario, que el servidor valide cada respuesta contra esa definición y que lo ya recibido no se rompa cuando el formulario cambia después.

## Decisión

1. **Almacenamiento en `jsonb`, validado en ambos lados.** La definición va en la columna `fields jsonb` de `form_definitions`; las respuestas van en `submissions.answers jsonb` (plan 006), con la clave estable del campo como llave. El ADR-0005 permite `jsonb` para datos realmente flexibles y validados.
2. **Claves generadas por el servidor.** La `key` de cada campo y el `value` de cada opción los genera el servidor, son inmutables y únicos por formulario. El cliente nunca los define.
3. **Lista blanca de tipos de campo** (`FieldType`): `short_text`, `long_text`, `number`, `single_choice`, `multiple_choice`, `checkbox`, `date`, `country`, `file`.
4. **Reglas de cambio con respuestas: solo añadir o retirar.** Con respuestas, un campo no se elimina ni cambia de tipo. Se permite editar etiqueta y ayuda, añadir campos opcionales, añadir opciones, retirar un campo (se oculta en nuevos envíos y se conservan los datos) y subir o bajar límites sin invalidar lo ya guardado. No se quita una opción ya usada ni se añaden campos obligatorios.
5. **Instantánea por propuesta.** Cada propuesta guarda una copia de la definición (`form_snapshot`, máximo 64 KB) y su `form_version`. Editar una etiqueta no cambia lo que significaba una respuesta ya enviada, y las exportaciones usan las etiquetas de esa instantánea. La `version` de `form_definitions` sube con cada cambio de la definición.
6. **Una sola fuente de reglas.** `BuildSubmissionRules` devuelve las reglas de validación a partir de la definición y es la única fuente para crear y editar propuestas.
7. **Vista previa dentro del panel, con el mismo `FormRenderer`.** La vista previa es una página del panel de Filament, no una ruta pública, y monta el mismo componente React que usa el formulario público (con el envío desactivado) para que no diverjan. Es una **excepción acotada al ADR-0004** (zonas 1 y 2 sin React salvo el editor de agenda).
8. **Marca de campo que delata al autor.** Atributo `identifiesAuthor` por campo, permitido solo en `short_text`, `long_text` y `file`. El plan 011 lo usa para ocultar esos campos al evaluador ciego.
9. **Límites de tamaño** (protección contra abuso): máximo 40 campos por formulario, 50 opciones por campo, 64 KB por definición y 256 KB por conjunto de respuestas.

## Alternativas consideradas

| Alternativa | Por qué se descartó |
|---|---|
| Modelo EAV (`form_fields` y `answers` como filas) | Multiplica las filas y las consultas para exportar y filtrar, y pierde la validación por tipo en la base |
| Una columna por campo | Exige migrar el esquema por cada formulario, lo contrario del objetivo del producto |

## Consecuencias

**Positivas:** el organizador cambia los campos sin programar; la definición versionada da una instantánea simple y exportable; la validación del servidor sale de la definición, no del cliente.

**Negativas o riesgos:** PostgreSQL no valida la estructura interna del `jsonb`, así que la forma de cada campo se valida en la aplicación con una clase de esquema con pruebas (la base solo comprueba que `fields` sea un arreglo); la vista previa en React es una excepción al ADR-0004 que hay que mantener acotada; los límites de la decisión 9 son propuestos y pueden ajustarse con el formulario real de la convocatoria 2027.

**Qué cambia para el equipo:** los tipos `FormField` de TypeScript se escriben a mano en `resources/js/types/`; ninguna validación de propuestas se escribe fuera de `BuildSubmissionRules`; `FormRenderer` se construye en el plan 005 (H-005-2) y el plan 006 lo reutiliza.

## Decisiones abiertas

- Ajustar los límites de la decisión 9 con el formulario real de la convocatoria 2027.
- Lista de campos del formulario de sesión, panel y póster 2027.

## Referencias

- `docs/plans/2026-11-09-005-feat-constructor-de-formularios.md`, ADR-0004, ADR-0005, `docs/privacidad.md`
