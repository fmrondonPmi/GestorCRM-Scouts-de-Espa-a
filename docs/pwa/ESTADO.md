# PWA · estado

**Plugin:** v0.12.3 en el código. La PWA saldrá como **v0.13.0** al cerrar la sección 6.
**Última actualización:** 2026-09-30.

> ⚠ **2026-10-02: el plugin y estos docs ya no están en la carpeta antigua del proyecto.** Viven en el repo git `C:\Proyectos\GestorCRM` (`asde-area-privada/`, `manual/`, `docs/`; ver `CLAUDE.md`): trabajar y commitear allí. Esta carpeta `docs/` (y `docs/pwa/`) sigue siendo común.

| # | Sección | Estado |
|---|---|---|
| 1 | **Base instalable:** manifiesto, iconos, etiquetas del `<head>` | ✅ hecha (2026-09-30) |
| 2 | **Service worker y página sin conexión** (solo estáticos; nunca datos personales) | ✅ hecha (2026-09-30) |
| 3 | **Invitación a instalar** (Android: aviso del navegador; iPhone: guía «Añadir a pantalla de inicio») | ⏳ |
| 4 | **Experiencia de app:** modo pantalla completa (ocultar cabecera del tema), zonas seguras, atrás, recarga | ⏳ |
| 5 | **Ajustes en administración** (activar/desactivar, nombre, colores, icono), «Instalación y entorno» y «Comprobar el hosting» | ⏳ |
| 6 | **Cierre:** pruebas en conjunto, docs del proyecto (00, 03, 06, 09, 10, 21), zip v0.13.0 | ⏳ |
| 7 | *(Opcional, más adelante)* Notificaciones push | — |

## Siguiente paso
**Sección 3: invitación a instalar.** Nuevo `assets/pwa/instalar.js` (+ un bloque nuevo al final de `area-privada.css`, releyéndolo antes) encolado desde `Pwa::registro()`/un método nuevo en `Pwa.php`: en Android/Chrome capturar `beforeinstallprompt` y mostrar un botón «Instalar la app»; en iPhone (Safari, no `navigator.standalone`) mostrar una guía corta «Compartir → Añadir a pantalla de inicio»; no mostrar si ya está instalada (`display-mode: standalone`) ni si la persona la ha cerrado (recordarlo en `localStorage`, con try/catch). Sitios: pantalla de acceso y cabecera/inicio del área. Pruebas: texto y condiciones en PHP (`tests/pwa.php`); la lógica del JS, con simulaciones en la consola del navegador.

## Registro
- 2026-09-30: creada esta carpeta; el usuario pide ir por secciones, pocos agentes y documentar para ahorrar tokens.
- 2026-09-30: **sección 1 hecha.** Ficheros: `src/Pwa/{Pwa,Manifiesto,Iconos}.php`, `assets/pwa/icono-{192,512,maskable-512,apple-180}.png` (generados con GD: tienda blanca sobre morado #632599), `tests/pwa.php` (55 comprobaciones, TODO OK; incluye un GET real al manifiesto), una línea `use` y otra `( new Pwa() )->register();` en `src/Plugin.php`. Comprobado con `curl`: el manifiesto se sirve con `application/manifest+json`, 200, `no-cache`; las etiquetas salen en la página pública *Acceso socios*. Filtros disponibles: `asde_ap_pwa_activa`, `asde_ap_pwa_nombre`, `asde_ap_pwa_nombre_corto`. Desplegado en Local (`localhost:10011`).
- 2026-09-30: **sección 2 hecha.** Ficheros nuevos: `src/Pwa/ServiceWorker.php` (config + contenido), `assets/pwa/sw.js` (plantilla; PHP sustituye `__ASDE_CONFIG__`), `assets/pwa/sin-conexion.html` (estática, SVG en línea, sin recursos externos), `assets/pwa/registro.js`; cambios en `Pwa.php` (ruta `/asde-sw.js`, método común `responder()`, `registro()` que encola `registro.js` con `window.asdePwa={sw,alcance}` en las páginas del área). **Qué guarda el SW:** solo la página «sin conexión» (de antemano) y los estáticos de `plugins/asde-area-privada/assets/` (CSS/JS/iconos; respuestas 200 `basic`, «guardada y refresco por detrás»). **Nunca:** páginas navegadas (red siempre; solo si falla la red → «sin conexión»; un 500 se ve tal cual), POST, JSON/ventana emergente, `uploads/`, otros orígenes. Caché `asde-pwa-<versión>`: se borran las antiguas al activarse; al subir la versión del plugin el SW se actualiza solo (`skipWaiting` + `claim`). `tests/pwa.php` ahora tiene **88 comprobaciones, TODO OK** (incluye GET reales a `/asde-sw.js` y a la página sin conexión). Además se probó la **lógica del SW con simulaciones en el navegador** (17 casos: install, activate, POST, otro origen, JSON, uploads, navegación con/sin red, 500, estáticos guardados/no guardados, respuestas no 200 u opacas): todos OK. **Límite de la prueba:** el navegador integrado de la app de escritorio **no permite registrar ningún service worker** (falla hasta uno de prueba), así que el registro real y el modo sin conexión **aún no se han visto en un navegador de verdad**: pendiente de comprobar en Chrome (Claude in Chrome o el móvil del usuario) antes del cierre (sección 6).
- 2026-09-30: **coordinación:** otra sesión («Scouts de Balears área privada») cierra la v0.12.3 en el mismo plugin (Instantanea, PersonaRepository, Escritura, area.js, area-privada.css, docs 00/03/04/08/26). Acuerdo: yo solo toco `src/Pwa/`, `assets/pwa/`, `tests/pwa.php`, `docs/pwa/` y las 2 líneas de `Plugin.php`; en CSS/JS/docs comunes solo añadiré bloques nuevos tras releer; versión propia v0.13.0 y zip propio; su zip 0.12.3 va sin Pwa. **Antes de tocar `Plugin.php`, `area-privada.css` o el índice 00, releerlos.**
