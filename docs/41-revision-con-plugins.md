# 41 · Revisión del sitio con los plugins de Claude Code (v0.33.1)

**Fecha:** 2026-10-15. **Petición del usuario:** «revisa con los nuevos plugins todo el site y ajusta cualquier cosa que encuentren».

## 1. Qué plugins y sobre qué

Plugins nuevos de Claude Code (cuenta de claude.ai, activos desde el 2026-10-15):

| Plugin | Qué aporta | Cómo se usó |
|---|---|---|
| **WordPress Dev Skills** (`wp-dev-skills` 1.3.0) | Skills `wp-plugin-audit` (auditoría en 4 dimensiones: versiones, prefijos/i18n, docs↔código, convenciones y seguridad), `wp-coding-standards`, `wp-org-submission` (17 motivos de rechazo de WordPress.org)… | Sus listas de comprobación, con grep y con el PHPCS de Plugin Check, sobre los tres plugins propios. |
| **Security Guidance** (`security-guidance` 2.0.13, de Anthropic) | Avisos por patrones al editar, revisión del diff al acabar cada turno y revisión de cada commit con un modelo. | **No funciona en este equipo: necesita Python 3** en el PATH (solo está el atajo de la Microsoft Store). Sus reglas de patrones se pasaron a mano (grep). Se añadieron las reglas del proyecto en `.claude/claude-security-guidance.md`. |
| **Frontend Design** | Guía de diseño para crear interfaces nuevas. | No revisa código existente: no aplica a esta revisión. |
| (marketplace **ponytail**, añadido pero sin instalar) | «Modo programador vago» (necesita Node.js, que tampoco está). | Nada. |

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
- Pruebas: 1565 OK en 31 ficheros del principal + 311 de la app = 1876, 0 fallos; `pwa.php` 198 OK. Plugin Check completo por WP-CLI: 0 (se acortaron de paso los «Upgrade Notice» de la 0.29 y la 0.30, que pasaban de 300 caracteres).

## 5. Del sitio (no es código nuestro: lo decide el usuario)

- **Security Guidance necesita Python 3** (y ponytail, Node.js) para funcionar: los instala el usuario si quiere las revisiones automáticas.
- Plugins de terceros con actualización en el Local (2026-10-15): UpdraftPlus 1.26.9, WP Migrate DB 2.7.12, WP Statistics 14.16.17, MainWP Child 6.3 (inactivo). Mirarlo también en producción.
- **WP File Manager** está activo: es un plugin con historial de fallos graves (CVE-2020-25213) que da acceso a todos los ficheros desde wp-admin; mejor tenerlo desactivado y activarlo solo cuando haga falta (también en producción).
