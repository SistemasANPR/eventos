# feat: Editar los datos de la organización

> Plan 028. Especificación (SDD, ADR-0001).

- **Fecha:** 2026-11-23
- **Estado:** En curso (2026-11-23: aprobado; la pagina de edicion de los datos de la organizacion ya esta fusionada en staging, sin registro de avance en §9)
- **Esfuerzo:** S
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

El panel de organizadores no tiene ninguna pantalla para ver o cambiar los datos de la organización. Este plan añade la página **«Datos de la organización»** (perfil del tenant de Filament), accesible desde el selector de organización del menú lateral.

**Dentro:** editar el **nombre** y el **identificador** (`slug`, el que va en la URL) de la organización, con una advertencia de que las URL antiguas dejan de funcionar.

**Fuera:** redirecciones desde el identificador anterior (las URL antiguas responden 404, por decisión expresa); logotipo, contacto u otros datos nuevos (exigen migración y propuesta de `docs/database-design.md` §6); estado (suspender/reactivar es de plataforma).

## 2. Punto de partida

Medido el 2026-11-23 sobre `staging`: `organizations` solo tiene `name`, `slug`, `status` y marcas de tiempo; `AdminPanelProvider` no registra `tenantProfile`; `OrganizationPolicy` solo concede a plataforma. No hay Action que cambie el nombre.

## 3. Decisiones

### DA-1 · Quién edita — **`org_admin`, con el permiso `team.manage` existente**
Es el único rol que administra la organización; reutilizar el permiso evita una migración de permisos y volver a sembrar roles en las organizaciones existentes. Cerrada el 2026-11-23.

### DA-2 · Identificador (`slug`) — **editable, con advertencia y sin redirecciones**
Cerrada el 2026-11-23 por petición expresa (reemplaza la decisión inicial de dejarlo en solo lectura). Cambiarlo rompe las URL públicas y del panel ya compartidas; el formulario lo advierte con un aviso visible y la URL antigua responde 404. Reglas: las mismas de la alta (`OrganizationSlug`: formato, 3 a 63 caracteres, reservados) y único entre todas las organizaciones, **incluidas las eliminadas** (el índice único las cuenta). Tras guardar, el panel redirige a la URL nueva.

## 4. Contrato

Sin datos ni rutas JSON nuevas. Ruta del panel: `/admin/{slug}/profile`. Validación en `UpdateOrganizationRequest::rulesFor($organization)`: `name` obligatorio, texto, 2 a 120 caracteres; `slug` obligatorio, `OrganizationSlug` y único (ignorando la propia organización).

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | `org_admin` ve y cambia el nombre y el identificador; `organizer` y `reviewer`, nada (403); plataforma no edita desde este panel |
| ¿Qué Policy cubre cada acción? | `OrganizationPolicy::update` (miembro activo con `team.manage`); la Action vuelve a autorizar. Prueba: `UpdateOrganizationTest` |
| ¿Modelos nuevos con datos de organización? | No |
| ¿Datos personales, archivos, dinero, correos? | No |
| ¿Qué se audita? | `organization_updated` con quién y nombre e identificador anterior y nuevo |

## 6. Criterios de aceptación

- **CA-028-1:** *Dado* un administrador de A, *cuando* cambia el nombre por uno válido, *entonces* la organización queda con el nombre nuevo, el `slug` no cambia si no se toca y queda un registro de auditoría con el nombre anterior y el nuevo.
- **CA-028-2:** *Dado* un nombre vacío o de más de 120 caracteres, *cuando* se guarda, *entonces* se rechaza y nada cambia.
- **CA-028-3:** *Dado* un organizador o un evaluador de A, *cuando* intenta cambiar el nombre (por la Action o por la página), *entonces* recibe 403 y nada cambia.
- **CA-028-4:** *Dado* un administrador de A, *cuando* intenta editar la organización B, *entonces* recibe 403 y B no cambia.
- **CA-028-5:** *Dado* un administrador de A, *cuando* abre la página en el panel, *entonces* ve el nombre y el identificador editables y una advertencia de que las URL antiguas dejarán de funcionar.
- **CA-028-6:** *Dado* un administrador de A, *cuando* cambia el identificador por uno válido y libre, *entonces* A queda con el identificador nuevo, la auditoría guarda el anterior y el nuevo, y el panel redirige a la URL nueva.
- **CA-028-7:** *Dado* un identificador con formato inválido, reservado o ya usado por otra organización (aunque esté eliminada), *cuando* se guarda, *entonces* se rechaza y nada cambia; conservar el propio identificador no cuenta como repetido.
- **CA-028-8:** *Dado* que A cambió su identificador, *cuando* se visita una URL pública con el identificador anterior, *entonces* responde 404, y con el nuevo responde igual que antes.
