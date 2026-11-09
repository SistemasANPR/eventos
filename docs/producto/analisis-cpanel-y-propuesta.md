# CPanel del Congreso Parques: base para el nuevo gestor de eventos de la ANPR

> Conversión a Markdown del documento Word del 9 de noviembre de 2026 (secciones 1 a 13). Análisis del cpanel del Congreso Parques, propuestas de mejora, módulos nuevos, stack recomendado y hoja de ruta. Las tablas y columnas del cpanel están **inferidas** del código. Las decisiones vigentes están en `docs/adr/`; si hay contradicción, mandan los ADR (ver `PROMPT-MAESTRO.md`).

# 1. Resumen ejecutivo

El cpanel es el panel administrativo con el que la ANPR y Parques de México operan el Congreso Parques. Recibe y evalúa propuestas de sesiones y pósters, administra conferencistas y mantiene el contenido del sitio público (boletos, patrocinadores, hospedaje, talleres, programa, etc.).

Es la base de un nuevo proyecto: una plataforma para gestionar **cualquier evento de la ANPR**, no solo el congreso. En el mercado esta categoría se conoce como **plataforma de gestión de eventos (Event Management System, EMS)**. Si además guarda el historial de personas y organizaciones entre eventos (ponentes recurrentes, patrocinadores, socios), incorpora elementos de **CRM**. Para la ANPR lo más cercano es un «EMS con memoria de comunidad».

**Lo valioso del cpanel actual** no es su código, sino el flujo de negocio ya probado en varias ediciones: convocatoria abierta, evaluación por comité con rúbrica, aceptación, portal del conferencista y armado del programa.

**Lo que conviene no heredar:** está construido para un solo evento, está a medias de una migración de base de datos, casi no tiene control de acceso en el servidor y tiene fallas de seguridad graves. Por eso se recomienda construir el nuevo sistema desde cero y usar este documento como especificación funcional.

> **Alcance y confiabilidad.** Todo lo descrito en las secciones 2 a 8 se dedujo leyendo el código del cpanel. No existe un archivo SQL versionado, así que las columnas de las tablas están inferidas y hay que contrastarlas con la base de datos real antes de diseñar el nuevo modelo. No se reproducen contraseñas ni credenciales.

# 2. Objetivo, alcance y usuarios

**Objetivo.** Que un equipo pequeño prepare y opere el congreso sin tocar código ni base de datos: captar contenido, decidir qué entra al programa y mantener informados a los conferencistas.

## 2.1 Qué resuelve hoy

- Convocatoria abierta de sesiones y pósters, con evaluación por pares.

- Ciclo de vida del conferencista: aceptación, documentos, acuerdos e invitaciones.

- Catálogos del evento: boletos, patrocinadores, expositores, hospedaje, recorridos, talleres, FAQ, testimoniales y numeralia.

- Programa y calendario del congreso.

- Registro de voluntarios (solo lectura en el panel).

- Resultados de la evaluación con promedios y avance de cada evaluador.

## 2.2 Quién lo usa

| **Usuario** | **Para qué entra** | **Cuenta en el cpanel** |
|----|----|----|
| Administrador ANPR / PdeM | Configura el evento, acepta o rechaza propuestas, edita contenido | Sí (categoría 1) |
| Comité evaluador | Califica las propuestas de los temas que tiene asignados | Sí (categorías 3 y 4) |
| Conferencista aceptado | Sube documentos, firma acuerdos, consulta su sesión | Sí (categoría 2), portal aparte |
| Aspirante / autor | Registra su propuesta en el sitio público | No entra al cpanel (categoría 5) |

## 2.3 Fuera de alcance hoy

- Cobro de boletos: se hace en Podiumeeting, una plataforma externa. El cpanel solo guarda el catálogo de boletos (y hoy ni siquiera está enlazado en el menú).

- Envío de correos: el cpanel no envía ninguno. Aceptar una propuesta no avisa al autor.

- Control de acceso en sitio, acreditación, gafetes y constancias.

- Finanzas, presupuesto, tareas del equipo y analítica del evento.

# 3. Arquitectura y stack técnico

Es un CMS en PHP procedural, con el estilo «una página por acción» (alta, editar, actualizar, eliminar). No usa framework ni composer.

| **Capa** | **Tecnología** |
|----|----|
| Backend | PHP con mysqli. Una clase por módulo que hereda de Conexion; las clases imprimen HTML directamente (lógica, SQL y vista mezcladas). |
| Base de datos | MySQL/MariaDB. Nombre de la BD: u951310947_congreso2023 (estilo Hostinger). No hay esquema versionado. |
| Frontend | Foundation 6, jQuery 3.2.1, DataTables, SweetAlert2, moment. Tablas cargadas por fetch + innerHTML. |
| Exportación | PHPExcel 1.8 (abandonada) y botones de DataTables (Excel/PDF del lado del cliente). |
| Idiomas | Panel en español, portal del conferencista en inglés, contenido público en ES/EN/PT en algunos módulos. |
| Hosting | Hostinger (producción) y XAMPP (desarrollo). Credenciales de ambos en el mismo archivo de configuración. |

## 3.1 Estructura de carpetas

| **Carpeta** | **Contenido** |
|----|----|
| cpanel/ (raíz) | Login (index.html + app.js) y inc/identificacion.php, que valida el acceso. |
| cpanel/class/ | Modelos por módulo, conexion.php, funciones.php (arranque global y función filtrado()), y unos 25 endpoints AJAX de 3 a 10 líneas. |
| cpanel/admin/ | Unas 130 páginas PHP agrupadas por módulo, más inc/ (cabecera, cinco menús, sesión) y js/ (scripts por módulo). |
| cpanel/conferencistas/ | Portal del conferencista: perfil, conferencia, acuerdos, documentos, invitaciones. |

## 3.2 Convención de cada módulo

La página de listado trae el formulario de alta. alta\*.php inserta, editar\*.php muestra el formulario con datos, actualizar\*.php guarda y eliminar\*.php borra. Las respuestas se muestran con una alerta de SweetAlert y regresan a la pantalla anterior. Las acciones destructivas se ejecutan por GET.

# 4. Roles, sesión y control de acceso

## 4.1 Flujo de login

1.  El usuario escribe correo y contraseña en index.html; app.js los envía a inc/identificacion.php.

2.  La clase Login consulta la tabla usuarios comparando correo y contraseña en texto plano.

3.  Si coincide, guarda en sesión el id, nombre, foto y tipo de usuario (id_categoria).

4.  El navegador redirige según el rol: administrador a admin/index.php, evaluador (4) a propuestas_calificar.php. Las categorías 2, 3 y 5 no tienen una redirección válida, así que el login no hace nada para ellas.

## 4.2 Roles

Se deducen del código; la tabla tipo_credenciales no se pudo leer.

| **id_categoria** | **Rol** | **Evidencia** |
|----|----|----|
| 1 | Administrador | El login devuelve «admin» |
| 2 | Conferencista (propuesta aceptada) | Al aceptar una propuesta, el autor pasa de 5 a 2 |
| 3 | Comité | En «Usuarios» se asignan temas solo si el tipo es 3 |
| 4 | Consejo / evaluador | Redirección a propuestas por calificar; menú propio |
| 5 | Autor / aspirante | Se crea al registrar una propuesta; rechazar un conferencista lo regresa a 5 |

## 4.3 Menús por rol

Los menús son archivos estáticos que cada página incluye; no se eligen por rol en el código.

- **Administrador** (menuEvento.php): Convocatoria (Propuestas, Pósters), Conferencias, Conferencistas, Talleres, Talleristas, Programa, Voluntarios y Comité (Preguntas, Avance, Resultados). Boletos, Ejes temáticos, Expositores, Hoteles, Patrocinadores, Numeralia, Testimoniales y FAQ están comentados, es decir, ocultos.

- **Evaluador** (menu_consejo.php): todas las propuestas, por evaluar, evaluadas y salir.

- **Conferencista** (conferencistas/inc/menu.php): Perfil, Acuerdos, Documentos, Conferencia y salir.

## 4.4 Cómo se controla el acceso (hallazgo crítico)

> **Prácticamente no se controla en el servidor.** Solo admin/index.php verifica la sesión, y lo hace sin detener la ejecución. Unas 85 de las ~130 páginas ni siquiera leen la sesión: todos los alta\*, actualizar\* y eliminar\*, la aceptación y el rechazo de propuestas, y los endpoints de class/. Los roles solo cambian qué menú se dibuja; nunca se comprueba el rol. Un evaluador, o cualquier visitante, puede abrir una URL de administración o borrar una propuesta.

# 5. Módulos y funciones actuales

Casi todas las listas filtran por el evento activo (\$\_SESSION\["evento"\]) y los formularios de alta llevan el evento en un campo oculto.

## 5.1 Mapa de módulos

| **Módulo** | **Función** | **Estado** |
|----|----|----|
| Eventos / Congresos | Alta y edición del evento (nombre, lugar, fechas, modalidad, idioma, redes, descripciones ES/EN, logotipo); selector del evento activo. | Parcial: lista eventos, pero escribe en congresos (tabla vieja) |
| Propuestas | Lista de propuestas con filtro; detalle con autores; aceptar, rechazar, eliminar. | Activo, central |
| Pósters | Lista de pósters, descarga de documento y póster, borrado lógico. | Activo |
| Conferencias | Sesiones aceptadas: alta, edición, baja del programa. | Activo |
| Conferencistas | Alta, edición y baja de ponentes con foto y biografía. | Activo |
| Talleres y talleristas | Taller con cupo, fecha y horario; talleristas asociados. | Activo |
| Programa / calendario | Bloques de horario por tipo (Conferencias, Magistral, Talleres) que muestran las sesiones del bloque. | Activo, con errores |
| Comité: rúbrica | Banco de preguntas de evaluación por área, con escala 1 a 5. | Activo |
| Comité: evaluación | Cada evaluador califica las propuestas de sus temas. | Activo |
| Comité: resultados y avance | Promedio por propuesta y porcentaje de avance por evaluador. | Activo |
| Usuarios | Alta de usuarios con tipo y temas asignados. | Roto: crea filas donde el login no busca |
| Voluntarios | Lista y detalle de voluntarios y sus turnos. | Solo lectura |
| Boletos | Catálogo con precios MXN/USD, vigencias y descripciones ES/EN. | Oculto en el menú |
| Patrocinadores | Alta con categoría, logotipo y banner. | Oculto en el menú |
| Expositores | Alta con representante, correo, web e imagen. | Oculto; con errores SQL |
| Hoteles y habitaciones | Hotel con clave de reservación y habitaciones con precio sencillo y doble. | Oculto |
| Recorridos turísticos | Recorridos con precio y descripciones en ES/EN/PT. | Activo, sin filtro por evento |
| Eventos sociales | Eventos con fecha, hora y lugar en tres idiomas. | Activo |
| Ejes temáticos | Temas con descripción e ícono. | Oculto |
| Numeralia | Cifras del evento: asistentes, países, ponentes, etc. | Oculto |
| FAQ y testimoniales | Preguntas frecuentes y testimonios con foto. | Ocultos |
| Portal del conferencista | Perfil, conferencia, documentos, acuerdos e invitación a la fiesta. | Roto en gran parte |

## 5.2 Propuestas (módulo central)

- **Lista:** título, subtítulo, fecha de registro, tema y evaluación, con exportación a Excel desde el navegador.

- **Detalle:** descripción, objetivos, justificación y datos de los autores (nombre, cargo, empresa, país, ciudad, correo, teléfono, biografía y foto).

- **Estados** de ponencias.estatus: 0 pendiente, 1 aceptada, NULL rechazada. El valor 2 ya no se escribe pero el código antiguo aún lo lee. Las rechazadas desaparecen de la lista y no hay forma de revertirlas desde la interfaz.

- **Aceptar:** única operación con transacción. Marca la propuesta como aceptada y cambia al autor de categoría 5 a 2. Así «nace» el conferencista.

- **Eliminar:** borrado físico por GET, sin confirmación en servidor.

## 5.3 Conferencias y programa

- **Alta de conferencia:** título, subtítulo, fecha, hora de inicio y fin, lugar, tema, modalidad, categoría, descripción y objetivos. Inserta primero en ponencias y luego en conferencias; si la segunda falla, borra la primera a mano (sin transacción).

- **Baja del programa:** deja estatus en NULL.

- **Bloques de programa:** fecha, hora de inicio y fin, tipo y nombre. El contenido del bloque se arma buscando ponencias o talleres que caen en ese rango. No filtra por evento ni por estatus, así que mezcla propuestas pendientes y rechazadas.

- **Enlace a Google Calendar:** arma la URL con nombre y lugar fijos («Congreso Parques León 2023»).

## 5.4 Conferencistas

- Se listan los usuarios con categoría 2, propuesta aceptada y el evento activo.

- Campos: correo, contraseña, nombres, apellidos, cargo, empresa, país, estado, ciudad, biografía, fotografía y conferencia asignada.

- «Eliminar» no borra: baja al usuario a categoría 5.

- Las fotos se guardan con su nombre original en una carpeta pública, sin validar el tipo de archivo.

## 5.5 Comité: rúbrica, evaluación, resultados y avance

Es el motor más elaborado del sistema (una sola clase de ~1,200 líneas).

1.  **Asignación.** El administrador da de alta al evaluador y marca los temas que le tocan; se guarda en comite_tema.

2.  **Rúbrica.** Preguntas agrupadas por área. Tipos: opción única con escala (1 Excelente, 2 Muy bien, 3 Bien, 4 Regular, 5 Malo), texto libre y «consulta» sobre temas. Al crear una pregunta de escala se generan automáticamente sus cinco respuestas.

3.  **Calificar.** El evaluador contesta la rúbrica de una propuesta. Cada nueva calificación borra la anterior de ese evaluador y vuelve a insertar las respuestas, sin transacción.

4.  **Resultados.** Por propuesta se muestra modalidad, autor, país, ciudad, tema y promedio; en rojo si es 60 o menos, en verde si es mayor. Desde ahí se acepta o rechaza.

5.  **Avance.** Porcentaje de propuestas calificadas por cada evaluador, con barra de progreso.

## 5.6 Portal del conferencista

| **Pantalla** | **Qué hace** |
|----|----|
| Perfil | Muestra y edita los datos personales y la foto. |
| Conferencia | Título, fecha, horario, salón, descripción y tema de su sesión. |
| Documentos | Sube presentación (PDF o PPT, hasta ~100 MB) y hasta tres enlaces de video. |
| Acuerdos | Dos casillas: uso de imagen y distribución del material. Se guarda como un 1 o 0; no hay firma digital ni constancia. |
| Invitaciones | Confirma asistencia a la fiesta (Sí o No). Oculto en el menú. |

> El portal viene de la edición WUPC 2022. Con el login actual no hay redirección que lleve a él, consulta tablas que el esquema nuevo reemplazó y varias pantallas incluyen un archivo (clases2.php) que no existe dentro del cpanel.

## 5.7 Exportaciones y correos

- Excel desde el navegador (DataTables) en propuestas y resultados.

- exportarExcel.php genera un .xls de conferencistas con **contraseñas** incluidas, sin pedir sesión. Ningún enlace de la interfaz lo llama.

- El cpanel no envía correos. El envío de acuses vive en el sitio público (PHPMailer).

# 6. Flujos clave de negocio

## 6.1 De la convocatoria al programa

1.  **Convocatoria:** el autor llena el formulario público; se crea la propuesta (pendiente) y su usuario de categoría 5.

2.  **Evaluación:** los evaluadores de cada tema califican con la rúbrica en el cpanel.

3.  **Decisión:** el administrador revisa los promedios y acepta o rechaza. Al aceptar, el autor pasa a conferencista (categoría 2).

4.  **Ciclo del conferencista:** entra al portal, sube documentos, acepta acuerdos y confirma invitaciones. Hoy sin avisos automáticos.

5.  **Programa:** se agenda la sesión (fecha, hora, lugar) y se arman los bloques del programa.

6.  **Publicación:** el sitio público lee las mismas tablas y muestra ponentes y programa.

## 6.2 Multi-evento

- Una tabla eventos y una columna id_evento (o la antigua id_congreso) en cada tabla hija.

- El evento activo se elige en el dashboard y se guarda en sesión.

- Todos los usuarios ven todos los eventos: no hay asignación de usuarios a eventos.

- Varias consultas ignoran el evento o lo traen escrito a mano (id_evento = 2, WUPC2022, CPL2020). Las ediciones se mezclan en hoteles, recorridos, usuarios y calificaciones.

# 7. Modelo de datos (inferido)

Columnas deducidas de las consultas; confirmar contra la base real.

| **Grupo** | **Tablas** | **Datos principales** |
|----|----|----|
| Núcleo | eventos, usuarios, ponencias, conferencias, usuarios_ponencias, temas, posters, usuarios_posters | Un usuario con id_categoria (1 a 5) y id_evento; la ponencia con estatus (0/1/NULL), tema, fecha y horario; el póster con documento y estado. |
| Catálogos | modalidades, categorias_conferencias, tipo_conferencia, area_conferencia, tipo_pregunta, tipo_credenciales, categorias_patrocinadores | Valores de apoyo para listas desplegables. |
| Evaluación | preguntas, respuestas, valor_respuestas, calificar_propuestas, comite_tema | Pregunta con tipo y área; escala con valor numérico; calificación por pregunta, propuesta y evaluador; temas por evaluador. |
| Contenido | talleres, talleristas, boletos, patrocinadores, expositores, numeralia, preguntas_frecuentes, testimoniales, eventos_sociales, programa | Texto bilingüe, imágenes y fechas. |
| Logística | hotel, habitacion, recorridos_turisticos | Hotel con clave de reservación; habitaciones con precio sencillo y doble. |
| Voluntarios | voluntarios, turnos_voluntarios, turnos_asignados | Datos personales, motivos y turnos. |
| Portal | material_conferencia | Archivo y hasta tres enlaces por conferencista. |
| Esquema viejo (aún usado) | congresos, credenciales, credencial_congreso, conferencistas, aspirantes | Eventos, usuarios del comité y firmas del conferencista (autoriza1, autoriza2, evento_social). |

> **Migración a medias.** Un lado usa eventos, usuarios y ponencias; el otro, congresos, credenciales y conferencistas. Alta y baja de eventos, el alta de usuarios del comité y el portal del conferencista siguen en el esquema viejo, mientras que el login y la lista de eventos usan el nuevo. Un evaluador dado de alta en «Usuarios» no puede iniciar sesión.

# 8. Limitaciones y deuda técnica

## 8.1 Seguridad (de mayor a menor gravedad)

| **\#** | **Problema** | **Consecuencia** |
|----|----|----|
| 1 | Sin control de acceso en el servidor; acciones destructivas por GET sin protección CSRF | Cualquiera puede borrar o aceptar propuestas con solo conocer la URL |
| 2 | SQL concatenado en casi todo el código, incluido el login | Inyección SQL: lectura o alteración de la base sin iniciar sesión |
| 3 | Contraseñas en texto plano; el login devuelve la fila completa al navegador; se muestran y se exportan | Robo de cuentas; fuga de credenciales |
| 4 | Suplantación por parámetro en URL (id_usuario, id, usuario) | Cualquiera actúa como un conferencista o evalúa a nombre de otro |
| 5 | Subida de archivos sin validar y con el nombre original, en carpetas públicas | Posible ejecución de código en el servidor (subir un .php) |
| 6 | Salida sin escapar (XSS) | Inyección de scripts en el panel |
| 7 | Credenciales de producción comentadas en archivos de configuración; var_dump en producción | Exposición de secretos |
| 8 | Sesiones sin regenerar ni endurecer | Secuestro de sesión |

> Las credenciales que aparecen en el repositorio (base de datos y correo) deben considerarse comprometidas y conviene **rotarlas** de inmediato, independientemente del proyecto nuevo.

## 8.2 Funcionales y de mantenimiento

- Migración de esquema inconsistente y columnas con nombres distintos para lo mismo (id_tema frente a id, id_congreso frente a id_evento).

- Valores de evento escritos en el código, que mezclan ediciones.

- INSERT sin lista de columnas: cualquier cambio de esquema los rompe.

- Sin transacciones en operaciones de varios pasos (alta de conferencia, guardar evaluación).

- Estados sin catálogo claro (el rechazo es NULL y sin reversa).

- Duplicación: dos clases de expositores (una escribe en la tabla de patrocinadores), tres scripts casi idénticos de propuestas, funciones de imagen copiadas en cinco clases, pares de actualizar «con foto» y «sin foto».

- Código muerto y roto: calendarios de demostración, un menú sin uso, una función con die() al inicio, rutas a otros proyectos.

- Cero correos automáticos, cero registro de actividad (auditoría), cero pruebas, sin paginación en servidor.

- Dependencias obsoletas: PHPExcel, jQuery 3.2.1, scripts de CDN sin verificación de integridad.

# 9. Propuestas de mejora por módulo existente

Cada propuesta parte de lo que hace hoy el módulo y de lo que se necesita para que funcione con **cualquier tipo de evento** de la ANPR. La prioridad indica cuándo conviene construirlo: **Alta** (base del sistema), **Media** (valor claro tras la base) y **Baja** (mejora posterior).

<table style="width:100%;">
<colgroup>
<col style="width: 20%" />
<col style="width: 70%" />
<col style="width: 9%" />
</colgroup>
<thead>
<tr>
<th><strong>Módulo actual</strong></th>
<th><strong>Mejora propuesta</strong></th>
<th><strong>Prioridad</strong></th>
</tr>
</thead>
<tbody>
<tr>
<td>Eventos / Congresos</td>
<td><p>• El evento es la entidad central: tipo (congreso, foro, taller, webinar, expo), sede, fechas, zona horaria, moneda, idiomas e imagen.</p>
<p>• Plantillas de evento y función «duplicar evento» que copia configuración, rúbrica, boletos y programa sin datos de personas.</p>
<p>• Activar o desactivar módulos por evento (un webinar no necesita hoteles).</p>
<p>• Estados del evento: borrador, publicado, en curso, cerrado, archivado.</p>
<p>• Asignar usuarios y roles a cada evento.</p></td>
<td>Alta</td>
</tr>
<tr>
<td>Usuarios y acceso</td>
<td><p>• Separar <strong>persona</strong> (contacto único con historial) de <strong>cuenta</strong> (acceso).</p>
<p>• Roles y permisos por evento (administrador, coordinador, comité, ponente, patrocinador, voluntario, asistente).</p>
<p>• Contraseñas con hash, recuperación por correo, verificación en dos pasos y sesión segura.</p>
<p>• Invitaciones por correo para evaluadores y equipo.</p>
<p>• Bitácora de auditoría de quién cambió qué y cuándo.</p></td>
<td>Alta</td>
</tr>
<tr>
<td>Propuestas (convocatoria)</td>
<td><p>• Convocatorias configurables: tipo de propuesta (sesión, póster, taller), fechas de apertura y cierre, ejes temáticos y campos por formulario, sin programar.</p>
<p>• Flujo de estados completo: borrador, enviada, en revisión, aceptada, lista de espera, rechazada, confirmada y retirada, con posibilidad de revertir.</p>
<p>• Autor con cuenta propia para ver el estado y editar mientras esté abierta.</p>
<p>• Aceptación, rechazo y recordatorios por correo con plantillas.</p>
<p>• Detección de propuestas duplicadas.</p></td>
<td>Alta</td>
</tr>
<tr>
<td>Comité: rúbrica y evaluación</td>
<td><p>• Rúbricas versionadas por evento y tipo de propuesta, con peso por criterio.</p>
<p>• Asignación de propuestas a evaluadores (manual o automática y balanceada), con declaración de conflicto de interés y revisión ciega.</p>
<p>• Mínimo de evaluadores por propuesta y cierre del periodo de evaluación.</p>
<p>• Comentarios para el comité y comentarios opcionales para el autor.</p>
<p>• Resultados con promedio ponderado, desviación y ranking; vista de discrepancias entre evaluadores.</p>
<p>• Guardado transaccional y reevaluación con historial.</p></td>
<td>Alta</td>
</tr>
<tr>
<td>Conferencistas y portal</td>
<td><p>• Acceso por enlace mágico, sin contraseñas compartidas, y portal con <strong>lista de requisitos</strong> (foto, biografía, presentación, acuerdos, datos de viaje) con avance visible.</p>
<p>• Carta de aceptación generada automáticamente (hoy se maneja en plantillas Word).</p>
<p>• Firma de acuerdos digital que guarde fecha, versión del texto y PDF firmado.</p>
<p>• Recordatorios automáticos a quien tenga pendientes.</p>
<p>• Un solo perfil de ponente que se reutiliza entre eventos.</p></td>
<td>Alta</td>
</tr>
<tr>
<td>Programa / calendario</td>
<td><p>• Agenda visual con arrastrar y soltar por día, sala y horario.</p>
<p>• Alertas de choques (ponente, sala, tema).</p>
<p>• Tracks, tipos de sesión y moderadores.</p>
<p>• Solo se agendan sesiones aceptadas y confirmadas.</p>
<p>• Versiones del programa y publicación al sitio o app.</p>
<p>• Exportación a iCal, PDF y a Excel para producción.</p></td>
<td>Alta</td>
</tr>
<tr>
<td>Pósters</td>
<td><p>• Mismo flujo de propuestas con rúbrica propia, sala o sesión de pósters y control de quién presenta.</p>
<p>• Previsualización del documento y del póster en el panel.</p>
<p>• Archivos en almacenamiento privado con enlace temporal.</p></td>
<td>Media</td>
</tr>
<tr>
<td>Talleres y talleristas</td>
<td><p>• Inscripción con cupo, lista de espera y asistencia.</p>
<p>• Material, costo opcional y evaluación del taller.</p>
<p>• Reutilizar a los talleristas como ponentes del mismo perfil.</p></td>
<td>Media</td>
</tr>
<tr>
<td>Boletos</td>
<td><p>• Registro y venta integrados: tipos de boleto, precios por etapa y por moneda, descuentos, cupones, cuotas para socios y grupos.</p>
<p>• Pasarela de pago (Stripe, Mercado Pago u otra) y facturación electrónica (CFDI).</p>
<p>• Reembolsos y transferencia de boleto.</p>
<p>• Alternativa mínima: mantener Podiumeeting y sincronizar asistentes por importación o API.</p></td>
<td>Alta</td>
</tr>
<tr>
<td>Patrocinadores y expositores</td>
<td><p>• Paquetes de patrocinio con beneficios y entregables, contrato, estado de pago y seguimiento comercial.</p>
<p>• Portal del patrocinador para subir logos, textos y su lista de personal.</p>
<p>• Mapa y asignación de stands.</p>
<p>• Una sola tabla de <strong>organizaciones</strong> reutilizada en todos los eventos.</p></td>
<td>Media</td>
</tr>
<tr>
<td>Hoteles y recorridos</td>
<td><p>• Convertirlos en «servicios del evento»: bloque de habitaciones, tarifas, cupo, clave de reservación y enlace de reserva.</p>
<p>• Recorridos y eventos sociales como actividades con cupo, precio e inscripción.</p>
<p>• Filtrar siempre por evento.</p></td>
<td>Baja</td>
</tr>
<tr>
<td>Voluntarios</td>
<td><p>• Convocatoria propia, turnos con cupo, asignación y control de horas.</p>
<p>• Constancia automática y comunicación por turno.</p>
<p>• Reutilizar voluntarios de ediciones previas.</p></td>
<td>Media</td>
</tr>
<tr>
<td>Contenido público (FAQ, testimoniales, numeralia, ejes)</td>
<td><p>• Un módulo de contenido por evento con traducción ES/EN/PT en un mismo registro.</p>
<p>• Numeralia calculada desde los datos reales y no capturada a mano.</p>
<p>• Un micrositio por evento generado desde estos datos (ver módulos nuevos).</p></td>
<td>Media</td>
</tr>
<tr>
<td>Exportaciones</td>
<td><p>• Exportaciones nuevas con permisos, filtros guardados y registro de quién descargó.</p>
<p>• Reemplazar PHPExcel y no incluir contraseñas jamás.</p></td>
<td>Media</td>
</tr>
<tr>
<td>Correo</td>
<td><p>• Plantillas editables en el panel, con variables (nombre, evento, sesión, enlace).</p>
<p>• Disparadores automáticos por cambio de estado.</p>
<p>• Historial de lo que se envió a cada persona.</p></td>
<td>Alta</td>
</tr>
</tbody>
</table>

# 10. Módulos nuevos propuestos para una plataforma multi-evento

Estos módulos no existen hoy. Los dos primeros son la base; el resto convierte la herramienta en una plataforma completa.

| **Módulo nuevo** | **Qué resuelve** | **Prioridad** |
|----|----|----|
| Núcleo multi-evento y organizaciones | Varias organizaciones y marcas (ANPR, Parques de México, regionales); eventos con configuración propia; tableros por evento y vista consolidada de todos. | Alta |
| CRM de comunidad | Historial único de personas y organizaciones: quién fue ponente, patrocinador, asistente, voluntario o evaluador, en qué evento y con qué resultado. Etiquetas, segmentos y notas. Es lo que convierte la herramienta de «sistema de un congreso» en memoria de la ANPR. | Alta |
| Registro y acreditación | Registro de asistentes con formularios por evento, QR personal, check-in con el celular, gafetes imprimibles, acceso por sesión o taller, y constancias de asistencia. | Alta |
| Pagos y facturación | Cobros, conciliación, facturas, cupones, reembolsos, reportes de ingresos por evento y por concepto. | Alta |
| Comunicación | Campañas por correo y WhatsApp, segmentación por rol o estatus, recordatorios, historial por persona y centro de preferencias. | Alta |
| Constructor de formularios | Formularios configurables (convocatorias, registro, voluntarios, encuestas) con campos, validaciones, archivos y lógica condicional, sin programar. | Alta |
| Sitio y micrositio por evento | Páginas públicas generadas desde los datos (programa, ponentes, patrocinadores, sede) en varios idiomas, con dominio propio, más una API pública para que otros sitios consuman el contenido. | Media |
| Tareas y producción | Tablero de tareas del equipo organizador con responsables, fechas y plantillas de checklist por tipo de evento (por ejemplo, «T-90 días»). | Media |
| Presupuesto y finanzas | Presupuesto por partida, gastos, proveedores, flujo de caja y comparativo con ediciones anteriores. | Media |
| Documentos y contratos | Biblioteca de plantillas (cartas, contratos, acuerdos) con variables, generación de PDF o Word y firma electrónica. | Media |
| Logística y proveedores | Salas y recursos, montaje, transporte, alimentos, viajes de ponentes y su itinerario. | Media |
| Encuestas y resultados | Encuesta posterior al evento y por sesión; NPS; reporte de satisfacción comparativo entre ediciones. | Media |
| Reportes y analítica | Tablero con embudo (visitas, registros, pagos, asistencia), propuestas por eje, ingresos y asistentes por país o estado, con comparación entre eventos. | Media |
| App para asistentes (PWA) | Programa personalizado, mapa, notificaciones, networking y evaluación de sesiones; sin tienda de apps. | Baja |
| Eventos híbridos o virtuales | Enlaces de transmisión por sesión, control de acceso y grabaciones. | Baja |
| Integraciones y API | API REST y webhooks; conexión con calendarios, contabilidad, correo, CRM externos y Podiumeeting, mientras exista. | Media |
| Auditoría, respaldos y privacidad | Bitácora de cambios, copias de seguridad, aviso de privacidad, consentimientos y borrado de datos personales a petición. | Alta |

## 10.1 Propuestas transversales de diseño

- **Un solo modelo de «persona» y «organización»** para ponentes, evaluadores, patrocinadores y asistentes, con un rol distinto por evento.

- **Todo cuelga de un evento**, y ninguna consulta debe funcionar sin él.

- **Estados explícitos y con historial** en cada entidad (propuesta, registro, pago, patrocinio).

- **Multi-idioma de origen:** campos traducibles en una sola tabla, no tres columnas por idioma.

- **Archivos privados por defecto**, con enlaces temporales.

- **Correo y avisos por evento** en lugar de procesos manuales.

- **Permisos finos:** cada acción se valida en el servidor, no por el menú.

# 11. Stack tecnológico recomendado

**Recomendación:** Laravel como backend único; Filament para el panel de organizadores y los portales con sesión; React (con Inertia y renderizado en servidor) para el sitio público; PostgreSQL como base de datos; y despliegue inicial en Railway o Laravel Cloud. Es una recomendación para discutir, no una decisión cerrada. Las versiones exactas deben confirmarse al iniciar el proyecto.

## 11.1 Contexto que condiciona la decisión

- **Equipo:** el mismo equipo actual; domina PHP y JavaScript.

- **Forma de trabajo:** el equipo construye con agentes de IA. Conviene un stack con convenciones fuertes y pruebas automatizadas.

- **Presupuesto:** existe para infraestructura; el equipo ya ha usado GCP y Railway y puede explorar AWS.

- **Objetivo de producto:** una plataforma para todos los eventos de la ANPR que además pueda venderse como **SaaS a otras organizaciones**.

- **Naturaleza del producto:** un sistema de back-office (formularios, roles, flujos de aprobación, correos, pagos y reportes) con un sitio público por evento.

## 11.2 Por qué Laravel y no Next.js

| **Criterio** | **Laravel** | **Next.js (TypeScript)** |
|----|----|----|
| Piezas de un SaaS de back-office | Autenticación, roles, colas, correo, validación, migraciones y pruebas vienen integrados | Hay que elegir y ensamblar ORM, autenticación, colas, correo, permisos y panel |
| Panel administrativo | Filament genera tablas, formularios, filtros y exportaciones | Se construye a mano o con plantillas de terceros |
| Trabajo con agentes | Una forma estándar de hacer cada cosa: el código generado es más uniforme | Muchas decisiones abiertas: cada módulo puede terminar con una arquitectura distinta |
| Conocimiento del equipo | PHP, que ya dominan | JavaScript/TypeScript, que también dominan |
| Operación | Un monolito bien organizado, barato de mantener | Más servicios que coordinar (web, API, colas, tareas programadas) |
| Interfaces muy interactivas | Posible con React o Livewire, con un poco más de integración | Su punto fuerte |

**Cuándo reconsiderar Next.js:** si el producto principal pasara a ser una experiencia muy interactiva en tiempo real (por ejemplo, una app de asistentes tipo red social), si se quisiera una sola base de código para web y móvil, o si el equipo se inclinara más hacia JavaScript que hacia PHP.

## 11.3 Tres zonas de interfaz

Laravel sirve todo: datos, permisos y reglas de negocio. La división es solo de la capa de interfaz, y la frontera es clara.

| **Zona** | **Quién la usa** | **Ejemplos** | **Interfaz** |
|----|----|----|----|
| 1\. Panel de organizadores | Equipo de la ANPR y de cada organización cliente | Eventos, propuestas, comité, usuarios, reportes, editor de agenda | Filament |
| 2\. Portales con sesión | Personas externas con cuenta | Conferencista, evaluador, patrocinador, voluntario | Filament, un panel separado por rol |
| 3\. Sitio público | Cualquier visitante | Programa, ponentes, sede, convocatoria, registro y compra de boletos | React con Inertia y SSR |

- **Editor de agenda:** es la única pantalla del panel que necesita una interfaz muy interactiva (arrastrar sesiones entre salas y horarios, ver choques en vivo). Puede resolverse con un componente Livewire con librería de arrastre o con un componente React montado dentro de Filament. Es una decisión puntual y no condiciona el resto.

- **Introducción gradual de React:** empezar con Filament y Livewire en la fase 1 (núcleo administrativo) y sumar React cuando lleguen el programa público, el registro y la compra de boletos.

- **SEO del sitio público:** React con Inertia necesita activar renderizado en servidor (SSR). Si el sitio público crece mucho y el SEO se vuelve crítico, se puede pasar a Next.js solo para esa parte, consumiendo datos de Laravel. No se recomienda empezar así porque duplica la operación.

- **Alternativa descartada al inicio:** un proyecto React o Next.js totalmente separado del backend, comunicado por API, para toda la interfaz.

## 11.4 Stack por capa

| **Capa** | **Elección** | **Nota** |
|----|----|----|
| Framework | Laravel (última versión estable) | Monolito modular organizado por dominios (propuestas, evaluación, registro, pagos...) |
| Panel y portales | Filament | Multi-tenancy integrado y varios paneles por rol |
| Sitio público | React + Inertia con SSR, Tailwind | Sistema de diseño compartido con los paneles |
| Base de datos | PostgreSQL (o MySQL 8 si algún hosting lo exige) | Datos muy relacionales; seguridad a nivel de fila como segunda barrera de aislamiento |
| Multi-evento y multi-organización | Una sola base, con organizacion_id y evento_id y un filtro global | Permite reportes entre eventos y el CRM de comunidad |
| Roles y permisos | spatie/laravel-permission, con rol por evento | Se valida en el servidor, nunca solo por el menú |
| Auditoría | spatie/laravel-activitylog | Quién cambió qué y cuándo |
| Archivos | spatie/laravel-medialibrary + almacenamiento S3 compatible privado (Cloudflare R2 o S3) | Enlaces temporales; nunca carpetas públicas |
| Traducciones | spatie/laravel-translatable | ES/EN/PT en un solo registro |
| Colas y tareas | Redis y workers de Laravel | Correos, recordatorios, exportaciones y generación de PDF |
| Correo transaccional | Postmark, Amazon SES o Resend | Plantillas editables e historial por persona |
| Suscripción del SaaS | Laravel Cashier con Stripe | Planes por eventos, asistentes o módulos activos |
| Cobro de boletos | Stripe Connect o Mercado Pago | Cada organización cobra directo y la plataforma cobra una comisión. Verificar disponibilidad para cada país |
| Facturación (CFDI) | Proveedor con API (por ejemplo, Facturapi) | Configuración por organización |
| Excel y PDF | Laravel Excel y DomPDF o Browsershot | Sustituye a PHPExcel; cartas, constancias y gafetes |
| QR y check-in | Librería de QR + PWA | Escaneo con el celular, sin app nativa |
| Pruebas y calidad | Pest, Larastan y Pint en CI (GitHub Actions) | Incluir una prueba de aislamiento entre organizaciones en cada módulo |

## 11.5 Diseño como SaaS desde el primer día

- **Jerarquía:** organización (tenant) → eventos → todo lo demás. La ANPR es la organización número 1.

- **Aislamiento de datos:** filtro global por organización en cada consulta y, en PostgreSQL, seguridad a nivel de fila como defensa adicional. Una fuga de datos entre clientes es el error más grave posible en un SaaS.

- **Dominios propios** por organización y por evento, con marca blanca básica (logotipo, colores, idioma y plantillas de correo).

- **Facturación y cobros por organización:** suscripción de la plataforma y comisión sobre boletos.

- **Agregar organizacion_id después es muy caro:** incluirlo desde el inicio cuesta poco.

- **Validar la demanda antes de construir todo:** conviene confirmar con dos o tres organizaciones afines que pagarían por el producto. La ventaja diferencial es el flujo de convocatoria, evaluación por comité y conferencistas que la ANPR ya probó.

## 11.6 Infraestructura y despliegue

| **Etapa** | **Opción** | **Comentario** |
|----|----|----|
| Arranque y primeros clientes | Railway o Laravel Cloud | Despliegue simple con PostgreSQL, Redis, colas y tareas programadas. Confirmar precios y límites vigentes |
| Crecimiento | GCP (Cloud Run + Cloud SQL) o AWS (ECS/Fargate + RDS) | Más escala y control; más configuración y costo de operación |
| En todas las etapas | Cloudflare delante, R2 o S3 para archivos, respaldos automáticos, correo transaccional, entornos de desarrollo, pruebas y producción, secretos en variables de entorno | Las credenciales dejan de vivir en el código |

No se recomienda empezar en AWS o GCP: exigen más configuración y operación de la que se necesita mientras se valida el producto. El hosting compartido actual no es adecuado, porque no sostiene bien colas ni tareas programadas.

## 11.7 Cómo trabajar con agentes en este stack

- Un archivo CLAUDE.md (o AGENTS.md) con las reglas del proyecto: estructura de carpetas, paquetes permitidos y cómo se aplica el filtro por organización.

- Pruebas automatizadas desde el inicio, con una prueba obligatoria de aislamiento entre organizaciones en cada módulo. Es la red de seguridad contra errores que un agente no detecta.

- Análisis estático y formato en el CI para que el código deficiente no se integre.

- Módulos pequeños y con fronteras claras, de modo que cada tarea de un agente sea revisable.

- **Revisión humana obligatoria** en autenticación, permisos, pagos y manejo de archivos, donde los errores son costosos.

## 11.8 Qué no se recomienda

- Seguir con PHP sin framework: es el origen de la deuda técnica actual.

- Una base de datos por evento: encarece el mantenimiento y rompe el CRM de comunidad.

- Microservicios o Kubernetes: es excesivo para un equipo pequeño.

- Una app móvil nativa al inicio: una PWA cubre el programa y el check-in.

# 12. Hoja de ruta y migración

## 12.1 Hoja de ruta por fases

Las fases están ordenadas por dependencia. No se estiman duraciones porque dependen del tamaño del equipo.

| **Fase** | **Contenido** | **Resultado esperado** |
|----|----|----|
| 0\. Definición | Obtener el esquema real de la base de datos; decidir qué datos históricos migrar; confirmar roles y procesos con el equipo ANPR; definir el nombre y la marca de la plataforma. | Documento de requisitos y modelo de datos aprobado |
| 1\. Núcleo | Organizaciones (tenants) con aislamiento de datos probado; eventos y plantillas; personas y cuentas; roles y permisos por evento; bitácora de auditoría; correo transaccional. | Se puede crear un evento y gestionar un equipo con permisos reales |
| 2\. Convocatoria y evaluación | Constructor de formularios, propuestas con flujo de estados, rúbricas, asignación, resultados, aceptación con aviso por correo y portal del ponente con requisitos. | Se reemplaza el flujo actual del cpanel con control de acceso y avisos automáticos |
| 3\. Programa y asistentes | Agenda visual, registro de asistentes, boletos y pagos, acreditación con QR y constancias. | Un evento completo se opera de principio a fin, sin plataformas externas |
| 4\. Ecosistema del evento | Patrocinadores y expositores, voluntarios, talleres con cupo, logística y hospedaje, comunicación masiva, tareas del equipo. | Todas las áreas del equipo organizador trabajan en la plataforma |
| 5\. Inteligencia y apertura | CRM de comunidad con historial, reportes comparativos, encuestas, micrositio por evento, API e integraciones, PWA. | La ANPR aprovecha lo aprendido de cada edición |

## 12.2 Estrategia de migración

- **Mantener el cpanel actual en producción** mientras se construye el nuevo, y corregir de inmediato lo urgente (rotar credenciales, cerrar el acceso a las acciones y al login inyectable).

- Probar el nuevo sistema con un evento pequeño de la ANPR antes de usarlo en el siguiente Congreso Parques.

- Importar personas, organizaciones, ponentes y propuestas de ediciones anteriores al CRM, deduplicando por correo.

- Archivar el sistema anterior como solo lectura una vez completada la migración.

## 12.3 Primeras decisiones por tomar

1.  ¿Se reemplaza Podiumeeting con registro y pagos propios, o se integra?

2.  ¿Qué organizaciones afines pueden validar el interés en el producto como servicio (SaaS) antes de construir las funciones multi-cliente completas?

3.  ¿Cuántos eventos y de qué tipos se gestionan al año? Define la urgencia de la plantilla de eventos.

4.  ¿Qué datos históricos del congreso se conservan?

5.  ¿Qué idiomas se necesitan además de español e inglés (el sistema actual ya usa portugués)?

6.  ¿Quién será el dueño del producto y quién mantendrá el sistema?

# 13. Plantilla de proyecto para Laravel

Para arrancar el desarrollo con una base consistente se creó la **plantilla-laravel**, derivada de la plantilla de proyectos en TypeScript que el equipo ya usa. Conserva la capa de proceso (especificaciones, ADR, planes, deuda técnica, reglas para agentes) y reescribe la capa técnica para Laravel y para un producto SaaS multi-organización.

**Ubicación:** C:\Users\iscgo\Documentos\Servidor\plantilla-laravel. No es una aplicación: son documentos, reglas para agentes, configuración de calidad y pruebas guardianas. El proyecto Laravel se crea aparte y la plantilla se copia encima.

## 13.1 Qué contiene

| **Componente** | **Para qué sirve** |
|----|----|
| AGENTS.md y CLAUDE.md | Reglas estables para agentes (única fuente de verdad) y contrato corto para Claude Code. Incluye las 14 acciones que requieren permiso explícito |
| Reglas por área | Backend (app/), frontend React (resources/js/), pruebas (tests/) y sistema de diseño del sitio público |
| ADR 0001 a 0007 | Desarrollo guiado por especificaciones, stack, multi-tenancy, zonas de interfaz, datos, idioma y pagos, ya redactados con lo decidido |
| Documentos técnicos | Seguridad (con las lecciones del cpanel actual), operación, privacidad, convenciones y diseño de base de datos |
| Contexto del producto | Glosario del dominio con equivalencias del cpanel, análisis del producto de origen y mapa de módulos |
| Primer plan | Núcleo multi-tenant, con 12 criterios de aceptación; sirve de ejemplo del formato |
| Reglas impuestas por la herramienta | Permisos, hook de formato y comandos de Claude Code (/nueva-especificacion, /revisar-aislamiento, /verificar) |
| Pruebas guardianas | Pruebas de arquitectura (Pest) y de aislamiento entre organizaciones |
| Andamiaje de calidad | CI de GitHub Actions, Pint, Larastan, plantilla de pull request, CODEOWNERS y Dependabot |

## 13.2 Mejoras respecto a la plantilla original

- **Una sola fuente de verdad:** CLAUDE.md deja de duplicar AGENTS.md y se reduce a un contrato corto.

- **Sin bloque «Sesión actual»:** el estado vive en los planes y en Git, no en un archivo que se edita en cada sesión.

- **Reglas reforzadas por la herramienta:** lectura de .env, git push, migraciones, dependencias y commits se bloquean o piden confirmación mediante configuración, no solo por texto.

- **Pruebas guardianas incluidas:** el aislamiento entre organizaciones y las reglas de arquitectura vienen escritos.

- **Criterios de aceptación únicos (CA-NNN-n):** un script comprueba que cada criterio de un plan terminado tenga su prueba.

- **Idioma definido:** código en inglés; documentos, comentarios, commits y textos de pantalla en español.

- **Documentos nuevos:** seguridad, operación, privacidad y glosario del dominio.

## 13.3 Cómo se usa

1.  Crear el proyecto con el instalador de Laravel (kit de inicio React con Inertia, PostgreSQL y Pest).

2.  Copiar la plantilla sobre el proyecto, sin sobrescribir composer.json, package.json ni .gitignore.

3.  Añadir los scripts de Composer y npm que las reglas dan por existentes.

4.  Instalar los paquetes autorizados (lo ejecuta una persona, no el agente).

5.  Rellenar los marcadores y cerrar las decisiones abiertas de los ADR 0003, 0004, 0005 y 0007.

6.  Aprobar y ejecutar el primer plan (núcleo multi-tenant), por fases.

Para automatizar los pasos 1 a 5 existe un prompt de arranque (PROMPT-ARRANQUE.md) en tres fases: lectura y preguntas, adaptación de documentos y plan del núcleo, con aprobación entre cada una.

> **Pendiente de verificar al instalar:** la sintaxis de las pruebas de arquitectura de Pest y las opciones del instalador de Laravel pueden variar entre versiones. Además, el hook de formato asume un shell tipo bash (Git Bash). Las decisiones abiertas más importantes son la seguridad a nivel de fila de PostgreSQL (se recomienda incluirla), el alcance de los roles (organización o evento) y la pasarela de pagos.
