# 33 · Plan (sin código): altas, bajas y creación de personas en SinergiaCRM

## Petición del usuario (2026-10-06)

> «Revisar la API de Sinergia (https://github.com/SinergiaTIC/SinergiaCRM-API-Examples) para ver altas y bajas y creación de personas.»

Estudio de los ejemplos oficiales de SinergiaTIC, contrastado con lo que ya sabemos del CRM de ASDE (doc 04, §7) y con el cliente del plugin. **No se ha escrito nada en el CRM** (regla 2 del `CLAUDE.md`): esto es un plan para decidir.

## 1. Qué hay en el repositorio de ejemplos

| Carpeta | Qué es |
|---|---|
| `v4.1/` | Cliente web y cliente PHP (`REST/PHP/APIClient.php`) de la **API REST v4.1** con usuario y contraseña (la que usa el plugin, con el endpoint propio de SinergiaCRM `custom/service/v4_1_SticCustom/rest.php`). Ejemplos en `REST/PHP/Ejemplos/GET` y `SET`: `get_entry`, `get_entry_list`, `get_module_fields`, `get_relationships`, `get_language_definition`, **`set_entry`**, **`set_relationship`**, documentos e imágenes |
| `v8/` | Cliente de la **API V8** (JSON:API, OAuth2 *client credentials*): solo lecturas (personas paginadas, relaciones activas de una persona, listas desplegables, módulos y permisos) |
| `PortalOauth/` | Flujo OAuth2 de *authorization code* para el portal de participantes |

### Lo que enseñan los ejemplos de escritura (v4.1)

- **Crear una persona:** `set_entry` con `module_name = Contacts` y **sin `id`**; devuelve el `id` nuevo. El cliente convierte los datos en `name_value_list` (`[{name, value}]`). Ejemplo 1: `first_name`, `last_name`. Ejemplo 3: además `created_by` y `set_created_by = false` (para que el registro conste creado por otro usuario).
- **Actualizar:** `set_entry` **con** `id` (ejemplos 2, 7, 9 y 12: emails, contraseña del portal, *opt-out*). Es lo que ya hace el plugin (`SinergiaV41Client::set_entry`, a través de `Crm\Escritura`, la única puerta de escritura).
- **Crear un registro enlazado:** el ejemplo 4 (`stic_Registrations`) pone los **campos de enlace** directamente en el `set_entry` (`stic_registrations_contactscontacts_ida` = id de la persona, `..._stic_events_ida` = id del evento) y `assigned_user_id`.
- **Enlazar registros ya creados:** `set_relationship` con `module_name`, `module_id`, `link_field_name` y `related_ids` (1–100). El ejemplo 1 enlaza una persona con una **relación** (`link_field_name = stic_contacts_relationships_contacts`).
- **Leer lo enlazado:** `get_relationships` (`deleted = 0` para los vigentes). Ejemplo 3: `Contacts` → **`SecurityGroups`** (los grupos de seguridad de una persona).
- **V8:** las relaciones activas de una persona son `GET /Api/V8/module/Contacts/{id}/relationships/stic_contacts_relationships_contacts?filter[active][eq]=1`, con `start_date`, `end_date`, `relationship_type`, `role`, `end_reason` y `other_end_reasons`.

## 2. Cómo encaja con el modelo de ASDE (doc 04, §7)

| Operación | En el CRM | Notas |
|---|---|---|
| **Crear persona** | `set_entry Contacts` (sin id): nombre, apellidos, fecha de nacimiento, DNI (`stic_identification_number_c`), contacto y, en menores, `tutor1_*` / `tutor2_*` | Debe quedar en el **grupo de seguridad del grupo** (si no, el grupo no la ve: doc 04 §7.2). Depende de la configuración de SuiteCRM (heredar el grupo del usuario que crea) o de enlazarla con `set_relationship` (`Contacts` → `SecurityGroups`). **Hay que confirmarlo** |
| **Alta** (membresía) | `set_entry stic_Contacts_Relationships`: `relationship_type` (la sección: Castores… Scouters, Voluntariado), `start_date`, `estado_c = activo`, `active = 1`, `numsocio_c`, y el enlace a la persona (`stic_contacts_relationships_contactscontacts_ida` en el mismo `set_entry`, o `set_relationship` después) | Hoy nadie tiene más de una membresía activa (doc 04 §7.4) |
| **Baja** | `set_entry` **con id** de la membresía activa: `end_date`, `end_reason` (`cambioseccion`, `personal`, `economy`, `disagreement`, `decease`, `other`), `estado_c = baja`, `active = 0` | **No borrar** (`deleted = 1` es el borrado lógico de SuiteCRM): se pierde la historia |
| **Cambio de sección** | Baja con `end_reason = cambioseccion` + alta en la sección nueva, el mismo día | Así lo registra hoy el CRM (motivo `cambioseccion`) |
| **«Secciones actuales»** (`Contacts.stic_relationship_type_c`) | Coincide siempre con las membresías activas | Parece que lo recalcula SinergiaCRM al guardar la membresía (un *logic hook*). **Hay que confirmarlo**: si no, habría que escribirlo también |

## 3. Riesgos y condiciones previas

1. **Un CRM de pruebas.** Hoy solo hay el CRM real federal (104.000 fichas). Las pruebas del plugin usan el CRM simulado, pero antes de crear personas de verdad hace falta probarlo **una vez en un entorno de pruebas de SinergiaCRM** (o en el real con una ficha de prueba acordada con ASDE y borrada después por quien administra el CRM).
2. **Duplicados en el CRM federal.** El usuario de API del grupo solo ve su grupo: una persona que ya existe en otro grupo (traslado, hermano de otro grupo, scouter que vuelve) **no la ve** y se crearía duplicada. Hace falta saber cómo lo resuelve ASDE (¿buscar por DNI a nivel federal?, ¿traslados solo desde la OF?).
3. **Nº de socio.** ¿Quién asigna `numerosocio_c` (Scouts de España) a una persona nueva: el CRM, la OF o el grupo? El plugin ya gestiona el nº territorial (doc 12), no el federal.
4. **Permisos y política.** El usuario de API **ya puede escribir** en `Contacts`, relaciones e inscripciones (doc 04 §7.1). Que el grupo cree personas y dé altas y bajas en el CRM federal tiene que estar **autorizado por ASDE/OF**: puede que estas operaciones las hagan solo ellos.
5. **Datos personales.** El alta pide datos de menores (y, si se piden, de salud): el formulario no guarda nada en WordPress (regla 1) y lo escrito va directo al CRM.

## 4. Propuesta de diseño (cuando se decida)

- **`Crm\Escritura`** sigue siendo la única puerta: nuevos `crear_persona()`, `alta()`, `baja()` y `cambio_seccion()`, con auditoría (nombres de campos, nunca valores) y la instantánea de fichas parcheada al escribir (doc 26).
- **Personas → «Alta de socio/a»** (Secretaría y Coordinación): primero se busca por DNI (y nombre y fecha) en el grupo; si no está, formulario con lo obligatorio, **vista previa** y confirmación. Se crea la persona, su membresía y su grupo de seguridad; si un paso falla, se dice qué ha quedado hecho y se puede reintentar sin duplicar. Al final, «Dar acceso» (doc 11).
- **Ficha → «Dar de baja»** (con motivo y fecha) y **«Cambiar de sección»**. Una baja avisa en Tesorería (la familia deja de pagar por esa persona) y en Usuarios (la cuenta pierde el acceso al no tener membresía activa).
- **Pruebas:** ampliar `tests/lib/CrmSimulado.php` para `set_entry` sin id, `set_relationship` y `get_relationships`; una prueba por operación, comprobando lo que se envía al CRM.
- **API V8:** no hace falta cambiar. Seguir con la v4.1, que ya funciona con este usuario y estos permisos; la V8 pediría credenciales OAuth2 nuevas al administrador del CRM.

## 5. Preguntas (doc 09: P-69 a P-75)

- **P-69** ¿Hay un CRM de pruebas de SinergiaCRM para ASDE, o se acuerda una ficha de prueba en el real?
- **P-70** ¿ASDE y la OF autorizan que el grupo cree personas y dé altas y bajas desde la web, o solo lo hacen ellos?
- **P-71** Personas que ya están en el CRM federal en otro grupo: ¿cómo se trasladan sin duplicarlas?
- **P-72** ¿Quién asigna el nº de socio de Scouts de España a una persona nueva?
- **P-73** ¿La persona nueva entra sola en el grupo de seguridad del grupo (herencia del usuario de API) o hay que enlazarla?
- **P-74** ¿«Secciones actuales» se recalcula solo al guardar una membresía?
- **P-75** ¿Qué datos son obligatorios en un alta (para menores y para adultos)?

## Fuentes

- https://github.com/SinergiaTIC/SinergiaCRM-API-Examples (README; `v4.1/README.md`; `v4.1/REST/PHP/APIClient.php`; `v4.1/REST/PHP/Ejemplos/SET/set_entry.php` y `set_relationship.php`; `v4.1/REST/PHP/Ejemplos/GET/get_relationships.php`; `v8/index.php`), consultado el 2026-10-06. Licencia AGPL-3.0: no se ha copiado código, solo se describe cómo se usa la API.
- Doc 04 (§7, modelo real del CRM de ASDE, verificado el 2026-09-26).
