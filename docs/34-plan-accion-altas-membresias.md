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
| ¿Se puede saber en qué grupo y OF está la otra ficha? | Sin leerla, solo **deduciéndolo con recuentos** sobre los grupos de seguridad (`SecurityGroups`, por `tipoasociacion_c`, `organizacionfederada_c` y `numgrupo_c` cifra a cifra), si el CRM acepta la subconsulta sobre `securitygroups_records`. Son unas 20–50 consultas (hasta un minuto). **Se confirma con el script del §2** | — |

## 2. Decisiones del usuario (2026-10-06, fase 0)

| | Decisión |
|---|---|
| **P-70** Permiso | **Sí**: el grupo crea personas y da altas y bajas desde la web |
| **P-78** Recuento federal | **Sí**: el plugin puede usar `get_entries_count` para saber si un documento ya existe en otro grupo |
| **P-71** Si está en otro grupo | **Se puede continuar** creando la persona para asociarla al grupo. Sale una **advertencia** de que ya está dada de alta en otro grupo, con **el grupo y la OF** a los que pertenece |
| **P-81** Regla de DNI único en el CRM | **No**: una persona puede estar en dos grupos o en dos asociaciones. No es una restricción: **se informa siempre** al usuario del duplicado durante el alta |
| **P-79** Quien vuelve | Si ya tiene ficha en el grupo, **solo necesita una membresía nueva** en esa ficha |
| **P-80** Menores sin documento | No se valida nada al darlos de alta sin documento. Se valida **cuando se le asigne un nº de documento**: antes de guardarlo en la ficha se comprueba si ya existe y se avisa del duplicado |
| **P-72** Nº de socio | **Lo devuelve el CRM** al crear la persona: el nº de Scouts de España es automático |
| **P-75** Datos obligatorios | **Nombre, apellidos, fecha de nacimiento, DNI o nº de documento y género** (`stic_gender_c`) |
| **P-69** Entorno de pruebas | **No hay CRM de pruebas**: se hace un **servidor de la API simulado** que conteste lo que se espera en cada acción |

Supuestos que salen de las respuestas (doc 09, para confirmar):

- **P-82** El documento es obligatorio en el alta (P-75) salvo en **menores que todavía no lo tienen** (P-80): para ellos se puede dejar vacío.
- **P-83** Si el recuento deja de ser federal (canario, §3), no se puede saber si está en otro grupo: **se avisa de que no se ha podido comprobar y se deja continuar**, porque estar en otro grupo tampoco bloquea.

### La prueba con un DNI real que ya existe

- El usuario facilitó un DNI que existe en el CRM (no se anota aquí: regla 1). El formato es válido (la letra cuadra).
- **No se ha dado de alta de verdad**: sería escribir en el CRM real (regla 2) y crearía otra ficha en el CRM federal.
- **Script de solo lectura**: `herramientas-desarrollo/crm-dni-existe.php` (fuera del repo), con las escrituras cortadas: (0) comprueba que el recuento sigue siendo federal; (1) cuenta en todo el CRM las fichas con ese documento escrito de varias formas; (2) busca en el grupo las fichas con ese documento y si tienen membresía activa; (3) dice qué haría el alta; (4) deduce con recuentos el grupo y la OF de las fichas de otros grupos; (5) lee qué campos marca el CRM como obligatorios y las opciones de género (solo metadatos). No imprime nombres, ids ni el documento; el DNI va solo en una variable de entorno. Se probó entero con un arnés que contesta con datos inventados (ninguna petición a la red): los cuatro casos (en otro grupo, en el grupo, en ninguno y recuento filtrado) dan la decisión esperada.
- **Lo lanza el usuario** (el entorno de Claude no tiene permiso para leer el CRM real). Desde PowerShell, con Local arrancado (la orden de Git Bash está en la cabecera del script):

  ```powershell
  $env:ASDE_DNI = '…'; & 'C:\Program Files\Git\bin\bash.exe' 'C:/Claude/Scouts De Balears  - Area Privada/herramientas-desarrollo/wp-local.sh' eval-file 'C:/Claude/Scouts De Balears  - Area Privada/herramientas-desarrollo/crm-dni-existe.php'; Remove-Item Env:ASDE_DNI
  ```

| Comprobación | Resultado |
|---|---|
| ¿El recuento es federal? | (pendiente) |
| Fichas con ese documento en todo el CRM | (pendiente) |
| … en el grupo, y con membresía activa | (pendiente) |
| Lo que haría el alta | (pendiente) |
| ¿Se deducen el grupo y la OF? ¿Cuánto tarda? | (pendiente) |
| Obligatorios según el CRM y opciones de género | (pendiente) |

## 3. Duplicados: qué se comprueba y qué se hace

Estar en otro grupo **no impide** el alta (P-71, P-81): lo que no puede pasar es **crear dos fichas en el mismo grupo** ni que nadie se entere de que la persona ya existe.

1. **Documento normalizado y válido.** Mayúsculas, sin espacios, puntos ni guiones (`PersonaRepository::normalizar_documento`, ya existe), y comprobación de la letra del DNI o del NIE (módulo 23). Si no es válido, no se sigue. Al CRM se escribe **siempre** normalizado.
2. **¿Está en el grupo?** Con `get_entry_list`, en **todas** las fichas del grupo, con membresía activa o sin ella (no como `por_documento`, que hoy solo mira las activas): por las cifras del documento con `LIKE` y comparando en memoria el documento normalizado. Si está, **no se crea otra ficha**: si no tiene membresía activa, el alta es una **membresía nueva en su ficha** (P-79); si la tiene, no hay nada que dar de alta. Si hubiera varias fichas del grupo con ese documento, se avisa para que se unifiquen.
3. **¿Está en otro grupo u organización?** Con `get_entries_count` en todo el CRM, por el documento (normalizado y con las cifras, por si en el CRM está escrito de otra forma). Si cuenta alguna ficha que el grupo no ve, sale la **advertencia**: «Esta persona ya está dada de alta en el grupo N de la OF X», y se puede **continuar** (P-71). El grupo y la OF se deducen con recuentos (§1); si el CRM no lo permitiera, la advertencia dice solo que está en otro grupo.
   - **Canario:** antes de fiarse del recuento, el plugin compara cuántas fichas cuenta `get_entries_count` en total con cuántas ve el grupo. Si fueran las mismas, el recuento habría dejado de ser federal: la advertencia dice que **no se ha podido comprobar** si está en otros grupos y se deja continuar (P-83), y wp-admin avisa a quien administra la web.
   - Como deducir el grupo y la OF puede tardar hasta un minuto, se hace **mientras se rellena el resto del formulario** (petición en segundo plano) y el resultado se guarda unos minutos, **cifrado y sin el documento** (regla 1).
4. **Menores sin documento (P-80).** Se dan de alta sin él. Cuando más adelante se le pone un documento en su ficha, **antes de guardarlo** se hacen las comprobaciones 1 a 3: si ya existe en el grupo, se avisa y no se guarda (sería la misma persona dos veces); si existe en otro grupo, se avisa y se puede guardar.
5. **Sin carreras dentro de la web.** Un cerrojo por documento (fila en una tabla con clave única e `INSERT IGNORE`, como el de la instantánea y el del instalador) mientras dura el alta, para que dos clics o dos personas a la vez no creen dos fichas en el grupo. Justo antes del `set_entry` se repite la comprobación 2.
6. **Reintentos sin duplicar.** El alta son varios pasos (persona → grupo de seguridad → membresía → leer el nº de socio). Cada paso hecho se apunta en una tabla de operaciones (ids del CRM y estado, **sin datos personales**). Si un paso falla, el reintento sigue desde ese paso y **no vuelve a crear la persona**.
7. **Informe en wp-admin** (solo lectura): fichas del grupo con el mismo documento normalizado, por si ya hubiera duplicados de antes.

## 4. Plan de acción

Cada fase se entrega como una versión, con sus pruebas (CRM simulado) y sus docs. Las fases 2 y 3 **nacen apagadas** (regla 5): una opción en wp-admin las activa por grupo.

### Fase 0 · Decisiones (hecha, salvo la prueba con el DNI)

- ✅ Respuestas a P-69, P-70, P-71, P-72, P-75, P-78, P-79, P-80 y P-81 (§2). P-73 y P-74, encaminadas (§1).
- ⏳ El usuario lanza `crm-dni-existe.php` con el DNI que facilitó y se anota el resultado en el §2.
- ⏳ Confirmar los supuestos P-82 y P-83.

### Fase 1 · Comprobación previa (solo lectura)

- **Qué:** `Crm\Duplicados` con las comprobaciones 1 a 3 del §3 (documento, grupo, recuento federal con canario, grupo y OF por recuentos). En Personas, **«¿Está ya en el CRM?»** (Secretaría y Coordinación): con un documento dice si está en el grupo (con o sin membresía activa) y si está en otros grupos (cuáles y de qué OF).
- **Por qué primero:** no escribe nada y es la pieza en la que se apoyan el alta (fase 3) y el documento de un menor (§3.4).
- **Pruebas:** el CRM simulado aprende `get_entries_count` federal (fichas de «otros grupos» que `get_entry_list` no devuelve), los recuentos sobre `SecurityGroups` y el caso del canario.
- **Hecho cuando:** los resultados salen bien con datos inventados, no se lee ninguna ficha de otro grupo y la auditoría guarda que se hizo una comprobación, sin el documento.

### Fase 2 · Escritura en el CRM simulado y servidor de la API simulado (P-69)

- **Qué:** en `Crm\Escritura` (la única puerta): `crear_persona()` (con los obligatorios de P-75), `alta()` (membresía nueva, también para una ficha que ya existe), `baja()` (fecha y motivo; nunca borrar), `cambio_seccion()` (baja con `cambioseccion` y alta el mismo día) y `poner_documento()` (con la comprobación del §3.4). Después de crear: comprobar que el grupo **ve** la ficha (si no, enlazarla a su grupo de seguridad: P-73) y **leer el nº de socio** que pone el CRM (P-72). Las comprobaciones 5 y 6 del §3.
- **CRM simulado** (`tests/lib/CrmSimulado.php`): `set_entry` sin id (devuelve un id y pone un nº de socio nuevo), `set_relationship`, `get_relationships` y lo que hace SinergiaCRM al guardar (calcular `active`, recalcular «Secciones actuales» y, según un ajuste, heredar el grupo de seguridad).
- **Servidor de la API simulado:** el mismo CRM simulado servido por HTTP (`php -S` con un router en `tests/servidor-crm/`, fuera del zip) que contesta como el endpoint `v4_1_SticCustom/rest.php`. En el Local se configura un **grupo de pruebas** con la URL de ese servidor: así se prueba el alta entera en el navegador, con peticiones de verdad, sin tocar el CRM real.
- **Pruebas:** una por operación, comprobando lo que se envía al CRM; un alta con un documento que ya está en el grupo, otro que está en otro grupo, dos altas a la vez, un fallo a mitad con su reintento y un documento puesto después a un menor.
- **Hecho cuando:** ninguna prueba crea dos fichas en el mismo grupo y todas las altas acaban con su nº de socio.

### Fase 3 · Pantallas y avisos

- **Personas → «Alta de socio/a»**, en pasos: documento (fase 1, con la advertencia si está en otro grupo) → datos obligatorios (P-75; el documento puede faltar en un menor) → sección y fecha → vista previa → confirmar → nº de socio. Al final, «Dar acceso» (doc 11).
- **Ficha → «Nueva membresía»** (si no tiene ninguna activa), **«Dar de baja»**, **«Cambiar de sección»** y **poner el documento** a quien no lo tiene.
- Una baja avisa en Tesorería (la familia deja de pagar por esa persona) y en Usuarios (la cuenta pierde el acceso al no tener membresía activa).
- Nada se guarda en WordPress en claro (regla 1): lo escrito va directo al CRM. Textos en ca/eu/gl, manual y capturas.

### Fase 4 · Prueba completa con el servidor simulado

En lugar de un CRM de pruebas (P-69), con el servidor de la fase 2 y un grupo de pruebas del Local, en el navegador: crear una persona (nº de socio devuelto), darle membresía, baja, cambio de sección, alta de alguien que está en otro grupo (advertencia con grupo y OF), alta de alguien que vuelve (membresía nueva en su ficha) y documento puesto a un menor. Se anota lo que se ve en el doc 04, §7.

Lo que el simulado no puede confirmar (P-73 y P-74 en la instancia real) se mira en la **primera alta real** del grupo piloto (fase 5), leyendo la ficha creada.

### Fase 5 · Puesta en marcha

- Se activa para un grupo piloto. La primera alta real se revisa entera: que el grupo vea la ficha, que «Secciones actuales» y el nº de socio estén bien.
- Se revisan la auditoría y el informe del §3.7 durante las primeras altas y, si va bien, se abre al resto de grupos.

## Fuentes

- SinergiaCRM (https://github.com/SinergiaTIC/SinergiaCRM, rama `master`): `custom/modules/Contacts/SticLogicHooksCode.php`, `sticContactsDuplicateQueries.php`, `SticUtils.php`; `modules/stic_Contacts_Relationships/LogicHooksCode.php`. Consultado el 2026-10-06; licencia AGPL-3.0: no se ha copiado código, solo se describe qué hace.
- SuiteCRM (https://github.com/salesagility/SuiteCRM): `modules/SecurityGroups/SecurityGroup.php` (herencia de grupos de seguridad en los registros nuevos).
- Doc 04 (§7, modelo real del CRM de ASDE) y doc 33 (estudio de la API).
