# 66 · Asistente para crear eventos (v0.64.0)

> **Estado:** implementado en la v0.64.0 (2026-11-05, rama `Wizard-Eventos`). Diseño: mock-up `docs/mockups/wizard-eventos.html`. Decisiones del usuario el 2026-11-05 (apartado 2).

## 1. Qué se pidió

> «Vamos a hacer un wizard para la creación de eventos.»

«Nuevo evento» era un formulario de unos 35 campos en una sola página. Ahora es un asistente de **seis pasos** que pregunta lo mínimo, enseña solo lo necesario y avisa de los trámites que salen de las respuestas.

## 2. Decisiones del usuario

| Pregunta | Decisión |
|---|---|
| ¿Cuándo se guarda? | **Al final**: un solo «Crear el evento (borrador)» en el paso 6. Si se cierra la pestaña a medias se pierde lo escrito (no quedan borradores a medias en la agenda) |
| ¿Dónde se usa? | **Solo al crear**. Editar sigue con el formulario de siempre |
| ¿Equipo y presupuesto? | El paso 3 deja elegir **director/a y monitores (opcional)**; el presupuesto se hace después desde la ficha, que muestra lo que falta |
| ¿Copiar de un evento anterior? | **Sí**, en el paso 1 |

## 3. Los pasos

1. **Qué**: tipo (tarjetas), nombre, alcance (solo si puede elegir: Coordinación) y secciones convocadas (casillas). «¿Se parece a uno anterior?» ofrece los 6 últimos eventos que ve.
2. **Cuándo y dónde**: fechas y horas (la de fin no puede ser anterior; al poner el inicio, el fin se rellena igual), noches, lugar, mapa, salida y vuelta.
3. **Cuántos**: una fila por sección (previstos y plazas), edades, **director/a** (desplegable) y **monitores** (casillas); calcula cuántos monitores hacen falta con la misma regla que `Normativa::monitores_necesarios`.
4. **Dinero y normativa**: sí/no a ¿pagan las familias?, ¿fuera de España?, ¿agua?, ¿riesgo especializado?, ¿dependencia?; cada «sí» abre sus datos (póliza, socorrista, titulados, nº de personas) y debajo sale «Con lo que has dicho, tendrás que…»: presupuesto y validación de Tesorería, aviso a ASDE Baleares con su fecha límite (2 noches y 10 menores previstos, 30 días antes), seguro aparte, socorrista, titulados y monitores de apoyo.
5. **Inscripción**: modalidad, plazos, circular y material personal.
6. **Revisar**: resumen con lo escrito y lo que se verá después de crearlo (presupuesto, equipo, menú, calendario).

## 4. Cómo está hecho

- **Un solo formulario** que envía a la misma acción `evento_crear` y la misma `Servicio::leer()`: no hay lógica nueva de validación, solo el equipo (`director`, `monitor[]`), que se añade con `Servicio::anadir_equipo` tras crear (un fallo avisa y el evento se crea igual).
- `templates/eventos/asistente.php` (seis `section`, textos traducidos en el servidor) + `assets/js/asistente.js` (un paso cada vez, avisos y resumen en vivo; solo muestra u oculta HTML ya traducido, así que no usa `TextosJs`). Sin JavaScript se ven los seis pasos seguidos y el formulario funciona igual.
- `Eventos\Formulario` calcula los valores (lo escrito, el evento o su copia, el tipo) para el asistente y para el formulario de editar, que antes los calculaba en la plantilla.
- **Copiar** (`?nuevo=1&copiar=<id>`): datos, lugar y textos; las fechas un año después; sin inscritos, pagos ni equipo. Solo eventos que quien crea puede ver (`Servicio::ve`).
- Las constantes de la normativa (noches, menores, días, participantes por monitor) viajan en `data-*` desde `Normativa`, no se repiten en el JS.
- Las preguntas de sí/no son pares de radios con los mismos nombres de campo que ya leía `leer()` (`'0'` cuenta como «no»). Las secciones usan filas de campos (no tabla) para no añadir una excepción a la regla de grids (doc 64) y verse bien en el móvil.

## 5. Pruebas

`tests/eventos-asistente.php`, 39 comprobaciones: pantalla (6 pasos, sin cuadros plegables, secciones y alcance por rol, equipo entre adultos), crear con equipo (director, monitor, sin equipo, socio inexistente), errores (sin crear nada y conservando lo escrito), copiar (con fechas +1 año, sin equipo, solo lo que se ve), `Formulario::valores` y coherencia JS↔plantilla. Pasan también `eventos`, `eventos-inscripciones`, `eventos-calendario`, `menus-evento`, `ayuda`, `colores`, `estilo-wordpress`, `grids-paginados`, `idiomas` e `idiomas-catalogos`.

## 6. Pendiente

- Revisar el asistente a mano en el móvil con el usuario (hecho solo a 375 px con datos inventados).
- Capturas del manual (el apartado «Eventos» describe el asistente sin captura nueva).
- Si se quiere, un paso de presupuesto con partidas habituales (autobuses, alimentación) y editar por pasos.
