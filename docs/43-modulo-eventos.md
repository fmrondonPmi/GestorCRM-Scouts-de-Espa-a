# 43 · Módulo de eventos (plan, decisiones y lo que ya está)

> **Estado:** plan escrito y **decidido** el 2026-10-15 (preguntas P-139 a P-154, tabla «Decisiones»; para la P-144, la normativa balear, el usuario pidió «investiga y propón»: apartado 6). **Hechas las dos primeras fases** (eventos, equipo dirigente y presupuesto; inscripciones y cobro): ver «lo que ya está» de cada una. Siguiente: **gastos, SEPA y cierre** (v0.36).

> **De dónde viene (2026-10-15):** este módulo se hizo en una **sesión de Claude Code en la nube** sobre el `main` de GitHub, que estaba en la v0.18 (allí fueron la v0.19.0 y la v0.19.1, este documento era el 32 y sus preguntas, P-61…P-76). A petición del usuario se integró en la línea de la v0.34 como **v0.35.0** (rama `Eventos`): doc 43, preguntas **P-139…P-154**, módulo **«Eventos» apagado por defecto** en wp-admin → Módulos (como los demás desde la v0.32) y adaptado a lo que cambió desde la v0.18 (ver «Integración en la v0.35»). El **inventario** que este plan proponía como fase propia **ya existe** en esta línea (doc 32, desde la v0.19): el material de un evento se hará sobre él.

## Petición del usuario (2026-10-15)

> «Iniciamos con el módulo de eventos. Primero define: tipos de eventos a realizar, secciones que pueden participar, gestión económica del evento, gestión de inscripción de participación, inventario asociado a un evento. Planifica primero.»

## Decisiones del usuario (2026-10-15)

| # | Decisión |
|---|---|
| P-139 | Los eventos se guardan **en la web**. En una fase final **se copian al CRM** (`stic_Events` / `stic_Registrations`). Con eso se responde también la P-08: sí, la «ficha de participación» son las inscripciones del CRM |
| P-140 | **Solo actividades.** Las reuniones semanales de sección **no** entran, y las de adultos (Kraal, Consejo de Grupo, asamblea de familias) **tampoco**. Desaparecen esos dos tipos y la modalidad «solo asistencia» |
| P-141 | Cada **scouter** crea y publica los eventos **de sus secciones**; **Coordinación**, los de grupo y los externos. Si el evento tiene coste, antes de publicarlo **Tesorería valida el presupuesto** (P-147) |
| P-142 | Los **asociados de 14 a 17** se preinscriben ellos y **firma la familia**. La familia también puede inscribirlos directamente, y **esa inscripción cuenta como autorización firmada** |
| P-143 | **Autorización:** las dos vías. La familia firma en la web o entrega el **papel**, y un scouter marca que lo ha recibido. **Consentimiento de imagen:** anual, el de la ficha del CRM (`cesionimagen_c`). El evento solo lo consulta |
| P-144 | «**Investiga y propón**»: la propuesta está en el apartado 6 |
| P-145 | **El precio sale del coste.** El coste del equipo (scouters) se **reparte** en la cuota de los asociados. Al coste se le suma un **margen del 5 %**, que es el **fondo de emergencia** de la actividad. El margen se puede activar o no y cambiar de porcentaje. Hay **precio por sección** y **becas y ajustes**. **Sin** descuento por hermanos, recargo por inscribirse tarde ni opciones con precio. **Devoluciones:** sin regla automática, **las decide Tesorería** con un motivo |
| P-145 bis | El precio queda **fijo al publicar** y se calcula con los participantes previstos. **Lo que sobre o falte lo asume el grupo** |
| P-146 | Se cobra en **efectivo**, por **ingreso o transferencia**, por **Bizum** y por **domiciliación SEPA** (con esto se responde en parte la P-44: la remesa también cobra actividades) |
| P-147 | **Tesorería valida el presupuesto** antes de publicar. En los gastos: **justificantes**, **reembolsos a scouters**, **anticipos de caja** y **uso del fondo de emergencia** |
| P-148 | El listado con salud y alimentación lo ven el **equipo del evento**, los **scouters de las secciones que van**, **Secretaría** y **Coordinación**, y **se puede imprimir**. Queda registrado quién lo consulta |
| P-149 | Habrá un **responsable de material** (responsabilidad nueva de un scouter). Hay **material del grupo y de cada sección**. Ni importación (no hay inventario previo) ni préstamos a otros grupos |
| P-150 | Lo cifrado de la inscripción (quién recoge al menor, observaciones) **se borra al cerrar** el evento. La firma de la autorización se queda con el evento |
| P-151 | En los **eventos externos**, el grupo inscribe, cobra y saca la lista para el organizador. **Además**, los eventos federativos **se leen del CRM** para no crearlos a mano |
| P-152 | Avisos: **email al publicar**, **al confirmar**, **recordatorio** antes del evento y **aviso push** de la app |
| P-153 | **Seguro:** el de la federación cubre accidentes y responsabilidad civil en todas las actividades. **Solo las internacionales** necesitan un seguro aparte |
| P-154 | **Cómo se comunica hoy:** el grupo **avisa por correo a ASDE Baleares** con **30 días de antelación**. El correo lleva: tipo de actividad y fechas; **director/a**; scouters titulados como **monitores o directores** de tiempo libre; **número** de voluntarios en prácticas y de apoyo; de **todos los scouters**, nombre completo, **DNI**, email y teléfono; **número de participantes** con su **rango de edades** y **distribución por género**; si **todas las personas mayores de 18** (educandos o scouters) tienen el **certificado de delitos sexuales**; y el **proyecto educativo** (con el programa) y la **evaluación de riesgos** |
| P-154 bis | **Qué se comunica:** las actividades de **2 o más noches** con **10 o más participantes menores de edad**. La comunicación se puede **enviar desde la web** o **descargar** para enviarla a mano; en los dos casos queda la fecha de envío |

## Primera fase (en la nube, 0.19.0) · lo que ya está (2026-10-15)

**Pantallas** (*Gestión del grupo → Eventos*; páginas `eventos` y `eventos/configuracion`):

- **Agenda**: los eventos que ve cada uno, con filtros por estado (próximos y en curso, ya celebrados, borradores, cancelados, todos), sección y tipo; en el móvil, en tarjetas. «Nuevo evento».
- **Ficha del evento** con tres pestañas y «Editar los datos»:
  - **Resumen**: la lista de comprobación (presupuesto, equipo dirigente, comunicación a ASDE Baleares, seguro, actividades de riesgo y agua), los datos y las acciones (publicar, volver a borrador, cancelar con motivo, borrar un borrador);
  - **Equipo**: director/a, monitores, en prácticas y apoyo, con lo que dice la ficha de cada uno (titulación, certificado, edad) y la ratio;
  - **Presupuesto**: partidas previstas con su reparto, previsión (personas del equipo y margen), el precio que sale por sección y la validación de Tesorería.
- **Configuración** (Coordinación y Tesorería): margen del fondo de emergencia, tipos de actividad (nombre, si nacen con coste, si se comunican, activos; se pueden añadir) y qué valores de `formaciontiempolibre_c` cuentan como título de director/a o de monitor/a (casillas si el CRM da la lista; si no, uno por línea).
- **Menú e inicio**: «Eventos» en *Gestión del grupo* (con el nº de presupuestos por validar para Tesorería, «Eventos (1)»), tarjeta «Eventos» y, en «Pendiente de revisar», los presupuestos por validar.
- Sin JavaScript (formularios normales); a 1280 y a 390 px sin desbordes (revisado con capturas de Chrome con datos inventados).

**Estados:** borrador → (presupuesto pendiente de Tesorería → presupuesto validado) → publicado, que se ve «en curso» y «celebrado» por las fechas. Se cancela con motivo; un borrador se borra (con su equipo y sus partidas); un publicado vuelve a borrador mientras no haya nadie inscrito.

**Permisos** (doc 02): `asde_eventos_gestion` (Scouter, Secretaría, Coordinación; sustituye a `asde_edit_section_events`, que no usaba nadie), `asde_eventos_grupo` (Coordinación: eventos de grupo y externos), `asde_eventos_economia` (Tesorería: validar y reabrir el presupuesto) y `asde_eventos_config` (Coordinación y Tesorería). Siempre con ámbito: un scouter gestiona los eventos de sus secciones y aquellos en cuyo equipo está.

**Precio** (`Eventos\Presupuesto`): el del apartado 4, con el ejemplo del plan como prueba (Lobatos 46 € y Scouts 57 €). Validado, queda guardado en el evento y no cambia; Tesorería lo reabre solo en borrador y sin inscritos.

**Normativa** (`Eventos\Normativa`, apartado 6): comprobación del equipo con **una** lectura en vivo del CRM para todo el equipo (`PersonaRepository::campos_varias`; formación y certificado no se guardan), ratio, comunicación a ASDE Baleares (2 o más noches y 10 o más menores previstos; fecha límite 30 días antes), seguro de las actividades internacionales, riesgo y agua. Todo avisa; lo único que bloquea es publicar un evento con coste sin el presupuesto validado.

**Datos:** `asde_eventos`, `asde_evento_inscripciones` (de momento, solo el equipo: nº Scouts de España, secciones y papel) y `asde_evento_partidas`, y la opción `asde_ap_eventos_<grupo>`. *Instalación y entorno* pasa a **17 tablas**. Sin datos personales en claro. Auditoría: `evento_creado`, `evento_editado` (nombres de campos), `evento_equipo_*`, `evento_partida_*`, `evento_presupuesto_*`, `evento_publicado`, `evento_despublicado`, `evento_cancelado`, `evento_borrado` y `eventos_configurados`.

**Textos:** 279 nuevos, en los cuatro idiomas (catalán, euskera y gallego, primera versión sin revisión nativa, P-46).

**Manual de uso:** apartado «Eventos» en los manuales de scouters y del equipo de coordinación (sin capturas todavía: se hacen con `manual-capturas.sh`).

**Pruebas:** `tests/eventos.php`, **92 comprobaciones** (configuración, precio, normativa, quién crea y gestiona, editar, equipo, presupuesto y validación, estados, pantallas, CRM caído, sin datos personales); doc 10.

**Supuestos de esta versión (para confirmar):**

1. Mientras no haya inscripciones, la ratio cuenta las **plazas** (o, si no hay, los participantes previstos) y la comunicación cuenta como **menores** los previstos de Castores a Escultas (los Rovers suelen ser mayores de edad). Con las inscripciones (segunda fase) se contará con las fechas de nacimiento.
2. **Tesorería**, que siempre es scouter, también crea y gestiona los eventos de su sección.
3. **Secretaría** gestiona los eventos de sección de todo el grupo, pero no crea los de grupo ni los externos (eso es Coordinación).
4. Hasta que se elijan en *Configuración* los valores reales de `formaciontiempolibre_c`, la titulación se deduce del texto del valor («director», «monitor»).
5. Un borrador se borra con su equipo y su presupuesto; uno publicado no se borra (se cancela).

**Para producción:** al subir la v0.35.0 (las dos fases a la vez; ver «Integración en la v0.35») se crean las tres tablas y las páginas «Eventos» y «Configuración de eventos». Conviene entrar en *Eventos → Configuración* y marcar qué valores de la formación de tiempo libre del CRM son de director/a y de monitor/a.

## Segunda fase (en la nube, 0.19.1) · lo que ya está (2026-10-15)

Petición del usuario: «Sigue con la siguiente» (la segunda fase de este plan).

**Para las familias y los asociados: «Mis eventos»** (*Mi espacio* / *Mi familia*; página `mis-eventos`):

- Los eventos publicados (que no han acabado) a los que están convocados la persona o los menores que representa (su sección y su edad el día que empieza), con fechas, lugar, mapa, información práctica (salida, llegada, descripción, material) y el **precio** de su sección.
- Por persona: su estado (sin respuesta, pendiente, confirmada, plaza ofrecida, lista de espera con su puesto, no participa, baja) y **lo que falta** (la autorización; pagar tanto, con la referencia) y cómo pagar (texto de la configuración).
- **Inscribir** (en un desplegable, sin JavaScript): transporte de ida y de vuelta (con el grupo o por su cuenta), personas autorizadas a recoger al menor y observaciones para el equipo (las dos **cifradas**), la casilla «he revisado la ficha de salud y alimentación» (con el enlace a la ficha para proponer cambios) y, la familia, el **texto de la autorización** con la casilla «la firmo»: **inscribir es firmar** (P-142).
- **Preinscripción de 14 a 17**: el asociado/a se apunta desde su cuenta y queda pendiente hasta que su familia **firma** desde la suya («Firmar la autorización») o la entrega en papel. **Mayores de edad**: se inscriben sin autorización.
- **Convocatoria**: además de inscribir, «No va» (queda como «no participa»; se puede cambiar de idea mientras el plazo está abierto).
- **Plaza ofrecida**: «Confirmar la plaza». **Baja**: mientras el plazo está abierto («Darse de baja», o salir de la lista de espera); después, con el equipo.
- En el **menú**, «Mis eventos (n)» con lo pendiente (autorizaciones por firmar, plazas por confirmar, convocatorias por responder); en el **inicio**, el aviso con esas tres cosas y la tarjeta «Mis eventos».
- En **«Mis pagos»**: los pagos de cada evento y lo que queda, con la referencia («E6840012 F684001» o, sin familia en Tesorería, el nº de inscripción «I68400087»).

**Para el equipo: pestaña «Inscripciones»** del evento (entre «Equipo» y «Presupuesto»):

- Modalidad, plazo, plazas ocupadas por sección y en total, y el recuento (confirmadas, pendientes, en espera, bajas).
- **Participantes** (tabla que en el móvil son tarjetas): sección, transporte, quién recoge y observaciones (solo quien gestiona el evento), estado y lo que falta, la autorización (firmada en la web —fecha y versión— o en papel) con «Marcar la de papel como recibida», el pago y «Dar de baja» con motivo.
- **Lista de espera** por orden (con la plaza ofrecida y hasta cuándo), **convocados sin respuesta** (o sin inscribir), **bajas y «no va»** (con lo que habían pagado: la devolución, en la v0.36) e **«Inscribir a alguien»**: de las secciones convocadas o, como excepción y **con motivo**, otra persona del grupo.
- **Tesorería**: «Registrar un pago» (importe, forma —efectivo, ingreso o transferencia, Bizum—, fecha, referencia, nota), «Beca o ajuste» (rebaja o recargo, con motivo), los movimientos con «Anular» y el **pago de una familia** (un solo pago para los hermanos, que se reparte por orden de inscripción; anularlo lo anula entero).
- En el **inicio**, «n autorizaciones de eventos por recoger en papel».

**Formulario del evento:** modalidad (abierta o convocatoria) y plazo de inscripción (desde y hasta, opcionales; sin «hasta», hasta que empieza). Si se suben las plazas, se ofrecen solas a la lista de espera.

**Configuración:** el **texto de la autorización** (vacío = el del plugin, que se traduce; cada cambio sube su **versión**, y cada firma guarda la que firmó: «v3» o «v4-propio»), cómo pagar los eventos y cuántos días antes sale el recordatorio (3; 0 = no).

**Reglas** (`Eventos\Inscripciones`, `Eventos\Cobros`):

- **Confirmada** = autorización (web, papel o no hace falta) + pagada (o sin coste). Si se anula un pago, vuelve a pendiente (sin repetir el email).
- **Plazas**: las ocupan las pendientes, las confirmadas y las plazas ofrecidas. Sin plaza (en la sección o en total), a la **lista de espera**. Cuando se libera una (baja, más plazas), se ofrece por orden con **3 días** para confirmarla (como mucho, hasta que empieza); si no, se pierde (baja automática con su motivo) y pasa a la siguiente.
- **Precio**: el de su sección, fijo desde que Tesorería validó el presupuesto. **Becas y ajustes** cambian solo esa inscripción, con motivo; una rebaja no puede pasar del precio.
- **Ratio y comunicación a ASDE Baleares**: cuentan a los inscritos (pendientes y confirmados; menores = sin 18 años el día que empieza, guardado al inscribir, sin consultar el CRM): mientras el plazo está abierto, lo mayor entre la previsión y los inscritos; cerrado, los inscritos.

**Avisos** (`Eventos\Avisos`, P-152): emails al **publicar** (a las cuentas de las personas convocadas, en segundo plano con WP-Cron y una sola vez), al **confirmar**, cuando se **libera una plaza**, el **recordatorio** (tarea diaria, con lo que falte) y al **cancelar** (a quien estaba inscrito o en espera). Cada email sale en el idioma de quien lo recibe y queda en el registro de emails (tipo «Eventos», sin nombres ni direcciones; con vista previa). Cada aviso dispara el enganche **`do_action( 'asde_ap_evento_aviso', $tipo, $evento_id, $user_ids )`** para que la app móvil mande el aviso push (este plugin no usa clases de la app). La tarea diaria también caduca las plazas ofrecidas y ofrece las libres.

**Datos:** `asde_evento_inscripciones` gana columnas (estado, sección, precio, quién inscribió, motivo de la excepción, lista de espera y oferta, autorización —quién, cuándo, versión y huella HMAC de la IP, no la IP—, transporte, fecha de la revisión de la ficha de salud, `datos_enc` cifrado, confirmación y baja); `asde_eventos`, modalidad, plazo y las marcas de los avisos; tabla nueva **`asde_evento_movimientos`** (pagos y ajustes; nada se borra, se anula). *Instalación y entorno*: **18 tablas**. Página nueva «Mis eventos». Capability nueva **`asde_eventos_inscribir`** (todos los roles, doc 02). La salud no se copia nunca.

**Auditoría:** `inscripcion_creada`, `inscripcion_espera`, `inscripcion_confirmada` / `_pendiente`, `inscripcion_no_participa`, `inscripcion_plaza_ofrecida` / `_aceptada`, `inscripcion_oferta_caducada`, `inscripcion_baja`, `autorizacion_firmada`, `autorizacion_papel`, `evento_pago`, `evento_pago_familia`, `evento_ajuste`, `evento_movimiento_anulado` (nombres de campos e ids, nunca valores ni motivos).

**Textos:** 206 nuevos, en los cuatro idiomas (primera versión sin revisión nativa; los más visibles: el texto de la autorización y los emails).

**Pruebas:** `tests/eventos-inscripciones.php`, **91 comprobaciones** (configuración y versión de la autorización, aviso al publicar, «Mis eventos», la familia inscribe y firma, datos cifrados y auditoría sin valores, plazas y lista de espera con su oferta y su caducidad, preinscripción de 14 a 17, mayores de edad, convocatoria, el equipo inscribe y marca el papel, excepciones con motivo, cobros de Tesorería y de la familia, becas, anular, «Mis pagos», plazo cerrado y bajas, pantallas, normativa, recordatorio, cancelación, registro de emails, nonce y datos personales). Ajustadas `menus.php` (la familia tiene «Mis eventos»), `entorno.php` (18 tablas) y `eventos.php` (borra los avisos que deja programados).

**Supuestos de esta versión (para confirmar):**

1. **3 días** para confirmar una plaza que se libera (nunca más allá del inicio). Si no se confirma, la inscripción pasa a **baja** (con el motivo «No se confirmó a tiempo la plaza ofrecida») y no vuelve al final de la lista.
2. Una **excepción** (alguien de otra sección, con motivo) ocupa plaza en la **primera sección convocada** y paga el **precio más alto** del evento; Tesorería lo ajusta si hace falta.
3. Una inscripción **pendiente de pago ocupa plaza** (si no, se podría pasar del máximo).
4. Sin fechas, el plazo va **de la publicación al inicio**; el equipo puede inscribir y dar de baja **hasta que acaba** el evento.
5. El email de publicación va a las **cuentas** (familiares y de asociados de 14 años o más) de las personas convocadas, no a los emails de la ficha de quien no tiene cuenta. Si hace falta avisar a quien no tiene cuenta, se hace a mano.
6. El **recordatorio** sale una vez, 3 días antes (configurable), a las inscripciones pendientes y confirmadas, con lo que les falte.
7. Quién recoge y las observaciones las ve **quien gestiona el evento**, no Tesorería (salvo que esté en su equipo).
8. Un **scouter** solo ve «Mis eventos» si hay algún evento para él (una formación, p. ej.); las familias y los asociados de una sección educativa, siempre.

**Para producción:** al subir la v0.35.0 se crean la tabla de cobros y la página «Mis eventos» y se añaden las columnas nuevas (sin tocar los datos). Conviene revisar en *Eventos → Configuración* el **texto de la autorización** (o dejar el del plugin), **cómo pagar** los eventos y el recordatorio. Los eventos publicados en la 0.19.0 no mandan el email de publicación (solo los que se publiquen desde ahora).

## Integración en la v0.35 (2026-10-15, rama `Eventos`)

Lo que se cambió al traer el módulo de la nube (base v0.18) a la línea de la v0.34:

- **Numeración:** la 0.19.0 y la 0.19.1 de la nube son la **v0.35.0**; el doc 32 es el **43** (el 32 es el inventario); P-61…P-76 son **P-139…P-154**; la fase «Inventario» del plan pasa a «Material del evento» (v0.39), sobre el inventario que ya existe.
- **Módulo «Eventos»** en `Domain\Modulos` (wp-admin → Grupos Scouts → Módulos), **apagado por defecto** (`POR_DEFECTO_APAGADOS`, como la lista de espera): sin él no salen el menú, el inicio ni «Mis eventos», no se dan las capacidades `asde_eventos_*` y el mantenimiento diario no manda recordatorios (`Avisos::diario()` solo con el módulo encendido). `tests/eventos.php` y `tests/eventos-inscripciones.php` lo encienden para su grupo y lo dejan como estaba; `modulos.php` y `menus.php` cuentan con él apagado.
- **Roles:** las capacidades de eventos, en los roles de la v0.34 (Scouter, Secretaría, Coordinación, Tesorería, Tutor y Participante). Quitada `asde_edit_section_events`, que no usaba nada (la sincronización de roles quita las que sobran).
- **«Mis pagos»** enseña también los pagos de los eventos: se queda mientras el grupo use Tesorería, Uniformes **o Eventos** (`Modulos::caps_quitadas()`; `tests/modulos.php` 24).
- **Inicio:** se conserva `Servicio::propios()` de la v0.34; lo pendiente de eventos (`eventos_validar`, `eventos_papel`, `eventos_pendientes`) se suma a «Pendiente de revisar».
- **Emails:** tipo `eventos` en el registro de emails, con su vista previa; el envío pasa por `RegistroEmails::enviar()`, como los demás avisos.
- **Plugin Check y revisión de WordPress.org:** la línea `phpcs:disable` en las 11 plantillas, `%i` para las tablas (15 consultas), salida escapada (unos 50 sitios), el *hook* `asde_ap_evento_aviso` con su nombre literal y las confirmaciones con `data-asde-confirmar` en vez de `onsubmit` (6 formularios), y la línea `phpcs:disable` de consultas directas en las cuatro tablas. Plugin Check completo (WP-CLI): 0 hallazgos.
- **Textos:** 475 nuevos, con sus traducciones a ca, eu y gl sacadas de los catálogos de la nube (uno, la descripción del módulo, traducido a mano).
- **Ayuda y manual:** `data-paginas` en los apartados de la nube (páginas `eventos`, `eventos_config` y `mis_eventos`), el módulo en «Módulos de cada grupo» y la captura 80 rehecha. Las pantallas de eventos aún no tienen capturas.
- **Skills de la nube** (`.claude/skills/`: entregar-version, modulo-nuevo, probar-en-la-nube y traducir-textos), traídas tal cual; el `CLAUDE.md` las cita.

**Para producción (v0.35.0):** al actualizar se crean las cuatro tablas (eventos, inscripciones, partidas del presupuesto y movimientos) y las páginas «Eventos», «Configuración de eventos» y «Mis eventos». El módulo nace **apagado**: para usarlo, encenderlo en wp-admin → Grupos Scouts → Módulos y después revisar *Eventos → Configuración* (qué formación cuenta como título de director/a y de monitor/a, texto de la autorización, cómo pagar y el recordatorio).

## Lo que ya hay y condiciona el diseño

| Pieza | Qué aporta al módulo |
|---|---|
| **CRM: `stic_Events` y `stic_Registrations`** (doc 04 §2 y §7.5) | El CRM federal ya tiene **eventos** (23; `type`: festivalasde, festivalof, as, campamento, Curso, taller, jornada, reunion) e **inscripciones** (5.091; `status`: participa, noparticipa, confirmado, cancelado, lista_espera, no_se_presenta; `participation_type`: asistente, organización, voluntario, coordinador…). El campo `special_needs` puede llevar **datos de salud** |
| **CRM: campos de la ficha** (doc 04 §7) | `cesionimagen_c` (cesión de imagen), `formaciontiempolibre_c`, `formacionscout_c`, `certificadods_c` y `certificadods_fecha_c` (certificado de delitos sexuales). Sirven para las **comprobaciones del equipo dirigente** y para la **comunicación a ASDE Baleares** (apartado 6). El DNI (`stic_identification_number_c`) y el género (`stic_gender_c`) **no** están en la instantánea: se leen en vivo solo para esa comunicación |
| Matriz de permisos (doc 02) y `Roles` | La fila «Ficha de participación en eventos» y la capability `asde_edit_section_events`, que **no usa ningún código** |
| **Uniformes** (doc 24) | «Una cosa por socio/a con comprobaciones, cada una con quién y cuándo»; nº con prefijo (`U6840007`); pago validado por Tesorería; «Mis pagos»; ventana emergente; CSV con auditoría |
| **Tesorería** (doc 14) | Familias `F684001`, **ajustes con motivo**, movimientos que **no se borran (se anulan)**, ronda de septiembre a agosto; **gastos** pendientes de diseñar (P-24) |
| **SEPA** (doc 25) | La **firma electrónica** de un texto versionado (quién, cuándo, versión, HMAC de la IP) y las **remesas**, que también cobrarán eventos |
| Salud (doc 08) e `Instantanea` (doc 26) | La salud **nunca se guarda**. Nombres y contactos, de la instantánea, sin consultas al CRM por fila |
| App móvil (otro plugin) | Avisos push con un **enganche genérico** (`do_action`), sin clases de la app aquí |

---

## 1. Tipos de evento

Los tipos son un **catálogo configurable por grupo**, precargado con estos ocho. Un tipo es una **plantilla de valores por defecto**: en cada evento se puede cambiar todo.

| Clave | Tipo | Ejemplos | Pernocta | Autorización familiar | Coste | Listado con salud | Material | Comunicación a ASDE Baleares (apartado 6) | `type` del CRM |
|---|---|---|---|---|---|---|---|---|---|
| `salida` | **Salida o excursión** | Excursión de día, visita | No | Sí | Según el caso | Sí | Opcional | No (sin pernocta) | jornada |
| `acampada` | **Acampada** | Fin de semana en albergue o terreno | Sí (1–3 noches) | Sí | Sí | Sí | Sí | **Automática** | campamento |
| `campamento` | **Campamento** | Verano, Pascua | Sí (4 o más) | Sí | Sí | Sí | Sí | **Automática** | campamento |
| `ruta` | **Ruta o travesía** | Ruta de Escultas o Rovers | Sí | Sí | Sí | Sí | Sí | **Automática** | campamento |
| `grupo` | **Actividad de grupo** | Inicio y fin de ronda, promesas, pasos de sección, aniversario | Opcional | Según el caso | Opcional | Si hay pernocta | Opcional | **Automática** | jornada |
| `servicio` | **Servicio o voluntariado** | Limpieza de un espacio natural, recogida de alimentos | No | Sí | No | Sí | Opcional | No (sin pernocta) | jornada |
| `externo` | **Evento externo** | Festival ASDE, actividad de Scouts de Balears, Jamboree, otro grupo | Según el caso | Sí | Sí | Sí | Opcional | No (la hace quien organiza) | festivalasde / festivalof |
| `formacion` | **Formación** | Curso de monitor/a, primeros auxilios | Según el caso | No (adultos) | Opcional | No | No | No (adultos) | Curso / taller |

- **Comunicación «automática»** (P-154 bis): la web la pide cuando el evento tiene **2 o más noches** y **10 o más participantes menores de edad**. Se puede marcar o desmarcar a mano en cada evento, con aviso.
- **Internacional:** casilla en el evento. Si se marca, hace falta un **seguro aparte** (P-153).
- **Fuera del módulo** (P-140): las reuniones semanales de sección y las de adultos (Kraal, Consejo de Grupo, asamblea de familias).
- **Duración:** fecha y hora de inicio y de fin. La pernocta se deduce de las fechas (si acaba otro día).
- **Ámbito del evento:**
  - de una sección;
  - de varias secciones;
  - de grupo;
  - externo (lo organiza otra entidad y el grupo gestiona solo **lo suyo**).
- **Estados del evento:**

| Estado | Qué se puede hacer |
|---|---|
| **Borrador** | Lo ven sus responsables, Coordinación y Tesorería. Se prepara el presupuesto |
| **Presupuesto validado** | Tesorería lo ha validado y el **precio queda fijo** (solo en los eventos con coste) |
| **Publicado · inscripción abierta** | Lo ven las secciones convocadas y las familias se inscriben. Sale el email de publicación |
| **Inscripción cerrada** | Solo los gestores inscriben o dan de baja, con motivo |
| **En curso** | Pasar lista y sacar el material |
| **Celebrado** | Devolver el material, apuntar los gastos reales, liquidar anticipos, devoluciones |
| **Cerrado** | Tesorería cierra las cuentas: ya no se tocan. Se borra lo cifrado de las inscripciones (P-150) |
| **Cancelado** | Con motivo. Se avisa a las inscritas y Tesorería decide las devoluciones |

- **Datos de cada evento:**
  - nº de evento: **`E` + grupo + correlativo**, p. ej. `E6840012`;
  - nombre, tipo y ámbito;
  - fechas, lugar (texto y enlace a un mapa), punto y hora de salida y de llegada;
  - circular (texto y PDF opcional) y **lista de material personal**;
  - **equipo dirigente** (apartado 6);
  - secciones convocadas, plazas y plazo de inscripción;
  - presupuesto y precio por sección.

## 2. Secciones que pueden participar

Salen del catálogo de siempre (`Domain\Secciones`, doc 05). Cada evento guarda las **secciones convocadas**.

| Sección | Papel en el evento | Paga |
|---|---|---|
| Castores, Lobatos, Scouts, Escultas, Rovers | **Participantes** (educandos) | Sí: el precio de su sección |
| Scouters | **Equipo dirigente**: director/a, monitores, personas en prácticas. En una formación, **participantes** | No. Su coste se reparte entre los participantes (P-145) |
| Voluntariado de apoyo | **Equipo de apoyo** (cocina, intendencia, transporte) | No. Su coste también se reparte |

**Reglas:**

1. Solo se convoca o inscribe a personas con **membresía activa** en el grupo (`Auth\Ambito`). **Un grupo no ve los eventos de otro** (cada evento lleva su nº de grupo).
2. **Secciones convocadas:** una o varias, con **plazas previstas** por sección (sirven para calcular el precio) y **plazas máximas** opcionales.
3. **Edad** opcional, mínima y máxima, el día de inicio: para actividades de mayores de edad o para quienes **pasan de sección**.
4. **Excepciones:** el responsable, Coordinación o Secretaría pueden inscribir a alguien de otra sección, **con motivo** y con constancia en la auditoría.
5. **Ratio y equipo:** comprobaciones de la normativa balear (apartado 6). **Avisan, no bloquean.**
6. **Quién gestiona un evento** (P-141):
   - de sección o de varias, **los scouters de esas secciones** y el equipo del evento;
   - de grupo y externos, **Coordinación** y el responsable designado;
   - Secretaría y Coordinación ven **todos** los del grupo, y Tesorería, la parte económica de todos.
7. **Eventos con otro grupo:** fuera de alcance. Cada grupo crea el suyo.

## 3. Gestión de la inscripción

### Dos modalidades

| Modalidad | Cómo funciona | Para |
|---|---|---|
| **Inscripción abierta** | La familia entra en «Mis eventos» y apunta a sus menores | Acampadas, campamentos, salidas |
| **Convocatoria** | La lista sale sola de las secciones convocadas. Cada familia responde **sí o no**, y el equipo ve **quién no ha contestado** | Actividades a las que se espera a todos (promesas, inicio de ronda) |

### Quién inscribe a quién (P-142)

| Quién | A quién | Autorización |
|---|---|---|
| Cuenta familiar (`nº + T`, `nº + T2`) | Sus menores | **Inscribir ya es firmar**: la inscripción lleva la firma de la autorización |
| Asociado/a **de 14 a 17** | A sí mismo, como **preinscripción** | Queda **pendiente** hasta que la familia firma desde su cuenta o entrega el papel |
| Asociado/a **mayor de edad** | A sí mismo | No hace falta |
| Scouter de la sección, Coordinación, Secretaría | A los de su ámbito, «en nombre de la familia» | **Papel** entregado: quien lo recibe marca «autorización en papel recibida» (quién y cuándo) |
| Responsable del evento | Al **equipo** (scouters y voluntariado) | No hace falta (son adultos) |

### Qué se pide al inscribirse (solo lo que no está en el CRM)

- **Autorización de participación** (la exige el art. 21.3.g de la Ley 10/2022). Se firma en la web, con un texto versionado y configurable; queda quién, cuándo, la versión y el HMAC de la IP, como en la SEPA. O se entrega en papel (P-143).
- **Transporte:** con el grupo o por su cuenta, a la ida y a la vuelta.
- **Personas autorizadas a recoger** al menor (nombre y documento). Va **cifrado** y se borra al cerrar el evento.
- **Observaciones para el equipo.** Van cifradas y se borran al cerrar.
- La casilla **«He revisado la ficha de salud y alimentación y está al día»**, con un enlace para proponer cambios (cola de cambios, doc 17). **La salud no se copia al evento.**
- **Imagen:** no se pide. Se usa la cesión de imagen de la ficha (`cesionimagen_c`), y el listado del equipo marca a quien **no** la tiene.

### Estados de una inscripción (y su equivalente en el CRM)

| Estado | Cuándo | CRM |
|---|---|---|
| Convocado | Convocatoria sin respuesta | (no se copia) |
| **Pendiente** | Falta la autorización (preinscripción de 14 a 17) o el pago | confirmado |
| **Confirmada** | Autorizada y pagada (o sin coste). Sale el email de confirmación | confirmado |
| Lista de espera | No quedaban plazas | lista_espera |
| No participa | Ha respondido «no» a la convocatoria | noparticipa |
| Baja | La familia o un gestor la cancela | cancelado |
| **Participa** / No se presenta | Al pasar lista el día del evento | participa / no_se_presenta |

### Plazas, plazos y bajas

- **Plazas máximas** en total y por sección. Cuando se llenan, las nuevas inscripciones entran en **lista de espera**, por orden. Si se libera una plaza, se ofrece a la primera de la lista por email, con **3 días para confirmar** *(supuesto)*. Si no confirma, pasa a la siguiente.
- **Plazo de inscripción:** fecha de apertura y de cierre. Pasado el cierre, solo inscriben los gestores.
- **Baja:** la familia, hasta el cierre; después, un gestor con motivo. **La devolución la decide Tesorería** (P-145).

### Listados para el equipo (lo más sensible del módulo)

- **Participantes** por sección: estado, pago, autorización, transporte y cesión de imagen.
- **Contactos de emergencia:** tutores y teléfonos, sacados de la instantánea.
- **Salud y alimentación:** se leen **en vivo** del CRM, en una sola lectura, y **nunca se guardan**. Pueden verlas e **imprimirlas** el equipo del evento, los scouters de las secciones que van, Secretaría y Coordinación (P-148). Cada consulta e impresión queda en la auditoría.
- **Resumen para cocina:** recuento por tipo de régimen y alergias alimentarias.
- **Lista para el autobús** y de quién recoge a quién.
- **Imprimir** (en el campamento puede no haber cobertura) y **CSV**. Cada descarga queda en la auditoría y lleva el aviso de que contiene datos de salud.
- **Comunicación a ASDE Baleares** con el equipo dirigente y los recuentos (apartado 6).

### El día del evento

- **Pasar lista** desde el móvil: participa o no se presenta, y llegada y salida (quién lo recoge).
- Bajas de última hora, con motivo.

### Avisos (P-152)

- **Emails** (anotados en el registro de emails):
  - al **publicar**, a las familias de las secciones convocadas;
  - al **confirmar** una inscripción, o cuando alguien pasa de la lista de espera a tener plaza;
  - un **recordatorio** unos días antes, con la información práctica y lo que falte (pagar, autorizar);
  - si se **cancela** el evento.
- **Avisos push:** enganche `do_action( 'asde_ap_evento_aviso', … )`. El plugin de la app los manda con el texto genérico de siempre.
- **«Mis eventos»** (Mi espacio / Mi familia): los próximos eventos, inscribirse, el estado, **lo que falta** y la información práctica.
- En **Inicio**, «Pendiente de revisar»: inscripciones por revisar, autorizaciones en papel por marcar, presupuestos por validar.

## 4. Gestión económica

### Cómo sale el precio (P-145)

1. El responsable del evento prepara el **presupuesto de gastos**. Cada partida tiene una forma de reparto:
   - **importe total para todos**: transporte, alojamiento, intendencia común;
   - **importe total para una sección**: el albergue de los Castores, una actividad solo de Scouts;
   - **importe por persona**: por participante o por miembro del equipo (cuota del organizador de un evento externo, comida por persona).
2. **El coste del equipo se reparte** entre los participantes: lo que cuesta un scouter (su comida, su plaza, su transporte) entra como gasto y se divide entre los que pagan.
3. **Otros ingresos previstos** (subvención, aportación del grupo) se restan de lo que hay que repartir.
4. **Coste por participante de cada sección** = partidas comunes netas ÷ participantes previstos de todas las secciones + partidas de la sección ÷ participantes previstos de esa sección.
5. **Margen del fondo de emergencia:** precio = coste × (1 + margen). Por defecto está **activo y al 5 %**. El grupo lo configura (activo o no, y porcentaje) y cada evento lo puede cambiar antes de validar el presupuesto.
6. **Redondeo:** al euro entero hacia arriba; lo que sobra del redondeo va al fondo *(supuesto)*.
7. **Tesorería valida el presupuesto** (P-147) y el **precio por sección queda fijo** (P-145 bis). Si cambia el presupuesto, Tesorería puede volver a validarlo mientras **no haya inscripciones** *(supuesto)*. Después, el precio ya no cambia: lo que sobre o falte lo asume el grupo.
8. **Becas y ajustes:** Tesorería sube o baja el importe de una inscripción, **siempre con motivo**, como en la cuota. El precio de los demás no cambia.

Ejemplo: una acampada de 20 Lobatos y 15 Scouts (35 participantes) con 6 scouters.

| Partida | Reparto | Importe |
|---|---|---|
| Autobús | Todos | 420 € |
| Albergue (41 personas a 12 €) | Por persona, participantes y equipo | 492 € |
| Comida (41 personas a 15 €) | Por persona, participantes y equipo | 615 € |
| Actividad de escalada | Solo Scouts | 150 € |

- Partidas comunes: 420 + 492 + 615 = 1.527 €, entre 35 participantes: 43,63 € por cabeza.
- Escalada: 150 € entre los 15 Scouts: 10 € más para cada uno.
- Precio de Lobatos: 43,63 × 1,05 = 45,81 → **46 €**.
- Precio de Scouts: 53,63 × 1,05 = 56,31 → **57 €**.
- El albergue y la comida de los 6 scouters (162 €) quedan repartidos entre los 35 participantes.

### Cobro (P-146)

- Tesorería registra los **pagos** de una inscripción o **de la familia** (un solo pago para los hermanos): **efectivo**, **ingreso o transferencia**, **Bizum** y **domiciliación SEPA**.
- **Referencia para la transferencia o el Bizum:** nº de evento y nº de familia (`E6840012 F684001`) o, si no tiene familia, el nº de inscripción. Más adelante, el banco la concilia sola (doc 16).
- **Domiciliación SEPA:** el importe del evento entra en la **remesa** de las familias que tienen la autorización firmada (doc 25), con su aviso previo. Es la primera vez que la remesa cobra algo que no es la cuota.
- **«Mis pagos»** muestra lo que hay que pagar de cada evento, lo pagado y lo pendiente.
- **No entra en la cuota** de la ronda ni en el control de pagos (como el uniforme).
- **Devoluciones:** **las decide Tesorería** en cada baja o cancelación, con motivo. Cada evento puede describir su política en la circular. Se registran como movimiento negativo: **nada se borra, se anula**.

### Gastos, anticipos y fondo de emergencia (P-147)

- **Gastos reales:** fecha, partida del presupuesto, concepto, proveedor, importe, forma de pago y **justificante** (imagen o PDF, guardado fuera de la carpeta pública).
- **Quién lo pagó:**
  - el grupo (cuenta o caja);
  - un scouter, al que hay que **reembolsar**: queda pendiente hasta que Tesorería lo marca como reembolsado (fecha y forma).
- **Anticipos de caja:** dinero que Tesorería da al responsable antes del evento. Al volver se **liquida**: gastos con justificante + dinero devuelto = anticipo. La diferencia se avisa.
- **Fondo de emergencia:** lo que aporta el margen (y el redondeo). Los gastos imprevistos se pueden marcar **«con cargo al fondo»**. Al cerrar, lo que sobra se queda en el grupo *(supuesto, por P-145 bis)*.
- **Balance:** previsto y real por partida, lo cobrado y lo pendiente, el fondo (recaudado, usado y sobrante) y el resultado, que asume el grupo.
- **Cierre:** Tesorería cierra las cuentas, con el **informe final** (imprimir y CSV) para el Consejo de Grupo. Los gastos se diseñan para que la **Tesorería general** los reutilice (P-24).

### Quién hace qué

| | Responsable del evento | Tesorería | Coordinación | Secretaría |
|---|---|---|---|---|
| Presupuesto y margen | ✅ lo prepara | ✅ **lo valida** (fija el precio) | 👁 | 👁 |
| Registrar pagos y devoluciones | — | ✅ | — | — |
| Becas y ajustes | — | ✅ con motivo | — | — |
| Gastos | 📝 los propone con justificante | ✅ los valida y reembolsa | 👁 | 👁 |
| Anticipos de caja | 📝 lo pide y lo liquida | ✅ lo da y cierra la liquidación | 👁 | — |
| Cerrar las cuentas | — | ✅ | 👁 | 👁 |

## 5. Inventario asociado al evento

El inventario es **del grupo y de sus secciones** (P-149) y **permanente**. Cada evento **reserva** lo que se lleva.

### Catálogo de material

- **De quién es:** del **grupo** o de **una sección** (la Manada tiene su material de juegos; la Tropa, sus tiendas de patrulla).
- **Categorías** (configurables):
  - tiendas de campaña (canadiense, iglú, intendencia);
  - cocina (fogones, bombonas, ollas, neveras, depósitos de agua);
  - herramientas (palas, sierras, mazas, hachas);
  - pionerismo (cuerdas, troncos, piquetas);
  - iluminación (focos, frontales);
  - primeros auxilios (botiquines, extintores);
  - juegos y talleres;
  - banderas y mástiles;
  - electrónica (altavoz, proyector);
  - mobiliario (mesas, sillas, lonas, toldos).
- **Cada artículo:**
  - código **`M` + grupo + correlativo** (`M6840031`), para ponerle una etiqueta o un QR;
  - nombre, categoría y foto;
  - unidades, y si se sigue **una a una** (la tienda nº 3) o **a granel** (40 piquetas);
  - estado: bueno, regular, a reparar o de baja;
  - ubicación;
  - fecha de compra y valor (opcionales);
  - **caducidad** (botiquín, extintor, gas) y última revisión con notas.
- **Historial:** alta, revisiones, reparaciones y baja (con motivo).
- **Avisos en Inicio:** lo que caduca en 30 días y lo que está pendiente de reparar.
- **Sin importación** (no hay inventario previo) y **sin préstamos** a otros grupos (P-149).

### Material de un evento

1. **Lista de material** del evento: artículos y cantidades, con **plantillas por tipo** («material de acampada»).
2. **Aviso de conflicto** si otro evento en las mismas fechas reserva el mismo material y no hay unidades suficientes.
3. **Salida:** quién lo saca y cuándo.
4. **Devolución** con **incidencias** (falta, roto). El estado del artículo cambia y, si hay que reponerlo, se propone el **gasto** en las cuentas del evento (con cargo al fondo, si se quiere).
5. El **material personal** de cada participante no es inventario: es un texto del evento.

- **No hay datos personales** en el inventario. De quién lo saca, solo el id de usuario. Las fotos van a la biblioteca de medios, como las de las insignias (sin SVG).

## 6. Normativa de tiempo libre en las Illes Balears (P-144: investigada y propuesta)

> Investigado el 2026-10-15 en fuentes oficiales. **No es asesoramiento legal**: conviene confirmarlo con Scouts de Balears o con el Servei de Joventut del Consell de Mallorca antes de la primera acampada gestionada con la web.

### Qué dice la norma

**Fuentes:**
- **Ley 10/2022**, de 23 de diciembre, de tiempo libre educativo para la infancia y la juventud de las Illes Balears ([BOE-A-2023-2979](https://www.boe.es/buscar/act.php?id=BOE-A-2023-2979));
- **Decreto 23/2018**, de 6 de julio (título III, arts. 37–54, con modificaciones hasta 2026; [normativa del Servei de Joventut](https://www.caib.es/sites/joventut/ca/normativa_i_documents_dinteres-43982));
- la guía del **Consell de Mallorca** [«Equip dirigent de les activitats d'educació en el lleure educatiu infantil i juvenil»](https://joventut.conselldemallorca.es/documents/867081/9460530/EQUIP+DIRIGENT+DE+LES+ACTIVITATS+D%E2%80%99EDUCACI%C3%93+EN+EL+TEMPS+LLIURE+INFANTIL+I+JUVENIL_compressed.pdf/e14dbb89-f3e2-dcc7-18db-978c9f25f727?t=1710940527642);
- el trámite del Consell de Mallorca [«Comunicació de les activitats de lleure educatiu»](https://seu.conselldemallorca.net/ca/fitxa?key=30119).

| Tema | Regla | Dónde |
|---|---|---|
| **A qué se aplica** | Actividades de tiempo libre educativo con **menores desde los 3 años** en las Illes Balears. Quedan fuera las familiares, las extraescolares y el deporte federado. También entran las **actividades continuadas** (al menos 6 meses al año con inscripción permanente) | Ley 10/2022, arts. 2.1.b, 18.1, 18.2 y 18.6 |
| **Declaración responsable** | La presenta la entidad organizadora **al consell insular** (Mallorca: Joventut), **al menos 15 días naturales antes** del inicio, con la **relación del equipo dirigente**: identificación, titulación, función (director/a, monitor/a, en prácticas) y, para quien está en prácticas, la escuela | Ley 10/2022, art. 21.1 y 21.2 |
| **Condiciones de la actividad** | Proyecto educativo; personal cualificado en proporción a los participantes; **medidas de emergencia y evacuación** y **evaluación de riesgos**; **seguro de accidentes y de responsabilidad civil**; condiciones higiénicas, sanitarias y de seguridad; **autorización de madres, padres o tutores** de los menores | Ley 10/2022, art. 21.3 |
| **Equipo dirigente** | **Director/a responsable** y monitores, **presentes durante la actividad**. Todos **mayores de 18**, con la **titulación** del Decreto 23/2018 y el **certificado negativo del Registro Central de Delincuentes Sexuales de menos de 12 meses** | Ley 10/2022, art. 21.4; guía del Consell |
| **Ratio** | Además del director/a, **1 monitor/a por cada 10 participantes**, y otro si sobra una fracción de **5 o más** (el director/a no cuenta) | Decreto 23/2018 (art. 48); guía del Consell |
| **Titulación** | **Director/a:** diploma de director/a de tiempo libre (o certificado de profesionalidad de dirección, TS en animación sociocultural…). **Monitores:** al menos **la mitad** con diploma de monitor/a de tiempo libre (o certificado de dinamización…); el resto, con estudios universitarios o FP superior relacionados con la educación, el deporte o el ocio | Decreto 23/2018; guía del Consell |
| **En prácticas** | Como máximo tantas como el equipo titulado; **no cuentan** en la ratio y no cobran | Guía del Consell |
| **Dependencia reconocida** | **+1 monitor/a de apoyo por cada 3** participantes con grado I o II; **1 por participante** con grado III | Decreto 23/2018 (art. 48.9, modificado en 2026) |
| **Actividades de riesgo** | Alta montaña, barrancos, escalada, buceo, náutica: con **personal titulado** en esa actividad. En **piscina o mar**: **socorrista** presente | Guía del Consell |
| **Prohibiciones** | Tabaco, alcohol y otras sustancias adictivas; fomentar conductas adictivas o apuestas | Ley 10/2022, art. 22 |
| **Protección de la infancia** | Obligaciones de la LO 8/2021; la entidad designa un **delegado/a de protección contra el maltrato** | LO 8/2021, art. 48; guía del Consell |

**Ojo:** según el art. 18.2, la **actividad semanal** del grupo puede ser una «actividad continuada» con su propia declaración. Eso queda **fuera del módulo** (P-140), pero conviene que el grupo lo compruebe.

### Cómo lo hace hoy el grupo (P-153 y P-154)

- **No presenta la declaración al Consell.** El grupo **avisa por correo a ASDE Baleares** con **30 días de antelación**; el plazo legal de la declaración es de 15 días.
- Se avisa de las actividades de **2 o más noches** con **10 o más participantes menores de edad**.
- **Seguro:** el de la federación cubre accidentes y responsabilidad civil. **Solo las actividades internacionales** necesitan un seguro aparte.

### Qué hará la web

Todo **avisa, no bloquea**: la responsabilidad es de la entidad organizadora y la web solo ayuda a no olvidar nada.

1. **Comunicación a ASDE Baleares** (P-154):
   - **cuándo:** la web la pide sola si el evento tiene **2 o más noches** y **10 o más participantes menores** (inscritos, pendientes o confirmados, menores el día de inicio). Se puede marcar o desmarcar a mano, con aviso;
   - **fecha límite** = inicio − **30 días**: aviso en *Inicio* a los 40 días y en rojo pasada la fecha;
   - **qué lleva**, montado en el momento a partir del evento y del CRM:
     - tipo de actividad, fechas y lugar;
     - **director/a** de la actividad;
     - scouters **titulados como directores o monitores** de tiempo libre;
     - **número** de personas en prácticas y de voluntariado de apoyo;
     - de **todos los scouters** del equipo, nombre completo, **DNI**, email y teléfono;
     - **número de participantes**, **rango de edades** (la menor y la mayor el día de inicio) y **distribución por género** (solo recuentos);
     - si **todas las personas mayores de 18**, educandos (Rovers) o scouters, tienen el **certificado de delitos sexuales** vigente. A ASDE Baleares le llega «sí» o «no»; el equipo ve quién falta;
     - **adjuntos:** el **proyecto educativo** (con el programa) y la **evaluación de riesgos**, que se suben al evento (PDF, fuera de la carpeta pública);
   - **dos formas de enviarla** (P-154 bis):
     - **«Enviar a ASDE Baleares»**: vista previa y botón. Sale del correo del grupo hacia la dirección de ASDE Baleares (nueva en *Organización federada*), con copia al director/a y a Coordinación, y con los adjuntos;
     - **«Descargar para enviarla a mano»**: un PDF o una página para imprimir con lo mismo y los adjuntos aparte. Después se marca **«Enviada el …»**;
   - **datos personales:** el DNI y el género se leen **en vivo** del CRM solo para montarla y **no se guardan**. El **registro de emails** anota que se ha enviado, a quién y cuándo, **sin el texto**. La auditoría anota quién la envía o la descarga;
   - si el equipo o las inscripciones cambian después de enviarla, la web avisa de que **la comunicación ya no está al día** y deja volver a enviarla.
2. **Equipo dirigente:** se elige entre los scouters y el voluntariado del grupo, con su función: director/a, monitor/a, en prácticas o apoyo. La web lo **comprueba con la ficha del CRM**, sin copiarla:
   - **mayor de 18** el día de inicio (fecha de nacimiento);
   - **titulación** (`formaciontiempolibre_c`): director/a con titulación de dirección y al menos la mitad de los monitores con titulación de monitor/a. Los valores del campo se leen de los metadatos del CRM (`Metadatos`) al programarlo;
   - **certificado de delitos sexuales** (`certificadods_c`, `certificadods_fecha_c`) de **menos de 12 meses** el día de **fin** del evento. Se comprueba también a los **participantes mayores de 18** (P-154);
   - personas en **prácticas**: no más que las tituladas.
3. **Ratio automática:** monitores necesarios = máx(1, ⌊n/10⌋ + 1 si el resto de n/10 es 5 o más), con n = participantes confirmados (o plazas máximas mientras está abierto). No cuentan el director/a ni las personas en prácticas.
   - El director/a puede escribir **cuántos** participantes tienen dependencia reconocida (grado I-II y grado III). **Solo números, sin nombres**: la web suma los monitores de apoyo.
   - Ejemplo: 35 participantes → ⌊35/10⌋ = 3 y resto 5 → **4 monitores** además del director/a.
4. **Lista de comprobación** en el resumen del evento:
   - comunicación a ASDE Baleares (si hace falta): enviada o no, y si sigue al día;
   - proyecto educativo y evaluación de riesgos (adjuntos);
   - **seguro**: «cubierto por el seguro de la federación»; si el evento es **internacional**, pide el **seguro aparte** (compañía, póliza y justificante) (P-153);
   - casilla «hay actividades de riesgo especializado» → pide el profesional titulado;
   - casilla «hay agua (piscina o mar)» → pide el socorrista;
   - certificados de delitos sexuales y autorizaciones de las familias (cuántos faltan).
5. **Configuración:**
   - del grupo: nombre del **delegado/a de protección** (LO 8/2021), que sale en la circular;
   - de *Organización federada* (doc 20): **email para comunicar actividades** y días de antelación (30 por defecto).

---

## Permisos (capabilities propuestas)

Siempre **capability + ámbito** (doc 02): la sección del evento, el equipo del evento o la persona inscrita.

| Capability | Roles | Qué permite |
|---|---|---|
| `asde_eventos_inscribir` | Todos | «Mis eventos»: ver los eventos a los que está convocado/a (o sus menores), inscribir, firmar la autorización y dar de baja |
| `asde_eventos_gestion` | Scouter, Secretaría, Coordinación | Crear, publicar y gestionar eventos **en su ámbito** (un scouter, los de sus secciones y los de su equipo); marcar autorizaciones en papel; pasar lista. **Sustituye a `asde_edit_section_events`** |
| `asde_eventos_grupo` | Coordinación | Eventos **de grupo** y externos, y todos los del grupo |
| `asde_eventos_economia` | **Tesorería** | Validar el presupuesto, pagos, devoluciones, becas, gastos, reembolsos, anticipos y cierre. Coordinación y Secretaría lo ven en solo lectura |
| `asde_eventos_config` | Coordinación, Tesorería | Tipos, margen del fondo de emergencia, textos de la autorización, delegado/a de protección |
| `asde_inventario_ver` | Scouter, Secretaría, Coordinación, Tesorería | Ver el material y reservarlo para un evento que gestiona |
| `asde_inventario_gestion` | **Responsable de material**, Coordinación | Alta, revisión, reparación y baja de todo el material |

- **Rol nuevo «Responsable de material»** (`asde_material`, P-149): una **responsabilidad de un scouter**, como Tesorería (exige también el rol Scouter). Tiene `asde_inventario_gestion` y `asde_inventario_ver`.
- Los **scouters de una sección** gestionan el material **de su sección** *(supuesto)*.
- **Salud en los listados:** con `asde_view_health` y **el ámbito del evento** (P-148).

## Datos (qué se guarda en WordPress)

**Ningún dato personal en claro.** De las personas, solo el **nº de Scouts de España**; los nombres y contactos salen de la instantánea, y la salud se lee en vivo.

| Tabla / opción | Contenido |
|---|---|
| `{prefix}asde_eventos` | Grupo, nº (`E6840012`), tipo, ámbito, nombre, fechas, lugar, estado, secciones convocadas con sus plazas previstas y máximas, **margen**, **precio por sección congelado**, validación del presupuesto (quién y cuándo), **comunicación a ASDE Baleares** (si hace falta, enviada o descargada, quién y cuándo, y una huella de lo enviado para saber si sigue al día), **internacional** y su seguro aparte, adjuntos (proyecto educativo, evaluación de riesgos), lista de comprobación, nº de participantes con dependencia (solo el número), configuración y, en la fase del CRM, el id de `stic_Events` |
| `{prefix}asde_evento_inscripciones` | Evento, nº de Scouts de España, secciones, si es adulto, **papel** (participante, director/a, monitor/a, en prácticas, apoyo), estado, orden en la lista de espera, importe, **autorización** (firma web: quién, cuándo, versión, HMAC de la IP; o papel: quién lo recibió y cuándo), `datos_enc` **cifrado** (quién recoge, observaciones), asistencia, y quién y cuándo de cada paso |
| `{prefix}asde_evento_movimientos` | Pagos, becas y ajustes, devoluciones (inscripción o familia, importe, forma: efectivo, ingreso, Bizum o SEPA; referencia, nota, quién, anulación) |
| `{prefix}asde_evento_partidas` | Presupuesto y gastos: previsto o real, ingreso o gasto, **reparto** (todos, una sección, por participante o por persona del equipo), concepto, proveedor, importe, justificante, quién lo pagó, reembolso, **con cargo al fondo** |
| `{prefix}asde_evento_anticipos` | Anticipos de caja: a quién (id de usuario), importe, fecha y liquidación (gastado, devuelto, diferencia) |
| `{prefix}asde_material` | Catálogo de material: del grupo o de una sección |
| `{prefix}asde_material_historial` | Altas, revisiones, reparaciones, bajas e incidencias |
| `{prefix}asde_evento_material` | Material reservado por evento: cantidad, salida, devolución e incidencias |
| Opción `asde_ap_eventos_<grupo>` | Tipos, margen por defecto (activo, 5 %), plantillas de material, textos de la autorización, delegado/a de protección |

- **8 tablas nuevas:** *Instalación y entorno* pasa de 14 a **22** (y `tests/entorno.php`). `uninstall.php` las conserva (son justificantes de cobros y gastos) y borra la configuración.
- **Conservación** (P-150): al **cerrar** el evento se vacía `datos_enc`. La firma de la autorización (sin datos personales) se queda con el evento. Los justificantes de gastos se quedan como los de Tesorería.
- **Auditoría** (nombres de campos, nunca valores): `evento_creado`, `evento_presupuesto_validado`, `evento_publicado`, `evento_cancelado`, `inscripcion_*`, `autorizacion_firmada`, `autorizacion_papel`, `evento_pago`, `evento_gasto`, `evento_anticipo`, `evento_cerrado`, `evento_listado_salud`, `evento_comunicacion_enviada`, `evento_comunicacion_descargada`, `material_*`…

## CRM

- **Lectura de eventos federativos** (P-151): al crear un evento externo, se elige uno de `stic_Events` (nombre, fechas, tipo, lugar) para no escribirlo a mano. **Hay que comprobar** si el usuario del CRM de cada grupo ve los eventos federativos, porque el filtro por grupo de seguridad puede ocultarlos. Si no los ve, el evento externo se crea a mano.
- **Copia al CRM** (P-139, última fase):
  - una **segunda puerta de escritura** (`Crm\EscrituraEventos`), aparte de `Escritura` (que sigue sin crear registros): solo `stic_Events` y `stic_Registrations`, solo crear y actualizar, solo si `Permisos::puede( …, 'edit' )`, con auditoría y probada **solo con el CRM simulado**;
  - el evento va con el `type` de la tabla del apartado 1; cada inscripción, con el estado de la tabla del apartado 3 y `participation_type` asistente u organización;
  - **nunca** `special_needs` ni salud.

## Rendimiento (doc 26)

- Agenda, inscripciones, economía y material: **0 consultas al CRM** (nombres con `obtener_varias`, de la instantánea).
- Listado con salud y comprobación del equipo: **una** lectura en vivo por evento, nunca una por persona.

## Pantallas

| Dónde | Pantalla | Qué hay |
|---|---|---|
| Gestión del grupo → **Eventos** | Agenda | Eventos de la ronda en tarjetas (móvil) o tabla; filtros por estado, tipo y sección; «Nuevo evento» |
| | Evento (pestañas) | **Resumen** (datos, estado, lista de comprobación, ratio, comunicación a ASDE Baleares) · **Equipo** · **Inscripciones** · **Economía** · **Material** · **Listados** |
| | Configuración | Tipos, margen, textos de la autorización, delegado/a de protección, plantillas de material |
| Gestión del grupo → **Material** | Inventario | Catálogo del grupo y de las secciones, con filtros; ficha del artículo con su historial; caducidades |
| Mi espacio / Mi familia → **Mis eventos** | Mis eventos | Próximos eventos, inscribirse y firmar, estado, lo que falta, información práctica |
| Inicio | «Pendiente de revisar» | Inscripciones, autorizaciones en papel, presupuestos por validar, gastos y reembolsos, comunicaciones a ASDE Baleares a punto de vencer o desfasadas, material que caduca |

Con las convenciones de siempre: sin JavaScript funciona; con JavaScript, ventana emergente y sin recargar la pantalla entera; móvil primero; textos en los cuatro idiomas.

## Fases (una versión cada una, con sus pruebas)

| Versión | Qué | Pruebas |
|---|---|---|
| ✅ **0.35.0 (en la nube, 0.19.0) · Eventos, equipo y presupuesto** (hecha, 2026-10-15) | Tipos, crear, editar y cancelar; agenda; ámbito y permisos; nº de evento; **equipo dirigente con las comprobaciones de la normativa** (edad, titulación, certificado, ratio), internacional y seguro; **presupuesto con reparto y margen**, validación de Tesorería, precio fijo por sección; publicar | `tests/eventos.php` |
| ✅ **0.35.0 (en la nube, 0.19.1) · Inscripciones y cobro** (hecha, 2026-10-15) | Las dos modalidades, «Mis eventos», autorización (web o papel), preinscripción de 14 a 17, plazas y lista de espera, bajas; pagos en efectivo, por transferencia y por Bizum; «Mis pagos»; becas y ajustes; emails y enganche de los avisos push | `tests/eventos-inscripciones.php` |
| **0.36 · Gastos, SEPA y cierre** | Gastos con justificante, reembolsos, anticipos, fondo de emergencia, devoluciones, balance y cierre (borra lo cifrado); **SEPA de eventos** en las remesas | `tests/eventos-economia.php` |
| **0.37 · Listados, comunicación y asistencia** | Participantes, emergencia, salud en vivo, cocina, autobús, imprimir y CSV con auditoría; **comunicación a ASDE Baleares** (enviar o descargar, con los adjuntos y el email de *Organización federada*); pasar lista | `tests/eventos-listados.php` |
| **0.38 · Eventos externos del CRM** | Leer `stic_Events` federativos para crear el evento externo | `tests/eventos-externos.php` |
| **0.39 · Material del evento** (el inventario **ya existe** en esta línea, doc 32) | Sobre el inventario de la v0.19: material del evento con plantillas, conflictos, salida y devolución (y, si hace falta, el rol «Responsable de material», caducidades y material de cada sección) | `tests/eventos-material.php` |
| **0.40 · Copia al CRM** | `EscrituraEventos`: eventos e inscripciones a `stic_Events` / `stic_Registrations` | `tests/eventos-crm.php` |

Cada fase actualiza los docs (00, este, 02, 10 y 09) y los manuales de uso (`manual/fuentes/`). Para la mecánica repetida hay *skills* del proyecto en `.claude/skills/`: **`modulo-nuevo`**, **`entregar-version`**, **`traducir-textos`** y **`probar-en-la-nube`**.

## Supuestos (para confirmar)

1. Si se libera una plaza, la primera de la lista de espera tiene **3 días** para confirmar.
2. El precio se **redondea al euro hacia arriba**, y lo que sobra del redondeo va al fondo de emergencia.
3. Tesorería puede **volver a validar** el presupuesto (y cambiar el precio) mientras no haya inscripciones. Después, el precio queda fijo.
4. Lo que **sobra del fondo de emergencia** al cerrar se queda en el grupo, igual que el resultado del evento.
5. Los **scouters de una sección** gestionan el material de su sección; el responsable de material y Coordinación, todo.
6. La comprobación del equipo (edad, titulación, certificado) **avisa, no bloquea**. La ratio cuenta los participantes **confirmados** (o las plazas máximas mientras la inscripción está abierta).
7. Los cobros del evento van en **su propia tabla** y no cuentan en la cuota de la ronda.
8. `asde_eventos_gestion` **sustituye** a `asde_edit_section_events`, que no usa ningún código.
9. Para decidir si hay que comunicar el evento a ASDE Baleares, las **noches** son los días que hay entre la fecha de inicio y la de fin, y los **menores** son los inscritos (pendientes o confirmados) que no tienen 18 años el día de inicio.
10. **Internacional** quiere decir que la actividad es, toda o en parte, fuera de España.
11. La comunicación enviada guarda solo una **huella** (no el contenido) para avisar si el equipo o los recuentos cambian después.

## Preguntas abiertas

Ninguna: P-139 a P-154 están respondidas (tabla «Decisiones» y doc 09).

Lo que hay que **comprobar al programar** (no es una pregunta para el usuario):
- qué valores tiene `formaciontiempolibre_c` en el CRM (`Metadatos`);
- qué valores tiene `stic_gender_c`;
- si el usuario del CRM de cada grupo **ve los eventos federativos** de `stic_Events`.
