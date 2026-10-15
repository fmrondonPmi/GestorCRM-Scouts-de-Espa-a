# 32 · Plan: módulo de eventos (sin código)

> **Estado:** plan escrito el 2026-10-15, **sin código**. Se empieza a programar cuando el usuario responda las preguntas **P-61 a P-74** (al final y en el doc 09) o dé por buenos los supuestos marcados como *(supuesto)*.

## Petición del usuario (2026-10-15)

> «Iniciamos con el módulo de eventos. Primero define: tipos de eventos a realizar, secciones que pueden participar, gestión económica del evento, gestión de inscripción de participación, inventario asociado a un evento. Planifica primero.»

## Lo que ya hay y condiciona el diseño

| Pieza | Qué aporta al módulo |
|---|---|
| **CRM: `stic_Events` y `stic_Registrations`** (doc 04 §2 y §7.5) | El CRM federal **ya tiene eventos** (23; `type`: festivalasde, festivalof, as —asamblea—, campamento, Curso, taller, jornada, reunion) e **inscripciones** (5.091; `status`: participa, noparticipa, confirmado, cancelado, lista_espera, no_se_presenta; `participation_type`: asistente, ponente, delegado, organización, voluntario, coordinador…). `special_needs` puede llevar **datos de salud** |
| Matriz de permisos (doc 02) y `Roles` | Fila «Ficha de participación en eventos» con ❓ y la capability **`asde_edit_section_events`**, que tiene el rol Scouter desde la v0.4 pero **no usa ningún código** (P-08) |
| **Uniformes** (doc 24) | El patrón de «una cosa por socio/a con comprobaciones, cada una con quién y cuándo»; nº con prefijo (`U6840007`); pago validado por Tesorería; «Mis pagos»; ventana emergente; CSV con auditoría |
| **Tesorería** (doc 14) | Familias `F684001`, descuento por hermanos, **ajustes con motivo**, movimientos que **no se borran (se anulan)**, ronda de septiembre a agosto. **Gastos** pendientes de diseñar (P-24) |
| **SEPA** (doc 25) | La **firma electrónica** de un texto versionado: quién, cuándo, versión del texto y HMAC de la IP. Vale para la **autorización de participación** |
| Salud (doc 08) e `Instantanea` (doc 26) | La salud **nunca se guarda**: se lee en vivo. Los nombres y contactos salen de la instantánea, sin consultas al CRM por fila |
| Informes (doc 13) | Columnas con salud por ámbito y CSV: base de los **listados del campamento** |
| App móvil (otro plugin) | Se engancha con `do_action`/`apply_filters` genéricos: los avisos push del evento serán un enganche, no código de la app |

## Decisión de fondo: ¿dónde vive el evento? (P-61)

| Opción | Ventajas | Inconvenientes |
|---|---|---|
| **A · Solo en la web** (tablas `asde_*`) | Se controla todo (precios, inventario, autorización, lista de espera); no depende de lo que permita el CRM; se prueba con el CRM simulado | La federación no ve las inscripciones del grupo en el CRM |
| B · Solo en el CRM (`stic_Events`/`stic_Registrations`) | Fuente única, como las fichas | El CRM no tiene inventario, precios por sección, descuentos ni firma de autorización; cada pantalla costaría consultas de 0,5–1,5 s; `Escritura` hoy **nunca crea registros** |
| **C · En la web y, después, publicado en el CRM** *(recomendada)* | Lo mejor de A y, cuando se quiera, el evento y sus inscripciones se **copian** al CRM (estado y tipo de participación, sin salud) | Una fase más (la última) y un permiso de escritura en dos módulos del CRM |

**Propuesta:** empezar con A (todo en la web, sin datos personales en claro) y dejar la publicación en el CRM para la última fase, si la federación la quiere. Mientras tanto, **se leen** del CRM los eventos federativos (Festival ASDE, asamblea…) solo si el usuario lo pide (P-73).

---

## 1. Tipos de evento

Los tipos son un **catálogo configurable por grupo** (como las insignias del uniforme), precargado con esta tabla. Un tipo es una **plantilla de valores por defecto**: en cada evento se puede cambiar todo.

| Clave | Tipo | Ejemplos | Pernocta | Inscripción | Autorización familiar | Coste | Listado con salud | Material | `type` del CRM |
|---|---|---|---|---|---|---|---|---|---|
| `reunion` | **Reunión o actividad de sección** | La reunión del sábado | No | No (solo asistencia) | No (la de la ronda) | No | No | Opcional | reunion |
| `salida` | **Salida o excursión** | Excursión de día, visita, actividad en la ciudad | No | Sí | Sí | Opcional | Sí | Opcional | jornada |
| `acampada` | **Acampada** | Fin de semana en un albergue o terreno | Sí (1–3 noches) | Sí | Sí | Sí | Sí | Sí | campamento |
| `campamento` | **Campamento** | Campamento de verano, de Pascua | Sí (4 o más noches) | Sí | Sí | Sí | Sí | Sí | campamento |
| `ruta` | **Ruta o travesía** | Ruta de Escultas o Rovers, Camino | Sí | Sí | Sí | Sí | Sí | Sí | campamento |
| `grupo` | **Actividad de grupo** | Inicio y fin de ronda, promesas, pasos de sección, aniversario | Opcional | Opcional | Según el caso | Opcional | Si hay pernocta | Opcional | jornada |
| `servicio` | **Servicio o voluntariado** | Limpieza de un espacio natural, recogida de alimentos | No | Sí | Sí | No | Sí | Opcional | jornada |
| `externo` | **Evento externo** | Festival ASDE, actividad de Scouts de Balears, Jamboree, actividad de otro grupo | Según el caso | Sí (solo las del grupo) | Sí | Sí (lo cobra el grupo) | Sí | Opcional | festivalasde / festivalof |
| `formacion` | **Formación** | Curso de monitor/a, taller de primeros auxilios | Según el caso | Sí | No (adultos) | Opcional | No | No | Curso / taller |
| `reunion_adultos` | **Kraal, Consejo de Grupo o asamblea** | Consejo de Grupo, reunión de familias, asamblea | No | No (convocatoria) | No | No | No | No | as / reunion |

- **Duración:** fecha y hora de inicio y de fin. La pernocta sale de las fechas (si acaba otro día) y cambia lo que se pide (autorización, listado con salud, material).
- **Ámbito del evento:**
  - *de sección* (una sección);
  - *de varias secciones*;
  - *de grupo* (todas);
  - *externo* (lo organiza otra entidad: el grupo solo gestiona **sus** inscripciones, su cobro y su material).
- **Estados del evento:**

| Estado | Qué se puede hacer |
|---|---|
| **Borrador** | Lo ven solo sus responsables y Coordinación. Se edita todo |
| **Publicado · inscripción abierta** | Lo ven las secciones convocadas; las familias se inscriben |
| **Inscripción cerrada** | Solo los gestores inscriben o dan de baja (con motivo) |
| **En curso** | Pasar lista, salida del material |
| **Celebrado** | Devolución del material, gastos reales, devoluciones de dinero |
| **Cerrado** | Tesorería cierra las cuentas: ya no se tocan pagos ni gastos. Solo consulta |
| **Cancelado** | Con motivo; se avisa a las inscritas y se proponen las devoluciones |

- **Datos de cada evento:** nº de evento (**`E` + grupo + correlativo**, p. ej. `E6840012`, como `U6840007`), nombre, tipo, ámbito, fechas, lugar (texto y enlace a un mapa), punto y hora de salida y de llegada, descripción o circular (texto y PDF opcional), **lista de material personal** (lo que lleva cada participante), **responsable** (scouter que dirige la actividad) y **equipo** (scouters y voluntariado), secciones convocadas, plazas, plazo de inscripción, precios y opciones.

## 2. Secciones que pueden participar

Salen del catálogo de siempre (`Domain\Secciones`, doc 05); el evento guarda las **secciones convocadas**.

| Sección | Papel en un evento | Notas |
|---|---|---|
| Castores, Lobatos, Scouts, Escultas, Rovers | **Participantes** (educandos) | Inscripción de la familia o, en mayores de edad, de la propia persona |
| Scouters | **Equipo** (organización) por defecto; **participantes** en formación, Kraal o Consejo | No pagan por defecto (como en la cuota); se puede cambiar por evento |
| Voluntariado de apoyo | **Equipo** (cocina, intendencia, transporte) | Igual que los scouters |
| Familias | Asistentes a una asamblea o fiesta de grupo | Sin inscripción: convocatoria y, si hace falta, recuento |

**Reglas:**

1. Solo se convoca o inscribe a personas con **membresía activa** en el grupo (`Auth\Ambito`). **Un grupo no ve los eventos de otro** (cada evento lleva su nº de grupo; regla 3 de `CLAUDE.md`).
2. **Secciones convocadas:** una o varias. Opcionalmente, **plazas por sección** y en total.
3. **Edad** opcional (mínima y máxima el día de inicio): sirve para actividades de mayores de edad o para los que **pasan de sección** ese verano.
4. **Excepciones:** el responsable, Coordinación o Secretaría pueden inscribir a alguien de otra sección (p. ej. un escultas que va de ayudante con la manada), **con motivo** y en la auditoría.
5. **Ratio de educadores:** si el equipo no llega a la ratio configurada (p. ej. 1 scouter por cada 8–10 educandos, valor a confirmar con la normativa, P-66), la pantalla **avisa, no bloquea**.
6. **Quién gestiona un evento:**
   - de sección o de varias: **los scouters de esas secciones** y el equipo del evento;
   - de grupo y externos: **Coordinación** y el responsable designado;
   - Secretaría y Coordinación ven **todos** los del grupo (visión global, como en uniformes).
7. **Eventos con otro grupo:** fuera de alcance; cada grupo crea el suyo y gestiona a los suyos.

## 3. Gestión de la inscripción

### Tres modalidades (las elige el tipo, se cambian en el evento)

| Modalidad | Cómo funciona | Para |
|---|---|---|
| **Inscripción abierta** | La familia entra en «Mis eventos» y apunta a sus menores | Acampadas, campamentos, salidas |
| **Convocatoria** | La lista sale sola de las secciones convocadas («convocado»); cada familia responde **sí o no**, y el equipo ve **quién no ha contestado** | Actividades a las que se espera a todos (promesas, inicio de ronda) |
| **Solo asistencia** | Sin inscripción: el día se pasa lista | Reuniones de sección, Kraal |

### Quién inscribe a quién

| Quién | A quién | Cómo |
|---|---|---|
| Cuenta familiar (`nº + T`, `nº + T2`) | Sus menores | Desde «Mis eventos», firmando la autorización |
| Asociado/a **mayor de edad** | A sí mismo | Desde «Mis eventos» |
| Asociado/a **de 14 a 17** | A sí mismo, como **preinscripción** *(supuesto, P-64)* | La inscripción no queda confirmada hasta que la autoriza su familia |
| Scouter | A los de sus secciones | «En nombre de la familia» (autorización en papel): queda anotado quién y por qué |
| Coordinación y Secretaría | A cualquiera del grupo | Igual que el scouter |
| Responsable del evento | Al **equipo** (scouters y voluntariado) | Los añade al equipo; cada scouter puede apuntarse al equipo de un evento de sus secciones |

### Qué se pide al inscribirse (solo lo que no está en el CRM)

- **Autorización de participación**, firmada electrónicamente por quien tiene la patria potestad o la tutela (texto versionado configurable, quién, cuándo y HMAC de la IP, como la SEPA; P-65).
- **Consentimiento de imagen** para ese evento, si no consta ya en la ficha (P-65).
- **Transporte:** con el grupo, por su cuenta, ida o vuelta.
- **Personas autorizadas a recoger** al menor (nombre y documento): **cifrado** y se borra al cerrar el evento.
- **Opciones del evento** con su precio (p. ej. camiseta del campamento con talla, autobús).
- **Observaciones para el equipo:** cifradas y con caducidad.
- **«He revisado la ficha de salud y alimentación y está al día»**, con un enlace a la ficha para proponer cambios (cola de cambios, doc 17). **La salud no se copia al evento**: se lee del CRM al hacer los listados.

### Estados de una inscripción (y su equivalente en el CRM)

| Estado | Cuándo | CRM |
|---|---|---|
| Convocado | Convocatoria sin respuesta | (no se publica) |
| **Pendiente** | Inscrito, pero falta la autorización o el pago | confirmado |
| **Confirmada** | Autorizada y pagada (o sin coste) | confirmado |
| Lista de espera | No quedaban plazas | lista_espera |
| No participa | Ha contestado «no» a la convocatoria | noparticipa |
| Baja | La familia o un gestor la cancela | cancelado |
| **Participa** / No se presenta | Al pasar lista | participa / no_se_presenta |

### Plazas, plazos y bajas

- **Plazas** en total y por sección. Al llenarse, las nuevas entran en **lista de espera** por orden. Si se libera una plaza, se ofrece a la primera con un email y **X días para confirmar** *(supuesto: 3 días; si no confirma, pasa a la siguiente)*.
- **Plazo de inscripción:** fecha de apertura y de cierre. Cerrado, solo inscriben los gestores.
- **Baja:** la familia, hasta el cierre de la inscripción; después, un gestor con motivo. La devolución sigue la política del evento (apartado 4).

### Listados para el equipo (lo más sensible del módulo)

- **Participantes** por sección: estado, pago, autorización, transporte y opciones.
- **Contactos de emergencia:** los tutores y sus teléfonos (de la instantánea, sin consultas por fila).
- **Salud y alimentación:** leídas **en vivo** del CRM en una sola lectura, **nunca guardadas**, con la auditoría de quién las consulta. Quién las ve: los scouters del **equipo del evento** y los de las secciones de los participantes, Secretaría y Coordinación *(supuesto, P-70)*.
- **Resumen para cocina:** recuento por tipo de régimen y alergias alimentarias.
- **Lista para el autobús** y de quién recoge a quién.
- **Imprimir** (para llevar al campamento, donde puede no haber cobertura) y **CSV**, cada descarga en la auditoría (doc 08, punto 11) y con aviso de que lleva datos de salud.

### El día del evento

- **Pasar lista** desde el móvil: participa o no se presenta; llegada y salida (quién lo recoge).
- Cambios de última hora (baja por enfermedad) con motivo.

### Comunicación

- Emails (en el **registro de emails**): publicación o convocatoria, inscripción recibida, confirmada, plaza liberada, recordatorio unos días antes, cancelación del evento.
- Enganche genérico `do_action( 'asde_ap_evento_aviso', … )` para que la app móvil mande el aviso push (sin código de la app aquí).
- **«Mis eventos»** (Mi espacio / Mi familia): próximos eventos de sus menores o propios, inscribirse, estado, **lo que falta** (pagar, autorizar), circular, material personal y horarios.
- **Inicio:** «Pendiente de revisar» con las inscripciones y autorizaciones pendientes de cada uno.

## 4. Gestión económica

### Lo que paga cada participante

- **Precio base** por participante y, si hace falta, **por sección** o **por papel** (el equipo, gratis por defecto).
- **Descuento por hermanos** en el mismo evento (importe o % a partir del 2.º), con las **familias de Tesorería**.
- **Precio con fecha:** hasta un día, un precio; después, otro (inscripción tardía).
- **Opciones** con precio (camiseta, autobús).
- **Becas y ajustes** con motivo (Tesorería), como en la cuota.
- El importe se **congela al inscribirse** con los precios de ese día (como el uniforme). Tesorería siempre puede escribir lo que ha cobrado de verdad.
- **Plazos** opcionales: señal al inscribirse y el resto antes de una fecha.

### Cobro

- Tesorería registra los **pagos** (efectivo, ingreso o transferencia; domiciliación SEPA si se decide, P-44 y P-68) de una inscripción o **de la familia** (un solo pago para los hermanos).
- **Referencia para la transferencia:** nº de evento + nº de familia (`E6840012 F684001`) o, si no tiene familia, el nº de inscripción. Más adelante, el banco la concilia sola (doc 16).
- **«Mis pagos»** muestra lo que hay que pagar de cada evento, lo pagado y lo pendiente.
- **No entra en la cuota** de la ronda ni en el control de pagos (como el uniforme).
- **Devoluciones:** política del evento (p. ej. «hasta 15 días antes, se devuelve todo; después, el 50 %»), siempre con la última palabra de Tesorería y con motivo. Se registran como movimiento negativo; **nada se borra, se anula**.

### Presupuesto, gastos y balance

- **Presupuesto** (lo prepara el responsable del evento): partidas de **ingreso** (cuotas de los participantes, que se calculan solas; subvención; aportación del grupo; otras) y de **gasto** (transporte, alojamiento o terreno, alimentación, material y actividades, seguros, otros).
- **Gastos reales:** fecha, partida, concepto, proveedor, importe, **quién lo pagó** (el grupo o un scouter al que hay que **reembolsar**) y **justificante** (imagen o PDF, guardado fuera de la carpeta pública). Es el primer uso de los **gastos** que la Tesorería tiene pendientes (P-24): se diseña para que la Tesorería general lo reutilice.
- **Anticipo de caja** al responsable para las compras durante el evento y su liquidación *(fase 2)*.
- **Balance:** previsto y real, lo cobrado y lo pendiente, el coste real por participante y el **punto de equilibrio** (cuántos participantes hacen falta para cubrir los gastos fijos).
- **Cierre:** Tesorería cierra las cuentas del evento; queda un **informe final** (imprimir y CSV) para el Consejo de Grupo.

### Quién hace qué

| | Responsable del evento | Tesorería | Coordinación | Secretaría |
|---|---|---|---|---|
| Precios y opciones | ✅ (mientras no haya pagos) | ✅ | ✅ | 👁 |
| Presupuesto | ✅ | ✅ | ✅ (¿lo aprueba? P-69) | 👁 |
| Registrar pagos y devoluciones | — | ✅ | — | — |
| Gastos | 📝 los propone con justificante | ✅ los valida y reembolsa | 👁 | 👁 |
| Becas y ajustes | — | ✅ (con motivo) | — | — |
| Cerrar las cuentas | — | ✅ | — | — |

## 5. Inventario asociado al evento

El inventario es **del grupo** (permanente) y cada evento **reserva** lo que se lleva.

### Catálogo de material

- **Categorías** (configurables): tiendas de campaña (canadiense, iglú, intendencia); cocina (fogones, bombonas, ollas, neveras, depósitos de agua); herramientas (palas, sierras, mazas, hachas); pionerismo (cuerdas, troncos, piquetas); iluminación (focos, frontales); primeros auxilios (botiquines, extintores); juegos y talleres; banderas y mástiles; electrónica (altavoz, proyector); mobiliario (mesas, sillas, lonas, toldos).
- **Cada artículo:** código (**`M` + grupo + correlativo**, p. ej. `M6840031`, para una etiqueta o un QR), nombre, categoría, foto, **unidades**, si se sigue **una a una** (la tienda nº 3) o **a granel** (40 piquetas), estado (bueno, regular, a reparar, de baja), ubicación (local, almacén…), de quién es (del grupo, de una sección, prestado), fecha de compra y valor (opcional, para el seguro), **caducidad** (botiquín, extintor, gas) y última revisión con notas.
- **Historial:** alta, revisiones, reparaciones y baja (con motivo).
- **Avisos en Inicio:** lo que caduca en 30 días y lo que está pendiente de reparar.

### Material de un evento

1. **Lista de material** del evento: artículos y cantidades, con **plantillas por tipo** («material de acampada»: tiendas, cocina, botiquín…) para no empezar de cero.
2. **Aviso de conflicto** si otro evento que se solapa en fechas reserva el mismo material y no hay unidades suficientes.
3. **Salida:** quién lo saca y cuándo (casilla por artículo o «todo»).
4. **Devolución:** comprobación a la vuelta con **incidencias** (falta, roto): cambia el estado del artículo y, si hay que reponerlo, propone el **gasto** en las cuentas del evento.
5. **Responsable de material** del evento (intendencia).

- El **material personal** de cada participante no es inventario: es un texto del evento que ven las familias.
- **No hay datos personales** en el inventario (solo quién lo saca, como id de usuario). Las fotos van a la biblioteca de medios, como las de las insignias (sin SVG).
- **Préstamos** a otros grupos o entidades: *fase 2* (P-71).

---

## Permisos (capabilities propuestas)

Siempre **capability + ámbito** (doc 02): la sección del evento, el equipo del evento o la persona inscrita.

| Capability | Roles | Qué permite |
|---|---|---|
| `asde_eventos_inscribir` | Todos | «Mis eventos»: ver los eventos a los que está convocado/a (o sus menores), inscribir y dar de baja |
| `asde_eventos_gestion` | Scouter, Secretaría, Coordinación | Crear y gestionar eventos e inscripciones **en su ámbito** (un scouter, los de sus secciones y aquellos en cuyo equipo está). **Sustituye a `asde_edit_section_events`**, que no usa nadie *(supuesto)* |
| `asde_eventos_grupo` | Coordinación | Crear eventos **de grupo** y externos, y gestionar todos los del grupo |
| `asde_eventos_economia` | Tesorería (y Coordinación, Secretaría para ver) | Pagos, devoluciones, becas, gastos y cierre de las cuentas |
| `asde_eventos_config` | Coordinación | Tipos de evento, plantillas de material, textos de la autorización, ratio |
| `asde_inventario_ver` | Scouter, Secretaría, Coordinación, Tesorería | Ver el material y reservarlo para un evento que gestiona |
| `asde_inventario_gestion` | Coordinación (y el responsable de material, P-71) | Dar de alta, revisar, reparar y dar de baja material |

- **Salud en los listados:** no es una capability nueva. Se usa `asde_view_health` **con el ámbito del evento** (equipo del evento + secciones de los participantes) *(supuesto, P-70)*.

## Datos (qué se guarda en WordPress)

**Ningún dato personal en claro**: de las personas, solo el **nº de Scouts de España** (como los pedidos de uniforme); los nombres y contactos, de la instantánea; la salud, en vivo.

| Tabla / opción | Contenido |
|---|---|
| `{prefix}asde_eventos` | Grupo, nº (`E6840012`), tipo, ámbito, nombre, fechas, lugar, estado, secciones convocadas, plazas, responsables (ids de usuario), configuración (precios, opciones, política de devolución, formulario) y, en la fase CRM, el id de `stic_Events` |
| `{prefix}asde_evento_inscripciones` | Evento, nº de Scouts de España, secciones, si es adulto, papel (participante o equipo), estado, orden en la lista de espera, líneas e importe congelado, **firma de la autorización** (quién, cuándo, versión del texto, HMAC de la IP), `datos_enc` **cifrado** (quién recoge, observaciones), asistencia; y quién y cuándo de cada paso |
| `{prefix}asde_evento_movimientos` | Pagos, ajustes y devoluciones (inscripción o familia, importe, forma, referencia, nota, quién, anulación). Tabla propia, separada de la cuota *(supuesto)* |
| `{prefix}asde_evento_partidas` | Presupuesto y gastos (previsto o real, ingreso o gasto, partida, concepto, proveedor, importe, justificante, quién pagó, reembolso) |
| `{prefix}asde_material` | Catálogo de material (sin datos personales) |
| `{prefix}asde_material_historial` | Altas, revisiones, reparaciones, bajas e incidencias |
| `{prefix}asde_evento_material` | Material reservado por evento: cantidad, salida, devolución e incidencias |
| Opción `asde_ap_eventos_<grupo>` | Tipos, plantillas de material, textos de la autorización, ratio, política de devolución por defecto |

- 7 tablas nuevas: *Instalación y entorno* pasa de 14 a **21**. `uninstall.php` las conserva (justificantes de cobros y gastos) y borra la configuración.
- **Conservación** *(supuesto, P-72)*: `datos_enc` (quién recoge, observaciones) se vacía al **cerrar** el evento; la firma de la autorización (sin datos personales) se conserva con el evento.
- Auditoría: `evento_creado`, `evento_publicado`, `evento_cancelado`, `inscripcion_*`, `evento_pago`, `evento_gasto`, `evento_cerrado`, `evento_listado_descargado`, `material_*`… con **nombres de campos, nunca valores**.

## Publicación en el CRM (última fase, solo si P-61 = C)

- Una **segunda puerta de escritura** (`Crm\EscrituraEventos`), aparte de `Escritura` (que sigue sin crear registros): solo `stic_Events` y `stic_Registrations`, solo crear y actualizar, solo si `Permisos::puede( …, 'edit' )`, con auditoría y probada **solo con el CRM simulado**.
- Al publicar: el evento → `stic_Events` (tipo del CRM de la tabla del apartado 1); cada inscripción → `stic_Registrations` (estado de la tabla del apartado 3, `participation_type` asistente u organización). **Nunca** `special_needs` ni salud.
- Lectura: el historial de eventos de una persona en su ficha, desde `stic_Registrations` (sin `special_needs`).

## Rendimiento (doc 26)

- Agenda, inscripciones, economía y material: **0 consultas al CRM** (nombres de la instantánea con `obtener_varias`).
- Listado con salud: **una** lectura en vivo para todo el evento, nunca una por participante.

## Pantallas

| Dónde | Pantalla | Qué hay |
|---|---|---|
| Gestión del grupo → **Eventos** | Agenda | Eventos de la ronda en tarjetas (móvil) o tabla; filtros por estado, tipo y sección; «Nuevo evento» |
| | Evento (pestañas) | **Resumen** (datos, estado, avisos de ratio y plazas) · **Inscripciones** · **Economía** · **Material** · **Listados** |
| | Configuración | Tipos, plantillas de material, textos de la autorización |
| Gestión del grupo → **Material** | Inventario | Catálogo con filtros, ficha del artículo con su historial, caducidades |
| Mi espacio / Mi familia → **Mis eventos** | Mis eventos | Próximos eventos, inscribirse, estado, lo que falta, información práctica |
| Inicio | «Pendiente de revisar» | Inscripciones, autorizaciones, gastos por validar, material que caduca |

Todo con las convenciones de siempre: sin JavaScript funciona; con JavaScript, ventana emergente y sin recargar la pantalla entera; móvil primero; textos en los cuatro idiomas.

## Fases (una versión cada una, con sus pruebas)

| Versión | Qué | Pruebas |
|---|---|---|
| **0.19.0 · Eventos** | Tipos, crear, editar, publicar y cancelar; agenda; ámbito y permisos; nº de evento; equipo y ratio | `tests/eventos.php` |
| **0.19.1 · Inscripciones** | Las tres modalidades, «Mis eventos», autorización firmada, plazas y lista de espera, bajas, emails | `tests/eventos-inscripciones.php` |
| **0.19.2 · Economía** | Precios, hermanos, opciones, pagos, «Mis pagos», devoluciones, presupuesto, gastos con justificante, balance y cierre | `tests/eventos-economia.php` |
| **0.19.3 · Listados y asistencia** | Participantes, emergencia, salud en vivo, cocina, autobús, imprimir y CSV con auditoría; pasar lista | `tests/eventos-listados.php` |
| **0.20.0 · Inventario** | Catálogo, historial, caducidades, material del evento con plantillas, conflictos, salida y devolución | `tests/inventario.php` |
| Después | Publicar en el CRM (P-61), conciliar el banco por referencia, SEPA de actividades (P-44), avisos push (enganche), calendario, préstamos de material | — |

Cada fase actualiza los docs (00, este, 02, 10 y 09) y los manuales de uso (`manual/fuentes/`). Para la mecánica repetida de cada fase hay dos *skills* del proyecto: **`modulo-nuevo`** (piezas de un módulo o pantalla nueva) y **`entregar-version`** (cierre de una versión) en `.claude/skills/`.

## Supuestos (para confirmar)

1. Los eventos viven **en la web** y la publicación en el CRM es la última fase (P-61, opción C).
2. Los tipos son un catálogo **configurable por grupo** con los diez de la tabla.
3. El equipo (scouters y voluntariado) **no paga** por defecto.
4. Los asociados de **14 a 17** solo se **preinscriben**; confirma la familia (P-64).
5. La plaza liberada se ofrece a la primera de la lista de espera con **3 días** para confirmar.
6. Ven la salud del listado: el **equipo del evento**, los scouters de las secciones de los participantes, Secretaría y Coordinación (P-70).
7. Los cobros del evento van en **su propia tabla** y no cuentan en la cuota de la ronda.
8. `asde_eventos_gestion` **sustituye** a `asde_edit_section_events`, que no usa ningún código.
9. Lo escrito en la inscripción (quién recoge, observaciones) se borra **al cerrar el evento** (P-72).

## Preguntas abiertas (también en el doc 09)

| # | Pregunta |
|---|---|
| P-61 | **¿Dónde vive el evento?** Solo en la web (A), solo en el CRM (B) o en la web y después publicado en el CRM (C, recomendada). ¿La federación usa `stic_Events`/`stic_Registrations` y espera ver ahí las actividades del grupo? (Resuelve P-08) |
| P-62 | **Tipos de evento:** ¿valen los diez propuestos? ¿Las **reuniones semanales** entran en el módulo (con asistencia) o solo las actividades con inscripción? |
| P-63 | **Quién crea y publica:** ¿cualquier scouter para sus secciones, o hace falta que **Coordinación apruebe** antes de publicar? |
| P-64 | **Asociados de 14 a 17:** ¿se preinscriben y confirma la familia, se inscriben solos, o solo inscribe la familia? |
| P-65 | **Autorización de participación:** ¿vale la firma electrónica (como la SEPA) o hace falta papel firmado? ¿Hay un **modelo** de ASDE o de Scouts de Balears? ¿El **consentimiento de imagen** es por evento o anual (en la ficha)? |
| P-66 | **Normativa de actividades de tiempo libre en las Illes Balears:** ¿hay que comunicar las acampadas y campamentos a la administración, qué **ratio** y qué **titulación** (director/a, monitores) se exigen, y quién lo tramita? La web puede avisar de la ratio y guardar la fecha de la comunicación |
| P-67 | **Precios:** ¿descuento por hermanos en los eventos? ¿Pagan los scouters? ¿Recargo por inscripción tardía? ¿Qué **política de devolución** por defecto? |
| P-68 | **Cobro:** ¿efectivo e ingreso como el uniforme? ¿También **Bizum**? ¿**Domiciliación SEPA** de actividades (P-44)? ¿Conciliar el banco con la referencia del evento? |
| P-69 | **Presupuesto y gastos:** ¿hace falta un presupuesto **aprobado** (por quién) antes de publicar? ¿Se suben los **justificantes** en el evento? ¿Hay **anticipos de caja** a los scouters? (Enlaza con P-24) |
| P-70 | **Salud en el evento:** ¿quién puede ver e imprimir el listado del campamento con salud y alimentación? ¿Se imprime para llevarlo o solo en pantalla? |
| P-71 | **Inventario:** ¿hay ya un inventario (hoja de cálculo) que importar? ¿Hay un **responsable de material** (rol nuevo o responsabilidad de un scouter)? ¿El material es todo del grupo o también de cada sección? ¿Se presta a otros grupos? |
| P-72 | **Conservación:** ¿cuánto se guardan las inscripciones y las autorizaciones de un evento ya celebrado? ¿Lo revisa la persona delegada de protección de datos de ASDE? |
| P-73 | **Eventos externos** (Festival ASDE, actividades de Scouts de Balears): ¿se inscribe desde aquí y se manda la lista al organizador? ¿Se leen del CRM? |
| P-74 | **Avisos:** ¿email a las familias al publicar, al confirmar y como recordatorio? ¿Avisos push de la app (con el texto genérico de siempre)? |
