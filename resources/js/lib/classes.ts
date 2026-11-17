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

