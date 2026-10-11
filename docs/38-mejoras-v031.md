# 38 · Mejoras v0.31 (2026-10-11): listados sin recargar y revisión de WordPress.org

Peticiones del usuario del 2026-10-11 (tras el push de la v0.30) y lo que pidió el equipo de
revisión de WordPress.org. Hecho con el límite semanal al 94-97 %: lo que no cupo queda en el
apartado 4 con su plan.

## 1. Listados

| Petición | Hecho |
|---|---|
| Personas: el contador después de «Filtrar» sobra; abajo del grid | `templates/personas/listado.php`: «N personas» debajo de la tabla, antes de la paginación. |
| 10 registros por página por defecto, de 5 en 5 hasta 50 | `Frontend\Tabla::POR_PAGINA` = 5…50 y `POR_PAGINA_DEFECTO` = 10. Vale para **todos** los listados (el tamaño es uno por persona); quien tenía 75 o 100 pasa a 10 (P-117). |
| Orden por defecto, siempre por sección | Personas ordena por `membresia`: sección (de Castores a Voluntariado) y, dentro, apellidos; sin membresía activa, al final (P-118). |
| Todos los grids: filtrar o paginar refresca solo el grid | `parcial.js` se carga en todas las páginas del área (`Router::assets`). Zonas nuevas: Control de pagos (`pagos-lista`), pedidos de uniforme (`uniformes-lista`), listas de compra del inventario (`inv-compras`) y resultado de un informe (`informe-resultado`, al cambiar la sección). Ya lo hacían Personas, Banco y la ficha de una familia; Usuarios y Emails, con sus hojas (`hojas.js`); el inventario filtra en el navegador. Los envíos que guardan dentro de esas zonas nuevas llevan `data-asde-recarga` (siguen recargando, como antes). |
| Control de pagos: selector, cuadro y botón en una línea | `asde-filter--una-linea` y búsqueda al escribir (desde la 3.ª letra). |
| «Ver / registrar pago» como enlace | Enlace (con `data-asde-recarga`: abre la ficha de la familia). |
| Los hijos de la columna Cuota, como tooltip del importe | `span.asde-tooltip` con `title` («2 hijos/as · ajuste −10,00 €») y el mismo texto para lectores de pantalla (P-121). |
| Banco: primero el cuadro, luego los tres desplegables y el botón, en una línea; el contador, abajo | `templates/tesoreria/banco.php`. |

## 2. Alta y cambio de sección

- **Nº territorial al dar de alta** (`Frontend\AltaSocio::territorial`): al guardar los datos
  obligatorios en el CRM (nombre, apellidos, nacimiento y documento) se genera siempre, aunque el
  grupo entre con el nº de Scouts de España, y se guarda también en el CRM si se puede, como
  «Asignar nº» de Personas (P-120). En la pantalla siguiente («Hecho») se ve solo si la persona
  tiene visible el nº territorial (`Numeros::ver_local()`); si no, no sale en ninguna. Si no se
  puede generar (CRM sin respuesta, falta el código de la organización), se asigna después desde
  Personas como hasta ahora.
- **Cambio de sección** (`Domain\Secciones::destinos_cambio`, `Crm\Altas::cambio_seccion` y la
  ventana): un educando solo pasa a la **siguiente sección por edad** (Castores → Lobatos → Scouts
  → Escultas → Rovers → Scouters); un adulto (Scouters o Voluntariado, como en P-103) a cualquier
  otra (P-119). El servidor lo comprueba aunque se fuerce el formulario.

## 3. Revisión de WordPress.org

La skill `plugin-check-area-privada` tiene un apartado nuevo («Revisión manual de WordPress.org»)
y el script `scripts/revision-wporg.sh`, que lo comprueba.

1. **`<script>` y `<style>` en PHP → encolados.** `Router::cabecera_guardada` (con
   `wp_add_inline_script` sobre un *handle* sin fichero), la versión para imprimir de un informe
   (`assets/css/informe-imprimir.css` con los colores y la orientación por `wp_add_inline_style`;
   `assets/js/imprimir.js`), y wp-admin: `Admin\Recursos` encola `assets/css/admin.css` (antes, un
   `<style>` en Organización, Entorno y Colores) y `assets/js/admin.js`; la vista previa de Colores
   usa `wp_add_inline_style` y `colores.js` cambia ese `<style>`.
   **Manejadores en línea** (34 `onsubmit`/`onclick`/`onchange`) → `data-asde-confirmar`,
   `data-asde-autoenviar` y `data-asde-imprimir-pagina`, que atienden `area.js` (captura, antes que
   la ventana y `parcial.js`) y `admin.js`. `asde_js()` devuelve ahora el texto sin escapar
   (se escapa con `esc_attr()` al ponerlo en el atributo).
2. **Nonces y permisos:** el script revisa cada `handle_post`/`admin_post`/AJAX: todos comprueban
   nonce y permisos (las pantallas públicas, nonce).
3. **Entradas:** `$_SERVER` (IP, cabeceras, URI, método), `$_COOKIE` del idioma y `$_GET` de
   «Mostrar nº territorial», saneados (`Logger`, `Intentos`, `Limite`, `RecuperarView`,
   `Mandatos`, `Router`, `Idioma`, `AppRetirada`, `View`). `Tabla` usa `absint()`.
4. **Salidas:** Plugin Check sin errores de `EscapeOutput`; el script no encuentra `echo` sin escapar.
5. **Ficheros de traducción:** pendiente (apartado 4).

## 4. Pendiente: traducciones por translate.wordpress.org

WordPress.org pide quitar `languages/*.php` del paquete: las traducciones las dan los paquetes de
idioma de translate.wordpress.org (guía: make.wordpress.org/meta/handbook/tutorials-guides/translations/:
*text domain* = *slug* `asde-area-privada`, ya en la cabecera; los textos se extraen de `__()`,
`_e()`…; los `.mo` que haya se importan una vez al aprobar el plugin y luego se quitan; el paquete
de un idioma se genera con el 90 % traducido y aprobado). Plan (después del reinicio semanal):

1. `Traducciones::t()` deja de leer `languages/<código>.php` y lee el paquete de WordPress:
   `WP_LANG_DIR/plugins/asde-area-privada-<locale>.l10n.php` (WP ≥ 6.5) o `.mo` (clase `MO`), con
   `ca`, `eu` y `gl_ES`; el castellano es el texto original. Las etiquetas personalizadas de
   wp-admin siguen mandando y **los enlaces de idioma del área (es, ca, eu, gl) se mantienen**.
2. `languages/es.php` (catálogo de textos y grupos de «Idioma y etiquetas»; no es una
   traducción) pasa a `src/Idiomas/catalogo.php`.
3. Para que translate.wordpress.org encuentre los ~2.900 textos de `asde_t()`, `generar-es.php`
   escribe también `src/Idiomas/Cadenas.php` con un `__( 'texto', 'asde-area-privada' )` por
   texto (no se ejecuta). Cambiar las ~3.000 llamadas a `__()` se descarta por riesgo.
4. Las traducciones de hoy se convierten en `.po`/`.mo` **fuera del plugin** (`traducciones/` del
   repo): los `.po` para importarlos en translate.wordpress.org al aprobarse el plugin; los `.mo`,
   para copiarlos a `wp-content/languages/plugins/` de cada web mientras tanto (si no, el área se
   vería en castellano).
5. «Idioma y etiquetas» avisa si falta el paquete de un idioma elegible.
6. Pruebas: `idiomas`, `idiomas-catalogos` y una nueva con un `.mo` inventado.

**Pregunta para el usuario (P-122):** translate.wordpress.org espera los textos originales en
inglés; los del área están en castellano. Funciona igual, pero quien traduzca verá el castellano
como original. ¿Se deja así o se plantea pasar los originales a inglés (mucho trabajo)?

## 5. Pruebas

Ver doc 10. Cambiadas: `grupos-emails-banco` y `usuarios-listado` (tamaños de 5 en 5),
`mejoras-v029`, `mejoras-v030` y `altas-crm-pantallas` (`data-asde-confirmar` en vez de
`onsubmit`), `altas-crm-escritura` (+2: la siguiente sección por edad).
