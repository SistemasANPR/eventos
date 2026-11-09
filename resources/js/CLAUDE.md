# Frontend — reglas para `resources/js/`

> Complementa `AGENTS.md` y `design.md`. Se aplica al sitio público en React + Inertia (ADR-0004). Los paneles de Filament no se tocan aquí.

## 1. Estructura

```
resources/js/
├── pages/           páginas de Inertia: reciben props de Laravel (Events/Show.tsx)
├── components/
│   ├── atoms/ · molecules/ · organisms/ · templates/
│   ├── infrastructure/   sin aspecto propio (límite de errores, saltar al contenido)
│   └── ui/               primitivas de shadcn/ui, generadas por su CLI
├── catalog/         registry.ts, demos y vista de /components (design.md §1.4)
├── hooks/           useXxx.ts
├── lib/             classes.ts, contrast.ts, utils.ts (cn) y helpers
├── layouts/         PublicLayout, etc. (equivalen a templates)
└── types/           tipos de las props que entrega Laravel
```

Cada módulo de `lib/` tiene su `.test.ts` al lado.

## 2. Datos

- Los datos llegan como **props de Inertia**. No se duplican con una capa de caché propia ni con `fetch` salvo en interacciones sin navegación (búsquedas, check-in).
- Los tipos de las props se mantienen en `types/` y deben coincidir con lo que entrega Laravel. [CONFIRMAR: generar los tipos desde PHP (ADR-0004, DA-3).]
- Formularios con el *helper* de Inertia (`useForm`); los errores de validación vienen del servidor con los nombres de campo de los Form Requests.
- Nada de lógica de negocio ni de autorización en el cliente: es solo presentación. El servidor decide.
- Las acciones destructivas o de cambio de estado usan `router.post/patch/delete`, nunca enlaces GET.

## 3. Nomenclatura

Código en inglés (ADR-0006); textos visibles por i18n, en español.

```
Componentes:  PascalCase                 EventCard.tsx · SubmissionForm.tsx
Páginas:      PascalCase por carpeta     Events/Show.tsx · Submissions/Create.tsx
Hooks:        use + sustantivo           useEventSchedule · useCountdown
Archivos lib: kebab-case                 date-format.ts · classes.ts
❌  Card (genérico) · EventCard2 (sufijo numérico) · utils2.ts · misc.ts
```

- `export function` nombrada para todo componente. `export default` **solo** en `pages/` (convención de Inertia). Una prueba guardiana lo vigila.
- Props: callbacks `onXxx`; booleanos `isXxx`, `hasXxx`, `canXxx`; variantes como unión de literales (`'primary' | 'secondary'`); `className?` siempre opcional; extender el elemento HTML cuando el componente *es* ese elemento.
- Anatomía de un componente: imports → tipos → JSDoc → componente → helpers locales. Manejadores y derivaciones nombrados, nunca lógica en línea en el JSX.

## 4. Componentes (Atomic Design)

Reglas completas en `design.md` §1. Antes de crear uno:

1. Buscar en `catalog/registry.ts` (`useWhen`, `avoidFor`).
2. Si falta una variante, añadirla al existente.
3. Si hace falta uno nuevo: carpeta de su nivel, entrada en `registry.ts` con su `level` y demo si corresponde. La prueba del catálogo falla si no.
4. Lo que deja de usarse se borra.

Imports directos por alias (`@/components/atoms/Button`), sin barrels. Un componente nuevo de shadcn/ui se añade con su CLI.

## 5. Responsive y accesibilidad

Mobile-first sin excepción (`design.md` §6). HTML semántico, nombres accesibles, teclado, foco visible, contraste AA y `prefers-reduced-motion`. Verificar toda vista a ~375 px y ~1280 px antes de darla por terminada.

## 6. Internacionalización

- Ningún texto visible escrito a mano: siempre por la función de traducción. [CONFIRMAR: librería de i18n y cómo comparte las claves con `lang/` de Laravel (ADR-0004, DA-2).]
- Una sola fuente de claves: `lang/` en Laravel; el cliente no mantiene una copia.
- Fechas, números y monedas con `Intl`; nunca formato manual.

```ts
new Intl.DateTimeFormat(locale, { dateStyle: 'long', timeStyle: 'short', timeZone: eventTimeZone }).format(date)
new Intl.NumberFormat(locale, { style: 'currency', currency }).format(amountInCents / 100)
```

## 7. Seguridad

- Sin secretos en variables públicas del bundler (`VITE_*`).
- Nunca `dangerouslySetInnerHTML` con contenido de usuario sin sanitizar; el contenido enriquecido llega ya sanitizado desde el servidor.
- Los enlaces a archivos privados son URL temporales entregadas por el servidor; no se construyen en el cliente.

## 8. SEO del sitio público

Cada página pública define título, descripción y metadatos sociales desde el servidor (SSR activado, ADR-0004). Las páginas con datos personales o con sesión llevan `noindex`.

## 9. Pruebas

Vitest + Testing Library. Pruebas guardianas listadas en `design.md` §2.6. Una prueba de componente cubre sus estados (carga, vacío, error, éxito) y sus variantes.
