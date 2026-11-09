# Catálogo de endpoints JSON y webhooks

> Se documenta **antes** de implementar (ADR-0001). Solo lo que consumen sistemas externos o interacciones sin navegación: API pública, webhooks, búsquedas y check-in. Las páginas de Inertia y de Filament no se catalogan aquí: su contrato son las props y los Form Requests.

## Reglas

```
Prefijo:    /api/v1/
Formato:    kebab-case, sustantivos en plural, sin verbo ni barra final
Parámetros: camelCase en query; en ruta, el nombre del modelo ({event}, {submission})
Ámbito:     /api/v1/orgs/{organization}/... para recursos de una organización (binding acotado)
Versión:    un cambio incompatible crea /api/v2; el v1 se mantiene hasta anunciar su retiro
```

**Verbos:** `GET` leer (sin efectos) · `POST` crear → 201 · `PATCH` actualizar parcial → 200 · `DELETE` eliminar (borrado lógico) → 204.

**Códigos:** 200 · 201 · 204 · 400 validación mal formada · 401 sin autenticar · 403 sin permiso · 404 no encontrado (también un recurso de otra organización) · 409 conflicto de unicidad · 422 validación o regla de negocio · 429 límite de peticiones · 500 error no manejado.

**Sobre de respuesta**

```json
{ "success": true, "data": {}, "meta": { "total": 150, "page": 1, "perPage": 20, "lastPage": 8 } }
{ "success": false, "error": { "code": "RESOURCE_NOT_FOUND", "message": "…", "field": "submissionId" } }
```

Códigos de error en `UPPER_SNAKE` en inglés. Base: `UNAUTHENTICATED`, `FORBIDDEN`, `VALIDATION_FAILED`, `RESOURCE_NOT_FOUND`, `UNIQUE_CONFLICT`, `RATE_LIMITED`, `INTERNAL_ERROR`. Los del dominio se añaden aquí: `[RELLENAR]`.

**Paginación:** `page` y `perPage` (máximo 100). Toda lista pagina.

**Visibilidad**

| Prefijo | Autenticación | Límite de peticiones |
|---|---|---|
| `/api/v1/public/*` | Ninguna | Estricto por IP |
| `/api/v1/orgs/{organization}/*` | Token con ámbito de la organización | Por token |
| `/api/v1/webhooks/*` | Firma del proveedor (sin usuario) | Por proveedor |
| `/up` | Ninguna (salud) | Sin límite |

## Catálogo

| Método y ruta | Auth | Entrada | Respuesta | Errores | Plan |
|---|---|---|---|---|---|
| [RELLENAR] | | | | | |

## Webhooks

| Proveedor | Ruta | Firma | Eventos que procesa | Idempotencia |
|---|---|---|---|---|
| [RELLENAR: ver ADR-0007] | | | | Guarda el identificador del evento del proveedor |
