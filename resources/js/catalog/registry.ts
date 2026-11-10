/**
 * Catálogo de componentes propios (design.md §1.4). Lo primero que se consulta
 * antes de crear uno: `useWhen` / `avoidFor`. `registry.test.ts` vigila que todo
 * archivo de `components/` (salvo `ui/`) tenga entrada, que el nivel coincida con
 * la carpeta y que nadie quede huérfano.
 */
export type ComponentLevel = 'atom' | 'molecule' | 'organism' | 'template' | 'infrastructure';

export interface CatalogEntry {
    name: string;
    /** Ruta relativa a `components/`, sin extensión. */
    path: string;
    level: ComponentLevel;
    category: string;
    useWhen: string;
    avoidFor: string;
    /** `demo` exige una demo ejecutable en `catalog/demos-*.tsx`. */
    preview: 'demo' | 'screenshot' | 'none';
}

export const registry: CatalogEntry[] = [
    {
        name: 'RichText',
        path: 'atoms/RichText',
        level: 'atom',
        category: 'Data display',
        useWhen: 'Mostrar texto enriquecido (párrafos, énfasis, listas, enlaces) ya sanitizado en el servidor, como el aviso de privacidad',
        avoidFor: 'HTML arbitrario o contenido con imágenes, tablas o formularios; texto plano (usar un <p>)',
        preview: 'none',
    },
    {
        name: 'TextLink',
        path: 'atoms/TextLink',
        level: 'atom',
        category: 'Navigation',
        useWhen: 'Un enlace de texto para navegar entre páginas o variantes',
        avoidFor: 'Acciones que cambian datos (usar un botón con router.post)',
        preview: 'none',
    },
    {
        name: 'EventFact',
        path: 'molecules/EventFact',
        level: 'molecule',
        category: 'Data display',
        useWhen: 'Un dato con su etiqueta dentro de un <dl> (fechas, sede, zona horaria)',
        avoidFor: 'Texto corrido o listas largas',
        preview: 'none',
    },
    {
        name: 'LocaleSwitcher',
        path: 'molecules/LocaleSwitcher',
        level: 'molecule',
        category: 'Navigation',
        useWhen: 'Ofrecer la página en otro de sus idiomas con ?lang=',
        avoidFor: 'Cambiar el idioma de la interfaz de un usuario con sesión',
        preview: 'none',
    },
    {
        name: 'EventHero',
        path: 'organisms/EventHero',
        level: 'organism',
        category: 'Events',
        useWhen: 'Presentar un evento: nombre, descripción, fechas, sede, zona horaria y enlace a su aviso de privacidad',
        avoidFor: 'Listados de eventos (usar una tarjeta compacta)',
        preview: 'none',
    },
    {
        name: 'SkipToContent',
        path: 'infrastructure/SkipToContent',
        level: 'infrastructure',
        category: 'Accessibility',
        useWhen: 'Una vez por layout, para saltar la cabecera con el teclado',
        avoidFor: 'Cualquier otro enlace interno',
        preview: 'none',
    },
];
