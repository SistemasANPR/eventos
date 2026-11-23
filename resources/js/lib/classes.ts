/*
 * Cadenas de clases que se repiten entre archivos (design.md §2.2 y §3.2). Los componentes
 * las importan de aquí; `classes.test.ts` falla si se reescriben en línea.
 * Las variantes de una primitiva viven en su propio componente.
 */

/** Título principal (h1) de una página pública. */
export const PAGE_TITLE = 'font-display text-3xl font-extrabold uppercase tracking-wide text-text sm:text-4xl';

/** Cuadrícula de tarjetas con los datos de un evento o una convocatoria: 1 columna en móvil, 2 y 3 después. */
export const FACTS_PANEL = 'grid gap-4 sm:grid-cols-2 lg:grid-cols-3';

/** Anillo de foco visible sobre fondo claro (design.md §3.1). */
export const FOCUS_RING = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';

/** Campo de texto, número, fecha o lista de un formulario: borde de 3:1 y objetivo táctil de 44 px. */
export const FORM_CONTROL =
    'block min-h-11 w-full rounded-xl border border-border-input bg-surface px-3 py-2 text-base text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60';


/** Botón de acción principal: píldora lima con texto índigo (`cta`), objetivo táctil de 48 px. */
export const BUTTON_PRIMARY =
    'inline-flex min-h-12 items-center justify-center rounded-full bg-cta px-8 text-base font-extrabold uppercase tracking-wide text-cta-contrast shadow-md transition hover:shadow-lg hover:brightness-95 disabled:opacity-60';

/** Botón secundario: contorno `primary` sobre `surface`, mismo tamaño táctil. */
export const BUTTON_SECONDARY =
    'inline-flex min-h-11 items-center justify-center rounded-full border-2 border-primary bg-surface px-5 text-base font-semibold text-primary hover:bg-bg disabled:opacity-60';

/** Pie de una página pública: enlace de regreso a la izquierda y selector de idioma a la derecha desde `sm`. */
export const PAGE_FOOTER =
    'flex flex-col gap-2 border-t border-border pt-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between';

/** Tarjeta de un participante en un formulario de propuesta (envío y edición). */
export const PARTICIPANT_CARD = 'space-y-4 rounded-2xl border border-border bg-bg p-4';

/** Título de una sección de la landing (h2), con subrayado lima. */
export const SECTION_HEADING =
    'relative pb-3 font-display text-2xl font-extrabold uppercase tracking-wide text-text after:absolute after:bottom-0 after:left-0 after:h-1 after:w-14 after:rounded-full after:bg-cta';

/** Tarjeta de un elemento de lista de la landing (convocatoria, eje, pregunta). */
export const LIST_CARD = 'flex flex-col gap-3 rounded-2xl border border-border border-t-4 border-t-cta bg-surface p-5 shadow-md sm:p-6';

/** Título de nivel intermedio de la landing y su editor (h2 de un panel, h3 de una tarjeta grande). */
export const HEADING_LG = 'font-display text-xl font-bold text-text';

/** Título de una tarjeta de lista de la landing (h3). */
export const HEADING_MD = 'font-display text-lg font-bold text-text';

/** Bloque de color de una portada o encabezado: degradado violeta con texto blanco y esquinas grandes. */
export const HERO_PANEL = 'relative overflow-hidden rounded-3xl bg-linear-to-br from-brand via-brand to-brand-deep text-primary-contrast';

/** Título (h1) sobre un bloque de color: muy grueso, en mayúsculas y con espaciado. */
export const HERO_TITLE = 'font-display text-3xl font-extrabold uppercase leading-tight tracking-wide text-primary-contrast sm:text-5xl';

/** Placa blanca bajo el logotipo del evento para que se vea sobre cualquier fondo. */
export const LOGO_PLATE = 'rounded-2xl bg-surface p-3 shadow-lg';

/** Anillo de foco sobre un bloque de color (violeta o índigo): lima (design.md §3.1). */
export const FOCUS_RING_ON_DARK = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cta';

/** Tarjeta de una sección de un formulario público: esquinas grandes y sombra suave. */
export const SECTION_CARD = 'rounded-3xl border border-border bg-surface p-5 shadow-md sm:p-8';

/** Columna central de una página de la convocatoria (portada, formulario o confirmación). */
export const CALL_COLUMN = 'mx-auto flex w-full max-w-4xl flex-col gap-8';
