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
| D7 | La PWA se puede **desactivar** desde *Grupos Scouts → App móvil* (opción `asde_ap_pwa`; sin guardar = activa, regla 5 del `CLAUDE.md`); el filtro `asde_ap_pwa_activa` (fragmento en un mu-plugin) sigue teniendo la **última palabra**. Apagada, `/asde-sw.js` sirve un SW que se da de baja solo. | Reversible en producción sin desinstalar el plugin y sin dejar apps «atascadas» en los móviles. |
| D8 | La **versión del SW** (`config.version`, nombre de la caché) = versión del plugin + huella (md5, 8 caracteres) de la página «sin conexión». | Si cambian los colores o el idioma, el SW cambia byte a byte, el navegador lo actualiza y vuelve a guardar la página nueva. |
| D9 | **Toda regla de `app.css` cuelga de `html.asde-app`** (o de la barra, oculta por defecto) y el JS no hace nada si la web no se abre como app; hay un test que lo comprueba. | En el navegador normal la PWA no cambia ni un píxel. |
| D10 | **«Salir» en la app va por una dirección propia dentro del alcance** (`Acceso socios?asde_pwa_salir=1| D8 | La **versión del SW** (`config.version`, nombre de la caché) = versión del plugin + huella (md5, 8 caracteres) de la página «sin conexión». | Si cambian los colores o el idioma, el SW cambia byte a byte, el navegador lo actualiza y vuelve a guardar la página nueva. |_wpnonce=…`), no por `wp-login.php`. Cierra la sesión con `wp_logout()` y vuelve al acceso. | `wp-login.php` está fuera del alcance: en iPhone dejaría a la persona en la web pública sin menú y sin botón atrás. El nonce evita cierres de sesión forzados desde otra web. |

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
| `assets/pwa/instalar.js`, `instalar.css` | 3 (0.17.1) | Aviso «Instala la app» al pie de acceso e inicio: botón en Android/Chrome (`beforeinstallprompt`), guía en iPhone/iPad; recuerda el cierre 30 días en `localStorage`; los textos llegan de PHP (`Pwa::textos_instalar()`) |
| `assets/pwa/app.js`, `app.css` | 4 (0.17.2) | La app abierta como tal (`html.asde-app`): sin cabecera/pie del tema, zonas seguras, barra inferior táctil «Atrás · Inicio · Recargar», «Salir» dentro del alcance. `Pwa::cabecera_app()`, `body_class()`, `url_salir()`, `salir()`, `textos_app()` |
| `src/Pwa/Ajustes.php` | 5 (0.17.3) | Opción `asde_ap_pwa` (`activa`, `nombre_corto`; sin guardar = activa y nombre de siempre) |
| `src/Pwa/Comprobacion.php` | 5 (0.17.3) | «Comprobar el servidor» (pide las 3 rutas a sí mismo y juzga estado, tipo, `Cache-Control`, cachés y contenido idéntico) y la pieza «App móvil» de *Instalación y entorno* |
| `src/Admin/PwaPage.php` | 5 (0.17.3) | *Grupos Scouts → App móvil*: ajustes, cómo sale la app y comprobación; auditoría `pwa_guardada` |
| `tests/pwa-ajustes.php` | 5 (0.17.3) | 74 comprobaciones; **sí** entra en `probar-todo.sh` |
| `src/Pwa/Vapid.php`, `Suscripciones.php`, `Push.php` | 7a (0.17.4) | Claves VAPID, suscripciones cifradas por cuenta (usermeta) y categorías, operaciones AJAX de la tarjeta y sus datos; `openssl-minimo.cnf` para generar claves en Windows |
| `assets/pwa/push.js` | 7a (0.17.4) | Tarjeta «Avisos en este móvil» en el inicio del área (el permiso se pide al pulsar) |
| `assets/pwa/sw.js` (parte push) | 7a (0.17.4) | `push` (texto siempre genérico; el mensaje solo trae la ruta) y `notificationclick` |
| `tests/pwa-push.php` | 7a (0.17.4) | 121 comprobaciones; entra en la suite |
| `tests/pwa.php` | 1… | Pruebas (198 en la 0.17.2; no entra en `probar-todo.sh` sin argumentos) |

## Trampas conocidas
- Un manifiesto solo es válido si se sirve con `Content-Type: application/manifest+json` y **sin** redirecciones; los avisos de LiteSpeed Cache no deben tocarlo (se manda `nocache` y `DONOTCACHEPAGE` como en el resto del área).
- iPhone: no hay botón de instalar por código; el aviso (sección 3) enseña la guía «Compartir → Añadir a pantalla de inicio». En Chrome para iOS (16.4+) también se puede añadir, pero la guía nombra Safari.
- **Botones flotantes de otros plugins** (cookies, chat) suelen ir abajo a la izquierda: el aviso de instalación deja libre esa esquina en móvil. Si aparece otro, revisar `instalar.css` (`@media (max-width: 480px)`).
- El Enfold pinta su propio `<link rel="icon">`; el `apple-touch-icon` del tema, si existe, puede convivir con el nuestro: iOS usa el último.
- **Service worker:** se registra con `scope` = carpeta del área (más estrecho que su ubicación `/asde-sw.js`, permitido sin la cabecera `Service-Worker-Allowed`). Debe llegar con `Cache-Control: no-cache`: **en producción (Hostinger/LiteSpeed) hay que comprobar que ninguna regla de caché del navegador por extensión `.js` se lo pone** (la sección 5 lo añade a «Comprobar el hosting»). Solo funciona en HTTPS (o `localhost`).
- El navegador integrado de la app de escritorio de Claude **no registra service workers**; para probar el registro real y el modo sin conexión hay que usar Chrome (extensión) o un móvil. La lógica del SW se prueba con simulaciones (`self`, `caches`, `fetch` falsos) en la consola: para repetirlas, cargar `/asde-sw.js`, sacar `CONFIG` con una expresión regular y ejecutarlo con `new Function('self','caches','fetch','Response','URL', src)`; ojo: una `Response` creada a mano tiene `type:'default'`, hay que forzar `'basic'` para simular una respuesta real.
- Las pruebas nunca se hacen en el WordPress en uso dentro del navegador (incidente del 2026-09-28, doc 10): HTML volcado + servidor estático, o `wp eval-file`.
- **Enfold: el `<body>` es `#top`.** Un selector `body.x #top #main` no casa nunca (no hay `#top` dentro del `<body>`): usar `#top.x #main`. Además, la cabecera plegada del plugin ya tiene reglas con `!important` sobre `#top #main` (`html.asde-cabecera-oculta …`): para ganarles hay que igualar o superar su especificidad.
- **Probar la app instalada sin instalarla:** `app.js` y `instalar.js` reciben la ventana como parámetro; en la consola se ejecutan con una ventana falsa (`new Function('window','document', src)(fake, document)`) con `navigator.standalone`, `matchMedia`, `history`, `localStorage` simulados. Es la forma de probar iPhone y escritorio instalado sin dispositivos.
- **En el Chrome del usuario** (extensión): solo lectura y medidas; no pulsar «Instalar» ni «Salir» (instala la app o cierra su sesión); limpiar `localStorage` y cerrar la pestaña al terminar.
- **Pocos procesos PHP (Local: 2):** una petición a uno mismo desde una pantalla de administración puede caer en el proceso ocupado por esa misma pantalla y agotar el tiempo. `Comprobacion::ejecutar()` repite una vez, y solo, si el error es de «timed out». En producción no suele pasar.
- **Formularios de WordPress:** `form.submit()` falla porque el botón de enviar se llama `submit`; en pruebas con el navegador usar `HTMLFormElement.prototype.submit.call(form)`.
- **Push: el texto del aviso nunca viene del mensaje.** El `push` del service worker saca título y texto de su configuración (genérico, en el idioma de la persona) y del mensaje solo toma `u` (una ruta), que se valida contra el alcance de la app. Así, aunque alguien consiguiera mandar un mensaje, no puede poner nombres, importes ni DNI en la pantalla de bloqueo. El test lo comprueba con el código fuente del SW.
- **Push: SSRF.** El servidor hará peticiones a la dirección de cada suscripción; por eso solo se admiten los servicios de avisos conocidos (`Suscripciones::hosts_permitidos()`), https y puerto 443, sin usuario ni clave.
- **Push y OpenSSL de Windows:** `openssl_pkey_new()` falla («No such process») si PHP no encuentra un `openssl.cnf`; `Vapid::generar()` reintenta con `src/Pwa/openssl-minimo.cnf` (con `default_bits`, si no, avisa de «384 bits»). En Linux/Hostinger funciona sin él. `openssl_pkey_derive`, `aes-128-gcm` y `hash_hkdf` están disponibles (comprobado en PHP 8.5/OpenSSL 3.5).
- **El permiso de notificaciones no se puede automatizar** (ni con la extensión de Chrome): se pide con un toque de la persona y lo concede ella en el navegador. Las pruebas del flujo usan una ventana falsa; la suscripción real la hace el usuario.
