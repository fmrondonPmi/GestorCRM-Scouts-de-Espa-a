# 34 · Plan de acción: crear personas y asociar membresías en SinergiaCRM

## Petición del usuario (2026-10-06)

> «Se hizo el estudio de cómo crear y asociar membresías: genera el plan de acción para esto. Con respecto a la pregunta de qué sucede si el DNI ya existe, deberás probarlo […] con un DNI que existe […] y cómo se puede asegurar que nuestro sistema no genere duplicados con otros grupos.»

Parte del estudio del doc 33 (qué permite la API y cómo encaja con el modelo de ASDE). Este doc dice **qué se hace, en qué orden y con qué garantías**. Todavía **no hay código** de escritura y **no se ha escrito nada en el CRM** (regla 2 del `CLAUDE.md`).

## 1. Lo que se ha averiguado (2026-10-06)

Leyendo el código público de SinergiaCRM y de SuiteCRM (la base de SinergiaCRM). La instancia de ASDE puede tener personalizaciones propias: lo que dice esta tabla se confirma en la primera prueba de la fase 4.

| Pregunta | Respuesta | Fuente |
|---|---|---|
| ¿El CRM rechaza crear una persona con un DNI que ya existe? | **No.** Al guardar una persona no se comprueba que el DNI (`stic_identification_number_c`) sea único: los *logic hooks* de `Contacts` solo calculan la edad y datos de localización. La búsqueda de duplicados (por nombre y apellidos o por DNI) solo existe en el **formulario web** del CRM, y avisa sin bloquear. `set_entry` por la API no pasa por ella: **se crearía un duplicado sin ningún aviso** | `custom/modules/Contacts/SticLogicHooksCode.php` y `sticContactsDuplicateQueries.php` |
| ¿El CRM valida el formato del DNI? | No en el código estándar | `custom/modules/Contacts/SticUtils.php` |
| P-74 · ¿«Secciones actuales» se recalcula sola al guardar una membresía? | **Sí, también por la API.** Antes de guardar la membresía se calcula `active` a partir de las fechas; después se recalcula `stic_relationship_type_c` de la persona (`setRelationshipType`), igual que al enlazar o desenlazar una membresía. El plugin **no** tiene que escribir «Secciones actuales» | `modules/stic_Contacts_Relationships/LogicHooksCode.php` |
| P-73 · ¿La persona nueva entra sola en el grupo de seguridad del grupo? | **Depende de la configuración de la instancia**: SuiteCRM añade un registro **nuevo** a los grupos de seguridad de quien lo crea si `securitysuite_inherit_creator` está activo (y al de la persona asignada o al del registro padre con `securitysuite_inherit_assigned` / `securitysuite_inherit_parent`). Si el usuario de API solo está en el grupo del grupo scout, la ficha quedaría en ese grupo. Si no, hay que enlazarla con `set_relationship` (`Contacts` → `SecurityGroups`). El plugin lo **comprueba después de crear** (fase 2) | `modules/SecurityGroups/SecurityGroup.php` (SuiteCRM) |
| ¿El grupo ve a las personas de otros grupos? | No: `get_entry_list` solo devuelve las del grupo de seguridad. Pero **`get_entries_count` cuenta en todo el CRM federal** (doc 04, §7.6): dice **cuántas** fichas cumplen una condición sin devolver ningún dato | Doc 04 |

**Consecuencia:** el CRM no protege de los duplicados; lo tiene que hacer el plugin **antes** de crear, y para lo que está en otros grupos solo tiene a mano un **recuento** federal.

## 2. La prueba con un DNI real que ya existe

- El usuario facilitó un DNI que existe en el CRM (no se anota aquí: regla 1). El formato es válido (la letra cuadra).
- **Lo que no se ha hecho:** darlo de alta de verdad. Sería escribir en el CRM real (regla 2) y, por lo visto en el §1, **crearía un duplicado real** en el CRM federal que luego tendría que fusionar quien lo administra. Esa prueba se hace en la fase 4, en un CRM de pruebas o con una ficha acordada con ASDE.
- **Lo que se ha preparado:** `herramientas-desarrollo/crm-dni-existe.php` (fuera del repo), que hace la comprobación previa al alta **solo leyendo** y con las escrituras cortadas: (0) comprueba que el recuento sigue siendo federal; (1) cuenta en todo el CRM las fichas con ese documento, escrito de varias formas (mayúscula, minúscula, con guion, con espacio, solo cifras); (2) busca en el grupo las fichas con ese documento y si tienen membresía activa; (3) dice qué haría el alta. No imprime nombres, ids ni el documento, y el DNI va solo en una variable de entorno.
- **Pendiente:** el entorno de Claude no tiene permiso para leer el CRM real, así que **lo lanza el usuario** (orden en el resumen de la sesión y en la cabecera del script) y el resultado se anota aquí:

| Comprobación | Resultado |
|---|---|
| ¿El recuento es federal? | (pendiente) |
| Fichas con ese documento en todo el CRM | (pendiente) |
| … en el grupo, y con membresía activa | (pendiente) |
| Lo que haría el alta | (pendiente) |

## 3. Cómo evitar duplicados

Seis capas, de la más barata a la más cara. Las cuatro primeras van **antes** de crear nada.

1. **Documento normalizado y válido.** Mayúsculas, sin espacios, puntos ni guiones (`PersonaRepository::normalizar_documento`, ya existe), y comprobación de la letra del DNI o del NIE (módulo 23). Si no es válido, no se sigue. Al CRM se escribe **siempre** normalizado, para que las búsquedas exactas funcionen.
2. **¿Está en el grupo?** Con `get_entry_list`, en **todas** las fichas del grupo, con membresía activa o sin ella (no como `por_documento`, que hoy solo mira las activas): por las cifras del documento con `LIKE` y comparando en memoria el documento normalizado. Para menores sin DNI, por nombre, apellidos y fecha de nacimiento. Si está, **no se crea**: si no tiene membresía activa (alguien que vuelve), se le da una **membresía nueva en su ficha**; si la tiene, no hay nada que dar de alta.
3. **¿Está en otro grupo?** Con `get_entries_count` en todo el CRM, por el documento (normalizado y con las cifras, por si en el CRM está escrito de otra forma). Si cuenta alguna ficha que el grupo no ve, **no se crea**: es un **traslado** y lo tramita la OF o ASDE (P-71). No se enseña nada de esa ficha: solo «ya existe en el CRM de Scouts de España». Para menores sin DNI, el recuento por nombre, apellidos y fecha de nacimiento da un **aviso** que hay que confirmar (puede ser otra persona: P-80).
   - **Canario:** antes de fiarse del recuento, el plugin compara cuántas fichas cuenta `get_entries_count` en total con cuántas ve el grupo. Si fueran las mismas, el recuento habría dejado de ser federal (ASDE puede cambiarlo en cualquier momento) y **las altas de personas nuevas se bloquean** («consulta a tu OF») hasta revisarlo. Mejor no dar de alta que duplicar.
   - Esta capa se apoya en un comportamiento del CRM, no en algo pensado para esto: **hay que acordarlo con ASDE** (P-78), que puede preferir darnos otra forma de comprobarlo.
4. **Sin carreras dentro de la web.** Un cerrojo por documento (fila en una tabla con clave única e `INSERT IGNORE`, como el de la instantánea y el del instalador) mientras dura el alta, para que dos clics o dos personas a la vez no creen dos fichas. Justo antes del `set_entry` se repiten las capas 2 y 3.
5. **Reintentos sin duplicar.** El alta son varios pasos (persona → grupo de seguridad → membresía). Cada paso hecho se apunta en una tabla de operaciones (ids del CRM y estado, **sin datos personales**). Si un paso falla, el reintento sigue desde ese paso y **no vuelve a crear la persona**.
6. **Después de crear.** Se vuelve a contar en todo el CRM: si hay más de una ficha con ese documento (otro grupo la creó a la vez), se avisa a quien administra la web y a la OF para que las fusionen. Y un informe de solo lectura en wp-admin lista las fichas del grupo con el mismo documento normalizado (duplicados que ya existan).

Lo que **no** se puede garantizar desde la web: que otro grupo, la OF o el propio CRM creen la misma persona por otro camino. Eso solo lo arreglaría una regla de unicidad en el propio CRM (P-81).

## 4. Plan de acción

Cada fase se entrega como una versión, con sus pruebas (CRM simulado) y sus docs. Las fases 2 y 3 **nacen apagadas** (regla 5): una opción en wp-admin las activa por grupo.

### Fase 0 · Decisiones y entorno (bloquea a las fases 2 a 5)

- Que el usuario lance `crm-dni-existe.php` con el DNI que facilitó y se anote el resultado en el §2.
- Respuestas de ASDE/OF: **P-69** (CRM de pruebas), **P-70** (si autorizan que el grupo cree personas y dé altas y bajas), **P-71** (traslados), **P-72** (quién pone el nº de socio), **P-75** (datos obligatorios) y **P-78** (comprobación federal con el recuento).
- **Hecho:** P-73 y P-74 quedan contestadas a falta de confirmarlo en la prueba (§1).

### Fase 1 · Comprobación previa al alta (solo lectura; se puede hacer ya)

- **Qué:** `Crm\Duplicados` con las capas 1 a 3 (validar documento, buscar en el grupo, contar en el CRM federal y el canario). En Personas, **«¿Está ya en el CRM?»** (Secretaría y Coordinación): con un documento (o nombre, apellidos y fecha de nacimiento) dice si está en el grupo (con o sin membresía activa), si existe en otro grupo (traslado: hay que pedirlo a la OF) o si no existe.
- **Por qué primero:** no escribe nada, así que no espera a P-69 ni a P-70, y ya sirve: hoy Secretaría no tiene forma de saber si alguien que llega ya está en otro grupo. Si ASDE no acepta el recuento federal (P-78), se quita esa parte y queda la del grupo.
- **Pruebas:** el CRM simulado aprende `get_entries_count` federal (fichas de «otros grupos» que `get_entry_list` no devuelve) y el caso del canario.
- **Hecho cuando:** los cuatro resultados salen bien con datos inventados, nunca se enseña nada de otro grupo y la auditoría guarda que se hizo una comprobación, sin el documento.

### Fase 2 · Escritura en el CRM simulado (sin pantalla todavía)

- **Qué:** en `Crm\Escritura` (la única puerta): `crear_persona()`, `alta()` (membresía nueva, también para una ficha que ya existe), `baja()` (fecha y motivo; nunca borrar) y `cambio_seccion()` (baja con `cambioseccion` y alta el mismo día). Comprobar después de crear que el grupo **ve** la ficha y, si no, enlazarla a su grupo de seguridad (P-73). Las capas 4 a 6 del §3. Tabla de operaciones para reintentar.
- **CRM simulado:** `set_entry` sin id, `set_relationship`, `get_relationships` y lo que hace SinergiaCRM al guardar (calcular `active`, recalcular «Secciones actuales» y, según un ajuste, heredar el grupo de seguridad).
- **Pruebas:** una por operación, comprobando lo que se envía al CRM; un alta con un DNI que ya está en el grupo, otro en otro grupo, dos altas a la vez y un fallo a mitad con su reintento.
- **Hecho cuando:** ninguna prueba crea dos fichas para el mismo documento.

### Fase 3 · Pantallas y avisos

- **Personas → «Alta de socio/a»**, en pasos: documento (fase 1) → datos obligatorios (P-75) → sección y fecha → vista previa → confirmar. Al final, «Dar acceso» (doc 11).
- **Ficha → «Nueva membresía»** (si no tiene ninguna activa), **«Dar de baja»** y **«Cambiar de sección»**.
- Una baja avisa en Tesorería (la familia deja de pagar por esa persona) y en Usuarios (la cuenta pierde el acceso al no tener membresía activa).
- Nada se guarda en WordPress en claro (regla 1): lo escrito va directo al CRM. Textos en ca/eu/gl, manual y capturas.

### Fase 4 · Prueba en un CRM de pruebas (o con una ficha acordada con ASDE)

Lista que se hace **una vez**, anotando cada resultado en el doc 04, §7:

1. Crear una persona: ¿la ve el grupo sin hacer nada más (P-73)?
2. Darle una membresía: ¿se recalculan `active` y «Secciones actuales» (P-74)?
3. Darle de baja y cambiarla de sección.
4. **Crear otra persona con el mismo DNI:** confirmar que el CRM la acepta (y que por eso el plugin la tiene que frenar antes).
5. ¿Quién pone el nº de socio (P-72)?
6. Quien administra el CRM borra o fusiona lo creado.

### Fase 5 · Puesta en marcha

- Se activa para un grupo piloto, con el usuario de API y los permisos que acuerde ASDE.
- Se revisan la auditoría y el informe de duplicados durante las primeras altas y, si va bien, se abre al resto de grupos.

## 5. Preguntas nuevas (doc 09)

- **P-78** ¿ASDE acepta que el plugin use el recuento federal (`get_entries_count`, que solo devuelve un número) para saber si un DNI ya existe en otro grupo antes de un alta? ¿O prefieren darnos otro mecanismo?
- **P-79** Si alguien que vuelve ya tiene ficha en el grupo (sin membresía activa), ¿se le da una membresía nueva en la misma ficha? (Es lo que propone el plan.)
- **P-80** Menores sin DNI: ¿qué se considera la misma persona (nombre, apellidos y fecha de nacimiento) y, si coincide en otro grupo, se avisa o se bloquea?
- **P-81** ¿Puede ASDE poner en el CRM una regla de DNI único (o un aviso) para que tampoco se dupliquen las altas hechas por otros caminos?

## Fuentes

- SinergiaCRM (https://github.com/SinergiaTIC/SinergiaCRM, rama `master`): `custom/modules/Contacts/SticLogicHooksCode.php`, `sticContactsDuplicateQueries.php`, `SticUtils.php`; `modules/stic_Contacts_Relationships/LogicHooksCode.php`. Consultado el 2026-10-06; licencia AGPL-3.0: no se ha copiado código, solo se describe qué hace.
- SuiteCRM (https://github.com/salesagility/SuiteCRM): `modules/SecurityGroups/SecurityGroup.php` (herencia de grupos de seguridad en los registros nuevos).
- Doc 04 (§7, modelo real del CRM de ASDE) y doc 33 (estudio de la API).
