---
description: Revisa un cambio buscando fugas entre organizaciones y fallos de autorización (solo lectura)
argument-hint: [rama, archivo o dejar vacío para el diff actual]
---

Revisión de solo lectura: no edites nada.

Alcance: $ARGUMENTS (si está vacío, el diff de `git diff` y `git diff --staged`).

Lee `docs/adr/0003-*.md`, `docs/seguridad.md` y `app/CLAUDE.md` §2, §3 y §8. Revisa cada archivo cambiado buscando, con evidencia de archivo y línea:

1. Modelos nuevos con datos de una organización que no usan `BelongsToOrganization`, o sin `organization_id` `NOT NULL` con clave foránea.
2. Consultas que saltan el scope global: `withoutOrganizationScope`, `DB::table`, `DB::select`, `withoutGlobalScopes`, consultas crudas.
3. Route-model binding sin acotar a la organización; identificadores del cliente usados sin comprobar propiedad (IDOR).
4. Acciones, controladores, páginas de Filament o jobs sin `authorize()` / Policy, o con autorización solo en la interfaz.
5. Claves únicas que no incluyen la organización; cachés, colas, archivos o rutas de almacenamiento sin la organización en su clave.
6. Entrada sin Form Request, `$request->all()`, `$guarded = []`, SQL con interpolación.
7. Subidas de archivos sin validación por contenido o guardadas fuera de un disco privado.
8. Datos personales en logs, en respuestas o en exportaciones sin permiso y auditoría.
9. Pruebas faltantes: aislamiento, una por rol y acción de cada Policy, criterios `CA-NNN-n` sin prueba.

Por cada hallazgo: archivo y línea, impacto, escenario que lo reproduce y corrección segura. Ordénalos por gravedad. Si no hay hallazgos, dilo y lista los riesgos residuales y lo que no pudiste verificar.
