# ADR-0011: Escaneo antivirus de los archivos de propuestas (RC-3)

- **Fecha:** 2026-11-24 (propuesta; la decisión es de Antonio, plazo 18-dic-2026 según RC-3)
- **Estado:** Propuesta. **No hay decisión tomada y no se implementó ningún escaneo.**
- **Alcance:** archivos que las personas suben con sus propuestas (`submission_files`, plan 007) y quienes los descargan: organizadores y, desde el plan 011, evaluadores
- **Dueño de la decisión:** Antonio. `[PENDIENTE: decisión de Antonio con base en el volumen de archivos y el costo]`

## Contexto

Cualquier persona con el enlace público de una convocatoria puede subir archivos; quienes los abren son el equipo organizador y, con el plan 011, **evaluadores que pueden ser externos** a la organización. Hoy un archivo malicioso aceptado llega sin análisis a quien lo descarga (RC-3 de `docs/plan-de-desarrollo.md` §3.4, plan 014 H-014-5, DA-3). Esta es la parte que se decide aquí; el código de cualquiera de las dos opciones no existe todavía.

Lo que **ya** protege (compensaciones de RC-3, plan 007 y `docs/seguridad.md` §5):

- Lista corta de tipos: PDF, PPTX, JPG y PNG (`PrivateUploads::ALLOWED`).
- Validación por contenido (el tipo real con `finfo` debe corresponder a la extensión), estructura mínima del PPTX (un ZIP OOXML con `ppt/`) y rechazo de políglotas (código PHP o un ZIP pegado al final).
- Nombre generado en disco, nombre original solo para mostrar y saneado; disco privado sin ruta pública.
- Descarga solo con Policy (`files.download` o la propuesta asignada al evaluador), por URL temporal firmada de 5 minutos como adjunto, con auditoría.
- Límites: 25 MB por archivo, 3 por campo y 10 por propuesta; cuotas de subida (10 por token y 30 por IP cada hora) y Turnstile en el envío.
- Acceso de evaluadores con 2FA obligatoria (plan 014 F1).

Lo que **no** cubre: un PDF o PPTX bien formado con un exploit dirigido al visor de quien lo abre, o malware conocido dentro de un documento válido.

### Volumen (para medir el costo)

La unidad es la propuesta; el máximo por propuesta es 10 archivos de 25 MB. Con los límites del sistema, el espacio ocupado es:

| Propuestas por convocatoria | Típico (2 archivos de 5 MB) | Máximo teórico (10 de 25 MB) |
|---|---|---|
| 200 | 2 GB | 50 GB |
| 1 000 | 10 GB | 250 GB |
| 5 000 | 50 GB | 1,25 TB |

`[PENDIENTE: Antonio — propuestas esperadas por convocatoria y tamaño típico de las presentaciones del Congreso]`. Lo que escala el costo del escaneo es el volumen **escaneado** (cada archivo se escanea una vez al adjuntarse) y la disponibilidad del servicio, no el almacenamiento.

## Opciones

### Opción A — Escaneo asíncrono antes de que alguien descargue

Al adjuntar un archivo a una propuesta, un trabajo en cola lo envía a un antivirus y guarda el resultado; mientras no sea `clean` no se puede descargar ni previsualizar, y el organizador ve su estado (CA-014-32).

- **Cómo:** columnas `scan_status` (`pending`, `clean`, `infected`, `error`) y `scanned_at` en `submission_files` (propuesta de datos de `docs/database-design.md` §6 antes de implementar); trabajo `ScanSubmissionFile` con reintentos; `DownloadSubmissionFile`, la vista previa y la descarga del evaluador rechazan lo que no está `clean`; un archivo `infected` pasa a cuarentena (no se borra: es evidencia) y el organizador recibe un aviso.
- **Servicio de antivirus, dos caminos:**
  1. **ClamAV propio** (demonio `clamd` como un servicio más del despliegue, con actualización de firmas con `freshclam`). Sin costo de licencia; sí de operación: un servicio permanente con memoria suficiente para cargar las firmas `[PENDIENTE: dimensionar y verificar el costo del servicio en la plataforma elegida]`, vigilar que las firmas se actualicen (si fallan, el escaneo sigue «limpio» pero ciego), y límites propios (tamaño máximo de flujo, profundidad de archivos comprimidos: un PPTX es un ZIP).
  2. **Servicio gestionado** (por ejemplo, el escaneo de malware del bucket si este es de un proveedor que lo ofrece, o una API de pago). Menos operación; costo por GB escaneado `[PENDIENTE: verificar precio vigente]` y **un subencargado nuevo** que recibe archivos con posibles datos personales (se añadiría a `docs/privacidad.md` §4 con su país y su contrato de tratamiento).
- **Costos:** desarrollo (una fase M: migración, trabajo, bloqueo en tres puntos de descarga, pantalla de estado, aviso —correo nuevo en el catálogo, ADR-0009—, pruebas con un escáner de mentira), operación del servicio y la rutina de firmas; latencia hasta que el archivo está disponible para el organizador (segundos a minutos).
- **Riesgos:** (1) **falso sentido de seguridad:** un antivirus detecta malware conocido, no un documento malicioso hecho a medida; (2) **falsos positivos** que bloquean una propuesta legítima (hace falta una vía de liberación manual y auditada); (3) **disponibilidad:** si el servicio cae, o se bloquean todas las descargas (cierra por defecto, seguro pero incómodo) o se permiten sin escanear (abre un hueco silencioso); (4) archivos que el escáner no puede abrir (cifrados, demasiado grandes) quedan en `error`.
- **Qué mejora:** cierra el caso de malware conocido para organizadores y evaluadores externos y da evidencia de revisión al cliente.

### Opción B — Seguir sin escaneo, con las compensaciones de RC-3

Se mantiene lo de «Contexto» y se cierra RC-3 como riesgo aceptado, con compensaciones adicionales baratas y sin servicio nuevo:

- Aviso visible en el panel y en la invitación del evaluador: los archivos vienen de personas externas; abrirlos en un visor actualizado o aislado.
- Rechazar al subir un PPTX con macros u objetos incrustados ejecutables (`vbaProject.bin`, `oleObject`): es una comprobación sobre el ZIP que ya se abre.
- Volver a codificar las imágenes al subirlas (quita EXIF y cargas anexas; ya lo pide DT-34 por la revisión ciega).
- Revisar los archivos que se descargan (la auditoría ya registra quién descargó qué) y un procedimiento de respuesta si alguien reporta un archivo sospechoso.
- **Costos:** casi nulos (dos comprobaciones y un texto); sin servicio que operar ni subencargado nuevo.
- **Riesgos:** el que ya existe, sin mitigar: malware conocido o un exploit dirigido llega sin análisis a quien descarga; un incidente real tendría un impacto reputacional alto con un cliente institucional; el cliente (ANPR) podría exigir el escaneo por contrato. La decisión sería reversible: se puede pasar a la opción A más adelante sin migrar datos (las filas existentes quedarían en `pending` y se escanean en lote).

## Comparación

| Criterio | A: escaneo asíncrono | B: sin escaneo |
|---|---|---|
| Cubre malware conocido | Sí | No |
| Cubre un exploit dirigido o un 0-day | No | No |
| Costo de desarrollo | Una fase M | Horas |
| Costo operativo | Un servicio permanente o un costo por GB | Ninguno |
| Datos personales a un tercero | Solo con servicio externo | No |
| Efecto en el flujo del organizador | Espera y estados nuevos; falsos positivos | Ninguno |
| Reversible | Sí (se apaga con una bandera y se vuelve a B) | Sí (se pasa a A sin migrar datos) |

## Recomendación

**Opción A, en su forma mínima** (ClamAV propio o el escaneo del proveedor del bucket si existe, el que cueste menos operar), **con cierre por defecto** (un archivo no `clean` no se descarga) **y vía de liberación manual auditada** por `org_admin`. Motivo: desde el plan 011 hay evaluadores externos que descargan archivos de desconocidos, el costo de desarrollo es acotado y se puede apagar. Hasta que se decida, aplicar las compensaciones de la opción B que no requieren decisión (aviso al evaluador, rechazo de macros, recodificar imágenes), que son aditivas y de bajo riesgo.

Esta es una recomendación, no una decisión: **la decisión es de Antonio** y depende del volumen (arriba) y del costo del servicio en la plataforma elegida.

## Decisión

`[PENDIENTE: Antonio]` — opción A, opción B, u otra. Al decidir: cambiar el **Estado** a «Aceptada» o «Rechazada» con la fecha, registrar el punto de deuda correspondiente (cerrado si B con compensaciones, o reprogramado con fecha si A) en `docs/sprints/deuda-tecnica.md` y, si es A, abrir su plan SDD (ADR-0001) con la propuesta de datos y los criterios de CA-014-32.

## Decisiones abiertas

- **DA-1:** ¿qué pasa si el servicio de escaneo no responde? — se recomienda **cerrar por defecto** (no descargar) con alerta, porque el hueco silencioso es peor que la incomodidad. *Se cierra al aceptar el ADR.*
- **DA-2:** ¿ClamAV propio o servicio gestionado? — se recomienda **lo que el proveedor del bucket ofrezca sin subencargado nuevo**; si no hay, ClamAV propio. `[PENDIENTE: depende de la plataforma elegida, `docs/operacion.md` §1]`. *Se cierra al aceptar el ADR.*
- **DA-3:** ¿se escanean los archivos ya subidos? — se recomienda **sí, en lote al activarlo**, con los nuevos en línea. *Se cierra al aceptar el ADR.*

## Referencias

- `docs/plan-de-desarrollo.md` §3.4 (RC-3) y H-014-5.
- `docs/plans/2026-11-09-014-feat-endurecimiento-y-privacidad.md` (DA-3, CA-014-31 y CA-014-32).
- `docs/plans/2026-11-09-007-feat-archivos-privados-de-propuestas.md` (DA-5, límites y compensaciones).
- `docs/seguridad.md` §5; `docs/sprints/deuda-tecnica.md` (DT-34, metadatos de archivos).
