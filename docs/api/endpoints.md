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

## Imágenes públicas del programa (no JSON)

Rutas web del sitio público que sirven archivos del disco privado `media` (plan 021, DA-11, CA-021-18). Sin autenticación ni sobre de respuesta: devuelven la imagen o 404. Bajo `/{organization:slug}/events/{event:slug}/...` (organización y evento acotados por la ruta; uno de otra organización responde 404).

| Método y ruta | Auth | Respuesta | Errores | Plan |
|---|---|---|---|---|
| `GET /{organization}/events/{event}/speakers/{speaker}/photo` (`public.speakers.photo`) | Ninguna | 200 con la fotografía del ponente (`image/jpeg`, `png` o `webp`), `Cache-Control: public, max-age=300`, `X-Content-Type-Options: nosniff` | 404 si el evento no está publicado, el ponente no es de ese evento, no está publicado, está borrado, no tiene fotografía, el identificador no es un uuid o el archivo falta | 021 |
| `GET /{organization}/events/{event}/activities/{activity}/{type}` con `type` = `icon` o `image` (`public.activities.media`) | Ninguna | 200 con el icono o la imagen de la actividad, mismas cabeceras | 404 en los mismos casos que la fotografía; otro valor de `type` también responde 404 | 021 |

### Imágenes de la landing del evento (plan 022, DA-7, CA-022-32)

| Método y ruta | Auth | Respuesta | Errores | Plan |
|---|---|---|---|---|
| `GET /{organization}/events/{event}/landing/images/{image}` (`public.landings.image`) | Ninguna | 200 con la imagen (`image/jpeg`, `png` o `webp`), `Cache-Control: public, max-age=300`, `X-Content-Type-Options: nosniff` | 404 si el evento no está publicado, no hay landing publicada, la imagen no figura en `published_blocks`, está borrada, es de otro evento u organización, el identificador no es un uuid o el archivo falta | 022 |

El editor del panel ve las imágenes del borrador por una ruta autenticada propia (`admin/{organization}/events/{event}/landing/images/{image}`, `events.manage`); esa ruta no es pública.

Una imagen de un registro no publicado nunca se sirve: el panel la previsualiza con una miniatura incrustada hecha en el servidor, no con estas rutas. La caché corta (5 minutos) acota cuánto sigue visible una imagen tras despublicarla.

## Webhooks

| Proveedor | Ruta | Firma | Eventos que procesa | Idempotencia |
|---|---|---|---|---|
| [RELLENAR: ver ADR-0007] | | | | Guarda el identificador del evento del proveedor |
