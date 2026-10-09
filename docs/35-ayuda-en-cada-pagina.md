# 35 · Ayuda en cada página según el rol (v0.26.0)

## Petición del usuario (2026-10-08)

Primero: «añade el enlace en la cabecera del área privada» al manual publicado en https://baleares.scout.es/manual/index.html. Después, mejor: «hagamos un manual por rol de usuario … según el rol que se loguea deberá ver su manual. Separemos el manual, tendremos en cada página su ayuda, y después un enlace a una wiki. Recuerda usar la skill».

Decisiones del usuario (2026-10-08, al preguntarle): la ayuda se ve **en la ventana emergente**, dentro del área; la «wiki» es **el manual completo de su rol**, publicado.

## Cómo funciona

- En la cabecera de cada página del área, junto al nombre: **«Ayuda»** (`templates/comun/nav.php`, enlace `?asde_ayuda=1` con `data-asde-modal`). Abre en la ventana emergente el **apartado de esa página del manual del rol** de quien entra y, al final, **«Ver el manual completo»**: desde la v0.28, el manual entero del rol **en la misma ventana** (`?asde_ayuda=manual&asde_manual=<manual>#asde-manual-<apartado>`), abierto en ese apartado. Sin JavaScript, la ayuda y el manual salen en su página («← Volver a la página»).
- **Qué manual** (`Frontend\Ayuda::manual_de()`, por los roles de la cuenta): Secretaría, Coordinación, Tesorería o quien administra la web → **equipo de coordinación**; Scouter → **scouters**; Tutor → **familias**; Participante → **asociados menores de edad**. El manual de administración es de wp-admin y no tiene ayuda en el área.
- **Si el manual del rol no tiene ayuda de una página**, se busca por este orden: coordinación, scouters, familias, participantes (p. ej. «Mi uniforme» de alguien de Secretaría sale del manual de scouters). Todas las páginas del área tienen ayuda en algún manual (lo comprueba `tests/ayuda.php`).
- **v0.28 · solo texto y siempre en la ventana** (petición del usuario, 2026-10-09: «el zip con las ayudas ha quedado demasiado pesado … rehacer todo el manual sin tantas capturas y solo con texto explicativo, siempre en ventanas modales; dejaremos una versión que se sube manualmente con el manual como ayuda extra»). La **v0.27.0**, que metía en el zip el manual con sus capturas, se **descartó** (el zip pasaba de 10 MB; commit deshecho). Ahora:
  - **Todo lo de la ayuda viene con el plugin y es texto** (~300 KB): los trozos de cada página, `ayuda/<manual>/<página>.html`, y el **manual completo de cada rol**, `ayuda/<manual>/_completo.html` (con índice; sus `id` llevan el prefijo `asde-manual-` para no chocar con los de la página de detrás). Sin imágenes: el pie de cada captura queda como una nota (`.asde-ayuda__pantalla`) sobre lo que se ve en pantalla.
  - Los **enlaces del texto se quedan en la ventana**: a otro apartado o a otro manual llevan al manual completo correspondiente (`data-asde-modal`); los del índice se mueven dentro de la ventana. `area.js` lleva la ventana al apartado pedido (`#…`) y mueve los enlaces internos sin tocar la página de detrás ni la dirección.
  - `Frontend\Ayuda`: `completo()`, `url_completo()`, `pide_completo()`, `manual_pedido()` (`?asde_manual=` solo admite los cuatro manuales de la ayuda; otro da el del rol).
- **El manual con capturas, ayuda extra:** se sube a mano aparte (`manual-de-uso-X.Y.Z.zip`). Su dirección va en wp-admin → Grupos Scouts → Organización federada → **«Manual con capturas (ayuda extra)»** (`Organizacion::manual()`; la carpeta o la portada `…/index.html`; se guarda la carpeta). Por defecto, la de la organización si se sabe (`Organizacion::MANUALES_PUBLICADOS`: Scouts de Balears → `https://baleares.scout.es/manual/`). Con ella, al final de la ayuda y del manual completo sale **«Manual con capturas»** (otra pestaña, en el mismo apartado); vacía, no sale (la ayuda en texto, igual).
- La ayuda está en castellano (como el manual); en otro idioma lo dice.

## De dónde salen los trozos (el manual partido por páginas)

- En `manual/fuentes/*.html`, cada `<section>` o `<h3>` dice qué páginas del área documenta: `data-paginas="tesoreria"`, `data-paginas="usuarios alta emails"` (claves de `Frontend\Pages`). Un `<h3>` con `data-paginas` lleva su parte (hasta el siguiente `<h3>`) con la entrada del apartado (su `<h2>` y lo que va antes del primer `<h3>`); un `<section>`, el apartado entero.
- `php herramientas/generar-manuales.php` monta los cinco manuales con capturas (sin los `data-paginas`) y escribe la ayuda del plugin, solo texto (v0.28): los trozos (enlaces al manual completo con `{{completo}}#asde-manual-…` y a otros con `{{completo:otro}}#…`, sin `id`, sin la etiqueta de «quién») y el manual completo de cada rol (con sus `id` prefijados e índice). Comprueba que las páginas existen, que no hay enlaces rotos en el manual completo y que **la ayuda no lleva ninguna imagen**, y dice cuánto pesa. **Si cambian los textos, cambia el plugin: hay que rehacer su zip.**
- Lo explica la skill `manuales-area-privada` (paso de textos y trampas).

## Al publicar

- Desde la v0.28 la ayuda no depende de nada publicado: el texto viene en el plugin. El manual con capturas es opcional; si se tiene publicado, conviene subir el de la misma versión que el plugin (`manual-de-uso-X.Y.Z.zip` en `/manual/`) para que su enlace lleve a los mismos apartados.

## Supuestos

- **P-97** (doc 09): quien administra la web sin roles del grupo ve la ayuda del manual de coordinación; la reserva cuando el manual del rol no tiene la página; ayuda solo en castellano. Desde la v0.28, sin capturas en la ayuda (los pies quedan como notas) y el manual completo de otro rol se puede abrir desde los enlaces (es el mismo texto público del manual).

## Pruebas

`tests/ayuda.php` (34 desde la v0.28: solo texto con notas en vez de capturas, los enlaces al manual completo en la ventana, el manual completo de cada rol con índice y sin enlaces rotos ni `id` sin prefijo, que el de administración o uno inventado no se leen, que la ayuda pesa menos de 600 KB y no lleva imágenes ni el plugin el manual con capturas, la dirección del manual completo, la ventana con `?asde_ayuda=manual` y el de otro rol, el enlace «Manual con capturas» con y sin su dirección; 26 en la v0.26): el manual de cada rol y de cada cuenta; que cada manual tiene la ayuda de sus páginas y que todas las páginas del área tienen alguna; la reserva; páginas inventadas o con «../»; el trozo sin marcadores, comentarios, `id` ni «quién», con la parte justa (Banco, no el coste federativo) y las capturas y enlaces al manual publicado; sin manual publicado; la dirección (por defecto, guardar la portada, rechazar lo que no es una dirección, que guardar la organización no la borre, vacía); «Ayuda» en la cabecera; la ayuda en su página y en la ventana; por el shortcode con `?asde_ayuda=1`; otro idioma; página sin ayuda.
