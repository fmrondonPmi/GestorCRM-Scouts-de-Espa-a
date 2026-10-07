# 34 · Plan de acción: crear personas y asociar membresías en SinergiaCRM

## Petición del usuario (2026-10-06)

> «Se hizo el estudio de cómo crear y asociar membresías: genera el plan de acción para esto. Con respecto a la pregunta de qué sucede si el DNI ya existe, deberás probarlo […] con un DNI que existe […] y cómo se puede asegurar que nuestro sistema no genere duplicados con otros grupos.»

Parte del estudio del doc 33 (qué permite la API y cómo encaja con el modelo de ASDE). Este doc dice **qué se hace, en qué orden y con qué garantías**. Desde la v0.22 hay código de escritura (fases 2 y 3), probado solo con el CRM simulado y el servidor de la API simulado: **no se ha escrito nada en el CRM real** (regla 2 del `CLAUDE.md`).

## 1. Lo que se ha averiguado (2026-10-06)

Leyendo el código público de SinergiaCRM y de SuiteCRM (la base de SinergiaCRM). La instancia de ASDE puede tener personalizaciones propias.

| Pregunta | Respuesta | Fuente |
|---|---|---|
| ¿El CRM rechaza crear una persona con un DNI que ya existe? | **No.** Al guardar una persona no se comprueba que el DNI (`stic_identification_number_c`) sea único: los *logic hooks* de `Contacts` solo calculan la edad y datos de localización. La búsqueda de duplicados (por nombre y apellidos o por DNI) solo existe en el **formulario web** del CRM, y avisa sin bloquear. `set_entry` por la API no pasa por ella: **se crea otra ficha sin ningún aviso**. Y es lo que quiere ASDE: una persona puede estar en dos grupos o en dos asociaciones (P-81) | `custom/modules/Contacts/SticLogicHooksCode.php` y `sticContactsDuplicateQueries.php` |
| ¿El CRM valida el formato del DNI? | No en el código estándar | `custom/modules/Contacts/SticUtils.php` |
| P-74 · ¿«Secciones actuales» se recalcula sola al guardar una membresía? | **Sí, también por la API.** Antes de guardar la membresía se calcula `active` a partir de las fechas; después se recalcula `stic_relationship_type_c` de la persona (`setRelationshipType`), igual que al enlazar o desenlazar una membresía. El plugin **no** tiene que escribir «Secciones actuales» | `modules/stic_Contacts_Relationships/LogicHooksCode.php` |
| P-73 · ¿La persona nueva entra sola en el grupo de seguridad del grupo? | **Depende de la configuración de la instancia**: SuiteCRM añade un registro **nuevo** a los grupos de seguridad de quien lo crea si `securitysuite_inherit_creator` está activo (y al de la persona asignada o al del registro padre con `securitysuite_inherit_assigned` / `securitysuite_inherit_parent`). Si el usuario de API solo está en el grupo de seguridad del grupo scout, la ficha quedaría en ese grupo. Si no, hay que enlazarla con `set_relationship` (`Contacts` → `SecurityGroups`). El plugin lo **comprueba después de crear** (fase 2) | `modules/SecurityGroups/SecurityGroup.php` (SuiteCRM) |
| ¿El grupo ve a las personas de otros grupos? | No: `get_entry_list` solo devuelve las del grupo de seguridad. Pero **`get_entries_count` cuenta en todo el CRM federal** (doc 04, §7.6): dice **cuántas** fichas cumplen una condición sin devolver ningún dato. ASDE acepta que se use para esto (P-78) | Doc 04 |
| ¿Se puede saber en qué grupo y OF está la otra ficha? | **No.** El CRM niega el acceso a los grupos de seguridad («You do not have access») y los únicos campos con grupo y OF son los de la importación de julio de 2024, que no se mantienen (§2). Por eso el aviso es **genérico** (P-84) | Diagnósticos del §2 |

## 2. Decisiones del usuario (2026-10-06, fase 0)

| | Decisión |
|---|---|
| **P-70** Permiso | **Sí**: el grupo crea personas y da altas y bajas desde la web |
| **P-78** Recuento federal | **Sí**: el plugin puede usar `get_entries_count` para saber si un documento ya existe en otro grupo |
| **P-71** Si está en otro grupo | **Se puede continuar** creando la persona para asociarla al grupo. Sale una **advertencia** de que ya está dada de alta en otro grupo (se pidió también el grupo y la OF, pero no se pueden saber: P-84) |
| **P-84** Qué dice esa advertencia | **Genérica** (opción a): «Esta persona ya está dada de alta en otro grupo u organización de Scouts de España». Si algún día ASDE da un dato oficial del grupo actual, o el visto bueno a usar los de la importación de julio de 2024, se añade a la advertencia |
| **P-81** Regla de DNI único en el CRM | **No**: una persona puede estar en dos grupos o en dos asociaciones. No es una restricción: **se informa siempre** al usuario del duplicado durante el alta |
| **P-79** Quien vuelve | Si ya tiene ficha en el grupo, **solo necesita una membresía nueva** en esa ficha |
| **P-80** Menores sin documento | No se valida nada al darlos de alta sin documento. Se valida **cuando se le asigne un nº de documento**: antes de guardarlo en la ficha se comprueba si ya existe y se avisa del duplicado |
| **P-72** Nº de socio | **Lo devuelve el CRM** al crear la persona: el nº de Scouts de España es automático |
| **P-75** Datos obligatorios | **Nombre, apellidos, fecha de nacimiento, DNI o nº de documento y género** (`stic_gender_c`) |
| **P-69** Entorno de pruebas | **No hay CRM de pruebas**: se hace un **servidor de la API simulado** que conteste lo que se espera en cada acción |
| **P-82** Documento obligatorio | **A partir de los 14 años** (ajuste del usuario, 2026-10-07): todo asociado de 14 años o más tiene que tener su documento. Por debajo de 14 se puede dar de alta sin él (y se comprueba cuando se le pone, P-80). En el código: `Duplicados::EDAD_DOCUMENTO` y `Duplicados::documento_obligatorio()` |
| **P-83** Si no se puede comprobar | Si el recuento deja de ser federal (canario, §3), no se puede saber si está en otro grupo: **se avisa de que no se ha podido comprobar y se deja continuar**, porque estar en otro grupo tampoco bloquea |

### La prueba con un DNI real que ya existe

- El usuario facilitó un DNI que existe en el CRM (no se anota aquí: regla 1). El formato es válido (la letra cuadra).
- **No se ha dado de alta de verdad**: sería escribir en el CRM real (regla 2) y crearía otra ficha en el CRM federal.
- **Script de solo lectura**: `herramientas-desarrollo/crm-dni-existe.php` (fuera del repo), con las escrituras cortadas: (0) comprueba que el recuento sigue siendo federal; (1) cuenta en todo el CRM las fichas con ese documento escrito de varias formas; (2) busca en el grupo las fichas con ese documento y si tienen membresía activa; (3) dice qué haría el alta; (4) intentaba deducir el grupo y la OF de las fichas de otros grupos (quitado: el CRM no lo permite, P-84); (5) lee qué campos marca el CRM como obligatorios y las opciones de género (solo metadatos). No imprime nombres, ids ni el documento; el DNI va solo en una variable de entorno. Se probó entero con un arnés que contesta con datos inventados (ninguna petición a la red): los cuatro casos (en otro grupo, en el grupo, en ninguno y recuento filtrado) dan la decisión esperada.
- **Lo lanza el usuario** (el entorno de Claude no tiene permiso para leer el CRM real). Desde PowerShell, con Local arrancado (la orden de Git Bash está en la cabecera del script):

  ```powershell
  $env:ASDE_DNI = '…'; & 'C:\Program Files\Git\bin\bash.exe' 'C:/Claude/Scouts De Balears  - Area Privada/herramientas-desarrollo/wp-local.sh' eval-file 'C:/Claude/Scouts De Balears  - Area Privada/herramientas-desarrollo/crm-dni-existe.php'; Remove-Item Env:ASDE_DNI
  ```

Resultado (lanzado por el usuario el 2026-10-06):

| Comprobación | Resultado |
|---|---|
| ¿El recuento es federal? | Sí (si no, la decisión habría sido «no se ha podido comprobar») |
| Fichas con ese documento en todo el CRM | **1**. La encuentran el documento exacto, en minúsculas y la búsqueda por las cifras; **no** con guion, con espacio ni solo las cifras: el CRM lo guarda **sin separadores** y compara sin distinguir mayúsculas. Por eso se busca por las cifras con `LIKE` y se compara el documento normalizado |
| … en el grupo, y con membresía activa | **0**: no está en el grupo 684 |
| Lo que haría el alta | Está en otro grupo u organización: advertencia y se puede continuar (P-71) |
| ¿Se deducen el grupo y la OF? | **No con `SecurityGroups`**: el CRM rechaza el recuento con la subconsulta. Diagnóstico pendiente (abajo) |
| Obligatorios según el CRM | `Contacts`: `first_name`, `last_name`, `birthdate`, `stic_gender_c` (el documento no: encaja con P-82). `stic_Contacts_Relationships`: `start_date`, `relationship_type`, la persona (`stic_contacts_relationships_contacts_name`) y `estado_c` |
| Opciones de género (`stic_gender_c`) | `masculino`, `femenino`, `nobinario` (y vacío) |

**Diagnóstico del grupo y la OF.** Primer intento (`crm-grupo-of.php`, lanzado por el usuario el 2026-10-06): **toda** consulta que toque `securitygroups_records` responde «You do not have access» (el CRM protege a propósito qué ficha está en qué grupo); las fichas y las membresías tienen `grupoimportacion_c` y `ofimportacion_c`, el grupo y la OF de la **importación de julio de 2024** (doc 04, §7.7). Segundo diagnóstico (`herramientas-desarrollo/crm-campos-importacion.php`, lanzado por el usuario el 2026-10-06): esos campos llevan nombres («Grupo Scout Nombre, 684», «ASDE Scouts de Balears») pero **no se mantienen**: en el grupo 684 faltan en 42 de las 100 fichas con membresía activa y en todas las membresías empezadas desde agosto de 2024; el usuario de API solo puede listar su grupo de seguridad; y las subconsultas solo se aceptan sobre las tablas de emails (a `contacts_cstm` tampoco). La ficha de ese documento sí los tiene rellenos. **Conclusión:** desde el plugin no se puede saber el grupo y la OF **actuales** de una ficha de otro grupo; solo, si la ficha es de antes de julio de 2024, los de la importación (deduciéndolos con recuentos sobre esos campos). Decisión pendiente: **P-84**. Lo que miraba el segundo diagnóstico: `herramientas-desarrollo/crm-campos-importacion.php` mira qué formato y cuántos vacíos tienen esos campos en las fichas y membresías **del propio grupo** (también en las altas posteriores a la importación), si se pueden listar los grupos de seguridad, si el bloqueo es solo de esas tablas y si la ficha de ese documento los tiene rellenos (sí/no). Con eso se decide si el aviso puede decir el grupo y la OF (con la salvedad de que es el de julio de 2024) o solo «otro grupo u organización». El primer intento: `herramientas-desarrollo/crm-grupo-of.php` prueba qué recuentos acepta el CRM (sobre `SecurityGroups`, sobre `Contacts` con subconsultas a `securitygroups_records` y `securitygroups_cstm`, sobre membresías), busca en los metadatos de las fichas y de las membresías algún campo de grupo u OF y, con lo que se acepte, deduce el grupo y la OF (como mucho 120 consultas). Enseña el motivo del rechazo que da el CRM, sin el documento. Si nada funciona, la advertencia dirá solo «ya está dada de alta en otro grupo u organización» y habría que pedir a ASDE una forma de saber cuál.

## 3. Duplicados: qué se comprueba y qué se hace

Estar en otro grupo **no impide** el alta (P-71, P-81): lo que no puede pasar es **crear dos fichas en el mismo grupo** ni que nadie se entere de que la persona ya existe.

1. **Documento normalizado y válido.** Mayúsculas, sin espacios, puntos ni guiones (`PersonaRepository::normalizar_documento`, ya existe), y comprobación de la letra del DNI o del NIE (módulo 23). Si no es válido, no se sigue. Al CRM se escribe **siempre** normalizado.
2. **¿Está en el grupo?** Con `get_entry_list`, en **todas** las fichas del grupo, con membresía activa o sin ella (no como `por_documento`, que hoy solo mira las activas): por las cifras del documento con `LIKE` y comparando en memoria el documento normalizado. Si está, **no se crea otra ficha**: si no tiene membresía activa, el alta es una **membresía nueva en su ficha** (P-79); si la tiene, no hay nada que dar de alta. Si hubiera varias fichas del grupo con ese documento, se avisa para que se unifiquen.
3. **¿Está en otro grupo u organización?** Con `get_entries_count` en todo el CRM, por el documento (normalizado y con las cifras, por si en el CRM está escrito de otra forma). Si cuenta alguna ficha que el grupo no ve, sale la **advertencia** «Esta persona ya está dada de alta en otro grupo u organización de Scouts de España» y se puede **continuar** (P-71, P-84). Es una sola consulta y no se lee nada de la otra ficha.
   - **Canario:** antes de fiarse del recuento, el plugin compara cuántas fichas cuenta `get_entries_count` en total con cuántas ve el grupo. Si fueran las mismas, el recuento habría dejado de ser federal: la advertencia dice que **no se ha podido comprobar** si está en otros grupos y se deja continuar (P-83), y wp-admin avisa a quien administra la web.
4. **Menores de 14 años sin documento (P-80, P-82).** Se dan de alta sin él. Cuando más adelante se le pone un documento en su ficha, **antes de guardarlo** se hacen las comprobaciones 1 a 3: si ya existe en el grupo, se avisa y no se guarda (sería la misma persona dos veces); si existe en otro grupo, se avisa y se puede guardar.
5. **Sin carreras dentro de la web.** Un cerrojo por documento (fila en una tabla con clave única e `INSERT IGNORE`, como el de la instantánea y el del instalador) mientras dura el alta, para que dos clics o dos personas a la vez no creen dos fichas en el grupo. Justo antes del `set_entry` se repite la comprobación 2.
6. **Reintentos sin duplicar.** El alta son varios pasos (persona → grupo de seguridad → membresía → leer el nº de socio). Cada paso hecho se apunta en una tabla de operaciones (ids del CRM y estado, **sin datos personales**). Si un paso falla, el reintento sigue desde ese paso y **no vuelve a crear la persona**.
7. **Informe en wp-admin** (solo lectura): fichas del grupo con el mismo documento normalizado, por si ya hubiera duplicados de antes.

## 4. Plan de acción

Cada fase se entrega como una versión, con sus pruebas (CRM simulado) y sus docs. Las fases 2 y 3 **nacen apagadas** (regla 5): una opción en wp-admin las activa por grupo.

### Fase 0 · Decisiones (hecha, salvo la prueba con el DNI)

- ✅ Respuestas a P-69, P-70, P-71, P-72, P-75, P-78, P-79, P-80 y P-81 (§2). P-73 y P-74, encaminadas (§1).
- ✅ El usuario lanzó `crm-dni-existe.php` con el DNI que facilitó (resultado en el §2).
- ✅ Diagnóstico del grupo y la OF (`crm-grupo-of.php` y `crm-campos-importacion.php`, §2): el grupo y la OF actuales no se pueden saber desde el plugin.
- ✅ **P-84**: el aviso de P-71 es genérico.
- ✅ P-82 y P-83 confirmadas.

### Fase 1 · Comprobación previa (solo lectura) ✅ v0.21.0 (2026-10-07)

> **v0.23.1 (decisión del usuario, P-91):** la comprobación **solo está dentro del alta** de un asociado nuevo (primer paso de «Alta de socio/a»). El botón y la pantalla sueltos «¿Está ya en el CRM?» que se describen abajo **se han quitado**; `Crm\Duplicados`, el límite por hora y la auditoría siguen igual.

**Hecho:**

- `Crm\Duplicados`: `validar()` normaliza el documento y comprueba la letra del DNI y del NIE (un DNI de siete cifras se completa con un cero; otros documentos, como el pasaporte, se aceptan tal cual con 5 a 20 caracteres); `documento_obligatorio()` (14 años); `formas()` (cómo puede estar escrito en el CRM: con guion, con espacio, sin el cero); `comprobar()`: en el grupo con `get_entry_list` (fichas con o sin membresía activa, por las cifras con `LIKE` y comparando el documento normalizado) y en otros grupos con un solo `get_entries_count` (sin subconsultas), sin contar dos veces las del grupo. El **canario** (lo que cuenta `get_entries_count` sin condición frente a lo que ve el grupo) se guarda 12 horas si está bien y 15 minutos si no; si no está bien, «no se ha podido comprobar» y queda en la auditoría (`crm_recuento_no_federal`).
- **Personas → «¿Está ya en el CRM?»** (botón arriba; `personas/?comprobar=1`, en la ventana emergente o en su página; plantilla `personas/comprobar.php`), para quien tiene Personas (Secretaría, Coordinación). El formulario va por **POST** y se contesta **en la misma petición** (sin redirigir), así el documento **no se guarda en ningún sitio** ni va en la dirección. Resultados: ya tiene ficha en el grupo (enlazada, con su membresía activa o «sin membresía activa: solo necesita una membresía nueva»), varias fichas en el grupo (habría que unificarlas), el aviso genérico de que está en otro grupo u organización (P-84), «no se ha podido comprobar» o que no existe.
- Como mucho **60 comprobaciones por persona y hora** (el recuento federal no debe servir para recorrer documentos); las de documentos mal escritos no cuentan. Auditoría `crm_documento_comprobado` con cuántas fichas hay en el grupo y en otros, **sin el documento**.
- `area.js`: un formulario con `data-asde-solo-consulta` dentro de la ventana no marca la página como cambiada: al cerrar la ventana no se recarga.
- CRM simulado: `get_entries_count` con fichas de «otros grupos» (`$otros_grupos`) que `get_entry_list` no devuelve, y `$recuento_por_grupo` para el canario. Prueba nueva `tests/altas-crm.php`. Revisada en el navegador (ventana, escritorio y 390 px) con fichas inventadas.

**Lo que se planeó:**

- **Qué:** `Crm\Duplicados` con las comprobaciones 1 a 3 del §3 (documento, grupo y recuento federal con canario). En Personas, **«¿Está ya en el CRM?»** (Secretaría y Coordinación): con un documento dice si está en el grupo (con o sin membresía activa) y si está dada de alta en otro grupo u organización.
- **Por qué primero:** no escribe nada y es la pieza en la que se apoyan el alta (fase 3) y el documento de un menor (§3.4).
- **Pruebas:** el CRM simulado aprende `get_entries_count` federal (fichas de «otros grupos» que `get_entry_list` no devuelve) y el caso del canario; y que la consulta no usa subconsultas (el CRM solo las acepta sobre las tablas de emails: doc 04, §7.7).
- **Hecho cuando:** los resultados salen bien con datos inventados, no se lee ninguna ficha de otro grupo y la auditoría guarda que se hizo una comprobación, sin el documento.

### Fase 2 · Escritura en el CRM simulado y servidor de la API simulado (P-69) ✅ v0.22.0 (2026-10-07)

**Hecho** (sin pantallas: eso es la fase 3; **apagado** hasta que se active por grupo, opción `asde_ap_altas_crm`, `Altas::activar()`):

- **Cliente** (`SinergiaV41Client`): `create_entry()` (set_entry sin id; exige que el CRM devuelva un id válido) y `set_relationship()`.
- **`Crm\Escritura`** sigue siendo la única puerta: `crear()` (solo personas y membresías; nunca el nº de socio ni «Secciones actuales»), `actualizar_membresia()` (solo una membresía de esa persona, comprobado antes; relee lo guardado) y `relacionar()` (solo con el grupo de seguridad del **propio** grupo o con una membresía). Exigen permiso de edición del usuario del CRM en `Contacts` y en las membresías (`puede_crear()`). Auditoría con nombres de campos, nunca valores.
- **`Crm\Altas`**: `crear_persona()` (obligatorios y documento desde los 14 años; comprobación previa: en el grupo, no; en otro grupo, hay que confirmarlo; después de crear: que el grupo vea la ficha o enlazarla, leer el nº de socio y crear la membresía con él; si el CRM no guarda el enlace en el mismo set_entry, se enlaza aparte), `nueva_membresia()` (quien vuelve: solo sin membresía activa), `baja()` (fecha y motivo de la lista; cierra la membresía activa: `end_date`, `end_reason`, `estado_c = baja`; nada se borra), `cambio_seccion()` (baja con `cambioseccion` y alta en la nueva, misma fecha) y `poner_documento()` (solo a quien no tiene; si otra ficha del grupo lo tiene, no; si está en otro grupo, confirmar). Lee las membresías por el enlace de la persona (`get_relationships`).
- **`Crm\Operaciones`** (tabla nueva `{prefix}asde_crm_operaciones`): cerrojo por documento (o por nombre y nacimiento en un menor sin documento) o por persona, con clave única e `INSERT IGNORE`; la clave se guarda como HMAC (sin el documento). Cada paso hecho se apunta (solo ids del CRM); un fallo deja la operación «fallida» y el reintento sigue desde ahí; una «en curso» de más de 10 minutos se da por abandonada.
- **CRM simulado** (`tests/lib/CrmSimulado.php`): crea personas (con nº de socio nuevo) y membresías, las cambia, `set_relationship`, `get_relationships`, `get_entry`, ACL; emula a SinergiaCRM al guardar (`active` por las fechas y «Secciones actuales» recalculada) y, con ajustes, un CRM que no hereda el grupo de seguridad (P-73), que no enlaza la membresía en el set_entry o que falla a propósito.
- **Servidor de la API simulado** (`tests/servidor-crm/`: `router.php`, `semilla.php`, `sustitutos.php`; no va en el zip): `php -S 127.0.0.1:8790 router.php`, **sin WordPress**, con el mismo CRM simulado y fichas inventadas del grupo 999; guarda el estado en un fichero temporal; `/estado`, `/reset` y `/ajustes` (heredar el grupo, enlazar, numerar, provocar un fallo). Solo escucha en 127.0.0.1.
- **Pruebas:** `tests/altas-crm-escritura.php` (39: apagado y permisos, datos, persona nueva, no duplicar, confirmar si está en otro grupo, menor sin documento, grupo de seguridad y enlace, a medias y reintento, dos a la vez, abandonada, quien vuelve, baja, cambio de sección y su reintento, documento de un menor, lo que la web no puede hacer). Comprobada con una mutación (sin el nº de socio en la membresía, falla). Además, `herramientas-desarrollo/servidor-crm-prueba.php` hace el recorrido entero **por HTTP** contra el servidor simulado (10 comprobaciones, todas bien).
- Supuestos nuevos: **P-85** (qué significa la fecha de baja) y **P-86** (qué campos lleva la membresía al crearla).

**Lo que se planeó:**

- **Qué:** en `Crm\Escritura` (la única puerta): `crear_persona()` (con los obligatorios de P-75), `alta()` (membresía nueva, también para una ficha que ya existe), `baja()` (fecha y motivo; nunca borrar), `cambio_seccion()` (baja con `cambioseccion` y alta el mismo día) y `poner_documento()` (con la comprobación del §3.4). Después de crear: comprobar que el grupo **ve** la ficha (si no, enlazarla a su grupo de seguridad: P-73) y **leer el nº de socio** que pone el CRM (P-72). Las comprobaciones 5 y 6 del §3.
- **CRM simulado** (`tests/lib/CrmSimulado.php`): `set_entry` sin id (devuelve un id y pone un nº de socio nuevo), `set_relationship`, `get_relationships` y lo que hace SinergiaCRM al guardar (calcular `active`, recalcular «Secciones actuales» y, según un ajuste, heredar el grupo de seguridad).
- **Servidor de la API simulado:** el mismo CRM simulado servido por HTTP (`php -S` con un router en `tests/servidor-crm/`, fuera del zip) que contesta como el endpoint `v4_1_SticCustom/rest.php`. En el Local se configura un **grupo de pruebas** con la URL de ese servidor: así se prueba el alta entera en el navegador, con peticiones de verdad, sin tocar el CRM real.
- **Pruebas:** una por operación, comprobando lo que se envía al CRM; un alta con un documento que ya está en el grupo, otro que está en otro grupo, dos altas a la vez, un fallo a mitad con su reintento y un documento puesto después a un menor.
- **Hecho cuando:** ninguna prueba crea dos fichas en el mismo grupo y todas las altas acaban con su nº de socio.

### Fase 3 · Pantallas y avisos ✅ v0.23.0 (2026-10-07)

**Hecho** (sigue **apagado** hasta que se activa por grupo; quien lo usa: Secretaría y Coordinación, los que ven Personas):

- **Interruptor por grupo:** wp-admin → Grupos Scouts → **Módulos**, columna nueva «Altas en el CRM» (opción `asde_ap_altas_crm`). Con el grupo activado y el usuario del CRM con permiso para crear personas y membresías (`Altas::disponible()`) salen las pantallas; si falta el permiso, la pantalla lo explica.
- **Personas → «Alta de socio/a»** (botón junto a «¿Está ya en el CRM?», en la ventana emergente; `personas/?alta=1`; `Frontend\AltaSocio` y `templates/personas/alta.php`), en **cuatro pasos** (P-88): **Documento** (o «Es menor de 14 años y no tiene documento») → **Datos** (persona: nombre, apellidos, nacimiento, género, documento, email y móvil; tutores 1 y 2; membresía: sección y fecha de alta) → **Revisar** (resumen; si está en otro grupo u organización, una casilla para confirmarlo) → **Hecho** (nº de socio/a que devuelve el CRM, «Ver su ficha», «Dar acceso a la web» y «Dar de alta a otra persona»). Si el documento ya tiene ficha en el grupo, el alta se para y lleva a su ficha (con «Darle una membresía nueva» si no tiene ninguna activa). Cada paso va por POST y se contesta en la misma petición: **lo escrito viaja en el formulario y no se guarda en WordPress** (la prueba lo comprueba en las opciones y en las operaciones). Los campos ocultos no se creen: `Crm\Altas` vuelve a comprobar todo al crear (si se quita el aviso de otro grupo a mano, pide confirmarlo igual; si se reenvía el último paso, no crea otra ficha).
- **Ficha → «Membresía en el CRM»** (tarjeta debajo de la cabecera; formularios en la ventana emergente, `ficha/?socio=…&membresia=nueva|baja|seccion|documento`, `templates/fichas/membresia.php`): **Nueva membresía** (solo sin ninguna activa), **Dar de baja** (fecha y motivo de la lista; enseña la membresía que se cierra y **qué cambia**: la cuenta de acceso de la persona, las cuentas familiares que la representan y la cuota de su familia; pide confirmación), **Cambiar de sección** (enseña la actual, leída del CRM) y **Poner el documento** (a quien no lo tiene; si otra ficha del grupo lo tiene, no; si está en otro grupo, casilla para confirmarlo). Si sale bien, vuelve a la ficha con un aviso; si no, el formulario se vuelve a pintar con el error y lo elegido.
- **Fechas** (P-87): el alta, la baja y el cambio de sección como mucho con **fecha de hoy** (no se programan) y nunca anteriores al alta de la membresía que se cierra. Lo comprueban las pantallas y `Crm\Altas`.
- **Avisos** (P-90): al dar la baja, el aviso dice qué cambia; **Tesorería → Control de pagos** lista las **«Bajas recientes»** (últimos 30 días, dadas desde la web, de personas que están en alguna familia, enlazadas a su familia: ya no cuentan en su cuota), sacadas de la auditoría (`Altas::bajas_recientes()`; la auditoría de altas y bajas guarda ahora el grupo y la fecha de baja). En **Usuarios** cada cuenta ya avisa «Sin membresía activa: no puede entrar»: no se ha añadido nada más.
- Textos nuevos en castellano, catalán, euskera y gallego (81; primera versión sin revisión nativa, como el resto: P-46/P-47); los géneros y los motivos de baja se traducen al mostrarlos (`Textos::extras`).
- **Pruebas:** `tests/altas-crm-pantallas.php` (47): apagado y sin permiso no sale nada ni se escribe aunque se envíen los formularios a mano; un scouter no puede; wp-admin enciende y apaga por grupo; el alta paso a paso (documento en el grupo con y sin membresía activa, mal escrito, nuevo, en otro grupo; datos que faltan, documento desde los 14, fecha futura; revisar; corregir; crear con el nº de socio; nada guardado en WordPress; reenviar sin duplicar; confirmar lo de otro grupo aunque se quite el aviso; menor sin documento); la ficha (botones según tenga o no membresía activa; nueva membresía, cambio de sección con fecha anterior y bien, baja con motivo inventado, con fecha futura y bien con su aviso, documento ya puesto, de otra ficha del grupo, de otro grupo con confirmación); y el aviso de Tesorería (solo las bajas del grupo). Revisadas en el navegador con datos inventados (en su página, en la ventana emergente y a 375 px, sin desbordes).
- **Manual:** sin actualizar todavía, por petición del usuario (se hace al terminar todas las fases).

**v0.23.1 (2026-10-07), con las respuestas del usuario a P-87 a P-90:**

- **Fechas (P-87):** se quedan así; no hace falta dar altas con fecha futura.
- **Tutores (P-88, P-93):** con menos de 18 años, el alta exige el **tutor/a 1 con nombre, apellidos y email** (`Altas::EDAD_ADULTA`, `Altas::tutores_obligatorios()`); el móvil y el tutor/a 2 siguen siendo opcionales. La pantalla lo dice en el bloque de tutores.
- **Avisos de una baja por email (P-90, P-92):** `Usuarios\AvisoBaja` y la plantilla `email/baja.php`. A **Tesorería** (cada cuenta con ese rol menos quien da la baja, en su idioma; si no hay ninguna con email, el email de contacto del grupo): nombre, nº de socio/a, fecha, motivo y su familia. A la **familia** (si es menor: sus cuentas familiares o, si no tiene, los emails de sus tutores en la ficha; si es mayor de edad: la propia persona): la confirmación sin el motivo, con el email de contacto del grupo como «Responder a». Con el motivo **«Fallecimiento» no se escribe a la familia**. Quedan en el registro de emails (tipo «Aviso de baja») con el destino enmascarado; el asunto no lleva el nombre y la vista previa lo vuelve a leer del CRM. El aviso de la pantalla dice cuántos emails han salido.
- **La comprobación del DNI, solo dentro del alta (P-91):** fuera el botón y la pantalla sueltos de Personas (`templates/personas/comprobar.php`).
- **Pruebas:** `altas-crm.php` (35: su parte C prueba ahora la comprobación dentro del alta y que ya no hay pantalla suelta), `altas-crm-escritura.php` (40: tutores de los menores) y `altas-crm-pantallas.php` (53: tutores, emails a Tesorería y a la familia, fallecimiento, tutor de la ficha cuando no hay cuenta familiar, registro de emails sin datos personales). El recorrido por HTTP contra el servidor simulado sigue saliendo entero.

**Lo que se planeó:**

- **Personas → «Alta de socio/a»**, en pasos: documento (fase 1, con la advertencia si está en otro grupo) → datos obligatorios (P-75; el documento, desde los 14 años: P-82) → sección y fecha → vista previa → confirmar → nº de socio. Al final, «Dar acceso» (doc 11).
- **Ficha → «Nueva membresía»** (si no tiene ninguna activa), **«Dar de baja»**, **«Cambiar de sección»** y **poner el documento** a quien no lo tiene.
- Una baja avisa en Tesorería (la familia deja de pagar por esa persona) y en Usuarios (la cuenta pierde el acceso al no tener membresía activa).
- Nada se guarda en WordPress en claro (regla 1): lo escrito va directo al CRM. Textos en ca/eu/gl, manual y capturas.
- ✅ El **informe en wp-admin del §3.7** se hizo en la v0.24.0: wp-admin → Grupos Scouts → **Documentos repetidos** (`Admin\DuplicadosPage`, `Duplicados::repetidos()`): eliges el grupo y, al pulsar el botón (con nonce), lee las fichas del grupo con documento y lista las que comparten el mismo documento normalizado (escrito de cualquier forma), con el documento **enmascarado** (`•••••678Z`), el nombre, el nº de Scouts de España y si tiene membresía activa. Solo lectura; no guarda nada; queda en la auditoría cuántas fichas y cuántos repetidos (`crm_documentos_repetidos`). Prueba en `tests/altas-crm.php` (parte D).

### Fase 4 · Prueba completa con el servidor simulado ✅ (2026-10-07, con la v0.23.1)

**Hecho.** No en el Local que se usa (regla del doc 10 desde el 2026-09-28: las pruebas en el navegador con datos inventados van en un **WordPress aparte**), sino en uno **temporal**: copia de WordPress y Enfold del Local, base de datos propia (`asde_fase4`) en el MySQL de Local, el plugin del repo, el grupo 999 con la URL del CRM apuntando al **servidor de la API simulado** (`tests/servidor-crm`, 127.0.0.1:8790) y un mu-plugin que corta cualquier petición que no vaya a ese servidor y guarda los emails en un fichero en vez de enviarlos. Se monta y desmonta con `herramientas-desarrollo/wp-aparte/montar.sh` y `desmontar.sh` (fuera del repo); al acabar se borraron la base de datos y la carpeta. Con la cuenta de Secretaría de prueba (ficha inventada de Clara), por «Acceso socios» y en el navegador:

| Recorrido | Resultado |
|---|---|
| La conexión del grupo con el CRM | «Conexión correcta» (el servidor simulado contesta el login, el ping y el grupo de seguridad del grupo 999) |
| 1. Alta de una persona nueva (16 años, con tutora), en la ventana emergente | Documento comprobado y normalizado → datos → revisar → **nº de socio 995001** devuelto por el CRM; su ficha sale con la sección (Escultas, recalculada por el CRM) y la tarjeta «Membresía en el CRM» |
| 2. Alta de alguien que está en otro grupo (adulto) | Aviso genérico en los datos y en revisar; **sin marcar la casilla no se crea nada**; marcándola, nº 995002 |
| 3. Alta de alguien que vuelve | El alta se para, enseña su ficha «sin membresía activa» y «Darle una membresía nueva»; en su ficha, membresía nueva (Scouters) con su mismo nº |
| 4. Cambio de sección | Enseña la actual (Escultas desde el 01/09/2023); pasa a Rovers y la anterior queda cerrada con «cambio de sección» |
| 5. Baja (menor con cuenta familiar) | El formulario dice qué cambia (cuenta familiar, avisos por email); tras confirmar, «Avisos por email: 1 a Tesorería y 1 a la familia» y la ficha queda «Sin membresía activa». Los dos emails, bien: a Tesorería con el motivo, a la familia sin él y con el email de contacto del grupo |
| 6. Documento a un menor | Con el documento de otra ficha del grupo, no se guarda («sería la misma persona dos veces»); con uno nuevo, se guarda |
| Lo escrito en el CRM simulado | 7 fichas (2 altas) y 9 membresías (2 altas, quien vuelve y el cambio de sección); ningún error de PHP |

**Lo que se ha visto** (anotado en el doc 04, §7.8):

- En el modo de usuario `local` (el de siempre), una persona recién dada de alta **todavía no tiene nº territorial**: se le asigna en Personas como a cualquier otra («Siguiente nº de socio/a libre»). Con el modo `asde`, el usuario es directamente el nº de Scouts de España (doc 27).
- Los socios no entran por `wp-login.php` (lo impide el plugin a propósito): por «Acceso socios».
- Trampa del entorno (doc 10): el servidor integrado de PHP con **opcache** activado dio un error fatal dentro de WordPress (`Requests\Cookie\Jar cannot implement CurlHandle`); sin opcache, todo bien. `montar.sh` ya lo apaga.

**Lo que se planeó:**

En lugar de un CRM de pruebas (P-69), con el servidor de la fase 2 y un grupo de pruebas del Local, en el navegador: crear una persona (nº de socio devuelto), darle membresía, baja, cambio de sección, alta de alguien que está en otro grupo (advertencia), alta de alguien que vuelve (membresía nueva en su ficha) y documento puesto a un menor. Se anota lo que se ve en el doc 04, §7.

Lo que el simulado no puede confirmar (P-73 y P-74 en la instancia real) se mira en la **primera alta real** del grupo piloto (fase 5), leyendo la ficha creada.

### Fase 5 · Puesta en marcha

**Preparado (2026-10-07); lo hace el usuario en producción:**

1. Subir el plugin (zip 0.24.0) y, en wp-admin → Grupos Scouts → **Módulos**, marcar «Altas en el CRM» **solo en el grupo piloto**.
2. Antes de la primera alta, wp-admin → Grupos Scouts → **Documentos repetidos** del grupo piloto: si salen fichas repetidas, unificarlas en el CRM (o pedírselo a ASDE).
3. **Primera alta real** desde Personas → «Alta de socio/a» (mejor una persona que de verdad se vaya a dar de alta). Anotar el nº de socio que devuelve.
4. Con ese nº, lanzar el script de **solo lectura** `herramientas-desarrollo/crm-revisar-alta.php` (no imprime nombres, documentos ni ids; lo lanza el usuario porque desde la sesión de Claude no se puede leer el CRM real). Comprueba P-72 (nº puesto), **P-73** (el grupo ve la ficha), **P-74** («Secciones actuales» = membresías activas), **P-85** (si después se da una baja: `active` y «Secciones actuales») y **P-86** (campos que tienen las membresías de ASDE y no la nueva). Desde PowerShell:

   ```powershell
   $env:ASDE_SOCIO = 'nº de socio'; & 'C:\Program Files\Git\bin\bash.exe' 'C:/Claude/Scouts De Balears  - Area Privada/herramientas-desarrollo/wp-local.sh' eval-file 'C:/Claude/Scouts De Balears  - Area Privada/herramientas-desarrollo/crm-revisar-alta.php'; Remove-Item Env:ASDE_SOCIO
   ```

   (El Local tiene que tener la conexión del grupo piloto con el CRM real, como para `crm-dni-existe.php`; `ASDE_GRUPO` si no es el 684.) Se probó contra el servidor simulado con un arnés (alta, baja y membresía nueva): lee bien las fichas y las membresías y, sin metadatos de campos, avisa en vez de cortarse.
5. Con el resultado se cierran P-73, P-74, P-85 y P-86 (doc 09) y, si va bien, se activan más grupos.

**Lo que se planeó:**

- Se activa para un grupo piloto. La primera alta real se revisa entera: que el grupo vea la ficha, que «Secciones actuales» y el nº de socio estén bien.
- Se revisan la auditoría y el informe del §3.7 durante las primeras altas y, si va bien, se abre al resto de grupos.

## Fuentes

- SinergiaCRM (https://github.com/SinergiaTIC/SinergiaCRM, rama `master`): `custom/modules/Contacts/SticLogicHooksCode.php`, `sticContactsDuplicateQueries.php`, `SticUtils.php`; `modules/stic_Contacts_Relationships/LogicHooksCode.php`. Consultado el 2026-10-06; licencia AGPL-3.0: no se ha copiado código, solo se describe qué hace.
- SuiteCRM (https://github.com/salesagility/SuiteCRM): `modules/SecurityGroups/SecurityGroup.php` (herencia de grupos de seguridad en los registros nuevos).
- Doc 04 (§7, modelo real del CRM de ASDE) y doc 33 (estudio de la API).
