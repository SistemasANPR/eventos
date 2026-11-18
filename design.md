# EMS — Sistema de diseño

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

Dirección visual: **Institucional** (variación A de la guía de marca). El marino del logotipo lidera la interfaz, la lima se reserva para acciones destacadas y el verde-azulado acompaña la información de apoyo. Sobria y de alto contraste: pensada para registro, administración y documentos.

#### Primitivos de marca (no se usan como clase en los componentes)

| Escala | 50 | 100 | 200 | 300 | 400 | 500 | 600 | 700 | 800 | 900 |
|---|---|---|---|---|---|---|---|---|---|---|
| Marino | `#F4F7FE` | `#E5EDFF` | `#CFDBF6` | `#B3C4EA` | `#768FCC` | `#5772BA` | `#3A56A6` | **`#152B83`** (logo) | `#0D1E70` | `#08155D` |
| Lima | `#F6FAE3` | `#EBF2C8` | `#D9E663` | **`#BFCC36`** (logo) | `#A9B523` | `#8F9A15` | `#757D0E` | `#5B620C` | `#43480C` | `#2D310C` |
| Neutro frío | `#F8FAFE` | `#F1F3F8` | `#E2E6EF` | `#D0D4DC` | `#A1A5AC` | `#777A82` | `#585B62` | `#3F4249` | `#26292F` | `#14161C` |

Verde-azulado de apoyo: `#156A62` (texto e iconos). El `#3D9E94` del logotipo es **solo decorativo**: no lleva texto.

#### Tokens semánticos (lo único que usan los componentes)

| Token | Valor | Uso | Contraste |
|---|---|---|---|
| `bg` | `#F8FAFE` | Fondo de página | — |
| `surface` | `#FFFFFF` | Tarjetas y campos de formulario | — |
| `surface-raised` | `#FFFFFF` con sombra | Modales, menús, tarjetas elevadas | — |
| `border` | `#E2E6EF` | Divisores y tarjetas | — |
| `border-input` | `#777A82` | Borde de campos de formulario | 3:1 (WCAG 1.4.11) |
| `text` | `#243342` | Cuerpo y títulos de tarjetas | AAA · 12,9:1 con blanco |
| `text-muted` | `#585B62` | Metadatos, ayudas, pies de foto | AAA · 6,8:1 con blanco |
| `primary` | `#152B83` | Botones principales, barra de navegación, enlaces y títulos | AAA · 12,3:1 con blanco |
| `primary-contrast` | `#FFFFFF` | Texto sobre `primary` y `primary-hover` | — |
| `primary-hover` | `#0D1E70` | Hover y activo del primario | AAA · 14,6:1 con blanco |
| `accent` | `#BFCC36` | Destacados, insignias y la acción principal sobre fondo marino | AA · 7,0:1 con texto marino |
| `accent-contrast` | `#152B83` | Texto sobre `accent` | — |
| `support` | `#156A62` | Etiquetas, iconos e información de apoyo | AA · 6,4:1 con blanco |

Semánticos de estado (texto / fondo):

| Token | Texto | Fondo |
|---|---|---|
| `success` | `#2E7D32` | `#E8F5E9` |
| `warning` | `#92400E` | `#FEF3C7` |
| `error` | `#B42318` | `#FEE4E2` |
| `info` | `#3A56A6` | `#E5EDFF` |

Reglas de uso:

- **La lima nunca es color de texto sobre blanco**; sobre lima el texto es siempre marino.
- El estado no se comunica solo con color: va con icono y texto (Pagado, Pendiente, Cancelado, En revisión).
- El foco visible usa `primary` con contorno de 2 px; sobre fondo marino, `accent`.
- Los campos llevan fondo `surface` y borde `border-input`, nunca `border`.
- Hay **una sola acción destacada** (lima) por pantalla; el resto de botones son `primary` (relleno) o contorno `primary`.
- Cada par nuevo de texto y fondo se añade a `lib/contrast.ts` y `tokens.test.ts` lo valida contra los valores reales del CSS.

#### Componentes de referencia de la guía

- **Barra de navegación:** fondo `primary`, texto blanco, enlace activo subrayado en `accent`, botón de acción en `accent` con texto marino.
- **Insignia de estado:** píldora con el par texto/fondo del semántico.
- **Etiqueta de categoría** (Plenaria, Taller…): mayúsculas pequeñas en `support`.
- **Alerta:** fondo del semántico, icono y texto del semántico.

**Marca blanca por organización (SaaS):** cada organización puede definir logotipo y colores de marca. Se aplican sobrescribiendo los tokens primitivos mediante variables CSS por organización; los componentes no cambian. El contraste AA se valida también contra los colores que la organización elija.

### 3.2 Clases compartidas

[RELLENAR: constantes en `lib/classes.ts` con las cadenas que se repiten (títulos de sección, botones sobre fondo oscuro, rejillas). Los componentes importan de ahí; `classes.test.ts` falla si se reescriben en línea. Las variantes de las primitivas viven en su componente con `cva`.]

Todo par nuevo de texto y fondo se añade a `lib/contrast.ts`; `tokens.test.ts` exige AA (4,5:1).

### 3.3 Tipografía

Fuentes de Google Fonts **alojadas en el proyecto** (`resources/fonts/`, subconjuntos `latin` y `latin-ext`, variables, `font-display: swap`). No se cargan desde los servidores de Google: hacerlo enviaría la IP del visitante a un tercero (`docs/privacidad.md`).

| Uso | Familia | Pesos | Clase |
|---|---|---|---|
| Títulos (h1, h2, h3) y display | Montserrat | 600 a 800 | `font-display` (aplicada por defecto a `h1`–`h3`) |
| Cuerpo, formularios, metadatos | Roboto | 400 a 700 | `font-sans` (por defecto) |

Si falta una fuente, cae a la pila del sistema. Jerarquía (display, h1, h2, h3, cuerpo, pequeño, etiqueta en mayúsculas): `[PENDIENTE: fijar tamaños y fijarlos en lib/classes.ts al construir la primera vista que los necesite]`.

---

## 4. Tema claro/oscuro

**Un solo modo (claro).** La guía de marca no define modo oscuro. Los componentes no usan variantes `dark:`; los tokens y variantes oscuros que traiga una librería se retiran, y una prueba guardiana impedirá reintroducirlos (`[PENDIENTE: añadirla junto a no-hex.test.ts]`).

Checklist de compatibilidad de un componente nuevo:

- [ ] Usa el token semántico de fondo, superficie, borde y texto (primario y secundario).
- [ ] Sobre `primary` usa blanco; sobre `accent` (lima) usa `accent-contrast` (marino), nunca blanco.
- [ ] Nunca usa tokens primitivos de marca como clases directas ni clases de color del framework (`bg-gray-900`).

---

## 5. Gráficas

No aplica: el sitio público no tiene gráficas. Los reportes de los organizadores viven en Filament. Si alguna vista pública las necesita, se especifica aquí antes de construirla.

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

*EMS — design.md v1.0 — noviembre 2026*
