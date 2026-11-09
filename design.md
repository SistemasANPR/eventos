# [RELLENAR: Nombre] — Sistema de diseño

> Prevalece sobre cualquier otro documento en todo lo visual. Aplica al **sitio público** en React (ADR-0004). Los paneles de Filament se personalizan con el tema de Filament y los mismos tokens de color, sin CSS suelto.
> Leer completo antes de crear o modificar un componente o estilo.

---

## 1. Atomic Design

Las carpetas usan los nombres estándar en inglés; los componentes también (ADR-0006). Los textos visibles van por i18n.

### 1.1 Reglas de cada nivel

| Nivel | Qué es | Puede | No puede |
|---|---|---|---|
| `atoms/` | La pieza mínima (Button, Input, Badge) | Estado trivial; acepta `className` | Subcomponentes propios, datos remotos |
| `molecules/` | 2 o más átomos con un fin único (FormField, StatCard) | Estado local simple (abierto/cerrado, foco) | Lógica de negocio, datos remotos |
| `organisms/` | Sección autónoma (EventHero, ScheduleGrid) | Estado complejo; componer moléculas y átomos | Definir el layout de la página |
| `templates/` y `layouts/` | Estructura de una pantalla tipo, con *slots* | Componer organismos | Datos reales |
| `infrastructure/` | Sin aspecto propio (límite de errores, saltar al contenido, título de pestaña) | — | Pintar contenido propio |
| `ui/` | Primitivas que genera el CLI de shadcn/ui; cuentan como átomos | Ajustar una variante | Moverse o editarse a mano: el CLI escribe ahí y está fuera de lint y formato |
| `pages/` | Única capa que une un layout con los **props de Inertia** | Leer props, orquestar organismos | Lógica de UI |

Flujo de datos:

```
pages/Events/Show.tsx            ← props de Inertia (datos del servidor)
    ↓
layouts/PublicLayout.tsx         ← estructura: cabecera, contenido, pie
    ↓
organisms/ScheduleGrid.tsx
    ↓
molecules/SessionRow.tsx
    ↓
atoms/Badge.tsx                  ← solo props
```

### 1.2 Regla de imports

```ts
// ✅ Directo, por alias
import { Button } from '@/components/atoms/Button'
// ❌ Barrels y rutas relativas largas
import { Button } from '@/components/atoms'
import { Button } from '../../../components/atoms/Button'
```

Sin barrels: la prueba del catálogo detecta qué componente se usa por su import directo.

### 1.3 ¿En qué nivel va?

```
¿La pieza más pequeña, sin subcomponentes?              → atom
¿Combina componentes con un fin, sin datos propios?     → molecule
¿Sección autónoma con estado?                           → organism
¿Estructura de pantalla sin datos?                      → template / layout
¿No pinta nada propio?                                  → infrastructure
¿Une la pantalla con los props de Inertia?              → page
Si dudas entre dos niveles, el más simple.
```

### 1.4 Catálogo de componentes (inventario obligatorio)

Todo componente propio tiene una entrada en `catalog/registry.ts` y se ve en una ruta interna (`/components`, con `noindex` o protegida, sin enlace en el menú). Es lo primero que se consulta antes de crear algo.

```ts
{
  name:      'FormField',
  path:      'molecules/FormField',
  level:     'molecule',             // debe coincidir con la carpeta
  category:  'Forms',
  useWhen:   'Un campo con etiqueta, ayuda y error',
  avoidFor:  'Búsquedas en la cabecera (usar SearchField)',
  preview:   'demo',                 // 'demo' | 'screenshot' | 'none'
}
```

Reglas vigiladas por `registry.test.ts`:

- Cada archivo de `components/` (salvo `ui/`) tiene entrada y cada entrada apunta a un archivo existente.
- El `level` coincide con la carpeta.
- Si `preview` es `demo`, existe su demo ejecutable en `catalog/demos-*.tsx`.
- Un componente que nadie importa falla la prueba: se borra.
- Una variante faltante se añade al componente existente, nunca `EventCard2`.

---

## 2. Estructura interna de un componente

### 2.1 Declaración

```
✅ export function EventCard(props: EventCardProps)
❌ const EventCard = (props) => ...
❌ export default function ...   (solo permitido en pages/, convención de Inertia)
```

### 2.2 Anatomía de cinco bloques

1. Imports (React → externos → internos por alias → tipos)
2. Tipos (interfaces nombradas, antes del componente)
3. JSDoc (qué es, cuándo usarlo, qué no hace)
4. El componente (hooks → derivaciones → manejadores → *guards* → JSX)
5. Helpers y subcomponentes privados del archivo

Las cadenas de clases que se repiten entre archivos van a `lib/classes.ts`, no al final del componente.

### 2.3 Props

```tsx
onClick: () => void            // callbacks: on + evento
isActive?: boolean             // booleanos: is / has / can
hasError?: boolean
canEdit?: boolean
variant?: 'primary' | 'secondary' | 'danger'   // unión de literales, nunca string libre
size?: 'sm' | 'md' | 'lg'
children: React.ReactNode      // slots con ReactNode
className?: string             // siempre opcional
interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {}  // si ES ese elemento
```

### 2.4 JSDoc

Mínimo: qué es y «cuándo usarlo». En organismos, «Usar en: ...». El ejemplo vive en `@example` **o** en la demo del catálogo, no en los dos.

### 2.5 Resumen

```
JSX      ✅ manejadores nombrados · derivaciones nombradas · t('key') siempre
         ✅ cn() para clases condicionales · manejar loading, error, empty, data
         ✅ data-testid en lo interactivo relevante
NIVELES  ✅ átomos sin datos · moléculas con estado local simple
         ✅ organismos con estado propio · templates sin datos · pages con props
         ✅ todo componente en el catálogo, con su nivel; imports directos
```

### 2.6 Pruebas guardianas (Vitest, leen el código fuente)

```
registry.test.ts      catálogo completo, level = carpeta, sin huérfanos
conventions.test.ts   sin export default fuera de pages/
no-hex.test.ts        sin hex arbitrarios en las clases (bg-[#123456])
tokens.test.ts        contraste AA de cada par texto/fondo contra los valores reales del CSS
classes.test.ts       las cadenas de lib/classes.ts no se reescriben en línea
```

Una guardiana nueva puede arrancar en «modo lista» (registra las violaciones de hoy y falla solo con las nuevas) y se vacía al cerrar la deuda.

---

## 3. Color y estilo

### 3.1 Tokens: siempre los semánticos, nunca hex directos

[RELLENAR: tabla de tokens primitivos de marca y tokens semánticos (`bg`, `surface`, `surface-raised`, `border`, `text`, `text-muted`, `accent`, `success`, `error`, `warning`) con su valor en cada modo. Los componentes solo usan los semánticos.]

**Marca blanca por organización (SaaS):** cada organización puede definir logotipo y colores de marca. Se aplican sobrescribiendo los tokens primitivos mediante variables CSS por organización; los componentes no cambian. El contraste AA se valida también contra los colores que la organización elija.

### 3.2 Clases compartidas

[RELLENAR: constantes en `lib/classes.ts` con las cadenas que se repiten (títulos de sección, botones sobre fondo oscuro, rejillas). Los componentes importan de ahí; `classes.test.ts` falla si se reescriben en línea. Las variantes de las primitivas viven en su componente con `cva`.]

Todo par nuevo de texto y fondo se añade a `lib/contrast.ts`; `tokens.test.ts` exige AA (4,5:1).

### 3.3 Tipografía

[RELLENAR: fuentes, pesos cargados y jerarquía (display, h1, h2, body, caption) con su clase.]

---

## 4. Tema claro/oscuro

[RELLENAR si aplica. Si solo hay un modo, borrar esta sección, dejar los tokens semánticos de §3.1, retirar los tokens y las variantes `dark:` que traiga la librería y añadir una prueba que impida reintroducirlos.]

Checklist de compatibilidad de un componente nuevo:

- [ ] Usa el token semántico de fondo, superficie, borde y texto (primario y secundario).
- [ ] Sobre fondos de color sólido (accent, error) usa blanco fijo.
- [ ] Nunca usa tokens primitivos de marca como clases directas ni clases de color del framework (`bg-gray-900`).

---

## 5. Gráficas

[RELLENAR: borrar si el sitio público no tiene gráficas. Los reportes de los organizadores viven en Filament.]

- **Gráfica de análisis:** título descriptivo, ejes etiquetados, unidad visible, leyenda (salvo serie única), etiquetas en puntos clave, cuadrícula sutil con eje Y desde 0 y nota de origen o filtro activo.
- **Sparkline en tarjeta:** el título lo pone la tarjeta; unidad en el texto; tooltip con el valor exacto; sin ejes, leyenda ni nota. Si necesita ejes o leyenda, es una gráfica de análisis.
- Paleta, tooltip, ejes y márgenes viven solo en `lib/chart-theme.ts`. Sin animaciones en gráficas que se refrescan por sondeo.

---

## 6. Responsive (mobile-first)

**Toda pantalla o componente nuevo se construye mobile-first.** El viewport base es móvil (< 640 px) y se escala con `sm:`, `md:`, `lg:`, `xl:`.

| Elemento | Móvil | Escritorio |
|---|---|---|
| Navegación principal | Cajón (*drawer*) con cierre por Escape, clic fuera o enlace | Fija desde `lg` |
| Tablas densas | Tarjetas apiladas con los mismos datos y acciones | Tabla completa desde `lg` |
| Modales | Pantalla completa con scroll; hoja inferior si es corto | Centrado con `max-w-*` desde `md` |
| Tarjetas KPI | `grid-cols-2`, nunca una fila fija ni scroll horizontal | `md:grid-cols-4` |
| Formularios | Una columna | Multicolumna desde `sm` o `md` |
| Barras de acción | `flex-col` con `gap` | `sm:flex-row`, con `flex-wrap` antes que scroll horizontal |

- **Objetivo táctil mínimo:** 40 px de alto. El breakpoint donde el control vuelve a su tamaño compacto coincide con el de la rama donde vive: visible siempre → `min-h-11 sm:min-h-8`; solo en una rama `lg:hidden` → `min-h-11 lg:min-h-8`.
- **Verificación:** no hay pruebas automáticas de layout visual. Toda vista nueva se comprueba en el navegador a ~375 px y ~1280 px, sin scroll horizontal de página y con la consola sin errores. Las animaciones de entrada pueden dejar una captura en blanco: esperar o hacer scroll.

[RELLENAR: script de verificación en navegador real si existe (captura + errores de consola), p. ej. `npm run check:browser -- <url> --width=375`.]

---

## 7. Accesibilidad

HTML semántico, nombre accesible en todo control, navegación por teclado completa, foco visible, contraste AA, `prefers-reduced-motion` respetado, formularios con `label` asociada y errores anunciados (`aria-live`). Textos alternativos en imágenes con contenido.

---

*[RELLENAR: Nombre] — design.md v1.0 — [RELLENAR: mes y año]*
