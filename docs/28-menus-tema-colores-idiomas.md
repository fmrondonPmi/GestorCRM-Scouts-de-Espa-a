# 28 · Uniformes para scouters, menús por rol, independencia del tema, colores e idiomas (v0.15)

Petición del usuario (2026-10-02). Cinco cambios; cada uno se cierra con sus pruebas y va en **su propio commit**.

| # | Cambio | Estado |
|---|---|---|
| 3 | Todo scouter pide su uniforme, no solo lo gestiona | ✅ |
| 2 | Menús distintos para scouters (gestión) y familias | ✅ |
| 4 | El plugin no depende del tema: se sugiere uno, no es obligatorio | ✅ |
| 5 | Pantalla de gestión de colores | ⏳ |
| 1 | Selector de idioma (castellano, catalán, euskera, gallego) y gestión de etiquetas | ⏳ |

---

## 3 · Los scouters también piden su uniforme

**Qué pasaba.** El permiso ya lo tenían (`asde_uniformes_pedir`) y `Servicio::para_quien()` incluye la ficha propia, pero en el menú principal el scouter solo veía «Uniformes» (la gestión): el formulario de pedido quedaba dentro de una subpestaña («Mis pedidos»), poco visible, y las tareas pendientes de entrega y de confirmar se mezclaban en un solo contador.

**Qué hay ahora.** Quien puede pedir y gestionar tiene **dos** entradas: «Mi uniforme» (pedir, seguir su pedido y confirmar la recepción; el contador son los pedidos por confirmar) y «Gestión de uniformes» (el contador son los pagos, inclusiones en el pedido y entregas pendientes). Quien solo pide (familias, participantes) sigue viendo «Uniforme».

**Decisiones / supuestos.**
- Un scouter pide **solo para sí mismo**; no para otros de su sección (los gestiona, no los pide). Lo comprueba una prueba.
- No cambian las capabilities ni los permisos.

Pruebas: `tests/uniformes.php` (pestañas del scouter, el formulario de pedido que se ve y el pedido de un scouter que no es de Tesorería).

---

## 2 · Menús distintos para scouters y familias

**Qué hay.** `Frontend\Menu` agrupa las entradas del menú en dos grupos, derivados de las capabilities de siempre (no hay un «tipo de cuenta» nuevo):

| Grupo | Título | Entradas |
|---|---|---|
| **Personal** | «Mi espacio» (con ficha propia) o «Mi familia» (solo menores a cargo) | Mis datos / Mi familia · Mis pagos · Uniforme (o «Mi uniforme» si además gestiona) |
| **Gestión** | «Gestión del grupo» | Personas · Solicitudes · Cambios · Informes · Gestión de uniformes · Tesorería · Usuarios (cada una, según sus permisos) |

- Una **familia** o un **participante** solo ve el grupo personal (y sin rótulos: no hace falta).
- Un **scouter** ve los dos, con un rótulo en el menú («Mi espacio» · «Gestión del grupo») y, en el inicio, dos bloques de atajos («Para ti» / «Gestión del grupo»). El aviso «Pendiente de revisar» es de gestión.
- Quien **no tiene ficha** (un administrador de WordPress) ve solo la gestión.
- «Seguridad de la cuenta» sigue siendo de cada persona: va con lo personal (o con la gestión si no hay lo personal).
- `View::pestanas()` se conserva (la lista plana, con «Inicio» delante) y la plantilla `comun/nav.php` sigue admitiendo `$items` para quien la sobrescriba desde el tema.

**Decisiones / supuestos** (para confirmar).
- El menú se separa; las **páginas** no cambian: el permiso de cada una sigue siendo el mismo.
- Un mismo menú en una sola fila que se desplaza en el móvil (como antes), con el rótulo del grupo dentro de la fila; no son dos menús desplegables.

Pruebas: `tests/menus.php` (23 comprobaciones: familia, participante, scouter, Coordinación, scouter+tutor, administrador y lo que se pinta).

---

## 4 · El plugin no depende del tema

**Qué había.** Casi nada estaba atado a Enfold, pero sí: los metadatos `layout` y `header_title_bar` que se escribían en las páginas, el botón «Ocultar cabecera morada» y la comprobación del entorno, que daba a entender que otro tema era «peor».

**Qué hay ahora** (`Frontend\Tema`, un único sitio que sabe de temas):
- El **área privada funciona con cualquier tema**: las páginas son páginas normales con un shortcode y el CSS y los textos son del plugin. Con un tema distinto de Enfold simplemente se omiten tres cosas: los ajustes de página de Enfold (ancho completo y sin barra de título), el botón de plegar la cabecera y los colores heredados de Enfold (se usan los de la pantalla «Colores»).
- Los ajustes de página de Enfold solo se escriben si Enfold está activo; **al cambiar a Enfold** (`after_switch_theme`) se aplican solos.
- El botón «Ocultar cabecera» (y el script que recuerda la cabecera plegada) solo salen con un tema cuya cabecera sabemos plegar. Otro tema con la cabecera en `#header` puede pedirlo con el filtro `asde_ap_cabecera_plegable`.
- **Wp-admin → Instalación y entorno:** la fila «Tema» ya no es un aviso de error con otro tema, sino informativa, y hay una fila nueva, «Qué tema conviene», con la sugerencia.

**Qué tema conviene** (texto que ve la administración):
- **El ideal: Enfold** (el de baleares.scout.es), para el que está diseñada.
- **Sirve** cualquier tema que permita páginas a **ancho completo, sin barra lateral y sin barra de título**, con el menú principal editable en Apariencia → Menús (por ejemplo Astra, Kadence o GeneratePress; no se han probado todos).
- **Los temas de bloques** (como Twenty Twenty-Five) también funcionan: poner las páginas del área en una plantilla de ancho completo y añadir «Acceso socios» desde el Editor del sitio.
- **Conviene evitar** los que obligan a una barra lateral o estrechan el contenido (los listados son tablas anchas).

**Supuesto / límite.** En el Local solo está instalado Enfold, así que otro tema se ha comprobado **simulándolo** (filtro `asde_ap_tema_slug`) y no visualmente. Antes de usar otro tema en producción conviene revisar las pantallas con él (sobre todo el ancho de las tablas y el menú en el móvil).

Pruebas: `tests/tema.php` (15 comprobaciones).
