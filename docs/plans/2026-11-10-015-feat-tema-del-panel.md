# feat: tema visual del panel de organizadores

> Especificación de SDD (ADR-0001), esfuerzo M. Se presenta y se espera aprobación antes de escribir código.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-10
- **Estado:** En curso
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna (DT-05 toca el login pero no su aspecto)

## 1. Objetivo y alcance

El panel de organizadores se ve como el Filament por defecto (`Color::Amber`, sin tema propio) y no comparte identidad con la guía institucional de `design.md`. Se le da un tema propio con los mismos tokens de color y tipografía, se unifican las medidas de los modales y la lista de eventos pasa de tabla a tarjetas. Es para el equipo de cada organización. Sin cambiar de tecnología: el panel sigue en Filament (ADR-0004).

**Dentro:**
- Tema de Filament (CSS compilado con Vite) con la paleta y las fuentes de `design.md` §3.1 y §3.3.
- Login y recuperación de contraseña con la marca (logotipo, fondo, tipografía).
- Anchos de modal coherentes y formularios largos en panel lateral (*slide-over*).
- Lista de eventos como cuadrícula de tarjetas.
- Acabado visual del panel (F3): superficies y elevación, menú lateral marino, barra superior, encabezados, tablas, formularios, modales, botones y movimiento, solo con el tema y los tokens de `design.md`.
- Verificación a ~375 px y ~1280 px.

**Fuera:**
- Sitio público en React: va en un plan propio (016) que reutiliza estos tokens.
- Marca blanca por organización (ADR-0004 DA-4).
- Reescribir el panel en React (descartado en ADR-0004).
- Cambiar datos, permisos, rutas o textos de negocio. No hay migraciones.
- Tarjetas en otras listas (propuestas, equipo): se evalúan después con lo aprendido aquí.

## 2. Punto de partida

Medido el 2026-11-10:

- `AdminPanelProvider` solo define `->colors(['primary' => Color::Amber])`; no hay `viteTheme`, ni fuentes, ni logotipo de marca.
- `resources/css/app.css` ya tiene los tokens y las fuentes alojadas del sitio público (Montserrat y Roboto en `resources/fonts/`), pero el panel no los usa.
- `EventsTable` es una tabla con cuatro columnas (nombre, estado, tipo, fechas) y menú de acciones; también hace de selector de evento (plan 003, DA-8).
- Filament instalado: v5.11.2, que admite `Table::contentGrid()` y `modalWidth()`.
- Rendimiento local ya resuelto (opcache de desarrollo): ~0,45 s por petición, lo que permite iterar sobre el aspecto.

## 3. Decisiones

- DA-1: paleta del panel = tokens de `design.md` (marino como `primary`, neutro frío como `gray`).
- DA-2: tipografía Montserrat (títulos) y Roboto (cuerpo), alojadas.
- DA-3: modo claro único en el panel, igual que el sitio público.
- DA-4: lista de eventos solo como tarjetas.
- DA-5: ancho de modal `md` por defecto y *slide-over* para formularios largos. Cerrada el 2026-11-10.

### DA-1 · Paleta del panel — se recomienda **tokens de `design.md`**

`primary` = escala Marino (500 `#5772BA`, 600 `#3A56A6`, 700 `#152B83`...), `gray` = Neutro frío; `success`, `warning`, `danger` e `info` con los semánticos de estado. La lima (`accent`) queda para **una acción destacada por pantalla** (p. ej. «Crear evento»), como exige `design.md`. Alternativa: conservar la paleta de Filament con solo el marino; se descarta porque el estado y los foco quedarían con otro criterio que el sitio público. Los hex viven en un solo lugar (el proveedor del panel) y una prueba los compara con `resources/css/app.css`.

### DA-2 · Tipografía — se recomienda **Montserrat y Roboto alojadas**

Misma regla de privacidad que `design.md` §3.3: nada se pide a Google. El tema de Filament importa las `@font-face` ya existentes; no se descargan fuentes nuevas.

### DA-3 · Modo oscuro — se recomienda **solo claro**

`design.md` §4 define un solo modo. Se desactiva el modo oscuro del panel (`->darkMode(false)`) para no mantener dos paletas. Si se quiere oscuro, es una decisión de marca aparte.

### DA-4 · Eventos como tarjetas — se recomienda **solo tarjetas, sin selector tabla/tarjetas**

Cada tarjeta: nombre, estado (insignia con icono y texto), tipo, fechas en la zona horaria del evento y menú de acciones; clic en la tarjeta abre el evento (igual que hoy `recordUrl`). Cuadrícula de 1 columna en móvil, 2 en tablet y 3 en escritorio. Se conservan orden por fecha, búsqueda y estado vacío. Un selector tabla/tarjetas añade estado de usuario y pruebas sin que lo hayas pedido (YAGNI).

### DA-5 · Medidas de modales — **cerrada: `md` por defecto**

Cerrada el 2026-11-10: ancho `md` para todos los modales de acción, definido en un único sitio (`Action::configureUsing`), y *slide-over* cuando el formulario tenga más de cuatro campos o secciones. Antes de dar F1 por buena se revisan capturas a 375 y 1280 px.

## 4. Contrato

No hay rutas, endpoints, Form Requests ni cambios de datos nuevos. El contrato es visual: los tokens de `design.md` §3.1 son la fuente y el panel los consume. No se tocan los textos, salvo los de login si se añade lema de marca (en `lang/es`, `en`, `pt`).

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Roles y acciones? | No cambia ninguna: las acciones de la tarjeta son las mismas del menú de la tabla actual (ver, editar, publicar, archivar) con las mismas Policies. |
| ¿Policy y prueba? | Las existentes (`EventPanelTest`, `EventActionsTest`) deben seguir en verde sin modificarse. |
| ¿Modelos nuevos? | No. |
| ¿Cruza eventos o `event_assignments`? | No. |
| ¿Datos personales nuevos? | No. |
| ¿Archivos subidos? | No. Las fuentes y el logotipo son recursos estáticos del repositorio. |
| ¿Dinero, pagos o webhooks? | No. |
| ¿Correos o notificaciones? | No. |
| ¿Auditoría? | No cambia. |

Riesgo de aislamiento: la lista de tarjetas debe seguir con la consulta acotada por organización (`BelongsToOrganization`); el cambio es solo de presentación y una prueba lo confirma (CA-015-5).

## 6. Estrategia por fases

Cada fase deja el repo en verde y termina en su propio commit.

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Tema de Filament: CSS del tema con tokens y fuentes, `viteTheme`, paleta, tipografía, `darkMode(false)`, prueba de coherencia de hex con `app.css`. | S |
| F1 | Login y recuperación con la marca; ancho de modal por defecto y *slide-over* según DA-5 (tras cerrarla). | S |
| F2 | Lista de eventos en tarjetas (`EventsTable` con `contentGrid`), manteniendo selector de evento, orden, estado vacío y acciones. | M |
| F3 | Acabado visual (aprobado el 2026-11-11): menú lateral marino con barra lima en el enlace activo, superficies con borde y sombra en dos niveles, radios 12/8/16 px, encabezados y tablas con jerarquía, tarjetas de evento con elevación al pasar el cursor, modales con sombra y fondo marino, una sola acción lima por pantalla y movimiento de 150–200 ms. Todo en `theme.css`. | M |

## 7. Criterios de aceptación

- **CA-015-1:** *Dado* el panel de organizadores, *cuando* se renderiza cualquier página, *entonces* carga el CSS del tema (`viteTheme`) y las fuentes se sirven desde el proyecto, sin ninguna petición a `fonts.googleapis.com` ni `fonts.gstatic.com`.
- **CA-015-2:** *Dada* la paleta del panel, *cuando* se compara con los tokens de `design.md` §3.1 (`resources/css/app.css`), *entonces* el `primary` y el `gray` del panel coinciden con los del sitio público; una prueba falla si divergen.
- **CA-015-3:** *Dado* un modal de acción del panel, *cuando* se abre, *entonces* usa el ancho `md` definido en un solo lugar; *y dado* un formulario con más de cuatro campos, *entonces* se abre como *slide-over*.
  Manual: abrir «Invitar miembro» y «Crear evento» a 375 px y 1280 px; el modal no desborda, no hay scroll horizontal y el contenido se lee sin recortes.
- **CA-015-4:** *Dada* una organización con eventos, *cuando* se abre la lista de eventos, *entonces* se muestran como tarjetas con nombre, estado, tipo y fechas en la zona horaria del evento, y cada tarjeta enlaza a la vista del evento.
- **CA-015-5:** *Dados* eventos de dos organizaciones distintas, *cuando* un usuario de la organización A abre la lista, *entonces* solo ve tarjetas de eventos de A y ninguna de B.
- **CA-015-6:** *Dada* una organización sin eventos, *cuando* se abre la lista, *entonces* se ve el estado vacío con la acción de crear (comportamiento actual conservado).
- **CA-015-7:** *Dado* un usuario sin permiso para publicar o archivar, *cuando* ve una tarjeta, *entonces* esas acciones no aparecen y el servidor las rechaza igual que hoy.
- **CA-015-8:** *Dado* el panel con el tema, *cuando* se revisa a ~375 px y ~1280 px con la consola abierta, *entonces* no hay errores de consola, el texto cumple contraste AA y el foco es visible.
  Manual: rutas `/admin/login`, lista de eventos, vista de un evento y un modal de acción, en ambos anchos; esperar o hacer scroll por las animaciones de entrada antes de concluir un fallo.
- **CA-015-9:** *Dado* el tema del panel, *cuando* se revisan sus colores, *entonces* todo hexadecimal de `theme.css` pertenece a los tokens de `design.md` (los de `resources/css/app.css` o las escalas de `BrandPalette`); una prueba falla si aparece un color suelto.
- **CA-015-10:** *Dados* los pares de texto y fondo nuevos del panel (menú lateral, encabezados, tablas, etiquetas, botones), *cuando* se calcula su contraste con luminancia WCAG, *entonces* todos alcanzan AA (4,5:1 para texto; 3:1 para bordes de campos y glifos).
- **CA-015-11:** *Dado* el movimiento del tema, *cuando* se revisa `theme.css`, *entonces* solo anima `transform`, `opacity` y `box-shadow` con 150–200 ms, no usa `transition: all` y respeta `prefers-reduced-motion`.
- **CA-015-12:** *Dadas* las páginas del panel, *cuando* se renderizan, *entonces* los selectores que el tema usa (`fi-sidebar`, `fi-topbar`, `fi-header-heading`, `fi-section`, `fi-modal-window`, `fi-ta-record`) existen en el HTML; una actualización de Filament que los renombre rompe la prueba y no el aspecto en silencio.
- **CA-015-13:** *Dado* el panel con el acabado visual, *cuando* se revisa a ~375 px y ~1280 px, *entonces* hay una sola acción lima por pantalla, el menú lateral es legible, las tarjetas se elevan al pasar el cursor sin mover el diseño y no hay errores de consola.
  Manual: login, lista de eventos, vista de un evento, un formulario y un modal, en ambos anchos; con `prefers-reduced-motion` activado no hay animación.
- **CA-015-14:** *Dado* el menú lateral del panel, *cuando* se muestra en escritorio, *entonces* mide como máximo 16 rem, se puede contraer a solo iconos y, en pantallas menores a 1024 px, es un cajón que se abre y se cierra; *y* el nombre de la organización del selector se lee en blanco (y su etiqueta en `#CFDBF6`) sobre el fondo marino, con contraste AA.
  Manual: a 1280 px el menú no ocupa más de 16 rem y se contrae; a 375 px se abre con el botón de menú; el nombre de la organización se lee sobre el marino.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| El tema compilado se desactualiza al actualizar Filament (clases nuevas no incluidas). | El CSS del tema usa las fuentes de Tailwind de Filament según su guía; `npm run build` en `composer check` / CI. |
| Contraste insuficiente al cambiar de paleta (estados, insignias, enlaces). | Se valida cada par texto/fondo nuevo (CA-015-8) y se añade a la prueba de contraste existente. |
| Las tarjetas dejan de servir de selector de evento (plan 003, DA-8). | Mismo `recordUrl` y mismo estado vacío; CA-015-4 y CA-015-6 lo vigilan. |
| La lima se usa en más de una acción por pantalla. | Revisión manual en CA-015-8; regla de `design.md` §3.1. |
| Cambios de CSS enmascarados por el caché de opcache y Vite en local. | Verificar con `docker compose restart app` y `npm run build` antes de dar la fase por buena. |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F0 | 120d192 | 2026-11-10 | Pest completo 672 pasan; pint, typecheck, lint y build en verde; login a 375 y 1280 px sin errores de consola. Opcache de desarrollo aparte en abb327b (~4 s a ~0,45 s). |
| F1 | 805f172 | 2026-11-10 | Paneles, Convocatorias, Equipo, Auth, Eventos y LangParity: 428 pasan, 1 fallo por interferencia de otra ejecucion sobre la base compartida (`relation users does not exist`); repetido en aislado, pasa. Pendiente la revision manual de CA-015-3 y de CA-015-8 en los modales a 375 y 1280 px. |
| F2 | d9056d0 | 2026-11-11 | Tarjetas de eventos: EventCardsTest 7 pasan; Eventos completos 73 pasan; Arch 26 pasan; Larastan sin errores. Pendiente la revision visual manual de CA-015-8 (y CA-015-3) a 375 y 1280 px, a cargo de la persona responsable. |
