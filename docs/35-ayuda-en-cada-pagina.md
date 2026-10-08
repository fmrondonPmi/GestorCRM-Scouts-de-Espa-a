# 35 · Ayuda en cada página según el rol (v0.26.0)

## Petición del usuario (2026-10-08)

Primero: «añade el enlace en la cabecera del área privada» al manual publicado en https://baleares.scout.es/manual/index.html. Después, mejor: «hagamos un manual por rol de usuario … según el rol que se loguea deberá ver su manual. Separemos el manual, tendremos en cada página su ayuda, y después un enlace a una wiki. Recuerda usar la skill».

Decisiones del usuario (2026-10-08, al preguntarle): la ayuda se ve **en la ventana emergente**, dentro del área; la «wiki» es **el manual completo de su rol**, publicado.

## Cómo funciona

- En la cabecera de cada página del área, junto al nombre: **«Ayuda»** (`templates/comun/nav.php`, enlace `?asde_ayuda=1` con `data-asde-modal`). Abre en la ventana emergente el **apartado de esa página del manual del rol** de quien entra y, al final, **«Ver el manual completo»**: su manual publicado, abierto en ese apartado y en otra pestaña. Sin JavaScript, la ayuda sale en su página («← Volver a la página»).
- **Qué manual** (`Frontend\Ayuda::manual_de()`, por los roles de la cuenta): Secretaría, Coordinación, Tesorería o quien administra la web → **equipo de coordinación**; Scouter → **scouters**; Tutor → **familias**; Participante → **asociados menores de edad**. El manual de administración es de wp-admin y no tiene ayuda en el área.
- **Si el manual del rol no tiene ayuda de una página**, se busca por este orden: coordinación, scouters, familias, participantes (p. ej. «Mi uniforme» de alguien de Secretaría sale del manual de scouters). Todas las páginas del área tienen ayuda en algún manual (lo comprueba `tests/ayuda.php`).
- **El texto viene con el plugin**: `asde-area-privada/ayuda/<manual>/<página>.html`, generado; las **capturas y los enlaces** van al **manual publicado** (`Organizacion::manual()`). Sin dirección del manual, la ayuda sale solo con el texto.
- **Dirección del manual publicado:** wp-admin → Grupos Scouts → Organización federada → **«Manual de uso»** (la carpeta o la portada `…/index.html`; se guarda la carpeta). Por defecto, la de la organización si se sabe (`Organizacion::MANUALES_PUBLICADOS`: Scouts de Balears → `https://baleares.scout.es/manual/`). Vacía: sin capturas ni enlaces.
- La ayuda está en castellano (como el manual); en otro idioma lo dice.

## De dónde salen los trozos (el manual partido por páginas)

- En `manual/fuentes/*.html`, cada `<section>` o `<h3>` dice qué páginas del área documenta: `data-paginas="tesoreria"`, `data-paginas="usuarios alta emails"` (claves de `Frontend\Pages`). Un `<h3>` con `data-paginas` lleva su parte (hasta el siguiente `<h3>`) con la entrada del apartado (su `<h2>` y lo que va antes del primer `<h3>`); un `<section>`, el apartado entero.
- `php herramientas/generar-manuales.php` monta los cinco manuales (sin los `data-paginas`) y escribe los trozos: capturas con `{{capturas}}`, enlaces al manual (`{{manual}}#…`) y a otros manuales (`{{base}}otro.html#…`) en otra pestaña, sin `id` (chocarían con los de la página) y sin la etiqueta de «quién». Comprueba que las páginas existen. **Si cambian los trozos, cambia el plugin: hay que rehacer su zip.**
- Lo explica la skill `manuales-area-privada` (paso de textos y trampas).

## Al publicar

- El texto de la ayuda es el de la versión del plugin; las capturas, las del manual publicado. **Al actualizar el plugin, publicar también el manual de la misma versión** (`manual-de-uso-X.Y.Z.zip` en `/manual/`), para que las capturas y los apartados coincidan.

## Supuestos

- **P-97** (doc 09): quien administra la web sin roles del grupo ve la ayuda del manual de coordinación; la reserva cuando el manual del rol no tiene la página; ayuda solo en castellano; capturas desde el manual publicado (si no está publicado o no coincide la versión, alguna captura puede no verse).

## Pruebas

`tests/ayuda.php` (26): el manual de cada rol y de cada cuenta; que cada manual tiene la ayuda de sus páginas y que todas las páginas del área tienen alguna; la reserva; páginas inventadas o con «../»; el trozo sin marcadores, comentarios, `id` ni «quién», con la parte justa (Banco, no el coste federativo) y las capturas y enlaces al manual publicado; sin manual publicado; la dirección (por defecto, guardar la portada, rechazar lo que no es una dirección, que guardar la organización no la borre, vacía); «Ayuda» en la cabecera; la ayuda en su página y en la ventana; por el shortcode con `?asde_ayuda=1`; otro idioma; página sin ayuda.
