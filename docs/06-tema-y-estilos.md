# 06 · Tema, estilos e integración visual

Análisis de https://baleares.scout.es/ hecho a partir del HTML público, `dynamic_avia/enfold.css` y `/wp-json/`, en septiembre de 2026. **Hay que volver a verificarlo sobre la copia local.**

## Plataforma

| Elemento | Valor |
|---|---|
| WordPress | 7.0.6 |
| Tema | **Enfold 7.1.2** (Kriesi, ThemeForest) — **sin tema hijo** |
| Constructor | Mixto: portada con **Avia Layout Builder**; `/nuestro-grupo-scout/` (page-id 21) con **Gutenberg** dentro de la plantilla de página por defecto de Enfold |
| CSS personalizado | En las opciones del tema → `uploads/dynamic_avia/enfold.css` y `themes/enfold/css/custom.css` |

### Plugins ya instalados que afectan al proyecto

| Plugin | Relevancia |
|---|---|
| **Ultimate Member 2.10.6** | Plugin de membresía **ya instalado**, pero sin páginas públicas configuradas (/register, /account, /user y /members dan 404). Hay que decidir si se reutiliza o se desactiva (P-16) |
| **Wordfence + Wordfence Login Security** | Ya da **2FA y protección de login** → cubre el punto 7 del doc 08 sin plugins nuevos |
| LiteSpeed Cache | **Hay que excluir del caché** todas las URLs del área privada y las de usuarios logueados, o se podrían servir fichas a quien no toca |
| CookieYes | Banner de cookies; el área privada solo usa cookies técnicas de sesión |
| All-in-One WP Migration | Útil para la copia a local (ver respuesta anterior) |
| WP Statistics | Excluir el área privada del seguimiento, o al menos no registrar URLs con IDs de personas |

## Acción previa recomendada: crear un tema hijo de Enfold

Hoy no hay tema hijo, así que cualquier plantilla o CSS añadido al tema padre se perdería al actualizar Enfold. Pasos:

1. Crear `wp-content/themes/enfold-child/` con `style.css` (`Template: enfold`) y `functions.php`. Kriesi publica un tema hijo oficial en su documentación.
2. **Antes de activarlo, exportar las opciones de Enfold** (Enfold → Import/Export). Al activar un tema hijo, las opciones del tema no se heredan solas, y la web perdería colores, logo y cabecera. Enfold tiene la opción "Import Parent Theme Settings".
3. Hacerlo **primero en local** y comprobar que la web queda igual.

Las plantillas del plugin se sobreescriben desde `enfold-child/asde-area-privada/`.

## Tipografía

- **Open Sans 400/600** (Google Fonts), tanto para títulos como para cuerpo. Variables `--enfold-font-family-heading` y `--enfold-font-family-body`.
- Tamaños: cuerpo 14px; h1 34, h2 28, h3 20, h4 18, h5 16, h6 14; menú 15px.
- Iconos: fuente `entypo-fontello-enfold`, que ya está cargada y se puede reutilizar.

## Paleta (variables `--enfold-*`)

| Token | Hex | Uso en el tema |
|---|---|---|
| Primario | **#632599** | morado: barra superior, botones, enlaces del menú, pie |
| Primario oscuro | **#421866** | pie inferior (socket) |
| Borde de botón | #410377 | |
| Lila | **#c7a2e8** | bordes del menú, separadores |
| Títulos del contenido | **#89b110** | verde lima |
| Texto | #444444 (meta #555555) | |
| Fondo | #ffffff | |
| Fondo secundario | #fff7de | crema |
| Fondo de sección alterna | #efefef (borde #e1e1e1) | |

## Reglas de integración para el área privada

1. **Usar las variables CSS de Enfold** (`var(--enfold-main-color-primary)`, etc.) y no copiar los hex. Si alguien cambia el color en el panel de Enfold, el área privada lo sigue.
2. **Usar el marcado de Enfold** para que los estilos salgan solos:
   - contenedor: `<div class="container_wrap container_wrap_first main_color"><div class="container"><main class="template-page content av-content-full units">…`
   - botones: `avia-button avia-color-theme-color`
   - separadores: `hr` con el estilo de Enfold
   - tablas: marcado estándar, que Enfold ya estiliza
3. **Las páginas del área privada son páginas WP normales** con la plantilla de página por defecto de Enfold, que contienen un shortcode o bloque del plugin. Así heredan cabecera, menú, migas de pan, barra de título y pie sin hacer nada.
4. **CSS propio mínimo** (`assets/css/area-privada.css`), con un prefijo de clase (`.asde-`) para no chocar con Enfold. Sirve para lo que el tema no tiene: badges de sección, estados de cambios, formularios de ficha.
5. **Badges de sección** con los colores de ASDE (doc 05), vigilando el contraste WCAG AA:

   | Sección | Fondo | Texto |
   |---|---|---|
   | Castores | azul celeste | #1a1a1a |
   | Lobatos | amarillo | #1a1a1a |
   | Scouts | verde | #ffffff |
   | Escultas | marrón | #ffffff |
   | Rovers | rojo | #ffffff |

   Los hex exactos están por confirmar con el grupo o con el manual de marca de ASDE.
6. **Móvil primero:** muchas familias entrarán desde el móvil. Enfold es responsive, pero las tablas de listados necesitan una versión en tarjetas en pantallas estrechas.
7. **Menú (hecho en v0.5):** en el menú principal solo está «Acceso socios», que con la sesión iniciada pasa a «Área privada» (doc 15).
8. **Sin barra de título ni migas en las páginas del área (v0.7, petición del usuario):**
   - meta de Enfold `header_title_bar = hidden_title_bar` en todas las páginas del plugin;
   - además, CSS de respaldo (`body.asde-ap-pagina .title_container { display: none }`);
   - el área ya tiene su propio menú.
9. **Cabecera morada plegable (v0.7, petición del usuario):**
   - botón **«Ocultar cabecera / Mostrar cabecera»** junto al nombre del usuario, en el menú del área;
   - **plegada:** se ocultan la franja superior (`#header_meta`) y el logo (`.av-logo-container`). Quedan **fijos arriba** el menú principal y, debajo, el **menú del área** (`position: sticky`), y el contenido se desplaza por debajo;
   - el estado se guarda en el navegador de cada persona (`localStorage`, clave `asde_cabecera_oculta`) y se aplica **antes de pintar** (script en el `<head>`), así que no hay parpadeo;
   - **tres cosas de Enfold que había que corregir** para que funcione:
     - `overflow: hidden` en `body` y `#wrap_all` impedía `sticky`: se cambia por `overflow: clip`;
     - la cabecera fija baja `margin-top: -31px` al hacer scroll (despega la franja superior): se anula con la cabecera plegada;
     - el hueco de la cabecera en `#main` se recalcula (`--asde-cabecera-h`, medido por `assets/js/cabecera.js`, que también tiene en cuenta la barra de administración).
   - **Móvil (≤ 989 px):** el menú hamburguesa está junto al logo, así que solo se oculta el logo y la cabecera queda fija. Pendiente de revisar a ojo en un móvil real.
10. **Los campos de Enfold ganan a las clases del plugin (v0.11.1):** Enfold da estilo a `#top input[type="text"]`, `#top input[type="number"]`, etc., con `width: 100%` y margen inferior. Un selector con `#top` pesa más que cualquier combinación de clases, así que cuando un campo tiene que ser estrecho (la cantidad de cada insignia) la regla del plugin lleva también `#top` delante. Los campos de fecha, en cambio, Enfold no los estiliza: el plugin les da borde y relleno.

11. **Vista en móvil (v0.12.1, petición del usuario con una captura del móvil):** se revisaron **las 36 pantallas** a 390 px de ancho, con el CSS de Enfold cargado (`herramientas-desarrollo/vistas-movil.php` las genera con datos inventados y `vistas-movil-envolver.sh` las envuelve con el CSS del tema; luego se miden desbordes, textos aplastados y tarjetas rotas desde el navegador). Lo que salió y se arregló:
    - **Tablas en tarjetas** (≤ 767 px): las celdas eran `display: flex`, así que cada trozo del contenido (enlace, salto de línea, descripción, insignia) pasaba a ser una **columna** y el texto se partía letra a letra (la lista de *Informes* de la captura). Ahora la etiqueta va **a la izquierda en posición absoluta** y el contenido, sea el que sea, fluye en su columna (`padding-left: 120px`). La etiqueta más larga («Nº Scouts de España») pasó a «Nº ASDE» y las demás se recortan con «…» si no caben.
    - **Cabeceras de fila (`th`) y pies** en tarjetas: ya no salen como cajitas con borde.
    - **Tablas de resumen de importes** (*Cuota de la ronda* de la ficha de familia, totales de *Coste federativo*): concepto a la izquierda e importe a la derecha, en una línea, sin tarjeta por fila (usa `:has()`; sin él, se ve como tarjetas). Las de varias columnas con etiquetas siguen como tarjetas.
    - **Menús de pestañas** (área y Tesorería): **una sola fila que se desplaza** con el dedo, con un degradado a la derecha que avisa de que hay más y la pestaña activa centrada al cargar (`cabecera.js`). Antes ocupaban 4 filas.
    - **Formularios:** los `fieldset` ya no ensanchan la página (`min-width: 0`) y los campos, incluidos los de archivo, no pasan de su tarjeta.
    - **Tarjetas:** menos relleno lateral (14 px) y el botón de la cabecera («+ Nuevo informe») a todo el ancho.
    - La elección de cuenta en *Solicitudes* lleva la leyenda corta y el detalle en un párrafo.
    - Comprobado **sin desbordes horizontales, sin textos aplastados y sin nada fuera de su tarjeta** en las 36 pantallas. Los informes con muchas columnas siguen desplazándose en horizontal dentro de su recuadro (a propósito).
12. **Listado de usuarios con filtros y paginación en el navegador (v0.12.2):**
    - **Escritorio:** debajo de los encabezados hay una fila de filtros (`tr.asde-filtros`): dos cajas de texto (usuario y persona) y tres desplegables (roles, ámbito y estado). Los encabezados se vuelven enlaces de orden (▲ ▼ ↕) y debajo de la tabla está la paginación de siempre (clases `asde-paginacion*`), montada por `assets/js/usuarios.js`.
    - **Móvil (≤ 767 px):** la cabecera de la tabla no se ve, pero sí la fila de filtros, como una tarjeta con un campo por columna (usuario y persona a todo el ancho, roles y ámbito en dos columnas, estado a todo el ancho); encima, «Ordenar por» con dos desplegables (columna y «A → Z»/«Z → A»). La paginación cabe en una sola línea (botones de 30 px).
    - **Sin JavaScript:** los controles llevan el atributo `hidden` y solo los activa el script, así que se ve la lista entera y las acciones recargan la página como antes.
    - **Trampa:** `display: block/grid` en `tr` o `div` anula el atributo `hidden` del navegador; por eso hay una regla `[hidden] { display: none !important }` para las filas, el orden móvil y los botones de la barra.

## Observaciones del contenido actual (fuera de alcance, para quien mantenga la web)

- `/nuestro-grupo-scout/` dice "Son 12 años…", cifra desactualizada: el grupo se fundó en 2010.
- El título es "Nuestros Grupos Scout" (en plural), pero solo aparece Myotragus. Encaja con la previsión de varios grupos (P-10).
