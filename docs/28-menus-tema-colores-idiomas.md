# 28 · Menús por rol, independencia del tema, colores e idiomas (v0.15)

Petición del usuario (2026-10-02). Cuatro cambios; cada uno se cierra con sus pruebas y va en **su propio commit**.

| # | Cambio | Estado |
|---|---|---|
| 2 | Menús distintos para scouters (gestión) y familias | ✅ |
| 4 | El plugin no depende del tema: se sugiere uno, no es obligatorio | ✅ |
| 5 | Pantalla de gestión de colores | ✅ |
| 1 | Selector de idioma (castellano, catalán, euskera, gallego) y gestión de etiquetas | ✅ |

---

## 2 · Menús distintos para scouters y familias

**Qué hay.** `Frontend\Menu` agrupa las entradas del menú en dos grupos, derivados de las capabilities de siempre (no hay un «tipo de cuenta» nuevo):

| Grupo | Título | Entradas |
|---|---|---|
| **Personal** | «Mi espacio» (con ficha propia) o «Mi familia» (solo menores a cargo) | Mis datos / Mi familia · Mis pagos · Tienda (o «Mis pedidos» si además gestiona) |
| **Gestión** | «Gestión del grupo» | Personas · Solicitudes · Cambios · Informes · Gestión de la tienda · Tesorería · Usuarios (cada una, según sus permisos) |

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

---

## 5 · Colores del área privada

**Dónde.** wp-admin → Grupos Scouts → **Colores** (`Admin\ColoresPage`, solo administradores).

**Qué se puede elegir (12 colores):**
- *Color de marca:* Principal, Principal oscuro, Acento (la línea bajo el menú) y Fondo suave.
- *Texto y fondos:* Texto, Texto atenuado, Bordes y Fondo de las tarjetas.
- *Estados:* Correcto, Aviso, Error e Información.

**Cómo funciona** (`Frontend\Colores`).
- Todo el CSS del plugin usa ahora variables `--asde-*` (antes había ~90 colores fijos). Los valores de siempre siguen en el CSS; **solo se guardan los colores que se cambian** (opción `asde_ap_colores`) y se emite un bloque de CSS después del de siempre. Un color igual al de siempre no se guarda: así, si no se toca, el principal sigue siendo el de Enfold.
- **De un color salen los demás**, para que el conjunto quede coherente: al elegir el principal salen su oscuro, el acento, tres fondos suaves/bordes y el color del texto que va encima (blanco o casi negro, según se lea mejor); al elegir un estado, su fondo y su texto (oscurecido hasta cumplir 4,5:1). Lo que se elige a mano manda sobre lo derivado.
- **Modelos de partida:** morado de siempre, verde scout, azul, naranja, rojo y gris azulado: cada uno fija solo el principal.
- **Vista previa en vivo** con las clases reales del área (menú, tarjetas, etiquetas, avisos, tabla, botón): al mover un color se pide al servidor el CSS y se pinta, así que lo que se ve es lo que se guardará.
- **Avisos de contraste** (WCAG AA, 4,5:1) para el texto, el texto atenuado, el principal sobre el fondo y el texto sobre el principal.
- Con otro color principal, los botones del área (`.button`, `input[type=submit]`) también lo siguen (si no se toca, siguen siendo los del tema).
- **Emails** (invitación, SEPA, solicitud rechazada): llevan los colores elegidos (no usan el CSS del plugin; `Colores::para_email()`).
- Cada cambio queda en el registro de auditoría; al desinstalar el plugin se borra la opción.

**Decisiones / supuestos** (para confirmar).
- Se unificaron los matices casi idénticos del CSS (8 lilas muy claros en uno, los verdes/amarillos/rojos de texto de cada estado en uno): el aspecto por defecto cambia, como mucho, en una tonalidad imperceptible.
- Los colores son **globales para todo el sitio** (no por grupo).
- La **PWA** (`theme_color` del manifiesto, de la otra sesión) **no** sigue aún el color principal: es de su sección 5 (ajustes de la PWA). Pendiente de que la otra sesión la enlace con `Colores::para_email()['primary']`.

Pruebas: `tests/colores.php` (32 comprobaciones). Revisión visual con capturas de Chrome del área con los colores por defecto, verde y azul.

---

## 1 · Idiomas y gestión de etiquetas

**Dónde.** wp-admin → Grupos Scouts → **Idioma y etiquetas** (`Admin\IdiomasPage`, solo administradores).

**Idiomas:** castellano (`es`, el original), catalán (`ca`), euskera (`eu`) y gallego (`gl`).

**Ajustes** (opción `asde_ap_idioma`):
- **Idioma por defecto** del área privada.
- **Dejar que cada persona elija su idioma.** Si está marcado, sale un selector junto a «Salir» en el menú y encima de las pantallas públicas (acceso, activar cuenta, recuperar contraseña, solicitar acceso). La elección se guarda en el usuario (`asde_ap_idioma`) y, sin sesión, en la cookie `asde_ap_lang`; el cambio va por `?asde_lang=xx` con nonce.
- Qué idioma se usa (`Idioma::actual()`): el forzado por el código (emails) → el que eligió la persona, si se permite elegir → el idioma por defecto.

**Gestión de etiquetas.** Hay una pestaña por idioma con la tabla de todos los textos: el original en castellano, la traducción del plugin y un campo para el **texto propio**. Se puede buscar (también en las traducciones), filtrar entre todas, personalizadas y sin traducir, y paginar.
- Lo personalizado (opción `asde_ap_etiquetas`) **manda sobre el catálogo** del plugin y vale para cualquier idioma, también para el castellano. Así se puede cambiar un texto sin tocar el código.
- Se comprueba que el texto propio conserva los mismos huecos (`%s`, `%d`, `%1$s`) que el original. El HTML que se permite es solo el básico: negrita, cursiva, código, saltos de línea y enlaces.
- Se puede restablecer un idioma y **exportar e importar** lo personalizado en un `.json`, para pasarlo de un sitio a otro. Cada cambio queda en la auditoría.

**Cómo está hecho** (`src/Idiomas/`).
- El texto en castellano **es la clave**: `asde_t()` devuelve texto plano, `asde_h()` HTML limpio, `asde_e()` lo imprime, `asde_a()` lo devuelve para un atributo, `asde_js()` para un `confirm()` en un atributo, `asde_ta()` traduce las listas de etiquetas de las constantes y `asde_n()` elige entre singular y plural. Las partes que cambian van con `sprintf` y la frase entera es un solo texto, para que cada idioma pueda ordenarla a su manera.
- **Catálogos:** `languages/es.php` es la lista de todos los textos, unos 1.780, con el grupo de pantallas de cada uno. No se edita a mano: se regenera con `herramientas/generar-es.php`, que junta lo que encuentra el `Extractor` con los textos de las constantes (`Textos::extras()`). `languages/ca.php`, `eu.php` y `gl.php` llevan la traducción de cada texto.
- **JavaScript:** los JS piden sus textos con `asdeT( 'texto', arg… )`. El servidor les da el diccionario traducido (`window.asdeI18n`, script `asde-i18n`) con los textos de `TextosJs::LISTA`.
- **Emails:** cada email sale en el idioma de quien lo recibe (`Idioma::en( Idioma::de_usuario() )`). La invitación tras una solicitud sale en el idioma en que se pidió el acceso.
- Las llamadas `__()` que aún quedan con el dominio del plugin también se traducen en el área (filtro `gettext`, solo en la parte pública).

**Decisiones / supuestos** (para confirmar).
- **Las pantallas de wp-admin del plugin siguen en castellano** (en el idioma de WordPress): las usa la administración.
- **Lo que se guarda no se traduce**: motivos de cancelación o de rechazo, conceptos de los movimientos, referencias SEPA, nombres de informes que crea cada uno, títulos de las páginas de WordPress y el catálogo de uniformes que configura el grupo (modelos e insignias). Se ve tal como se escribió. Los informes predefinidos sí se traducen: se guardan en castellano y se traducen al mostrarlos.
- **Las traducciones del plugin son una primera versión**, hecha sin revisión de hablantes nativos. Por eso existe la gestión de etiquetas: conviene que alguien de cada idioma revise los textos más visibles (menú, inicio, acceso, emails). Los nombres de las secciones (Castors, Llobatons, Otsokumeak…) y de los roles son una propuesta.
- Las palabras sueltas que vienen de trocear frases alrededor del HTML (por ejemplo «Si la has olvidado,» + enlace) se traducen trozo a trozo. En euskera el orden de alguna de esas frases puede quedar forzado; si molesta, se rehace la frase en el código como un solo texto.

Pruebas: `tests/idiomas.php` (57 comprobaciones del motor y las pantallas) y `tests/idiomas-catalogos.php`. La segunda comprueba que `es.php` está al día con el código, que los tres catálogos traducen el 100 % con los mismos huecos y etiquetas, que los JS no tienen textos en castellano escritos a mano y que las pantallas salen traducidas. También se han generado todas las pantallas del área, con datos de prueba y cuatro tipos de usuario, con un idioma ficticio y en euskera: solo quedan en castellano datos y nombres propios.
