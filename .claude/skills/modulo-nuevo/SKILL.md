---
name: modulo-nuevo
description: Lista de piezas para añadir un módulo, una pantalla, una tabla o una capability nueva al plugin asde-area-privada (eventos, inventario, gastos…). Úsala siempre que se cree una vista con shortcode, una página del área, una entrada de menú, una tabla {prefix}asde_*, una capability asde_* o textos nuevos, para no olvidar ninguna de las piezas que hay que tocar a la vez ni las pruebas que dependen de ellas.
---

# Módulo o pantalla nueva en el área privada

Antes de nada: leer `CLAUDE.md`, `docs/00-INDICE.md` y el doc del módulo (si el cambio es grande, el plan va antes en un `docs/NN-plan-….md`). Seguir el estilo del módulo más parecido: **Uniformes** (`src/Uniformes/`, `UniformesBaseView`, `templates/uniformes/`, `tests/uniformes.php`) es la plantilla de «una cosa por socio/a con comprobaciones».

Marca cada pieza que toque; las que no apliquen, sáltalas a propósito.

## 1. Permisos

- [ ] Capability nueva `asde_<modulo>_<accion>` en `src/Install/Roles.php` (`definitions()`), en cada rol que la tenga, con un comentario de versión. Los administradores la reciben solos (`all_caps`). Se aplica al actualizar (`Installer::maybe_upgrade` → `Roles::install`, que **quita** las `asde_*` que ya no estén).
- [ ] Constante `CAP_*` en la clase de servicio del módulo (como `Uniformes\Servicio::CAP_GESTION`).
- [ ] Siempre **capability + ámbito** (`Auth\Ambito`: `secciones_scouter()`, `vision_global()`, `persona`, `representados`). Nada de comprobar solo la capability.
- [ ] Tabla de capabilities en `docs/02-roles-y-permisos.md` («Actualización vX.Y»).

## 2. Datos

- [ ] Clase `…Table` con `table()` e `install_table()` (`dbDelta`, `$wpdb->get_charset_collate()`). De las personas, **solo el nº de Scouts de España** (y secciones/adulto si hace falta para el ámbito sin consultar el CRM). Lo personal que haga falta, **cifrado** (`Crm\Crypto`) y con caducidad; la salud, **nunca**.
- [ ] Llamada en `src/Install/Installer.php` (`install()`).
- [ ] Añadirla a `Install\Entorno::tablas()` y **subir el recuento** en `tests/entorno.php` («las N existen») y en el doc 19.
- [ ] `uninstall.php`: comentario de tablas conservadas y `delete_option` de las opciones nuevas (las de grupo, en el bucle `asde_ap_<x>_<grupo>`).
- [ ] Opciones `asde_ap_*`; lo nuevo **nace apagado** o con el valor de siempre para instalaciones anteriores (regla 5).
- [ ] SQL siempre con `$wpdb->prepare`. Nada se borra si es un justificante: se anula.

## 3. Pantalla

- [ ] Vista `src/Frontend/Views/<Nombre>View.php` (extiende `View` o una base del módulo con `subnav()`): `render()` con `$this->layout( 'clave', … )`, `handle_post()` para las acciones (nonce con `check_admin_referer`/`wp_verify_nonce` y `current_user_can` **antes** de tocar nada; en GET también si cambia estado), `denegado()` sin revelar si algo existe.
- [ ] `Router::VIEWS`: `'clave' => array( 'asde_<shortcode>', Vista::class )` (+ `use`). Si se abre en la ventana emergente, también en `Router::MODALES`.
- [ ] `Pages::DEFINICIONES`: `'clave' => array( 'slug', 'Título', '[asde_<shortcode>]', 'clave del padre' )`. Pública → `Pages::PUBLICAS`; sin ámbito → `SIN_AMBITO`.
- [ ] Menú en `Frontend\Menu::grupos()`: en `$personal` (Mi espacio / Mi familia) o en `$gestion` (Gestión del grupo), con el recuento de pendientes `(n)` si lo hay. Si la página no tiene pestaña propia, marcar la de su sección en `View::layout()` (`$marcar`).
- [ ] «Pendiente de revisar» del inicio: `AreaPrivadaView` + `templates/area/inicio.php`.
- [ ] Plantillas en `templates/<modulo>/*.php` (sobrescribibles desde el tema): todo escapado (`esc_html`, `esc_attr`, `esc_url`), formularios con nonce, los que descargan un fichero con `data-asde-sin-carga`.
- [ ] Sin JavaScript funciona; el JS (si hace falta) en `assets/js/<modulo>.js`, solo mejora. Al usuario no le gusta recargar la pantalla entera.
- [ ] CSS **al final** de `assets/css/area-privada.css` en un bloque `/* vX.Y · <qué> (doc NN) */`. Móvil primero: 390 px sin desbordes, tablas como tarjetas (`data-label`), pestañas en una fila que se desplaza.
- [ ] Rendimiento: nombres con `PersonaRepository::obtener_varias` (instantánea); **ninguna consulta al CRM por fila**. Salud, búsquedas e informes, en vivo y en una sola lectura.

## 4. Textos (cuatro idiomas, doc 28)

- [ ] Todo texto visible en `asde_t()` / `asde_h()` / `asde_e()` / `asde_a()` / `asde_js()` / `asde_n()`, frases enteras con `sprintf`.
- [ ] Constantes con textos (estados, filtros): se muestran con `asde_ta()` y la constante se añade a `Idiomas\Textos::extras()`.
- [ ] JS: `asdeT( 'texto', arg )` y el texto en `Idiomas\TextosJs::LISTA`.
- [ ] Regenerar `languages/es.php` con `herramientas/generar-es.php` (WP-CLI en el Local: `wp eval-file <ruta>/herramientas/generar-es.php <ruta-al-plugin>`; no se edita a mano) y añadir cada texto nuevo a `languages/ca.php`, `eu.php` y `gl.php` con la skill **`traducir-textos`**. `tests/idiomas-catalogos.php` avisa de los que faltan.
- [ ] Lo que **se guarda** (motivos, conceptos, referencias) va en castellano, sin traducir. wp-admin sigue con `__()`.

## 5. Auditoría y avisos

- [ ] `Logger::log( 'accion', 'tipo', id, array( … ) )` en cada acción: **nombres de campos, nunca valores** ni datos personales.
- [ ] `Notices` para que quien actúa vea el resultado.
- [ ] Emails por `Usuarios\RegistroEmails` (registro de emails).
- [ ] Si la app móvil necesita enterarse: un enganche genérico (`do_action( 'asde_ap_…' )` / `apply_filters`) **con su prueba**; nunca clases de la app.

## 6. Pruebas (obligatorias)

- [ ] `tests/<modulo>.php` copiando el esqueleto de `tests/uniformes.php`: `require_once __DIR__ . '/lib/CrmSimulado.php'`, fichas **inventadas** (`Asde_Prueba_Crm`), usuarios `prueba-<modulo>-*` (y salir si ya existen), cachés en memoria (`wp_using_ext_object_cache( true )`), `pre_wp_mail`, cortar el CRM real (`pre_http_request`), y **todo restaurado en el `finally`** (filas `id > max_antes`, opciones, usuarios, `asde_socios`, cachés).
- [ ] Aserciones que puedan fallar (nada de `|| true` ni ORs siempre ciertos); partes A, B, C… con `echo "== Parte X · … ==\n"`.
- [ ] Cubrir: permisos y ámbito (otra sección, otra familia, otro grupo, sin capability), nonce, cada estado, lo que se guarda (sin datos personales en claro) y la pantalla (HTML de la vista).
- [ ] Revisar las pruebas que dependen de lo tocado: `tests/menus.php` (entradas del menú por rol), `tests/entorno.php` (nº de tablas), `tests/idiomas-catalogos.php` (textos), y las del módulo vecino.
- [ ] `php -l` de cada fichero cambiado. En el entorno del usuario: `herramientas-desarrollo/probar-todo.sh <modulo>` y después todo hasta **«TODO OK»**. **No cortar nunca una prueba a medias.** En una sesión en la nube no hay Local: skill **`probar-en-la-nube`** (pruebas y capturas) y dejar para el Local lo que lee el CRM real.

## 7. Documentación

- [ ] Doc del módulo (`docs/NN-….md`): qué hace, quién ve qué, qué se guarda, pruebas y pendiente.
- [ ] `docs/02` (capabilities), `docs/10` (recuento y lista de pruebas), `docs/09` (decisiones y preguntas nuevas, con el siguiente número P-NN libre), `docs/00` (bullet de estado y fila del doc).
- [ ] Manuales de uso, si cambia lo que ve alguien: `manual/fuentes/*.html` y `php herramientas/generar-manuales.php` (no editar los `.html` generados).
- [ ] Supuestos hechos sin preguntar: escritos en el doc y en el resumen final.

Para cerrar la versión: skill **`entregar-version`**.
