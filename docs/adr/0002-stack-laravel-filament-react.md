# ADR-0002: Stack — Laravel, Filament y React (Inertia)

- **Fecha:** 2026-11-09
- **Estado:** Aceptada
- **Alcance:** todo el repositorio

## Contexto

Se construye desde cero una plataforma de gestión de eventos que nace de un panel administrativo en PHP sin framework (el cpanel del Congreso Parques) y que se quiere ofrecer como **SaaS a otras organizaciones**. El producto es sobre todo de *back-office*: formularios, roles, flujos de aprobación, correos, pagos y reportes, más un sitio público por evento. El equipo domina PHP y JavaScript, construye con agentes de código y tiene presupuesto de infraestructura y experiencia con GCP y Railway.

## Decisión

- **Backend:** Laravel como monolito modular, con **PostgreSQL**.
- **Panel de organizadores y portales con sesión** (ponente, evaluador, patrocinador, voluntario): **Filament**, un panel por rol.
- **Sitio público** (programa, convocatoria, registro y boletos): **React con Inertia**, con renderizado en servidor (ADR-0004).
- Cola y caché en Redis; archivos en almacenamiento privado compatible con S3; correo transaccional; ver `docs/operacion.md`.
- Despliegue inicial en Railway o Laravel Cloud; crecimiento posible a GCP o AWS.

## Alternativas consideradas

| Alternativa | Por qué se descartó |
|---|---|
| Next.js + TypeScript para todo | Obliga a ensamblar ORM, autenticación, permisos, colas, correo y panel. Con agentes, las decisiones abiertas generan arquitecturas distintas por módulo |
| PHP sin framework (el cpanel actual) | Es el origen de la deuda técnica y de los fallos de seguridad |
| Laravel + React/SPA totalmente desacoplada por API | Duplica la operación (dos despliegues y una capa de API) sin beneficio al inicio |
| Todo el frontend en Livewire | Viable, pero el sitio público y el editor de agenda se benefician de React; se mantiene como zona para Filament |
| Una base de datos por organización | Encarece el mantenimiento y rompe los reportes entre eventos (ADR-0003) |

## Consecuencias

**Positivas:** autenticación, colas, correo, validación, migraciones y pruebas vienen integradas; Filament evita escribir un CRUD por entidad; las convenciones de Laravel hacen el código generado por agentes más uniforme; se reutiliza el conocimiento de PHP.

**Negativas o riesgos:** dos sistemas de interfaz (Filament y React) que mantener coherentes; dependencia de la comunidad de Laravel y de Filament; el hosting compartido deja de servir (hacen falta colas y tareas programadas).

**Qué cambia para el equipo:** una sola aplicación Laravel; React solo en el sitio público; las dependencias autorizadas están en `AGENTS.md`.

## Cuándo reconsiderarlo

Si el producto pasara a ser una experiencia muy interactiva en tiempo real, si se necesitara una sola base de código para web y móvil, o si el equipo se inclinara claramente hacia TypeScript.

## Referencias

- `docs/producto/vision-y-modulos.md`
- Análisis del cpanel de origen: `docs/producto/cpanel-actual.md`
