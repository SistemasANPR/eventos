# El cpanel actual del Congreso Parques (producto de origen)

> Contexto para entender qué se está reemplazando. Resumen de un análisis del código (noviembre de 2026); las tablas y columnas están **inferidas**, porque el origen no versionaba su esquema. No es una especificación: lo que se construye se especifica en `docs/plans/`. No se copian contraseñas ni credenciales.

## Qué es

El panel administrativo con el que la ANPR y Parques de México operan el Congreso Parques (www.congresoparques.com). Recibe y evalúa propuestas de sesiones y pósters, administra conferencistas y mantiene el contenido del sitio público. Es PHP procedural sin framework (~130 archivos «una página por acción»), mysqli, Foundation 6 y jQuery, con una base MySQL. Está a medias de una migración de esquema y fue construido para un solo evento.

## Usuarios y roles (deducidos de `usuarios.id_categoria`)

| id | Rol | Notas |
|---|---|---|
| 1 | Administrador | Gestiona todo |
| 2 | Conferencista aceptado | Se asigna al aceptar la propuesta (5 → 2) |
| 3 | Comité | Se le asignan ejes temáticos |
| 4 | Consejo / evaluador | Califica propuestas de sus ejes |
| 5 | Autor / aspirante | Se crea al registrar una propuesta; sin acceso al cpanel |

## Módulos

| Módulo | Qué hace |
|---|---|
| Eventos | Alta y edición del evento (nombre, lugar, fechas, modalidad, idioma, redes, descripciones ES/EN, logotipo); selector del evento activo |
| Propuestas | Lista, detalle con autores, aceptar, rechazar, eliminar; exportación a Excel desde el navegador |
| Pósters | Lista con descarga del documento y del póster; borrado lógico |
| Conferencias | Sesiones aceptadas: alta, edición y baja del programa |
| Conferencistas | Alta, edición y baja con foto y biografía |
| Talleres y talleristas | Cupo, fecha, horario y talleristas asociados |
| Programa | Bloques de horario por tipo (conferencias, magistral, talleres) que muestran las sesiones del bloque |
| Comité: rúbrica | Banco de preguntas por área con escala 1 a 5; tipos: escala, texto libre y consulta |
| Comité: evaluación | Cada evaluador califica las propuestas de sus ejes |
| Comité: resultados y avance | Promedio por propuesta (rojo ≤ 60, verde > 60) y porcentaje de avance por evaluador |
| Usuarios | Alta con tipo y ejes asignados |
| Voluntarios | Lista y detalle con turnos (el registro es público) |
| Catálogos | Boletos (MXN/USD, vigencias, ES/EN), patrocinadores, expositores, hoteles y habitaciones, recorridos (ES/EN/PT), eventos sociales, ejes, numeralia, FAQ y testimoniales |
| Portal del conferencista | Perfil, conferencia, subida de documentos, acuerdos (dos casillas) e invitación a la fiesta |

Boletos y pagos se hacían en **Podiumeeting** (externo). El cpanel solo guardaba el catálogo y **no enviaba correos**.

## Flujo principal

1. El autor llena el formulario público: se crea la propuesta (pendiente) y su usuario (categoría 5).
2. Los evaluadores califican con la rúbrica en el cpanel.
3. El administrador revisa promedios y acepta o rechaza. Al aceptar, el autor pasa a conferencista (categoría 2). Rechazar dejaba la propuesta sin estado (desaparecía, sin reversa).
4. El conferencista entra a su portal: documentos, acuerdos, invitaciones.
5. Se agenda la sesión y se arman los bloques del programa.

## Por qué se reconstruye

- **Seguridad:** casi ninguna página verificaba la sesión ni el rol; acciones destructivas por GET; SQL concatenado (login inyectable); contraseñas en texto plano; suplantación por parámetro en la URL; subidas de archivos sin validar a carpetas públicas; credenciales en el repositorio. Las reglas que corrigen cada uno están en `docs/seguridad.md` §1.
- **Un solo evento:** el evento se deducía de valores escritos en el código (`id_evento = 2`, códigos fijos) y varias consultas lo ignoraban, mezclando ediciones.
- **Migración a medias:** esquema nuevo y viejo conviviendo; el alta de evaluadores creaba filas donde el login no buscaba.
- **Sin correos ni auditoría ni pruebas**; duplicación de código y rutas a otros proyectos.

## Qué se conserva (valor de negocio)

El flujo de convocatoria → evaluación por comité con rúbrica → aceptación → conferencista → programa; los ejes temáticos; los catálogos del evento; el registro de voluntarios con turnos; el contenido en varios idiomas.

## Qué se mejora (ver `vision-y-modulos.md`)

Estados de propuesta completos y reversibles; asignación de propuestas a evaluadores con conflictos de interés y revisión ciega; avisos automáticos; portales por rol con lista de requisitos; agenda visual con detección de choques; registro y pagos propios; CRM de comunidad entre ediciones; multi-evento y multi-organización.
