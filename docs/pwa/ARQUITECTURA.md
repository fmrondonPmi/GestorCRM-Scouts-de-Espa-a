# PWA · arquitectura y decisiones

## Decisiones
| # | Decisión | Por qué |
|---|---|---|
| D1 | **Rutas virtuales sin reglas de reescritura**: `/asde-manifest.webmanifest` y `/asde-sw.js` (este desde la sección 2) se sirven interceptando la petición en `init` (prioridad 0) si la ruta coincide. | WordPress manda al `index.php` cualquier ruta que no sea un fichero; así **no hay que vaciar las reglas de reescritura** (frágil al actualizar) y el service worker puede ir en la raíz del sitio. |
| D2 | **Alcance (`scope`) = la carpeta de la página «Área privada»** (p. ej. `/nuestro-grupo-scout/area-privada/`), y `start_url` = esa página. | Todas las páginas del área cuelgan de ella (doc 03, `Pages::DEFINICIONES`). El resto del sitio público no se convierte en app. |
| D3 | **Etiquetas `<link rel="manifest">`, `theme-color` y las de iOS solo en las páginas del área** (`Pages::current_key() !== null`). | No tocar el resto de la web. Incluye las públicas (acceso, solicitar…) para poder instalar desde el acceso. |
| D4 | **Icono: primero el «Icono del sitio» de WordPress** (512 px) y, si no hay, **unos iconos genéricos del plugin** (morado #632599, sin marca de nadie). | El grupo pone su icono en *Apariencia → Personalizar → Identidad del sitio* sin tocar código. Los genéricos garantizan que sea instalable desde el primer día. |
| D5 | Nombre de la app = `Área privada · <nombre del grupo>` (corto: `Área privada`, por `asde_t()`). Color del tema = el principal de la pantalla «Colores» (`Colores::para_email()`), con `#632599` de respaldo; fondo `#ffffff`. | Paleta del doc 06; colores e idiomas de la v0.15 (doc 28). Los textos de la PWA están en el catálogo (grupo «App móvil»). |
| D6 | **Nunca se cachea HTML del área ni respuestas con datos personales** (sección 2 en adelante): solo ficheros estáticos del plugin y una página «sin conexión» genérica. | Datos de menores (doc 08): nada personal en el almacenamiento del navegador más allá de la sesión. Además el área ya manda `nocache` y `DONOTCACHEPAGE`. |
| D7 | La PWA se puede **desactivar**: ahora con el filtro `asde_ap_pwa_activa` (fragmento en un mu-plugin, manual §9); la sección 5 lo convertirá en un ajuste. Apagada, `/asde-sw.js` sirve un SW que se da de baja solo. | Reversible en producción sin desinstalar el plugin y sin dejar apps «atascadas» en los móviles. |
| D8 | La **versión del SW** (`config.version`, nombre de la caché) = versión del plugin + huella (md5, 8 caracteres) de la página «sin conexión». | Si cambian los colores o el idioma, el SW cambia byte a byte, el navegador lo actualiza y vuelve a guardar la página nueva. |

## Mapa de ficheros (se va completando)
| Fichero | Sección | Contenido |
|---|---|---|
| `src/Pwa/Pwa.php` | 1 | Registra ganchos: rutas virtuales y etiquetas de `<head>` |
| `src/Pwa/Manifiesto.php` | 1 | Construye el manifiesto (nombre, colores, alcance, iconos) |
| `src/Pwa/Iconos.php` | 1 | Elige los iconos: icono del sitio o genéricos |
| `assets/pwa/icono-*.png` | 1 | Iconos genéricos (192, 512, maskable 512, apple-touch 180) |
| `src/Pwa/ServiceWorker.php` | 2 | Configuración del SW (versión, página sin conexión, carpeta de estáticos) y contenido servido |
| `assets/pwa/sw.js` | 2 | Plantilla del service worker (`__ASDE_CONFIG__`) |
| `templates/pwa/sin-conexion.php` | 2 (0.17) | Página «sin conexión», generada por PHP en `/asde-sin-conexion`: color del grupo, idioma de quien la pide, sin recursos externos ni datos (antes `assets/pwa/sin-conexion.html`, estática) |
| `assets/pwa/sw-apagado.js` | 2 (0.17) | SW de «PWA apagada»: borra las cachés `asde-pwa-*` y se da de baja |
| `assets/pwa/registro.js` | 2 | Registra el SW (`window.asdePwa` lo pone `Pwa::registro()`) |
| `tests/pwa.php` | 1… | Pruebas (108 en la 0.17.0; no entra en `probar-todo.sh` sin argumentos) |

## Trampas conocidas
- Un manifiesto solo es válido si se sirve con `Content-Type: application/manifest+json` y **sin** redirecciones; los avisos de LiteSpeed Cache no deben tocarlo (se manda `nocache` y `DONOTCACHEPAGE` como en el resto del área).
- iPhone: solo permite instalar desde **Safari** («Compartir → Añadir a pantalla de inicio»); no hay aviso automático (sección 3 lo explica con una guía).
- El Enfold pinta su propio `<link rel="icon">`; el `apple-touch-icon` del tema, si existe, puede convivir con el nuestro: iOS usa el último.
- **Service worker:** se registra con `scope` = carpeta del área (más estrecho que su ubicación `/asde-sw.js`, permitido sin la cabecera `Service-Worker-Allowed`). Debe llegar con `Cache-Control: no-cache`: **en producción (Hostinger/LiteSpeed) hay que comprobar que ninguna regla de caché del navegador por extensión `.js` se lo pone** (la sección 5 lo añade a «Comprobar el hosting»). Solo funciona en HTTPS (o `localhost`).
- El navegador integrado de la app de escritorio de Claude **no registra service workers**; para probar el registro real y el modo sin conexión hay que usar Chrome (extensión) o un móvil. La lógica del SW se prueba con simulaciones (`self`, `caches`, `fetch` falsos) en la consola: para repetirlas, cargar `/asde-sw.js`, sacar `CONFIG` con una expresión regular y ejecutarlo con `new Function('self','caches','fetch','Response','URL', src)`; ojo: una `Response` creada a mano tiene `type:'default'`, hay que forzar `'basic'` para simular una respuesta real.
- Las pruebas nunca se hacen en el WordPress en uso dentro del navegador (incidente del 2026-09-28, doc 10): HTML volcado + servidor estático, o `wp eval-file`.
