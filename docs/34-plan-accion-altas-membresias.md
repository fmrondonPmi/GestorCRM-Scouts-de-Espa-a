# 34 · Plan de acción: crear personas y asociar membresías en SinergiaCRM

## Petición del usuario (2026-10-06)

> «Se hizo el estudio de cómo crear y asociar membresías: genera el plan de acción para esto. Con respecto a la pregunta de qué sucede si el DNI ya existe, deberás probarlo […] con un DNI que existe […] y cómo se puede asegurar que nuestro sistema no genere duplicados con otros grupos.»

Parte del estudio del doc 33 (qué permite la API y cómo encaja con el modelo de ASDE). Este doc dice **qué se hace, en qué orden y con qué garantías**. Todavía **no hay código** de escritura y **no se ha escrito nada en el CRM** (regla 2 del `CLAUDE.md`).

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

### Fase 2 · Escritura en el CRM simulado y servidor de la API simulado (P-69)

- **Qué:** en `Crm\Escritura` (la única puerta): `crear_persona()` (con los obligatorios de P-75), `alta()` (membresía nueva, también para una ficha que ya existe), `baja()` (fecha y motivo; nunca borrar), `cambio_seccion()` (baja con `cambioseccion` y alta el mismo día) y `poner_documento()` (con la comprobación del §3.4). Después de crear: comprobar que el grupo **ve** la ficha (si no, enlazarla a su grupo de seguridad: P-73) y **leer el nº de socio** que pone el CRM (P-72). Las comprobaciones 5 y 6 del §3.
- **CRM simulado** (`tests/lib/CrmSimulado.php`): `set_entry` sin id (devuelve un id y pone un nº de socio nuevo), `set_relationship`, `get_relationships` y lo que hace SinergiaCRM al guardar (calcular `active`, recalcular «Secciones actuales» y, según un ajuste, heredar el grupo de seguridad).
- **Servidor de la API simulado:** el mismo CRM simulado servido por HTTP (`php -S` con un router en `tests/servidor-crm/`, fuera del zip) que contesta como el endpoint `v4_1_SticCustom/rest.php`. En el Local se configura un **grupo de pruebas** con la URL de ese servidor: así se prueba el alta entera en el navegador, con peticiones de verdad, sin tocar el CRM real.
- **Pruebas:** una por operación, comprobando lo que se envía al CRM; un alta con un documento que ya está en el grupo, otro que está en otro grupo, dos altas a la vez, un fallo a mitad con su reintento y un documento puesto después a un menor.
- **Hecho cuando:** ninguna prueba crea dos fichas en el mismo grupo y todas las altas acaban con su nº de socio.

### Fase 3 · Pantallas y avisos

- **Personas → «Alta de socio/a»**, en pasos: documento (fase 1, con la advertencia si está en otro grupo) → datos obligatorios (P-75; el documento, desde los 14 años: P-82) → sección y fecha → vista previa → confirmar → nº de socio. Al final, «Dar acceso» (doc 11).
- **Ficha → «Nueva membresía»** (si no tiene ninguna activa), **«Dar de baja»**, **«Cambiar de sección»** y **poner el documento** a quien no lo tiene.
- Una baja avisa en Tesorería (la familia deja de pagar por esa persona) y en Usuarios (la cuenta pierde el acceso al no tener membresía activa).
- Nada se guarda en WordPress en claro (regla 1): lo escrito va directo al CRM. Textos en ca/eu/gl, manual y capturas.

### Fase 4 · Prueba completa con el servidor simulado

En lugar de un CRM de pruebas (P-69), con el servidor de la fase 2 y un grupo de pruebas del Local, en el navegador: crear una persona (nº de socio devuelto), darle membresía, baja, cambio de sección, alta de alguien que está en otro grupo (advertencia), alta de alguien que vuelve (membresía nueva en su ficha) y documento puesto a un menor. Se anota lo que se ve en el doc 04, §7.

Lo que el simulado no puede confirmar (P-73 y P-74 en la instancia real) se mira en la **primera alta real** del grupo piloto (fase 5), leyendo la ficha creada.

### Fase 5 · Puesta en marcha

- Se activa para un grupo piloto. La primera alta real se revisa entera: que el grupo vea la ficha, que «Secciones actuales» y el nº de socio estén bien.
- Se revisan la auditoría y el informe del §3.7 durante las primeras altas y, si va bien, se abre al resto de grupos.

## Fuentes

- SinergiaCRM (https://github.com/SinergiaTIC/SinergiaCRM, rama `master`): `custom/modules/Contacts/SticLogicHooksCode.php`, `sticContactsDuplicateQueries.php`, `SticUtils.php`; `modules/stic_Contacts_Relationships/LogicHooksCode.php`. Consultado el 2026-10-06; licencia AGPL-3.0: no se ha copiado código, solo se describe qué hace.
- SuiteCRM (https://github.com/salesagility/SuiteCRM): `modules/SecurityGroups/SecurityGroup.php` (herencia de grupos de seguridad en los registros nuevos).
- Doc 04 (§7, modelo real del CRM de ASDE) y doc 33 (estudio de la API).
