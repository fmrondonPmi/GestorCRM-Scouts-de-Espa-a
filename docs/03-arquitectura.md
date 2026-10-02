# 03 · Arquitectura propuesta

## Decisión: un plugin propio, no un plugin de membresías genérico

Plugins como Ultimate Member, MemberPress o Paid Memberships Pro resuelven "contenido solo para usuarios logueados", pero **no** resuelven:

- permisos por *grupo + sección + parentesco* calculados desde un CRM externo,
- la cola de cambios con aprobación,
- la lectura en vivo de SinergiaCRM.

Acabaríamos peleándonos con ellos. La propuesta es un **plugin propio** (`asde-area-privada`) que usa solo el núcleo de WordPress: usuarios, roles, REST API, transients y shortcodes o bloques. Como mucho se añaden un plugin de 2FA y otro de límite de login.

**El site ya tiene instalados** (ver doc 06):

- **Ultimate Member 2.10.6**, sin páginas configuradas. Recomendación: **no apoyarse en él** para permisos. Como mucho puede aportar formularios de login y "olvidé mi contraseña" con estilo, pero su sistema de roles se solaparía con el nuestro. Si nadie lo usa, lo mejor es desactivarlo y quitarlo, porque carga su CSS y JS en todas las páginas (P-16).
- **Wordfence Login Security**, que ya da 2FA. Se aprovecha.
- **LiteSpeed Cache**: hay que excluir `/grupo-scout/area-privada/*` y a los usuarios logueados. **Es crítico**, porque sin esa exclusión la caché podría servir la ficha de un menor a otro usuario.

El código va en un plugin y **no en el tema**. Así sobrevive a un cambio de tema, se versiona aparte y se puede instalar en otras organizaciones federadas de ASDE.

## Estructura del plugin

```
asde-area-privada/
├── asde-area-privada.php          # cabecera del plugin, bootstrap
├── uninstall.php
├── src/
│   ├── Plugin.php                 # arranque, hooks
│   ├── Install/
│   │   ├── Roles.php              # alta de roles/capabilities (activation hook)
│   │   └── Schema.php             # tablas asde_cambios, asde_auditoria (dbDelta)
│   ├── Crm/
│   │   ├── CrmClientInterface.php # contrato: solo métodos de lectura en v1
│   │   ├── SinergiaV8Client.php   # implementación API V8 (OAuth2) — ver doc 04
│   │   ├── TokenStore.php         # token OAuth2 en transient
│   │   └── FakeCrmClient.php      # datos ficticios para desarrollo local/tests
│   ├── Domain/                    # objetos de dominio, sin dependencias de WP
│   │   ├── Grupo.php  Seccion.php  Persona.php  Membresia.php
│   │   ├── RelacionTutela.php  FichaSalud.php  Cambio.php
│   ├── Repository/
│   │   ├── PersonaRepository.php  # traduce CRM → dominio, aplica caché
│   │   └── CambioRepository.php   # tabla asde_cambios
│   ├── Auth/
│   │   ├── AccessPolicy.php       # ÚNICO punto de decisión de permisos
│   │   ├── UserLinker.php         # WP user ↔ Contact CRM (asde_crm_contact_id)
│   │   └── ScopeResolver.php      # calcula grupo/secciones/representados desde CRM
│   ├── Rest/                      # /wp-json/asde/v1/*
│   ├── Frontend/
│   │   ├── Router.php             # rutas bajo /grupo-scout/area-privada/...
│   │   ├── Shortcodes.php  (o Blocks/)
│   │   └── TemplateLoader.php     # plantillas sobreescribibles por el tema
│   ├── Admin/                     # ajustes, estado de conexión CRM, bandeja cambios
│   └── Audit/Logger.php
├── templates/                     # HTML con clases del tema (ver doc 06)
│   ├── dashboard.php  ficha-persona.php  listado-seccion.php  bandeja-cambios.php
├── assets/css/area-privada.css    # mínimo; hereda variables del tema
└── tests/
```

## Decisiones clave

| Tema | Decisión | Motivo |
|---|---|---|
| Cliente CRM | Detrás de `CrmClientInterface` | Permite `FakeCrmClient` en local, tests sin CRM y cambiar de V8 a v4_1 si hiciera falta |
| Solo lectura en v1 | La interfaz v1 **no tiene** métodos de escritura | Es imposible escribir en el CRM por error |
| Vínculo usuario ↔ CRM | `user_meta asde_crm_contact_id` | El resto (roles efectivos, ámbito) se calcula desde el CRM |
| Roles efectivos | Se **derivan** del CRM y se sincronizan al hacer login (y con un cron diario) | Si alguien causa baja o cambia de sección en el CRM, la web lo refleja sin intervención manual |
| Permisos | `AccessPolicy::can()` en todas partes | Un solo sitio que auditar |
| Caché | Transients de 5 a 15 min para lo no sensible, nada para salud | Ver doc 08 |
| Plantillas | Sobreescribibles desde `tema-hijo/asde-area-privada/*.php` (patrón WooCommerce) | Se integran con el tema sin tocar el plugin |
| URLs | `/grupo-scout/` (pública, la actual `/nuestro-grupo-scout/`) y `/grupo-scout/area-privada/...` | Encaja con la sección "Grupo Scout" del menú (P-10) |
| Multi-grupo | Cada petición trabaja en un grupo (`Domain\Grupo`). Con varios grupos, las fichas del CRM se filtran por el vínculo `SecurityGroups`, y los datos de WordPress llevan su grupo (doc 15) | Sin visibilidad entre grupos |
| Organización (v0.9) | Lo propio de la organización federada (nombre, código del nº de socio, coste federativo por defecto) está en `Domain\Organizacion` (opción `asde_ap_organizacion`) y se edita en `Admin\OrganizacionPage` (doc 20) | Cualquier organización de Scouts de España puede usar el plugin |
| Usuario de acceso (v0.9) | Nº de socio/a o su email del CRM. `Auth\Identificacion` busca el email en el CRM del grupo al entrar y elige la cuenta (doc 15) | Más cómodo para las familias sin guardar emails en WordPress |
| Email de las cuentas (v0.10) | `Usuarios\EmailCuenta`: email cifrado con una clave derivada de la clave del sitio y del id (`Crm\Crypto::encrypt_contexto`) + huella HMAC para buscar. El CRM manda (`Usuarios\Contacto`, tarea diaria) (doc 15) | Decisión del usuario (2026-09-28): entrar y escribir sin depender del CRM, sin que una copia de la base de datos sirva para leer los emails |
| Solicitudes de acceso (v0.10) | `Usuarios\SolicitudService` (comprobar con el CRM, aprobar), `Usuarios\Solicitudes` (tabla `asde_solicitudes`, datos cifrados, borrado a 30 días), `Frontend\Views\SolicitarView` (pública) y `SolicitudesView` (revisión), `Auth\Limite` (8 por hora) (doc 22) | El alta una a una era mucho trabajo; así lo piden las familias y lo aprueba un scouter |
| Ventana emergente (v0.10) | `assets/js/area.js` abre en un `<dialog>` los enlaces `data-asde-modal`; el servidor devuelve solo el contenido con la cabecera `X-Asde-Modal` (`Router::pide_modal`, `View::en_modal`) (doc 23) | Fichas y altas sin salir de la lista; sin JavaScript, las páginas de siempre |
| Listado de usuarios en memoria (v0.12.2) | `assets/js/usuarios.js` filtra por columna, ordena y pagina las filas de *Usuarios* que ya llegaron en la página (atributos `data-*`); reenviar/desactivar y «Actualizar» piden JSON con la cabecera `X-Asde-Json` (`Router::pide_json`, `UsuariosView::json`). Los nombres salen de la instantánea de fichas (`Usuarios\Listado`) | Sin recargar la pantalla entera; sin JavaScript, la lista completa y los botones con recarga |
| Instantánea de fichas (v0.12.3) | `Crm\Instantanea`: las fichas del grupo (sin salud) se leen del CRM una vez y se guardan **cifradas** 5 min; `PersonaRepository::todas/obtener/obtener_varias` las sirven de ahí (refresco en segundo plano con WP-Cron, y al guardar desde la web con `Escritura`). Sesión del CRM renovada mientras se usa (`SinergiaV41Client`). Aviso de carga en `area.js` (doc 26) | Cada consulta al CRM tarda 0,5–1,5 s y las páginas hacían de 1 a 6; ahora casi ninguna |
| Uniformes (v0.11) | `Uniformes\Config` (configuración por grupo, opción `asde_ap_uniformes_<grupo>`, imágenes en la biblioteca de medios), `Uniformes\PedidosTable` (tabla `asde_uniformes`: una fila por pedido con las cuatro comprobaciones y quién/cuándo), `Uniformes\Servicio` (líneas y precios, quién puede qué, pendientes, resumen para el proveedor); vistas `UniformeView`, `UniformesGestionView`, `UniformesConfigView` y `MisPagosView` (doc 24) | Pedido del socio/a y flujo del grupo en un mismo sitio, sin guardar nombres; los pagos del uniforme, aparte de la cuota |
| Pruebas con CRM simulado (v0.10) | `tests/lib/CrmSimulado.php`: responde como la API v4_1 con fichas inventadas (lecturas y escrituras en memoria); la prueba guarda sus cachés en memoria (`wp_using_ext_object_cache`) (doc 10) | Probar flujos completos sin tocar el CRM real ni las cachés de la web |

## Alta de usuarios (a decidir, P-02)

Opciones:

- **A. Invitación:** secretaría elige un contacto del CRM y pulsa "Invitar". Se crea el usuario WP con `asde_crm_contact_id` y se envía un enlace para fijar la contraseña. *Es la recomendada: controlada y trazable.*
- **B. Autorregistro con verificación:** la persona introduce su email. Si coincide con un Contact del CRM con membresía o tutela activa, recibe un enlace. Es más cómodo, pero depende de que los emails del CRM estén limpios y únicos.

Se eligió la A (P-02, v0.2). En la v0.10 se añadió una variante de la B **con aprobación**: la familia se identifica con el documento y la fecha de nacimiento del socio/a (no con el email), un scouter aprueba y el email escrito pasa al CRM (doc 22).

## Entornos

1. **Local** (LocalWP) con `FakeCrmClient`, o con un CRM de pruebas si SinergiaTIC ofrece uno.
2. **Staging**, si el hosting lo permite, contra el CRM real con el usuario de solo lectura.
3. **Producción.**

El plugin se versiona en Git desde el primer día.
