/*
 * Cadenas de clases que se repiten entre archivos (design.md §2.2 y §3.2). Los componentes
 * las importan de aquí; `classes.test.ts` falla si se reescriben en línea.
 * Las variantes de una primitiva viven en su propio componente.
 */

/** Título principal (h1) de una página pública. */
export const PAGE_TITLE = 'text-3xl font-bold tracking-tight text-text sm:text-4xl';

/** Panel con los datos de un evento o una convocatoria, en una o dos columnas. */
export const FACTS_PANEL = 'grid gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2 sm:p-6';

/** Anillo de foco visible sobre fondo claro (design.md §3.1). */
export const FOCUS_RING = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';

/** Campo de texto, número, fecha o lista de un formulario: borde de 3:1 y objetivo táctil de 44 px. */
export const FORM_CONTROL =
    'block min-h-11 w-full rounded-md border border-border-input bg-surface px-3 py-2 text-base text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60';


/** Botón de acción principal: relleno `primary`, objetivo táctil de 44 px (design.md §3.1). */
export const BUTTON_PRIMARY =
    'inline-flex min-h-11 items-center justify-center rounded-md bg-primary px-6 text-base font-semibold text-primary-contrast hover:bg-primary-hover disabled:opacity-60';

/** Botón secundario: contorno `primary` sobre `surface`, mismo tamaño táctil. */
export const BUTTON_SECONDARY =
    'inline-flex min-h-11 items-center justify-center rounded-md border border-primary bg-surface px-4 text-base font-semibold text-primary hover:bg-bg disabled:opacity-60';

/** Pie de una página pública: enlace de regreso a la izquierda y selector de idioma a la derecha desde `sm`. */
export const PAGE_FOOTER =
    'flex flex-col gap-2 border-t border-border pt-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between';

/** Tarjeta de un participante en un formulario de propuesta (envío y edición). */
export const PARTICIPANT_CARD = 'space-y-4 rounded-lg border border-border bg-surface p-4';
