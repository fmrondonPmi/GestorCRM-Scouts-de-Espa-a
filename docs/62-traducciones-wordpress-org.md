# 62 · Traducciones con translate.wordpress.org (plan, 2026-10-28)

Petición del usuario: que las traducciones del plugin se gestionen en **translate.wordpress.org** (GlotPress) con cinco idiomas: **castellano (es_ES), gallego (gl_ES), euskera (eu), catalán (ca) y valenciano (variante `valencia` de `ca`)**.

## Decisiones del usuario (2026-10-28)

1. **El texto original pasa a inglés.** translate.wordpress.org solo extrae gettext estándar (`__()`, `_e()`, `_n()`, `_x()`, `esc_html__()`…) con el dominio del plugin y el original en inglés. Hoy el castellano es la clave de `asde_t()`; deja de serlo.
2. **Solo se usa el idioma de WordPress.** Se retiran el selector de idioma por persona (`Idioma`, cookie `asde_ap_lang`, meta `asde_ap_idioma`), la pantalla «Idioma y etiquetas» (`Admin\IdiomasPage`) y las etiquetas personalizadas (`Traducciones`). El idioma lo da `determine_locale()` (sitio o perfil de cada usuario). Los emails salen en el idioma del destinatario con `switch_to_user_locale()`.

## Cómo queda

- Cada texto se pide con la función estándar: `asde_t('x')` → `__( 'x', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' )`, `asde_a` → `esc_attr__`, `asde_h`/`asde_e` → `wp_kses( __(…), asde_html_permitido() )`, `asde_n` → `_n()` (plurales de verdad), `asde_js` → `sprintf( __() )`.
- Las constantes con etiquetas (`asde_ta`) pasan a funciones con `__()` literales (una constante no puede llamar a `__()`).
- JavaScript: el servidor entrega `window.asdeI18n` con `__()` literales (`TextosJs`); el JS sigue pidiendo `asdeT()`, ahora con el texto en inglés.
- **El paquete del plugin no lleva ficheros de traducción** (regla de la revisión de WordPress.org, v0.59.3). En el repo, fuera del plugin, `traducciones/`: `asde.pot` y un `.po` por idioma (es_ES, gl_ES, eu, ca, ca_valencia) generados desde los catálogos archivados (`idiomas-archivo/`, clave en castellano) con el nuevo original en inglés; las pruebas los cargan con un filtro. Para subirlas a translate.wordpress.org: el plugin tiene que estar publicado en el directorio; ahí se importan los `.po` (rol de editor de traducciones) o se traducen en la web.
- Lo que **se guarda** (motivos, conceptos, referencias) sigue en castellano, sin traducir.

## Fases

| # | Fase | Estado |
|---|---|---|
| 1 | Mapa castellano → inglés de los 4.583 textos (`traducciones/es-en.json`, +162 de wp-admin) | ✅ |
| 2 | Conversión del código: `a-gettext.php` (5.959 llamadas), `constantes-a-metodos.php` (74 constantes → métodos), dinámicas resueltas, `Guardados`, `TextosJs` | ✅ (sin probar en WordPress) |
| 3 | Selector, etiquetas propias y `IdiomasPage` retirados; `Idioma` ahora es una capa sobre `switch_to_locale()` | ✅ (sin probar) |
| 4 | `herramientas/generar-po.php` → `.pot`, cinco `.po` y `.mo` (4.609 textos; ca/eu/gl sin los 158 de wp-admin) | ✅ |
| 5 | Pruebas adaptadas (`idiomas`, `idiomas-catalogos`, `estilo-wordpress` nuevas; mu-plugin de Local), `CLAUDE.md` y skill `traducir-textos` | ✅ batería del plugin principal: 3.454 OK, 0 fallos |

## Puntos a confirmar

- Código de WordPress del valenciano (`ca_valencia` / `ca@valencia`): comprobarlo en la lista de idiomas de translate.wordpress.org (`/locale/ca/valencia/`) antes de generar el `.po`. Hasta que haya traductores, el valenciano se parte del catalán.
- Textos distintos en castellano que coinciden en inglés (p. ej. «Cerrar» / «Cerrado»): se desambiguan con `_x()` o con otro inglés.
- La app móvil (otro repo) usaba los filtros `asde_ap_catalogo` y `asde_ap_textos`; desaparecen con este cambio.


## Hallazgos de la puesta en marcha

- `switch_to_locale()` solo cambia a idiomas **instalados** en el sitio: los emails a quien tenga un idioma no instalado salen en el del sitio. En producción hay que instalar los paquetes de idioma (ca, eu, gl_ES, valenciano) en Ajustes → Generales.
- Cuando varios castellanos compartían inglés (59 grupos, casi todos género o número), el `.po` toma el más usado del código original (`traducciones/uso-es.json`); los que importaban se han separado con inglés propio (p. ej. «Pending collections»). Si sale alguno mal, usar `_x()` con contexto.
- La app móvil (plugin aparte, descartado) usa `asde_t()` y los filtros `asde_ap_catalogo`/`asde_ap_textos`, que ya no existen: sus pruebas `app-plugin` y `pwa-*` fallan en Local.
- El `.po` de catalán, euskera y gallego no trae los 158 textos de wp-admin (nunca estuvieron traducidos).

## Idioma fijo en castellano (v0.60.1, 2026-11-02)

En producción el sitio está en inglés y no tiene instalado el paquete de idioma del plugin (translate.wordpress.org aún no lo sirve), así que el área salía en inglés mezclado con los datos guardados en castellano. Decisión del usuario: **castellano fijo**.

- `Idioma::fijo()` (por defecto `es_ES`, filtro `asde_ap_idioma_fijo`; vacío = seguir a WordPress). Si el paquete lleva `languages/<dominio>-es_ES.mo` (`Idioma::fijo_activo()`), el plugin lo carga él mismo (`Idioma::registrar()` al cargar el plugin, filtro `override_load_textdomain` y `override_unload_textdomain`), lee el `.mo` con `WP_Translation_File` (`TraduccionesFijas`) y no cambia de idioma (`Idioma::en()`/`forzar()` no hacen nada; los emails salen en castellano).
- `construir-zip.ps1` incluye ese `.mo` por defecto (instalación directa); con `-SinIdioma` hace el paquete de WordPress.org, sin ficheros de traducción. Hay que ejecutar antes `php herramientas/generar-po.php --mo`.
- El resto de WordPress (login, menús de wp-admin) sigue el idioma del sitio: para que también salga en castellano, instalar el paquete es_ES de WordPress.
- Prueba: `tests/idiomas.php`, parte E.
