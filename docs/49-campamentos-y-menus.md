# 49 · Programador de acampadas y campamentos + Gestor de menús (análisis y plan)

> **Estado:** análisis y plan escritos el 2026-10-17; **decisiones del usuario recogidas el mismo día (apartado 9)**. **No hay código hecho.** Sale de las dos bases de conocimiento que dio el usuario («Siguiente módulo»), guardadas tal cual en `docs/fuentes-49/`:
>
> | Fichero | Qué es |
> |---|---|
> | `campamentos_conocimiento.md` / `.json` | El *Organizador de Acampadas y Campamentos* de ASDE (programador de acampadas, programador de campamentos y hoja de presupuestos). El `.json` trae las tareas con su desfase en días (`-60`, `fin+7`…) y los ejemplos con fechas, listos para sembrar y para probar |
> | `gestor_menus_conocimiento.md` / `.json` | El *generador de menús de eventos*: recetas, ingredientes con alérgenos, menú por día y comida, validación contra las alergias de los asistentes. Trae el catálogo de 14 alérgenos y la tabla de intolerancias |
>
> Ambos son **extensiones del módulo Eventos** (doc 43, v0.35–v0.36), que ya existe. Este documento dice qué ya está, qué falta y en qué orden hacerlo. La rama es `Campamentos-Menus`, desde `origin/main` (la v0.39 de la Tienda, doc 48, va en la rama `Tienda`).

## 1. Qué piden los ficheros

### 1.1 Organizador de acampadas y campamentos

| Pieza | Contenido |
|---|---|
| **Programador de acampadas** | 31 tareas: 28 con fecha límite = `T` (inicio) menos N días y 3 posteriores contadas desde el **final** (`Fin + 7` dos veces y `Fin + 14`). Pide: lugar, autobuses, gastos de secciones (hasta 5), actividad especial, asistentes por rama, títulos, equipo de cocina y comidas |
| **Programador de campamentos** | 35 tareas (32 antes y 3 después), con más antelación: reserva del lugar a `T − 120`, evaluación a `Fin + 60`. Hasta 15 gastos de intendencia, 4 actividades especiales y una partida por rama |
| **Hoja de presupuestos** | Ingresos y gastos de cualquier actividad: cuota = (gastos − ingresos seguros − otras cuotas + 5 % de margen) ÷ educandos, **redondeada al euro hacia arriba**; balance |
| **Reglas de uso** | «Hoy es día» y «Quedan (días)» se calculan solos; cada tarea se marca **hecha sí/no** y lleva **observaciones**; lugar: precio por persona y día **o** total, nunca los dos; el indicador de títulos tiene que estar en verde |

### 1.2 Gestor de menús

| Pieza | Contenido |
|---|---|
| **Recetas** | Nombre, tipo de plato, **porciones base**, elaboración, observaciones y lista de ingredientes (cantidad, unidad) |
| **Ingredientes** | Llevan sus **alérgenos** (y trazas); la receta los **hereda** de sus ingredientes. Coste unitario opcional |
| **Escalado** | Cantidad × (comensales ÷ porciones base) |
| **Menú del evento** | Por día y tipo de comida (desayuno, comida, merienda, cena): una o varias recetas; **lista de la compra** sumando lo escalado |
| **Validación** | Cruza los alérgenos del menú con las alergias, intolerancias y dietas de los **asistentes**; niveles *Conflicto*, *Posible riesgo* (trazas), *Sin conflictos*, *Datos incompletos*; cada aviso se resuelve o se confirma con constancia; no bloquea |
| **Privacidad** | Son datos de salud (art. 9 RGPD) y los asistentes son menores: detalle por persona solo a quien corresponde; cocina ve **alérgeno y nº de personas**, sin nombres; registro de quién consulta o confirma |

El `.md` de menús terminaba con «[PENDIENTE DE REPO]»: lo que faltaba (cómo se guardan eventos, inscritos, fichas de salud, roles y tecnología) está contestado en el apartado 3.

## 2. Qué ya hace la aplicación (no se repite)

| Lo que pide el fichero | Lo que ya hay (v0.35–v0.38) |
|---|---|
| Hoja de presupuestos: partidas con reparto, **margen del 5 %**, cuota redondeada al euro hacia arriba, aportaciones, cuota especial, balance | `Eventos\Presupuesto`, `Eventos\PartidasTable`: partidas **por persona, para todos o para una sección**, margen activable (5 %), redondeo al euro, **otros ingresos previstos**, becas y ajustes con motivo; pestañas *Presupuesto* y *Cuentas* (doc 43 §4). Es lo mismo, con más: validación de Tesorería y cuentas reales |
| Títulos suficientes, ratio de la Comunidad Autónoma | `Eventos\Normativa` con la **normativa de las Illes Balears** (1 monitor por 10 participantes, director/a, certificado de delitos sexuales…) y `formaciontiempolibre_c` leída **en vivo**, sin guardarla |
| Equipo: director/a, cocinero, intendente, ayudantes | Pestaña *Equipo* (director/a, monitores, en prácticas, apoyo) |
| Estimar nº de educandos y scouters por rama | Participantes previstos por sección + **inscripciones reales** |
| Recoger autorizaciones, totales, cobrar | Inscripciones, firma de la autorización, cobros de Tesorería y remesa SEPA |
| «Enviar la ficha a la OF» | Comunicación a ASDE Baleares (2 o más noches y 10 o más menores; 30 días antes) |
| Revisar alergias de los asistentes | Casilla «he revisado la ficha de salud y alimentación» al inscribir; **listado de salud y alimentación en vivo** para el equipo, con auditoría (P-148) |
| Carta a las familias, reunión de padres, publicar el post | Email al publicar y recordatorio; ninguna tarea los vigila |

**Lo que de verdad falta:** (A) el **calendario de tareas con fecha límite** que recuerde qué toca y cuándo, y (B) **recetas, menús y la validación de alérgenos**. La hoja de presupuestos solo aporta dos cosas pequeñas: plantillas de **partidas sugeridas** por tipo de evento y el cálculo de **autobuses (55 plazas)**.

## 3. Cómo encaja (respuesta al «PENDIENTE DE REPO»)

| Pregunta del fichero | Respuesta |
|---|---|
| **Evento** | Tabla `asde_eventos` (nº `E6840012`, tipo, fechas, secciones convocadas, estado, presupuesto) y partidas `asde_evento_partidas`. Un grupo no ve los de otro |
| **Lista de inscritos** | `asde_evento_inscripciones` (`Eventos\InscripcionesTable`): nº Scouts de España, sección, estado (**pendiente, confirmada**, lista de espera, baja…), transporte, fecha de revisión de la ficha de salud. Para el menú cuentan las **pendientes y confirmadas** (como la ratio) y el equipo |
| **Persona** | Solo el nº Scouts de España; nombre y contactos salen de la instantánea cifrada; **la salud nunca se guarda** (doc 08) |
| **Ficha de salud** | Campos del CRM `alergias_c`, `alergias_obs_c` y `alimentacion_*` (alergias, intolerancias, régimen, trastornos); el régimen es un desplegable de 4 dietas. Se leen **en vivo** (`PersonaRepository::campos_varias`, una lectura para todo el evento). **Falta comprobar si las alergias e intolerancias son texto libre o una lista** (P-188) |
| **Roles** | `asde_eventos_gestion`, `asde_eventos_grupo`, `asde_eventos_economia`, `asde_eventos_config`, `asde_eventos_inscribir`; ver salud: `asde_view_health` y el listado de P-148 |
| **Tecnología** | Plugin de WordPress (PHP 8, namespace `AsdeBalears\AreaPrivada`), tablas `{prefix}asde_*` con `dbDelta`, vistas + plantillas sin JavaScript obligatorio, módulos que se encienden por grupo, textos en es/ca/eu/gl (`asde_t()`) |

## 4. Diseño A · Calendario de tareas del evento (el «programador»)

**Dónde vive:** dentro de **Eventos**, no como módulo nuevo. Es una pestaña más del evento, **«Calendario»**, junto a *Resumen, Equipo, Inscripciones, Presupuesto y Cuentas*. Se enciende con el módulo Eventos.

### 4.1 Plantillas

- **Una plantilla = una lista de tareas** con: título, detalle, **referencia** (`inicio` o `fin` del evento), **desfase en días** (−60, +7…), rol sugerido (director/a, intendente, Tesorería, Secretaría, cualquiera de la sección) y, si procede, el **enlace a la pantalla** que la resuelve (presupuesto, equipo, inscripciones, comunicación).
- **Se siembra** una sola plantilla desde el `.json` (P-184): **Acampada** (31 tareas). La de **Campamento** (35 tareas) viene en el mismo `.json` y cada grupo la puede crear desde la configuración; los ejemplos de fechas de ambas son casos de prueba. Las fechas del ejemplo del fichero (acampada del 19/03/2027, campamento del 16/07/2027) son la **prueba** de que el cálculo coincide con el Excel.
- **Cada grupo las edita** (*Eventos → Configuración → Plantillas de tareas*): añadir, quitar, cambiar desfase o título, y crear plantillas propias («Salida de día», «Ruta»). Lo que se edita se guarda como opción del grupo, y la original se puede restaurar.
- **Cada tipo de evento** (doc 43 §1) puede tener una plantilla por defecto: acampada y ruta → *Acampada*; el resto, ninguna (se puede aplicar a mano o crear la propia).

### 4.2 Tareas de un evento

- Al **aplicar la plantilla** a un evento se copian sus tareas a `asde_evento_tareas`, con la **fecha límite** calculada (`inicio − 60 días`, `fin + 7 días`). Se pueden **añadir tareas propias** y **quitar o cambiar** las copiadas.
- Cada tarea tiene: estado (**pendiente / hecha**, con quién y cuándo), **responsable** (una cuenta del equipo), **observaciones** y una **fecha propia** opcional que gana a la calculada.
- Si **cambian las fechas del evento**, se recalculan las tareas no hechas **y sin fecha propia**, y se avisa de cuántas se han movido.
- **«Quedan N días»** y el semáforo salen solos: vencida, esta semana, más adelante, hecha. La vista se agrupa por fecha y se **imprime**.
- **Fechas en fin de semana (P-185, decidido):** una fecha límite que cae en sábado o domingo **se adelanta al viernes anterior**. Es un ajuste del grupo (activado por defecto) y la tarea guarda la fecha calculada y la ajustada. Las fechas de los ejemplos del fichero, que sí caen en fin de semana, se prueban sin el ajuste y con él.

### 4.3 Tareas que se comprueban solas

Para no tener dos fuentes de verdad, algunas tareas llevan una **comprobación automática** que las marca (con la insignia «automática») cuando se cumple lo que ya mide la aplicación:

| Tarea de la plantilla | Se da por hecha cuando… |
|---|---|
| Estimar presupuesto de acampada / campamento | el presupuesto tiene partidas |
| (añadida a la plantilla) Presupuesto validado por Tesorería | Tesorería lo ha validado (solo si el evento tiene coste) |
| Elegir cocinero, intendente y ayudantes / Elegir equipo | el equipo tiene director/a y la ratio cumple |
| Revisar nº de títulos | `Normativa` no avisa de titulación ni de ratio |
| Enviar la ficha a la OF | la comunicación a ASDE Baleares tiene fecha de envío |
| Recoger autorizaciones | todas las inscripciones activas tienen la autorización (web o papel) |
| Último día para pagar | todas las inscripciones activas están pagadas |
| Elaborar menú / Revisar alergias y menú | el menú tiene todas las comidas y no quedan avisos sin resolver (diseño B) |

Las demás (reservar el lugar, la carta, la reunión de padres, cargar la furgoneta…) las marca una persona. Una tarea automática **no se puede marcar a mano** mientras no se cumpla, pero sí cerrarse con una nota («no procede»).

### 4.4 Avisos

- En **Inicio → «Pendiente de revisar»**: tareas vencidas y de esta semana de los eventos que gestiona cada uno (contador barato, sin consultar el CRM).
- **Email** al responsable de la tarea (o al director/a si no tiene) cuando faltan **3 días** y cuando vence, con la tarea diaria del mantenimiento, en su idioma y en el registro de emails (tipo *Eventos*). Se pueden desactivar por grupo. Enganche `do_action( 'asde_ap_evento_aviso', 'tarea', … )` para el aviso push de la app.
- Sin datos personales en el email: tarea, evento y fecha.

### 4.5 Hoja de presupuestos: lo poco que falta

- **Partidas sugeridas** al crear el presupuesto: las categorías de la hoja (desplazamientos, alimentación, material y actividades, gastos de voluntarios, instalaciones, otros) como partidas en blanco que se rellenan o se quitan.
- **Autobuses:** calculadora «nº de autobuses = personas ÷ plazas (55 por defecto, configurable)», que propone la partida de transporte.
- **Lugar:** el aviso de la hoja («precio por persona y día **o** total, nunca los dos») ya lo resuelve el tipo de reparto de cada partida; solo se comprueba con una prueba.
- **Paridad:** una prueba con el ejemplo numérico de la hoja (apartado 5 del fichero) y con el de `docs/43` (Lobatos 46 €, Scouts 57 €).

## 5. Diseño B · Gestor de menús

**Dónde vive (P-183, decidido):** **dentro de Eventos**, sin módulo nuevo: el **recetario** del grupo es una pantalla de Eventos y cada evento tiene su pestaña **«Menú»**. Se enciende con el módulo Eventos (que ya nace apagado).

### 5.1 Datos

| Tabla | Para qué |
|---|---|
| `asde_menu_ingredientes` | Ingredientes del grupo: nombre, unidad por defecto, **alérgenos** (los 14 del Reglamento 1169/2011), **trazas**, coste unitario opcional. Se reutilizan en todas las recetas |
| `asde_menu_recetas` | Receta: nombre, tipo de plato, porciones base, elaboración, observaciones, activa |
| `asde_menu_receta_lineas` | Receta → ingrediente, cantidad y unidad. Los alérgenos de la receta **se calculan** (unión de los de sus ingredientes), no se guardan |
| `asde_evento_menu` | Comida de un evento: evento, día, tipo de comida, receta, comensales (por defecto los del evento) |
| `asde_evento_menu_avisos` | Solo las **confirmaciones** (P-191, decidido): comida, alérgeno, quién confirma, cuándo y la alternativa (texto genérico). **Sin nombres, sin diagnósticos y sin nº de afectados** |

El catálogo de alérgenos y la tabla *intolerancia → alérgeno* (lactosa → leche, celiaquía → gluten) van **en código** (`Menus\Alergenos`), traducidos.

### 5.2 Recetas y escalado

- Alta, edición y baja (se archivan, como los productos de la tienda) por quien tenga `asde_menus_recetas` (scouters y el equipo del evento). Importación por CSV con el mismo método que el inventario.
- **Escalado:** cantidad × (comensales ÷ porciones base), con 2 decimales. Los comensales son los **inscritos pendientes y confirmados + el equipo**, o el nº que se ponga a mano.
- **Lista de la compra:** suma por ingrediente y unidad (g con kg, ml con l; «unidades» y «cucharadas» no se convierten), para todo el evento o por día. **Imprimir y CSV.**
- **Coste:** si los ingredientes llevan precio, sale el coste del menú, por persona y día; se puede **proponer como partida «Alimentación»** del presupuesto (nunca la cambia sola).

### 5.3 Validación de alérgenos (lo sensible)

**Regla de oro (doc 08): nada de salud se guarda.** Por eso la validación se hace **en vivo, cada vez que se abre el menú**, y solo se guardan las confirmaciones.

1. Se calculan los alérgenos de cada receta del menú (de los ingredientes).
2. Se leen **en vivo** del CRM, **en una sola lectura**, las alergias, intolerancias y dieta de los asistentes (`PersonaRepository::campos_varias`); no se cachean.
3. Un normalizador convierte lo que haya en la ficha en alérgenos del catálogo (P-188): palabras clave en castellano y catalán («celiaquía», «lactosa», «frutos secos», «marisco»…), y los valores de la lista si la ficha usa una.
4. Se comparan y se pintan los avisos:

| Nivel | Cuándo | Color |
|---|---|---|
| **Conflicto** | un ingrediente contiene un alérgeno que afecta a algún asistente | rojo |
| **Posible riesgo** | solo «puede contener» | ámbar |
| **Sin conflictos** | a nadie le afecta | verde |
| **Datos incompletos** | hay inscritos que **no han marcado «he revisado la ficha de salud y alimentación»** (P-189, decidido) | gris |

5. Cada aviso dice **alérgeno, ingrediente causante, receta y comida** y las **alternativas** (cambiar el ingrediente, otra receta o una ración aparte). Se resuelve cambiando el menú o **confirmándolo** («se prepara una ración sin gluten»), con quién y cuándo. **No bloquea** nada.

**Quién ve qué (P-148 y el fichero §7):**

| Quién | Ve |
|---|---|
| Equipo del evento, scouters de las secciones que van, Secretaría, Coordinación (los que ya ven el listado de salud) | **Quiénes** están afectados, por alérgeno. Cada consulta queda en la auditoría |
| **Quien edita el menú** (P-190, cambiado por el usuario) | **Alérgeno, nº de personas y sus nombres.** Por eso editar el menú exige poder ver la salud del evento (`asde_menus_salud`) |
| Cocina/intendencia que no edita | Solo **alérgeno y nº de personas**, sin nombres |
| Menú impreso y lista de la compra | **Solo alérgenos**, ni nombres ni cifras (no decidido por el usuario: se mantiene la propuesta) |
| Cualquier otro | Nada de salud |

**Se revalida solo** porque se calcula al abrir: si alguien se inscribe, se da de baja, cambia su ficha o se cambia una receta, el siguiente que abra el menú lo ve. Para no depender de que alguien lo abra, la tarea *Revisar alergias y menú* (8 días antes en acampadas, 15 en campamentos) manda un aviso «hay avisos de alérgenos sin resolver en el menú de *X*» **sin cifras ni nombres** (P-192).

### 5.4 Permisos y módulos

- Capabilities nuevas: `asde_menus_recetas` (alta de recetas e ingredientes), `asde_menus_gestion` (el menú de un evento: quien gestiona el evento), `asde_menus_salud` (ver quiénes: **la misma regla** que el listado de salud de P-148, no una nueva).
- Sin módulo propio (P-183): las capacidades `asde_menus_*` se dan o se quitan junto con las `asde_eventos_*`; con Eventos apagado no hay recetario, ni pestaña, ni menú.
- Auditoría: `menu_receta_*`, `menu_ingrediente_*`, `menu_comida_*`, `menu_validado` (quién consulta, **qué evento**, sin resultados), `menu_aviso_confirmado`.

## 6. Plan de ejecución

> Una **rama nueva desde `origin/main`** por versión; cada fase: pruebas, textos (ca, eu, gl), ayuda y manual, `docs`, commit. Skills: `modulo-area-privada` (menús), `version-area-privada`. **Numeración (P-196, decidido):** el doc 43 reservaba la v0.39 para el *material del evento*; la v0.39 es ahora la Tienda (doc 48), así que el material pasa a la **v0.42**.

| Fase | Versión | Qué se hace | Pruebas |
|---|---|---|---|
| **A1** · Calendario | **0.40.0** | `asde_evento_tareas`; `Eventos\Tareas` (calcular fechas, aplicar plantilla, recalcular); plantillas *Acampada* y *Campamento* sembradas del `.json`; pestaña *Calendario* (hacer, observaciones, responsable, imprimir); *Configuración → Plantillas de tareas*; «Pendiente de revisar» en Inicio | Las dos fechas de ejemplo del fichero (acampada 19/03/2027, campamento 16/07/2027) tarea por tarea; cambiar fechas; tareas propias; permisos; sin datos personales |
| **A2** · Automático y avisos | 0.40.0 | Comprobaciones automáticas (§4.3), enlaces a las pantallas, emails (3 días y al vencer) y `asde_ap_evento_aviso`, partidas sugeridas, calculadora de autobuses, paridad con la hoja de presupuestos | Las tareas se marcan solas y se desmarcan al deshacer; aviso una sola vez; el ejemplo de la hoja y el del doc 43 |
| **B1** · Recetario | **0.41.0** | Tablas de ingredientes, recetas y líneas (sin módulo nuevo: P-183); **biblioteca básica común** de recetas (P-195); `Menus\Alergenos`; páginas *Recetas* y *Receta*; escalado; importar CSV; permisos | Herencia de alérgenos, escalado (el ejemplo de las lentejas: 1,5 kg × 42 ÷ 10 = 6,3 kg), unidades, archivar |
| **B2** · Menú del evento | 0.41.0 | Pestaña *Menú* (día × comida), comensales, lista de la compra (imprimir y CSV), coste y propuesta de partida | Sumas con conversión de unidades, coste, módulo apagado |
| **B3** · Validación | 0.41.0 | Lectura en vivo, normalizador, los cuatro niveles, confirmaciones, permisos de ver quiénes, auditoría, tareas T-18 y T-8 integradas, aviso por email | Los cuatro ejemplos del fichero (§9); la salud **no se guarda** (se comprueba en la base de datos); nombres solo a quien toca; CRM caído; ficha vacía |
| **Cierre** | 0.40 y 0.41 | Ayuda por página, manuales (con capturas con datos inventados), mapa `40`, `00-INDICE`, Plugin Check | — |

**Orden recomendado:** A1 → A2 (calendario útil desde el primer día y sin riesgo con datos de salud) → B1 → B2 → B3 (la parte sensible, con las decisiones de privacidad ya confirmadas).

## 7. Riesgos

| Riesgo | Mitigación |
|---|---|
| Datos de salud de menores | Nada se guarda; lectura en vivo y en una sola consulta; nombres solo a quien ya ve el listado de salud; auditoría de cada consulta; menús y listas impresas sin nombres |
| **Texto libre en las alergias de la ficha** (falsos negativos) | Normalizador con diccionario es/ca, revisable; el aviso lo presenta como **orientativo** y deja a la persona leer el texto original; «datos incompletos» cuando no hay revisión de la ficha |
| Tareas que caen en fines de semana o se mueven | Se avisa; las editadas a mano no se recalculan |
| Dos fuentes de verdad (tarea manual vs. estado real) | Las tareas con comprobación automática se marcan solas |
| Alcance | Fases pequeñas y versionadas; Menús nace apagado |
| Normativa autonómica | El fichero es orientativo y de ámbito estatal; la web ya usa la normativa balear (doc 43 §6) |

## 8. Preguntas abiertas (para confirmar)

| # | Pregunta | Propuesta |
|---|---|---|
| P-183 ✅ | ¿Dónde vive cada cosa? | **Todo dentro de Eventos** (decidido; la propuesta era Menús aparte) |
| P-184 ✅ | ¿Qué plantillas se siembran? | **Solo Acampada** (decidido) |
| P-185 ✅ | Fecha límite en fin de semana | **Se adelanta al viernes** (decidido; la propuesta era dejarla) |
| P-186 ✅ | Avisos de tareas | **Al responsable (o al director/a): 3 días antes y al vencer** (decidido) |
| P-187 ✅ | ¿Quién marca tareas como hechas? | **Cualquier scouter con acceso al evento** (decidido) |
| P-188 ✅ | ¿Cómo están escritas las alergias en el CRM? | **Mirado en solo lectura el 2026-10-17** (solo metadatos de Contacts): ver apartado 9 |
| P-189 ✅ | «Datos incompletos» | **Inscritos sin la ficha de salud revisada** (decidido) |
| P-190 ✅ | ¿Qué ve quien edita el menú? | **También los nombres** (decidido; la propuesta era solo alérgeno y nº) |
| P-191 ✅ | ¿Qué se guarda al confirmar un aviso? | **Comida, alérgeno, quién, cuándo y alternativa genérica; nada de personas ni cifras** (decidido) |
| P-192 ✅ | Email de «conflictos sin resolver» (T-8 / T-15) | **Con los nombres de quien no tiene la ficha revisada y de los afectados por cada conflicto** (decidido; la propuesta era sin cifras ni nombres). Ver las salvaguardas del apartado 9 |
| P-193 ✅ | ¿El coste propone la partida «Alimentación»? | **Sí, como propuesta que se acepta** (decidido) |
| P-194 ✅ | Unidades | **Solo g↔kg y ml↔l** (decidido) |
| P-195 ✅ | ¿Recetas compartidas? | **Biblioteca básica común** (decidido; la propuesta era no compartir) |
| P-196 ✅ | El *material del evento* pasa a la v0.42 | **Sí** (decidido) |

## 9. Cómo se prueba

Pruebas nuevas por fase (`tests/eventos-calendario.php`, `tests/menus-recetas.php`, `tests/menus-validacion.php`) con el CRM simulado y fichas inventadas; los cuatro ejemplos del §9 del fichero de menús y las dos fechas de ejemplo del de campamentos son casos de prueba tal cual. **Nunca contra el CRM real**; para P-188 solo una lectura de los valores de los campos, sin datos de personas, y con permiso.

## 9. Decisiones del usuario (2026-10-17) y lo averiguado

**Resumen de lo decidido:** todo dentro de Eventos (sin módulo Menús); solo se siembra la plantilla *Acampada*; las fechas de fin de semana se **adelantan al viernes**; aviso al responsable 3 días antes y al vencer; marca tareas como hechas **cualquier scouter con acceso al evento**; «datos incompletos» = inscritos sin ficha de salud revisada; quien edita el menú **ve también los nombres**; al confirmar un aviso se guarda solo comida, alérgeno, quién, cuándo y alternativa genérica; el email de conflictos lleva **nombres**; coste del menú como propuesta de partida; conversiones solo g↔kg y ml↔l; **biblioteca básica común** de recetas; el material del evento pasa a la v0.42.

**P-188 · lo que hay en el CRM** (script `herramientas-desarrollo/crm-campos-alimentacion.php`, solo `get_module_fields` de Contacts, sin leer ninguna ficha; 20 campos):

| Campo | Tipo | Sirve para |
|---|---|---|
| `alimentacion_alergia_c`, `alimentacion_intolerancia_c`, `alimentacion_regimen_c`, `alimentacion_transtornos_c` | **Sí / No** (radio) | Saber **si hay algo que mirar** sin leer texto: es un dato fiable |
| `alimentacion_alerg_alimentos_c`, `alimentacion_alerg_obs_c`, `alergias_c`, `alergias_obs_c` | **Texto libre** | Alergias (qué alimentos) |
| `alimentacion_intol_alimentos_c`, `alimentacion_intol_obs_c`, `alergias_alimentos_c` (etiquetada «Intolerancias alimentarias») | **Texto libre** | Intolerancias |
| `alimentacion_regimen_tipo_y__c` | texto corto (en la web ya es un desplegable de 4 dietas) | Dieta: omnívora, vegetariana, vegana, flexitariana |
| `alimentacion_regimen_razon_c`, `alimentacion_regimen_otros_c` | lista (voluntariamente, prescripción médica, otros) y texto | Motivo del régimen |
| `alimentacion_transtorno_*`, `alergiamedicamentos*_c`, `alergias_medicamentossn_c` | Sí/No y texto | **No cuentan para el menú** (medicamentos y trastornos) |

**Consecuencias para el diseño:**

- Las alergias e intolerancias son **texto libre**, así que el normalizador (§5.3, paso 3) es imprescindible: diccionario es/ca por alérgeno, sobre `alimentacion_alerg_alimentos_c`, `alergias_c`, `alimentacion_intol_alimentos_c` y `alergias_alimentos_c` (más las observaciones).
- Los **Sí/No** permiten tres casos sin ambigüedad: *Sí + texto que no se reconoce* → aviso **«revisar a mano»** con el texto original; *No* → sin riesgo; *vacío* → ficha sin rellenar.
- La **dieta** (vegetariana, vegana…) se cruza con ingredientes de origen animal; hace falta una marca «de origen animal» en el ingrediente (se añade al diseño de `asde_menu_ingredientes`).
- Cuando el texto no se reconoce no se puede dar un «sin conflictos» seguro: el resultado se muestra siempre como orientativo.

**Salvaguardas que piden las decisiones** (no cambian el plan, pero hay que cuidarlas al programar):

1. **Editar el menú exige ver salud** (P-190): `asde_menus_gestion` y `asde_menus_salud` van juntas para quien edita el menú de un evento; quien no ve la salud no edita el menú, solo lo ve (alérgenos y nº).
2. **El email con nombres** (P-192) lleva datos de salud de menores por correo, que no es un canal seguro. Se mandará **solo** a quien ya ve la salud de ese evento, con el mínimo (nombre y alérgeno, sin diagnóstico ni texto de la ficha), y cada envío queda en el registro de emails y en la auditoría. La alternativa es un email sin nombres con un enlace a la pantalla del menú (que exige sesión): el cambio es de una línea y se puede decidir al llegar a B3.
3. **Biblioteca básica común** (P-195): son recetas de ejemplo **incluidas en el plugin**, con sus ingredientes y alérgenos. Un alérgeno mal puesto es un riesgo real, así que se sembrarán pocas (10-15), **revisadas por una persona del grupo antes de publicar** y marcadas como «de ejemplo: revísalas». Cada grupo las copia y las edita; no se comparten recetas entre grupos.
4. **Fechas al viernes** (P-185): las tareas guardan la fecha calculada y la ajustada; los ejemplos de fechas del fichero se prueban con el ajuste desactivado y activado.
