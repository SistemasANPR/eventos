# feat: Editar los datos de la organización

> Plan 028. Especificación (SDD, ADR-0001).

- **Fecha:** 2026-11-23
- **Estado:** Aprobado (2026-11-23: Gabriel aprueba el plan por la delegación de AGENTS.md)
- **Esfuerzo:** S
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

El panel de organizadores no tiene ninguna pantalla para ver o cambiar los datos de la organización. Este plan añade la página **«Datos de la organización»** (perfil del tenant de Filament), accesible desde el selector de organización del menú lateral.

**Dentro:** editar el **nombre** de la organización; mostrar el identificador (`slug`) en solo lectura.

**Fuera:** cambiar el `slug` (rompería las direcciones públicas `/{organization:slug}/...`; se haría con redirecciones, en otro plan); logotipo, contacto u otros datos nuevos (exigen migración y propuesta de `docs/database-design.md` §6); estado (suspender/reactivar es de plataforma).

## 2. Punto de partida

Medido el 2026-11-23 sobre `staging`: `organizations` solo tiene `name`, `slug`, `status` y marcas de tiempo; `AdminPanelProvider` no registra `tenantProfile`; `OrganizationPolicy` solo concede a plataforma. No hay Action que cambie el nombre.

## 3. Decisiones

### DA-1 · Quién edita — **`org_admin`, con el permiso `team.manage` existente**
Es el único rol que administra la organización; reutilizar el permiso evita una migración de permisos y volver a sembrar roles en las organizaciones existentes. Cerrada el 2026-11-23.

### DA-2 · Slug — **solo lectura**
Cerrada el 2026-11-23: cambiarlo rompe enlaces públicos ya compartidos.

## 4. Contrato

Sin datos ni rutas JSON nuevas. Ruta del panel: `/admin/{slug}/profile`. Validación en `UpdateOrganizationRequest::rulesFor()`: `name` obligatorio, texto, 2 a 120 caracteres.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | `org_admin` ve y cambia el nombre; `organizer` y `reviewer`, nada (403); plataforma no edita desde este panel |
| ¿Qué Policy cubre cada acción? | `OrganizationPolicy::update` (miembro activo con `team.manage`); la Action vuelve a autorizar. Prueba: `UpdateOrganizationTest` |
| ¿Modelos nuevos con datos de organización? | No |
| ¿Datos personales, archivos, dinero, correos? | No |
| ¿Qué se audita? | `organization_updated` con quién y nombre anterior y nuevo |

## 6. Criterios de aceptación

- **CA-028-1:** *Dado* un administrador de A, *cuando* cambia el nombre por uno válido, *entonces* la organización queda con el nombre nuevo, el `slug` no cambia y queda un registro de auditoría con el nombre anterior y el nuevo.
- **CA-028-2:** *Dado* un nombre vacío o de más de 120 caracteres, *cuando* se guarda, *entonces* se rechaza y nada cambia.
- **CA-028-3:** *Dado* un organizador o un evaluador de A, *cuando* intenta cambiar el nombre (por la Action o por la página), *entonces* recibe 403 y nada cambia.
- **CA-028-4:** *Dado* un administrador de A, *cuando* intenta editar la organización B, *entonces* recibe 403 y B no cambia.
- **CA-028-5:** *Dado* un administrador de A, *cuando* abre la página en el panel, *entonces* ve el nombre editable y el identificador en solo lectura.
