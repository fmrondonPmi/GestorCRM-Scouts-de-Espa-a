# 41 · Revisión del sitio con los plugins de Claude Code (v0.33.1 y v0.33.2)

**Fecha:** 2026-10-15. **Petición del usuario:** «revisa con los nuevos plugins todo el site y ajusta cualquier cosa que encuentren».

## 1. Qué plugins y sobre qué

Plugins nuevos de Claude Code (cuenta de claude.ai, activos desde el 2026-10-15):

| Plugin | Qué aporta | Cómo se usó |
|---|---|---|
| **WordPress Dev Skills** (`wp-dev-skills` 1.3.0) | Skills `wp-plugin-audit` (auditoría en 4 dimensiones: versiones, prefijos/i18n, docs↔código, convenciones y seguridad), `wp-coding-standards`, `wp-org-submission` (17 motivos de rechazo de WordPress.org)… | Sus listas de comprobación, con grep y con el PHPCS de Plugin Check, sobre los tres plugins propios. |
| **Security Guidance** (`security-guidance` 2.0.13, de Anthropic) | Avisos por patrones al editar, revisión del diff al acabar cada turno y revisión de cada commit con un modelo. | **No funciona en este equipo: necesita Python 3** en el PATH (solo está el atajo de la Microsoft Store). Sus reglas de patrones se pasaron a mano (grep). Se añadieron las reglas del proyecto en `.claude/claude-security-guidance.md`. |
| **Frontend Design** | Guía de diseño para crear interfaces nuevas. | No revisa código existente: no aplica a esta revisión. |
| (marketplace **ponytail**, añadido pero sin instalar) | «Modo programador vago» (necesita Node.js, que tampoco está). | Sus skills, a mano, en la v0.33.2: ver §6. |

Alcance: los tres plugins propios del sitio — `asde-area-privada` (0.33.0, rama `Sepa-Cuenta-Crm`), `asde-area-privada-api` (0.1.0) y `asde-area-privada-app` (0.18.3, la PWA descartada pero aún activa). Los plugins de terceros y el tema Enfold no se tocan (ver §5).

## 2. Lo que se corrigió

| # | Hallazgo | Dónde | Arreglo |
|---|---|---|---|
| 1 | **XSS por atributo (defensa en profundidad).** Los JS montan HTML con textos de `asdeT()` dentro de `aria-label="…"`. Esos textos pueden venir de las **etiquetas personalizadas** (wp-admin → Idioma y etiquetas, `manage_options`), que pasan por `wp_kses` pero conservan las comillas: una etiqueta con `"` abría otro atributo (`onmouseover=…`). Comprobado en un banco estático: antes se creaba el atributo, después no. | `area.js`, `informes.js`, `uniforme.js`, `usuarios.js` (6 sitios) | `window.asdeEsc()` (en `TextosJs::script()`, escapa `& < > " '`) y `asdeEsc( asdeT( '…' ) )` en los atributos. `tests/estilo-wordpress.php` parte F lo vigila. |
| 2 | **WordPress.org, motivo 2:** el readme no explicaba el uso de un servicio externo (SinergiaCRM). | `readme.txt` | Sección `== External services ==`: qué servidor, qué se envía, cuándo, y enlaces de SinergiaTIC (comprobados: 200). |
| 3 | WPCS `WordPress.WP.GlobalVariablesOverride` (47 avisos): `$s`, `$m`, `$id`, `$cat` son globales de WordPress, pero en las plantillas son **locales** (las incluye `TemplateLoader::render()` dentro de una función). Plugin Check aún no lo mira; WPCS completo, sí. | 72 plantillas | Añadido a la línea `phpcs:disable` de las plantillas (regla 8 del `CLAUDE.md`, `plantillas-disable.php` de la skill y la prueba). |
| 4 | Cabecera sin `License URI` (auditoría, dimensión A). | `asde-area-privada.php` | `License URI: https://opensource.org/licenses/MIT`. |
| 5 | **App 0.18.4:** un `<script>` escrito a mano en `wp_head` (WordPress.org lo rechaza) y readme sin «External services» (servicios push de Google, Mozilla, Apple y Microsoft). | `GestorCRM-App`: `src/Pwa.php`, `readme.txt` | `wp_print_inline_script_tag()` y la sección del readme. |
| 6 | Falsos positivos de los scripts de la skill `plugin-check-area-privada`: `InscripcionEsperaView` (pública) «sin permisos»; el dominio de textos fijo en el del principal (la API y la app salían mal); `RESUMEN=1` fallaba al lanzarlo desde la carpeta de la skill. | `~/.claude/skills/plugin-check-area-privada/scripts/` | Arreglados (`TEXT_DOMAIN=…` para la API y la app). |

## 3. Comprobado y **no** es un fallo (no «arreglarlo»)

- **Permisos y nonce:** las 27 vistas con `handle_post()`, los 11 `admin_post_*` y los 6 `wp_ajax_*` comprueban nonce y permiso (las del inventario, con `denegar_post()`; los de Grupos, con `comprobar()`). Las públicas (acceso, activar cuenta, recuperar, solicitar, alta de scouters, inscripción en la lista de espera) llevan nonce o token firmado, sin permiso, a propósito. `wp_ajax_nopriv_asde_ap_tarea` (numeración en segundo plano) va con token y `hash_equals`. `asde_ap_por_pagina` solo guarda una preferencia de la propia cuenta.
- **IDOR (un grupo no ve a otro):** las escrituras por id sin `grupo` en el `WHERE` (`Remesas::marcar_enviada/anular/registrar_cobros`, `FamiliasTable::borrar`, `MovimientosTable::anular`, `InformesTable::guardar/borrar`, `Inscripciones::*`) leen antes la fila con el `obtener()` de su clase, que filtra por grupo, o comprueban la familia (`Pagos::eliminar`) o el token del enlace personal (lista de espera).
- **`innerHTML` en los JS** (regla de Security Guidance): lo que entra es HTML ya escapado por el servidor (ventanas, `parcial.js`, listado de usuarios), números o textos del catálogo; solo los atributos necesitaban `asdeEsc()` (§2.1).
- **Ficheros subidos:** `is_uploaded_file()` y solo se lee su contenido (CSV del inventario, Norma 43, JSON de etiquetas); las imágenes de uniformes con `media_handle_upload()` y lista de tipos.
- **Rutas:** la ayuda (`?asde_ayuda`, `?asde_manual`) solo abre manuales de una lista y páginas `^[a-z][a-z_]*$`. Sin `unserialize`, `eval`, `wp_redirect` sin validar ni `ORDER BY` con datos del usuario.
- **Peticiones salientes:** el CRM (dirección que pone el administrador) y la propia web (`https_local_ssl_verify`, el gancho del núcleo). La app envía los push con `wp_safe_remote_post`, sin redirecciones y solo a los servidores push conocidos. `sslverify => false` en `tests/pwa.php` es contra el Local.
- **WPCS:** «Capabilities unknown» (41) son las capacidades propias `asde_*`; los 3 `catch` vacíos («CRM caído: …») y las 3 asignaciones dentro de una condición (`( $u = get_userdata( $id ) ) ? …`) son a propósito.
- **Revisión de WordPress.org:** `languages/*.php` dentro del plugin (decisión del usuario, P-122) y los `<style>`/`<script>` de `templates/pwa/sin-conexion.php` de la app (página suelta que guarda el service worker; no pasa por `wp_head`).
- Versiones: `Tested up to: 7.1` = WordPress 7.1.3 del Local; cabecera, constante y `Stable tag` coinciden en los tres plugins.

## 4. Resultado

- Plugin Check (PHPCS, los sniffs de la skill): **0** en el principal, la API y la app (sin `tests/` ni `herramientas/`, que no van en los zips).
- WPCS extra (`wp-coding-standards`): sin `in_array` no estricto, comparaciones débiles, funciones de depuración, fechas ni redirecciones inseguras.
- `revision-wporg.sh`: solo lo de §3 (catálogos y página sin conexión).
- Pruebas (v0.33.1): 1565 OK en 31 ficheros del principal + 311 de la app = 1876, 0 fallos; `pwa.php` 198 OK. Plugin Check completo por WP-CLI: 0 (se acortaron de paso los «Upgrade Notice» de la 0.29 y la 0.30, que pasaban de 300 caracteres).

## 5. Del sitio (no es código nuestro: lo decide el usuario)

- **Security Guidance necesita Python 3** (y ponytail, Node.js) para funcionar: los instala el usuario si quiere las revisiones automáticas.
- Plugins de terceros con actualización en el Local (2026-10-15): UpdraftPlus 1.26.9, WP Migrate DB 2.7.12, WP Statistics 14.16.17, MainWP Child 6.3 (inactivo). Mirarlo también en producción.
- **WP File Manager** está activo: es un plugin con historial de fallos graves (CVE-2020-25213) que da acceso a todos los ficheros desde wp-admin; mejor tenerlo desactivado y activarlo solo cuando haga falta (también en producción).

## 6. Revisión con ponytail (v0.33.2, mismo día)

**Petición del usuario:** «revisa la implementación con el plugin de ponytail y reescribe lo que consideres que se puede mejorar». Ponytail (`DietrichGebert/ponytail` 5.2.0) solo está como catálogo: no se instaló y sus ganchos necesitan Node.js. Se siguieron sus skills a mano: **ponytail-audit** (todo el repo; orden: correcto, seguro, aguanta la carga, probado, rápido, ligero) y la regla «el cambio más pequeño que funciona» para reescribir.

**Cómo se miró** (32 000 líneas de PHP no caben en una lectura): tres detectores con el tokenizador de PHP —funciones y constantes que nadie nombra (en los tres plugins, también como cadena), funciones con el mismo cuerpo en sitios distintos y consultas a la base de datos o al CRM dentro de bucles— y lectura a fondo de lo caro: dinero (cuotas, SEPA), permisos y escrituras (ya en §3). Carga supuesta: un grupo, unas decenas de personas a la vez y tareas diarias.

| # | Hallazgo (ponytail) | Arreglo |
|---|---|---|
| 1 | **La regla del umbral del inventario, escrita tres veces** (`lista.php`, `nueva.php` y `inventario.js`); la prueba comprobaba `Servicio::pocas_unidades()`/`ya_hay_bastantes()`, que solo usaba ella. | Las plantillas llaman a esas dos funciones (la prueba ya cubre lo que se usa); `nueva.php` ya no recibe `$umbral`. El JS sigue con su copia (necesita el valor en el navegador: `data-umbral`). |
| 2 | **El envío de los emails, copiado 7 veces** (invitación, rechazo, baja, SEPA, compras, compra revisada, lista de espera) y una 8.ª en «Instalación y entorno»: capturar `wp_mail_failed`, enviar, soltar la captura y el texto de respaldo. | `RegistroEmails::enviar( $email, $asunto, $html, $headers )` devuelve `''` si sale o el motivo. Prueba nueva en `grupos-emails-banco.php` (fallo con motivo, sin motivo, envío correcto y que no deja la captura enganchada): **ninguna prueba cubría el fallo**. |
| 3 | **La clave secreta de los enlaces de grupo, copiada** en `Usuarios\EnlaceScouters` y `ListaEspera\Enlaces` (crear, renovar, validar con `hash_equals`). | `Domain\ClaveDeGrupo` (trait); cada clase pone su `OPCION`. |
| 4 | `post_mapa()` repetido en `BancoView` e `InventarioComprasView`. | En `View`, junto a `post_array()`. |
| 5 | Código muerto: `ConnectionResult::to_array`, `Query::enum_in` (y su texto «Ningún valor permitido.», fuera de los catálogos), `SinergiaV41Client::available_modules`, `CamposFicha::de_grupo`, `EmailCuenta::borrar_todo` (su comentario decía «desinstalación», pero `uninstall.php` conserva los emails a propósito) y `Grupos::multiples` (solo lo usaba una prueba). | Borrado. |

Resultado: 22 ficheros, unas 140 líneas menos y una clase (el trait) más. Sin cambios de uso ni de base de datos.

**Mirado y se queda como está:**
- **Dinero:** importes `DECIMAL(10,2)`, `round( …, 2 )` en cada paso de `Calculadora` con margen de 0,001 al comparar, y el fichero SEPA en céntimos enteros (`Texto::importe`). Bien.
- **Bucles con consultas:** los del CRM (`MisPagosView`, `Mantenimiento`) leen de la instantánea (una lectura para todas las fichas); los de la base de datos (importar el banco, la numeración) son de cientos de filas: bien para un grupo.
- **Duplicados pequeños:** `dma()` (fecha d/m/a, en `Crm\Altas` y `FichaView`), `aviso()` de cuatro pantallas de wp-admin, las fábricas `make()` y los `obtener()` de cada tabla. Son de 2-4 líneas y en capas distintas: unirlos añadiría una clase para ahorrar menos de lo que cuesta.

**No revisado línea a línea:** las 9 000 líneas de plantillas y el CSS.
