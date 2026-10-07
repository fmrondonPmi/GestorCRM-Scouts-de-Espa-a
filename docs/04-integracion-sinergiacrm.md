# 04 · Integración con SinergiaCRM (v1: solo lectura)

Se ha revisado la rama `develop` de https://github.com/SinergiaTIC/SinergiaCRM (25-09-2026), que es SuiteCRM 7.14.6 y corresponde a la release 2.11.2. También la wiki de SinergiaTIC, los ejemplos oficiales de https://github.com/SinergiaTIC/SinergiaCRM-API-Examples y docs.suitecrm.com. Las rutas de fichero son relativas al repositorio.
**[NV]** = no verificado; hay que confirmarlo con SinergiaTIC o con la instancia real.

> ⚠️ **Actualización del 2026-09-26.** Ya hay conexión con el CRM real: el **CRM federal de ASDE**, `https://scout.sinergiacrm.org`, con API **v4_1**. Está muy personalizado para scouts y varias suposiciones de §1–§6 no aplican.
> **La referencia buena es la [§7 · Modelo real del CRM de ASDE](#7-modelo-real-del-crm-de-asde-verificado-2026-09-26).** Las secciones §1–§6 quedan como referencia genérica de SinergiaCRM.

---

## 1. Qué API usar

| | **V8 JSON:API + OAuth2** ✅ elegida | REST legacy v4_1 (alternativa) |
|---|---|---|
| Endpoint | `/Api/access_token`, `/Api/V8/module/...` | `/custom/service/v4_1_SticCustom/rest.php` |
| Autenticación | `client_credentials`, token de 1 h | `login` con contraseña en **MD5** |
| Documentada por SinergiaCRM | En su repositorio de ejemplos (`v8/`) | En la wiki ("Acceso al CRM vía API") |
| Estado en SuiteCRM | Recomendada | Legacy |
| Puntos fuertes | REST limpio, `fields[]` para pedir solo ciertos campos | Una sola llamada devuelve relación y contacto (`link_name_to_fields_array`) |
| Puntos débiles | Sin operador `in`, sin IS NULL, sin filtros por módulo relacionado, patrón N+1 | Filtros en SQL crudo, MD5 |

**Decisión:** usar V8. La v4_1 queda como segunda implementación de `CrmClientInterface` por si V8 no estuviera habilitada en la instancia alojada (P-12).

### Lo que hay que pedir al administrador del CRM
1. **Comprobar que V8 funciona:**
   - que existan las claves `Api/V8/OAuth2/private.key` y `public.key` (permisos 600),
   - que `$sugar_config['oauth2_encryption_key']` esté definida en `config.php` (si no, se usa la clave por defecto `SCRM-DEFK`),
   - que `mod_rewrite` esté activo y el `.htaccess` reenvíe la cabecera `Authorization`.
2. **Crear un usuario CRM dedicado** (p. ej. `api-web-lectura`): **no administrador**, sin acceso a la interfaz y con contraseña larga.
3. **Crear un rol "API Web – Solo lectura"** con estas reglas:
   - *Access* y *List/View* habilitados **solo** en los módulos necesarios (tabla §2).
   - **Edit, Delete, Import, Export y Mass Update en *None***.
   - El resto de módulos deshabilitados, sobre todo pagos, SEPE, Incorpora y valoraciones.
4. **Crear un cliente OAuth2** en *Admin → OAuth2 Clients and Tokens*, de tipo *client credentials*, con **`assigned_user_id` = el usuario del punto 2**.
   > ⚠️ En V8 **los scopes no se aplican** (`ScopeRepository::finalizeScopes`). El "solo lectura" depende **por completo** del rol del usuario asignado. Si se asigna un administrador, la web tendría acceso total al CRM.
5. Pasarnos `client_id` y `client_secret` por un canal seguro. Irán como constantes en `wp-config.php`.

---

## 2. Correspondencia del modelo: conceptos scouts → SinergiaCRM

| Concepto | Módulo SinergiaCRM | Campos y enlaces clave |
|---|---|---|
| Persona (participante, scouter, tutor) | `Contacts` | `first_name`, `last_name`, `birthdate`, `email1`, `phone_mobile`, `stic_identification_type_c/number_c`, `stic_gender_c`, `stic_age_c`, `stic_relationship_type_c` (multienum agregado, p. ej. `^member^,^volunteer^`) |
| **Membresía** (pertenencia a grupo y sección) | `stic_Contacts_Relationships` | `relationship_type` (`member`, `volunteer`, `pre-volunteer`, `beneficiary`…), `start_date`, `end_date`, `active` (calculado), `role` (`coordinator`, `technician`, `user`, `volunteer`), `end_reason` |
| ↳ contacto de la membresía | link `stic_contacts_relationships_contacts` | id en `stic_contacts_relationships_contactscontacts_ida` |
| ↳ proyecto de la membresía | link `stic_contacts_relationships_project` → `Project` | id en `stic_contacts_relationships_projectproject_ida` |
| ↳ centro de la membresía | link `stic_centers_stic_contacts_relationships` → `stic_Centers` | id en `stic_centers_stic_contacts_relationshipsstic_centers_ida` |
| **Grupo / Sección** | **[NV]** SinergiaCRM no tiene el concepto "sección". Lo probable es `Project`, `stic_Centers`, el campo `role` o un campo personalizado de la instancia | **P-15**: hay que verlo en el CRM real |
| **Tutor ↔ menor** | `stic_Personal_Environment` | `relationship_type` (`father`, `mother`, **`legal` = Tutor/a**, `carer`…), `reference_contact`, `authorized_signer`, `coexistence_status`, `start_date`, `end_date` (**no tiene `active`**) |
| ↳ persona base (el menor) | link `stic_personal_environment_contacts` | id en `stic_personal_environment_contactscontacts_ida` |
| ↳ persona del entorno (el tutor) | link `stic_personal_environment_contacts_1` | id en `stic_personal_environment_contacts_1contacts_ida` |
| Unidad familiar | `stic_Families` | `code`, `type`, `members_amount`, `active`. Los miembros se enlazan **a través de** `stic_Personal_Environment`, no directamente. Probablemente sea la base del **módulo 1 · Familias** |
| Eventos / actividades | `stic_Events` | `type`, `status`, `start_date`, `end_date`, `max_attendees`, `price` |
| **Ficha de participación en eventos** (P-08) | `stic_Registrations` | `status` (`invited`, `confirmed`, `participates`, `dropped`…), `registration_date`, `participation_type`, **`special_needs`**; links `stic_registrations_contacts` y `stic_registrations_stic_events` |
| Sesiones y asistencia | `stic_Sessions`, `stic_Attendances` | |
| Cuotas | `stic_Payment_Commitments` (`payment_type=fee`), `stic_Payments` | Fuera de v1 salvo que se pida |
| **Salud / medicación** | `stic_Medication`, `stic_Prescription`, `stic_Medication_Log` | Pautas de medicación. **Las alergias y dietas probablemente sean campos personalizados de la instancia [NV]** (P-09) |

Dirección semántica del parentesco **[NV]**: según las etiquetas, `relationship_type` describe qué es la *persona del entorno* para la *persona base* (base = menor, entorno = madre → `mother`). Hay que validarlo con datos reales antes de basar permisos en ello.

---

## 3. Detalles de V8 (según el código de esta rama)

- **Rutas de lectura:**
  - `GET /Api/V8/module/{M}`
  - `GET /Api/V8/module/{M}/{id}`
  - `GET /Api/V8/module/{M}/{id}/relationships/{link}`
  - `GET /Api/V8/meta/fields/{M}`
  - `GET /Api/V8/meta/modules` (incluye los ACL del usuario; útil para comprobar el rol)
  - `GET /Api/V8/current-user`
- **Filtros:** `filter[campo][op]=valor` con `eq`, `neq`, `gt`, `gte`, `lt`, `lte` y `like`, y `filter[operator]=and|or`.
  - **No existen `in`, `nin` ni `li`**, aunque la documentación de SuiteCRM los cite; si se usan, dan error.
  - No hay IS NULL.
  - No se puede filtrar por campos de módulos relacionados.
- **Paginación:** `page[size]`, `page[number]` (desde 1). La respuesta trae `meta.total-pages` y `links.next`.
  - ⚠️ **Sin `page[size]` se devuelve TODO.** Hay que enviarlo siempre (50–100).
- **Campos:** `fields[Module]=a,b,c` en `/module/{M}` y `/module/{M}/{id}`.
  - ⚠️ **El endpoint `/relationships/` no acepta `fields`** y devuelve **todos** los atributos del registro relacionado.
- **Orden:** `sort=campo` o `sort=-campo`.
- **Rendimiento:** V8 hace una carga extra por cada registro (patrón N+1). Conviene usar páginas moderadas y caché.
- **Límite de peticiones:** no hay en el código; **[NV]** si lo hay en el hosting de SinergiaTIC (WAF).
- **Token:** dura 1 h. Se guarda en un transient unos 55 min y se renueva al recibir un 401.

### Reglas de minimización derivadas (obligatorias en el plugin)
1. **Nunca usar `/relationships/{link}` cuando apunte a `Contacts`**, porque traería la ficha completa: salud, datos de discapacidad (`inc_*`, `sepe_*`), `stic_pa_password_c`… Se recorren las relaciones desde el lado de la relación y luego se pide cada contacto con `GET /module/Contacts/{id}?fields[Contacts]=<lista blanca>`.
2. Tener una **lista blanca de campos por rol y por pantalla**, definida en un único sitio (`FieldPolicy`).
3. `stic_pa_password_c` está marcado `write_only` pero no `sensitive`. **[NV]** si aparece en las respuestas. Hay que pedir al administrador que lo oculte en el rol mediante ACL de campo y no incluirlo nunca en la lista blanca.

---

## 4. Estrategia de lectura: índice local + detalle en vivo

Sin operador `in` y con el patrón N+1, calcular en vivo "¿qué participantes ve este scouter?" costaría decenas de llamadas por página. Propuesta:

### 4.1 Índice local no sensible (WP-Cron cada 15–30 min y botón "Sincronizar ahora" en el módulo CRM de Coordinación)
Tabla `{prefix}asde_indice`, que solo guarda **identificadores y relaciones, sin datos personales sensibles**:

| Tabla | Contenido |
|---|---|
| `asde_idx_personas` | `crm_id`, nombre visible, año de nacimiento (para calcular la sección esperada), `hash` de `date_modified` |
| `asde_idx_membresias` | `crm_id`, `persona_crm_id`, `grupo`, `seccion`, `tipo` (participante/scouter), `start_date`, `end_date`, `active` |
| `asde_idx_tutelas` | `menor_crm_id`, `tutor_crm_id`, `tipo` (`father`/`mother`/`legal`/`carer`), `authorized_signer`, `start_date`, `end_date` |

Este índice es lo que usan `ScopeResolver` y `AccessPolicy` para decidir el acceso, así que responde al instante.

**Cálculo de membresía vigente:** no fiarse solo de `active`, porque lo recalcula una tarea programada **[NV]** cuya frecuencia no conocemos. Se calcula también en PHP: (`start_date` vacío o ≤ hoy) y (`end_date` vacío o > hoy).

### 4.2 Detalle en vivo
Las fichas (contacto, salud, inscripciones) se piden al CRM al abrirlas, con lista blanca de campos.
- Los datos no sensibles se guardan en un transient de 5 min.
- **Salud y dietas no se guardan nunca** (doc 08).

### 4.3 Consultas tipo (V8)

**Socios activos de una "sección"** (si la sección es un `Project`):
- **Opción A:** recorrer `GET /module/stic_Contacts_Relationships?filter[active][eq]=1&filter[relationship_type][eq]=member&fields[stic_Contacts_Relationships]=name,start_date,end_date,relationship_type,role,active,stic_contacts_relationships_contactscontacts_ida,stic_contacts_relationships_projectproject_ida&page[size]=100` y agrupar por proyecto en PHP.
  - Encaja con el índice.
  - **[NV]** que los campos `_ida` se puedan pedir en `fields`. El ejemplo oficial los lee de `attributes`.
- **Opción B**, por proyecto: `GET /module/Project/{id}/relationships/stic_contacts_relationships_project?filter[active][eq]=1&filter[relationship_type][eq]=member&page[size]=100`. Aquí la entidad relacionada es la relación, no el contacto, así que no expone datos personales.

**Tutores de un menor:** `GET /module/Contacts/{menor}/relationships/stic_personal_environment_contacts?page[size]=50`.
- La entidad relacionada es `stic_Personal_Environment`, así que es seguro.
- Se filtra en PHP por `legal`, `father`, `mother` o `carer`, porque V8 no permite repetir la clave del filtro.
- El id del tutor está en `stic_personal_environment_contacts_1contacts_ida`.

**Representados de un tutor:** `GET /module/Contacts/{tutor}/relationships/stic_personal_environment_contacts_1?page[size]=50`. El id del menor está en `stic_personal_environment_contactscontacts_ida`.

**Inscripciones de un participante:** hay que recorrer `stic_Registrations` desde su lado. **Cuidado:** `/module/Contacts/{id}/relationships/stic_registrations_contacts` es seguro porque la entidad devuelta es `stic_Registrations`, pero sus atributos incluyen **`special_needs`**, que puede contener datos de salud. Se trata como un dato sensible.

---

## 5. Esqueleto del cliente en el plugin (V8)

```php
namespace AsdeBalears\AreaPrivada\Crm;

interface CrmClientInterface {
    /** @return array{data: array, meta: array} */
    public function list( string $module, array $filter = [], array $fields = [], int $page = 1, int $size = 100, string $sort = '' ): array;
    public function get( string $module, string $id, array $fields ): ?array;
    /** Solo usar cuando el módulo relacionado NO sea Contacts (ver §3). */
    public function related( string $module, string $id, string $link, array $filter = [], int $page = 1, int $size = 50 ): array;
    public function ping(): bool;   // GET /Api/V8/current-user, para la pantalla de estado
}
// v1: NO existen create/update/delete en la interfaz.
```

```php
final class SinergiaV8Client implements CrmClientInterface {
    private function token( bool $force = false ) {
        if ( ! $force && ( $t = get_transient( 'asde_crm_token' ) ) ) return $t;
        $r = wp_remote_post( ASDE_CRM_URL . '/Api/access_token', [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/vnd.api+json', 'Accept' => 'application/vnd.api+json' ],
            'body'    => wp_json_encode( [
                'grant_type'    => 'client_credentials',
                'client_id'     => ASDE_CRM_CLIENT_ID,
                'client_secret' => ASDE_CRM_CLIENT_SECRET,
            ] ),
        ] );
        if ( is_wp_error( $r ) ) throw new CrmException( $r->get_error_message() );
        $b = json_decode( wp_remote_retrieve_body( $r ), true );
        if ( 200 !== wp_remote_retrieve_response_code( $r ) || empty( $b['access_token'] ) ) {
            throw new CrmException( 'No se pudo obtener token OAuth2' );
        }
        set_transient( 'asde_crm_token', $b['access_token'], max( 60, (int) $b['expires_in'] - 300 ) );
        return $b['access_token'];
    }

    private function request( string $path, array $query = [], bool $retry = true ): array {
        $url = ASDE_CRM_URL . '/Api/V8/' . ltrim( $path, '/' );
        if ( $query ) $url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        $r = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => [ 'Authorization' => 'Bearer ' . $this->token(), 'Accept' => 'application/vnd.api+json' ],
        ] );
        if ( is_wp_error( $r ) ) throw new CrmException( $r->get_error_message() );
        $code = wp_remote_retrieve_response_code( $r );
        if ( 401 === $code && $retry ) { delete_transient( 'asde_crm_token' ); return $this->request( $path, $query, false ); }
        if ( $code >= 400 ) throw new CrmException( "CRM HTTP $code en $path" );   // no loguear el body: puede tener datos personales
        return json_decode( wp_remote_retrieve_body( $r ), true ) ?: [];
    }

    public function list( string $module, array $filter = [], array $fields = [], int $page = 1, int $size = 100, string $sort = '' ): array {
        $q = [ 'page' => [ 'size' => $size, 'number' => $page ] ];          // SIEMPRE page[size]
        if ( $filter ) $q['filter'] = $filter;                                // ['active' => ['eq' => '1']]
        if ( $fields ) $q['fields'] = [ $module => implode( ',', $fields ) ];
        if ( $sort )   $q['sort']   = $sort;
        return $this->request( "module/$module", $q );
    }
    // get(), related(), ping() análogos. Validar ids con /^[0-9a-f-]{36}$/i antes de construir rutas.
}
```

### Configuración (implementada en v0.1.0)

La URL y las credenciales se introducen en **wp-admin → Área privada Scout → Conexión CRM**. Esa página:
- solo es accesible con `manage_options`,
- guarda la opción `asde_ap_crm` sin autoload, con los secretos **cifrados** mediante libsodium,
- **no devuelve nunca los secretos al navegador**,
- tiene un botón **Probar conexión**, que inicia sesión y la cierra sin leer datos de personas. En v4_1 avisa además si el usuario tiene permisos de escritura en Contacts, Relaciones, Entorno o Inscripciones, o si es administrador.

Cada campo puede fijarse en `wp-config.php`. La constante tiene prioridad sobre la base de datos y es lo recomendado en producción:

```php
define( 'ASDE_CRM_API',           'v41' );  // 'v41' (usuario+contraseña) | 'v8' (OAuth2)
define( 'ASDE_CRM_URL',           'https://<entidad>.sinergiacrm.org' );
define( 'ASDE_CRM_USER',          '...' );
define( 'ASDE_CRM_PASSWORD',      '...' );
define( 'ASDE_CRM_CLIENT_ID',     '...' );  // solo V8
define( 'ASDE_CRM_CLIENT_SECRET', '...' );  // solo V8
define( 'ASDE_AP_ENCRYPTION_KEY', '...' );  // opcional: clave propia de cifrado
```

> Por petición del usuario, la página pide **usuario y contraseña**, así que la API por defecto es **v4_1**, la que documenta la wiki de SinergiaCRM. En V8, si se rellenan también usuario y contraseña, se usa el grant `password`; si no, `client_credentials`.

La alternativa v4_1 (login con MD5 y `get_entry_list` con `link_name_to_fields_array`) está en los ejemplos oficiales: https://github.com/SinergiaTIC/SinergiaCRM-API-Examples/tree/HEAD/v4.1/REST/PHP/Ejemplos/GET. Si hubiera que usarla, la `query` SQL se construye **solo** con valores validados (UUID por regex, tipos de una lista blanca).

---

> **v0.20 (2026-10-06):** estudio de cómo **crear personas y dar altas y bajas** con la API (ejemplos oficiales de SinergiaTIC), con propuesta y preguntas: **doc 33**. Sin código todavía.

## 6. Pendiente de confirmar en la instancia real

| # | Qué | Cómo |
|---|---|---|
| 1 | Que V8 esté operativo y el administrador pueda crear clientes OAuth2 | Soporte o foro de SinergiaTIC (P-12) |
| 2 | Cómo se modelan **grupo** y **sección** en las membresías | Mirar una membresía real en el CRM (P-15) |
| 3 | Dónde están **salud y dietas** | Revisar los campos de Contacts, Registrations y Prescription en el CRM (P-09) |
| 4 | Dirección de `relationship_type` en Entorno personal | Ver un caso real menor ↔ madre |
| 5 | Frecuencia de la tarea que recalcula `active` | *Admin → Planificador* (`validationActions`) |
| 6 | Si `stic_pa_password_c` sale en las respuestas | Probar `GET /module/Contacts/{id}` sin `fields` con el usuario de API |
| 7 | Si `/relationships/` aplica ACL al módulo relacionado | Probar con el rol de solo lectura |
| 8 | Límites o WAF del hosting | Soporte de SinergiaTIC |

Primera prueba al tener credenciales: `GET /Api/V8/meta/modules` con el usuario de API, para comprobar que el rol solo da `view`/`list` en los módulos previstos.

---

## 7. Modelo real del CRM de ASDE (verificado 2026-09-26)

Obtenido con el usuario de API del grupo, a partir de metadatos (`get_module_fields`) y **recuentos agregados**. No se ha volcado ningún dato personal.

### 7.1 Instancia y acceso

| | |
|---|---|
| URL | `https://scout.sinergiacrm.org`. Es el **CRM federal** de ASDE Scouts de España, compartido por todas las organizaciones federadas y grupos |
| API | REST **v4_1**, endpoint `/custom/service/v4_1_SticCustom/rest.php`. En la web está configurada con usuario y contraseña |
| Usuario de API | `usuario-api@example.invalid` (ejemplo: el usuario real no se documenta aquí). ⚠️ **Tiene permisos de escritura** en Contacts, Relaciones e Inscripciones. El plugin escribe solo a través de `Crmscritura`: edita fichas (doc 17) y, desde la v0.22 y solo si se activa para el grupo, crea personas y membresías, cierra membresías y las enlaza (doc 34). Para eso necesita permiso de edición en `Contacts` y en `stic_Contacts_Relationships` |
| Visibilidad | Solo ve su grupo: 1 grupo de seguridad (*Grupo Scout Myotragus, 684*), unas 382 fichas de un total de 104.042 en el CRM |
| Módulos **no** accesibles | `Project`, `stic_Personal_Environment`, `Accounts` |

### 7.2 Grupo Scout = grupo de seguridad

- **Módulo `SecurityGroups`**, con campos propios: `tipoasociacion_c` (`GS` = Grupo Scout, `OF` = Organización Federada), `numgrupo_c` (684), `organizacionfederada_c` (`OFBALEARS`…), además de CIF, direcciones y teléfonos.
- **Para varios grupos en el futuro:** el aislamiento lo hace el CRM, con grupos de seguridad. Hará falta un usuario de API con acceso a varios grupos (nivel OF) o un usuario por grupo, y filtrar por grupo en la web.

### 7.3 Personas (`Contacts`): campos propios de ASDE relevantes

| Campo | Uso |
|---|---|
| `stic_relationship_type_c` (multienum, etiqueta "Secciones actuales") | Secciones con **membresía activa**, p. ej. `^Lobatos^`. `^^` significa ninguna. Coincide exactamente con las membresías activas |
| `seccioneducativa_c` | "Sección educativa". Casi siempre vacío: **no usar** |
| `cargoeducativo_c` | "Cargo": (GS) Coordinador/a de Grupo, (GS) Secretario/a de Grupo, (GS) Scouter de sección, (GS) Coordinador/a de sección… Solo relleno en unas 4 fichas: **no sirve para asignar roles** |
| `tutor1_nombre_c`, `tutor1_apellidos_c`, `tutor1_email_c`, `tutor1_movil_c`, `tutor1_telefono_c`, `tutor1_telwork_c` y lo mismo para `tutor2_*` | **Los tutores son campos de la ficha del menor**, no personas aparte. En `tutorN_nombre_c` a veces va el nombre completo. El email de tutor solo está en 9–11 fichas |
| `birthdate`, `stic_age_c`, `tiene18_c` | Edad |
| `email1`, `phone_mobile` | Contacto. En los menores, `email1` suele ser el **email familiar**: 24 emails se repiten en varias fichas |
| `numerosocio_c`, `stic_identification_number_c` | Nº de socio de Scouts de España y DNI (sirven para buscar) |
| `tipocontacto_c` | `grupo` (ficha de grupo) o `persona`. Vacío en todas las fichas visibles |
| **Salud y alimentación** | `alergias_c`, `alergias_obs_c`, `alergiamedicamentos*_c`, `alergias_medicamentossn_c`, `alimentacion_*` (alergias, intolerancias, régimen, trastornos), `datosmedicos_*` (medicación, consentimiento para administrarla, operaciones, enfermedades, minusvalías), `medicos_*` (enfermedades 1–3, tratamiento, vacunas, seguro y póliza, informe adjunto), `medicosnumtarjetasanitaria_c` → **categoría especial (RGPD art. 9)**, ver doc 08 |
| Otros | `cesionimagen_c`, `certificadods_c` y `certificadods_fecha_c` (certificado de delitos sexuales), `formacionscout_c`, `formaciontiempolibre_c`, `iban_*`, `apodo_c` |

### 7.4 Membresías (`stic_Contacts_Relationships`, reetiquetado)

| Campo | Valores |
|---|---|
| `relationship_type` → etiqueta **"Sección"** | `Castores`, `Lobatos`, `Scouts`, `Escultas`, `Rovers`, `Scouters`, `Voluntariado` |
| `estado_c` | `activo`, `baja`, `prueba` |
| `active` | Coherente con `estado_c`. Ninguna membresía con `active=1` tiene la fecha de baja vencida |
| `role` | Educadores/Educandos. **Vacío en todas** |
| `end_reason` | `cambioseccion`, `personal`, `economy`, `disagreement`, `decease`, `other` |
| `nombreseccion_c`, `numsocio_c` | Nombre de sección (vacío) y nº de socio |

Recuento a 26-09-2026: 442 membresías, 107 activas (Scouters 23, Escultas 22, Scouts 20, Castores 15, Lobatos 15→20 durante la sesión, Rovers 7, Voluntariado 5). **Nadie tiene más de una membresía activa.** Están llegando altas de la nueva ronda.

**Consecuencia:** para saber la sección basta con `Contacts.stic_relationship_type_c`, sin consultar las membresías una por una. **No consta en qué sección educativa colabora un scouter** (su membresía es "Scouters"): se asigna en la web, en el alta.

### 7.5 Eventos e inscripciones

- `stic_Events`: 23 eventos. `type` puede ser festivalasde, festivalof, as (Asamblea), campamento, Curso, taller, jornada o reunion.
- `stic_Registrations`: 5.091 inscripciones.
  - `status`: participa, noparticipa, confirmado, cancelado, lista_espera, no_se_presenta.
  - `participation_type`: asistente, ponente, delegado, organización, voluntario, coordinador, representante Rover, etc.
  - `special_needs` / `special_needs_description` → posible dato de salud.
- Probablemente es la base de la "ficha de participación en eventos" (P-08).

### 7.6 Particularidades de la API v4_1 en esta instancia

| Hecho | Consecuencia en el código |
|---|---|
| ⚠️ **`get_entries_count` no aplica el filtro por grupo de seguridad**: cuenta sobre todo el CRM federal | No se usa para nada del ámbito del grupo. Los recuentos se hacen con `get_entry_list` |
| `get_entry_list` **sí** filtra por grupo | Toda lectura de personas, **también la de una ficha por id**, pasa por `get_entry_list` con `contacts.id = '…'`. `get_entry` no garantiza el filtro |
| `order_by` se **ignora** en todos los formatos probados | Se ordena en PHP |
| Se permiten subconsultas sobre `email_addr_bean_rel` y `email_addresses` | Búsqueda por email principal o secundario con subconsulta, más `contacts_cstm.tutorN_email_c` |
| Los campos propios se consultan como `contacts_cstm.campo_c` | Funciona en el WHERE de `get_entry_list` |
| Los argumentos de `rest_data` son **posicionales** | Se respeta el orden exacto de la firma de cada método |
| Los valores llegan con entidades HTML | Se decodifican (`html_entity_decode`) |
| La sesión caduca con el error 11 (Invalid Session ID) | Se vuelve a iniciar sesión y se reintenta una vez. La sesión se cachea **cifrada** en un transient de 15 minutos **que se alarga mientras se usa** (v0.12.3: antes caducaba a los 15 minutos aunque se usara y cada 15 minutos alguien esperaba ~1 s de inicio de sesión) |

### 7.7 Comprobado al preparar las altas (2026-10-06, doc 34)

Con un script de solo lectura lanzado por el usuario (`herramientas-desarrollo/crm-dni-existe.php`; no imprime datos):

| Hecho | Consecuencia |
|---|---|
| El documento (`stic_identification_number_c`) se guarda **sin separadores** (`12345678Z`) y se compara sin distinguir mayúsculas: lo encuentran el exacto, en minúsculas y `LIKE` con las cifras; no con guion, espacio ni solo las cifras | Buscar por las cifras con `LIKE` y comparar el documento normalizado (como `PersonaRepository::por_documento`). Escribir siempre normalizado |
| `get_entries_count` sigue contando en todo el CRM federal (§7.6) | Sirve para saber si un documento existe en otro grupo (P-78), con el canario del doc 34 |
| Cualquier consulta que toque `securitygroups_records` (desde `SecurityGroups` o desde `Contacts`) responde **«You do not have access»**: el CRM protege a propósito qué ficha está en qué grupo | El grupo y la OF de una ficha de otro grupo **no** se pueden saber por los grupos de seguridad (`crm-grupo-of.php`, doc 34) |
| `get_entries_count` sin condición: 352 grupos de seguridad (`SecurityGroups`) y 128.704 membresías en todo el CRM; contar `SecurityGroups` por `numgrupo_c` sí se acepta | Recuentos simples, sí; cruces con los grupos de seguridad, no |
| Campos con el grupo y la OF **de la importación de julio de 2024**: `grupoimportacion_c` y `ofimportacion_c` (texto, con nombres: «Grupo Scout Nombre, 684» y «ASDE Scouts de Balears»), en `Contacts` y en `stic_Contacts_Relationships`. **No se mantienen**: en el grupo 684 faltan en 70 de 382 fichas, en 42 de las 100 con membresía activa y en las 100 membresías empezadas desde agosto de 2024. `inc_reference_group_c` es de otro programa («Incorpora») | No sirven como «grupo actual»: como mucho, «según la importación de julio de 2024» (doc 34, P-84) |
| Las **subconsultas** solo se aceptan sobre las tablas de emails (`email_addr_bean_rel`): a `contacts_cstm` o a `securitygroups_records` responden «You do not have access». Los campos de `contacts_cstm` sí se pueden usar en el WHERE principal | En el plugin, nada de subconsultas salvo las de emails |
| El usuario de API solo ve **su** grupo de seguridad al listar `SecurityGroups` (aunque el recuento dé 352) | No hay forma de traducir un nº de grupo a su nombre u OF desde el plugin |
| Obligatorios en `Contacts`: `first_name`, `last_name`, `birthdate`, `stic_gender_c` (el documento no). En `stic_Contacts_Relationships`: `start_date`, `relationship_type`, la persona y `estado_c` | Formulario del alta (P-75, P-82) |
| `stic_gender_c`: `masculino`, `femenino`, `nobinario` (y vacío) | Opciones del alta |
