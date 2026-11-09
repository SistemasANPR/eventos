# ADR-0004: Tres zonas de interfaz

- **Fecha:** 2026-11-09
- **Estado:** Aceptada (con decisiones abiertas de implementación)
- **Alcance:** `app/Filament/`, `resources/js/`

## Contexto

La plataforma tiene usuarios muy distintos: el equipo organizador, personas externas con cuenta (ponentes, evaluadores, patrocinadores, voluntarios) y visitantes anónimos. Hace falta que cada pantalla tenga un lugar claro, que no haya dos sistemas de interfaz solapados y que el sitio público tenga buen SEO y rendimiento.

## Decisión

| Zona | Quién | Interfaz | Ejemplos |
|---|---|---|---|
| 1. Panel de organizadores | Equipo de cada organización | **Filament** | Eventos, propuestas, comité, usuarios, reportes, editor de agenda |
| 2. Portales con sesión | Personas externas con cuenta | **Filament**, un panel por rol | Ponente, evaluador, patrocinador, voluntario |
| 3. Sitio público | Cualquier visitante | **React + Inertia con SSR** | Programa, ponentes, sede, convocatoria, registro y compra de boletos |

- Laravel sirve todo: datos, permisos y reglas de negocio. La división es solo de la capa de interfaz.
- **Introducción gradual de React:** la fase 1 (núcleo administrativo) usa solo Filament; React llega con el programa público, el registro y la compra.
- **Editor de agenda** (la única pantalla del panel que necesita arrastrar y soltar con detección de choques): un componente Livewire con librería de arrastre o un componente React montado dentro de Filament. Se decide en su plan, y no condiciona el resto.
- Sin proyecto frontend separado comunicado por API.

## Alternativas consideradas

| Alternativa | Por qué se descartó |
|---|---|
| Todo en Filament y Livewire | Viable, pero el sitio público y la compra se benefician de React y de su ecosistema de componentes |
| Todo en React/Inertia, incluido el panel | Habría que construir a mano el panel administrativo, la parte más grande del proyecto |
| Next.js solo para el sitio público | Mayor control del SEO, pero un servicio más que operar. Se reevalúa si el sitio público crece mucho |

## Consecuencias

**Positivas:** frontera obvia sobre dónde va cada pantalla; el volumen repetitivo (CRUD) queda en Filament; React donde aporta valor.

**Negativas o riesgos:** dos sistemas de interfaz que mantener coherentes; hay que cuidar el sistema de diseño y los tokens de color compartidos (`design.md`).

**Qué cambia para el equipo:** una pantalla nueva se ubica primero en una zona; las zonas 1 y 2 no llevan React salvo el editor de agenda.

## Decisiones abiertas

- **DA-1: SSR del sitio público.** Se recomienda **activar el SSR de Inertia** desde la primera página pública. Si el SEO se vuelve crítico, reevaluar Next.js.
- **DA-2: librería de i18n del cliente.** Se recomienda que **`lang/` de Laravel sea la única fuente de claves** y que el cliente las reciba por props compartidas, con una librería ligera (por ejemplo `laravel-react-i18n`). Confirmar contra la documentación vigente antes de elegirla.
- **DA-3: tipos TypeScript de las props.** ¿A mano o generados desde PHP (por ejemplo con `spatie/laravel-typescript-transformer`)? Se recomienda **generarlos** cuando haya más de diez páginas, y a mano antes.
- **DA-4: marca blanca por organización** en el sitio público (variables CSS por organización, `design.md` §3.1). Alcance de la primera versión por definir.

## Referencias

- `design.md`, `resources/js/CLAUDE.md`, `app/CLAUDE.md` §9
