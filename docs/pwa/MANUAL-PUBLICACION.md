# Manual · Publicar la app (PWA) en producción

> Para quien administra el sitio (https://baleares.scout.es, WordPress en Hostinger con LiteSpeed). **Claude no publica nada en producción**: este manual es lo que hace una persona. Versión del plugin con la PWA: **0.17.3**. Última revisión: 2026-10-03.

## 0. Qué se está publicando (y qué no)

**Es una PWA, no una app de las tiendas.** La misma web del área privada, pero que se puede **instalar en el móvil** (Android e iPhone) con su icono, se abre **a pantalla completa** y, si no hay internet, enseña una pantalla «Sin conexión» en vez del error del navegador. No hay que publicar nada en Google Play ni en App Store.

| Ya incluido (0.17.3) | Todavía no (`ESTADO.md`, sección 6) |
|---|---|
| Manifiesto de la app (nombre, colores del grupo, icono, alcance) | Apartado «Instalar la app» en los manuales de uso (sección 6) |
| Icono: el «Icono del sitio» de WordPress o uno genérico (tienda morada) | Notificaciones push |
| Service worker: guarda solo CSS/JS del plugin y la página «Sin conexión» | |
| **Pantalla *Grupos Scouts → App móvil*** (administradores): encender/apagar la app, nombre bajo el icono, cómo sale (icono, color, alcance) y **«Comprobar el servidor»** (las tres direcciones, sin cachés por medio); y una pieza «App móvil» en *Instalación y entorno* | |
| **Modo app (pantalla completa):** sin cabecera ni pie del tema en las páginas privadas, zonas seguras del iPhone, y en móvil una **barra inferior «Atrás · Inicio · Recargar»**; «Salir» cierra la sesión sin salir de la app | |
| Página «Sin conexión» en castellano, catalán, euskera o gallego, con el color del grupo | |
| **Aviso «Instala la app en tu móvil»** al pie de «Acceso socios» y del inicio del área: botón «Instalar la app» en Android/Chrome y guía «Compartir → Añadir a pantalla de inicio» en iPhone/iPad; se puede cerrar («Ahora no») y no vuelve a salir en 30 días; no sale si la app ya está instalada | |
| Apagado limpio: si se desactiva, las apps ya instaladas se dan de baja solas | |

**Qué guarda el móvil:** únicamente ficheros del plugin (CSS, JS, iconos) y la página «Sin conexión». **Nunca** páginas del área, fichas, pagos, respuestas JSON ni nada de las personas. La sesión funciona como en cualquier web (cookie normal). No se recoge ningún dato nuevo.

**Alcance:** la app cubre solo `…/nuestro-grupo-scout/area-privada/` y sus páginas. El resto de la web pública sigue siendo una web normal.

## 1. Requisitos del servidor

| Requisito | Cómo comprobarlo |
|---|---|
| **HTTPS válido** (candado en el navegador). Sin HTTPS no hay service worker ni instalación | Abrir el sitio con `https://` sin avisos |
| **Enlaces permanentes «bonitos»** (no `?p=123`) | *Ajustes → Enlaces permanentes*: ya es `/%year%/%monthnum%/%day%/%postname%/` |
| WordPress ≥ 6.4 y PHP ≥ 8.0 | *Herramientas → Salud del sitio → Información* |
| El plugin en la **0.17.3** (la PWA entró en la 0.17.0; la 0.16.x y anteriores no la llevan activa) | *Plugins* |
| Un **«Icono del sitio»** cuadrado de **512 × 512 px** (PNG) para que la app lleve el logo del grupo | *Apariencia → Personalizar → Identidad del sitio*. Sin él se usa el icono genérico |

## 2. Antes de subir (en el ordenador)

1. En Local, la suite entera en verde: `herramientas-desarrollo/probar-todo.sh` («TODO OK») y `wp eval-file …/tests/pwa.php` (la suite general no incluye `pwa.php`: se lanza aparte; doc 10).
2. **Copia de seguridad** del sitio de producción (la de Hostinger, *Copias de seguridad*, o la que uséis) **antes** de subir el plugin.
3. Anotar la versión que hay ahora en producción por si hay que volver atrás (el zip anterior se conserva en la carpeta del proyecto).

## 3. El paquete

El zip `asde-area-privada-0.17.3.zip` se construye en el ordenador con `herramientas-desarrollo/construir-zip.ps1` (sin tocar la versión). **Debe llevar** `src/Pwa/`, `assets/pwa/` (iconos, `sw.js`, `sw-apagado.js`, `registro.js`) y `templates/pwa/`, y **no** `tests/`. Para comprobarlo: abrir el zip y mirar que están esas tres carpetas y que `tests/` no está.

## 4. Subir y activar

1. *Plugins → Añadir nuevo → Subir plugin* → elegir el zip → **Instalar ahora**.
2. WordPress avisa de que ya existe: **«Reemplazar el actual con el subido»**. Los datos y los ajustes se conservan.
3. Si estaba activo sigue activo. Al cambiar la versión, el instalador del plugin se ejecuta solo (roles, tablas, páginas; docs 10 y 19).
4. Entrar en *Grupos Scouts → Instalación y entorno* y comprobar que todo sale en verde.

## 5. Ajustes del grupo que afectan a la app

- **Icono del sitio** (§1): se aplica a la app instalada y al icono del iPhone.
- **Colores** (*Grupos Scouts → Colores*): el color principal es el de la barra del móvil y el de la página «Sin conexión». Si se cambia, el service worker se actualiza solo y vuelve a guardar la página con el color nuevo.
- **Idioma:** el nombre de la app y la página «Sin conexión» salen en el idioma de la persona (si el grupo permite elegir) o en el idioma por defecto.
- **Nombre:** «Área privada · <nombre del sitio>» (el nombre del sitio está en *Ajustes → Generales*). El primer trozo, el que sale **bajo el icono**, se cambia en *Grupos Scouts → App móvil → «Nombre bajo el icono»* (máximo 12 caracteres; vacío = «Área privada» en el idioma de cada persona; solo afecta a las apps que se instalen después).

## 6. Caché y servidor: lo que más suele fallar

La app usa **tres direcciones virtuales** en la raíz del sitio (no son ficheros: las genera WordPress). **Ninguna puede quedar en una caché**:

| Dirección | Para qué | Respuesta correcta |
|---|---|---|
| `/asde-manifest.webmanifest` | Manifiesto | `200`, `application/manifest+json`, `Cache-Control: no-cache` |
| `/asde-sw.js` | Service worker | `200`, `text/javascript`, `Cache-Control: no-cache` |
| `/asde-sin-conexion` | Página «Sin conexión» | `200`, `text/html`, `Cache-Control: no-cache` |

El plugin ya manda las señales de «no cachear» (`DONOTCACHEPAGE`, `litespeed_control_set_nocache` y la cabecera), pero **en Hostinger hay que comprobarlo**. **La forma fácil:** *Grupos Scouts → App móvil → «Comprobar el servidor»*: el sitio se pide a sí mismo las tres direcciones y dice, una por una, si están bien, si responde una caché (LiteSpeed, Cloudflare, proxy, cabecera `Age`) o si el contenido que llega no es el que genera el plugin (minificado). Si no hay nada que revisar, **no hace falta la §7**; esta pantalla hace lo mismo que los `curl`. Si alguna sale cacheada o modificada:

1. **LiteSpeed Cache** (plugin) → *Cache → Excludes → Do Not Cache URIs* (los nombres pueden salir traducidos, según el idioma del panel): añadir las tres rutas (`/asde-manifest.webmanifest`, `/asde-sw.js`, `/asde-sin-conexion`).
2. LiteSpeed Cache → *Page Optimization → Tuning* (excluir JS de la minificación y la combinación): que `asde-sw.js` no se minifique ni se combine.
3. Si hay **CDN** (Cloudflare u otro): regla para no cachear esas tres rutas y no «reescribirlas» (nada de minificado, Rocket Loader ni «Always Online»).
4. **`.htaccess`**: las reglas de WordPress ya envían estas rutas a `index.php`. Si hay reglas de «caducidad del navegador» por tipo `.js`, pueden añadir un `max-age` largo: no es grave (con `no-cache` el navegador revalida y el plugin registra el service worker con `updateViaCache: 'none'`), pero conviene verlo en §7.
5. **Wordfence:** no debería bloquear estas rutas. Si alguna da `403`, mirar los bloqueos recientes del firewall de Wordfence.

## 7. Verificación técnica en producción (desde cualquier ordenador)

Sustituye `https://baleares.scout.es` si cambia el dominio. Cada comando debe dar lo que dice la tabla del §6:

```bash
curl -sI https://baleares.scout.es/asde-manifest.webmanifest
```

```bash
curl -sI https://baleares.scout.es/asde-sw.js
```

```bash
curl -sI https://baleares.scout.es/asde-sin-conexion
```

Mirar en cada una: `HTTP/… 200`, el `content-type` y `cache-control: no-cache`. Si sale `x-litespeed-cache: hit` o `age:` con un número, **está cacheada** (§6).

El manifiesto debe tener el `start_url` y el `scope` de tu área privada y tus iconos:

```bash
curl -s https://baleares.scout.es/asde-manifest.webmanifest
```

Y la página pública de acceso debe llevar las etiquetas de la app (`rel="manifest"`, `theme-color`, `apple-touch-icon`):

```bash
curl -s https://baleares.scout.es/nuestro-grupo-scout/area-privada/acceso/ | grep -E "manifest|theme-color|apple-"
```

(Esa dirección es la de Local; en producción es la de «Acceso socios».)

**En Chrome de escritorio:** abrir la página de acceso → *F12 → Application*: en **Manifest** no debe haber errores ni avisos (aparece «Installability» sin problemas) y en **Service Workers** debe aparecer `asde-sw.js` «activated and is running». Opcional: *Lighthouse → Progressive Web App*.

## 8. Prueba en los móviles (la hace una persona, con el móvil en la mano)

**Android (Chrome):**
1. Abrir «Acceso socios» (HTTPS): al cabo de unos segundos sale al pie el aviso **«Instala la app en tu móvil»** → **Instalar la app**. Si no sale (lo cerraron hace menos de 30 días, o Chrome aún no la ofrece), menú ⋮ → **Instalar aplicación** (o «Añadir a pantalla de inicio»).
2. Debe aparecer el icono en la pantalla de inicio. Abrirlo: **sin barra de direcciones**, con la barra superior del color del grupo.
3. Entrar con un usuario de prueba (con doble factor si es del equipo). Ya dentro: **sin cabecera ni pie de la web**, con el menú del área fijo arriba y, abajo, la **barra «‹ Atrás · Inicio · ↻ Recargar»** (sin el botón de cookies tapándola). Navegar por varias pantallas: «Atrás» debe volver; «Recargar» recarga la página.
4. **Salir** (en el menú del área): debe volver a «Acceso socios» **dentro de la app**, sin saltar a la web pública ni abrir otra ventana. Probar también «Seguridad» (doble factor) y abrir una ficha en la ventana emergente.
5. Poner el **modo avión**, cerrar y volver a abrir la app o pulsar un enlace: debe salir **«Sin conexión»** con el botón *Reintentar*. Al quitar el modo avión, se recarga sola.

**iPhone (Safari):**
1. Abrir «Acceso socios» en **Safari**: sale al pie el aviso con la **guía** (iOS no deja instalar con un botón). Seguirla: botón **Compartir** → **Añadir a pantalla de inicio** → *Añadir*. «Entendido» cierra el aviso.
2. Abrirla desde el icono: pantalla completa. Probar entrar, la barra inferior, «Salir» y la ventana emergente como en Android. En iPhone no hay botón de atrás del sistema: la barra «‹ Atrás» es el único (además del gesto de deslizar desde el borde izquierdo). Mirar que la barra no queda tapada por la línea de inicio del iPhone (zona segura).
3. Modo avión: debe salir «Sin conexión».

**Qué anotar si algo falla:** modelo del móvil, navegador y versión, qué paso y una captura. Los fallos de instalación casi siempre son de §6 (rutas cacheadas) o de HTTPS.

## 9. Desactivar la app o volver atrás

- **Apagarla sin perder nada:** *Grupos Scouts → App móvil* → quitar la marca de **«Activa»** → *Guardar*. Con eso desaparecen las etiquetas, el manifiesto y la página «Sin conexión», y quien ya tenía la app recibe un service worker de apagado que **borra las cachés y se da de baja solo** (puede tardar hasta 24 h en llegar a cada móvil; el icono de la pantalla de inicio lo puede borrar cada persona). Para volver a encenderla, marcar la casilla otra vez.
- **Apagarla desde el código** (por si no se puede entrar a la pantalla, o para que nadie la encienda): crear el fichero `wp-content/mu-plugins/asde-pwa-apagada.php` con
  ```php
  <?php
  add_filter( 'asde_ap_pwa_activa', '__return_false' );
  ```
  Queda apagada igual que desde la pantalla, y el fichero **manda sobre la casilla** (aunque «Activa» esté marcada, sigue apagada). Para volver a encenderla, borrar el fichero.
- **Volver a la versión anterior:** *Plugins → Subir plugin* con el zip anterior y «Reemplazar el actual». Los zips de versiones anteriores se conservan en la carpeta del proyecto. Con una versión anterior a la 0.17.0 no hay PWA y las apps instaladas siguen abriendo como una web normal.

## 10. Actualizaciones futuras

Al subir una versión nueva del plugin, el service worker cambia (lleva la versión), los móviles lo actualizan solos en su siguiente visita y borran la caché antigua. No hay que avisar a nadie ni reinstalar la app. Lo mismo si se cambian los colores o el idioma por defecto.

## 11. Texto para las familias (se puede copiar en un email o en la web)

> **Instala el área privada en tu móvil**
> Así entras con un toque, sin escribir la dirección.
> **Android:** abre la página de acceso en Chrome, pulsa los tres puntos (⋮) y elige *Instalar aplicación*.
> **iPhone:** abre la página de acceso en Safari, pulsa el botón *Compartir* (el cuadrado con la flecha) y elige *Añadir a pantalla de inicio*.
> Es la misma web de siempre, con tu usuario y tu contraseña. Si no tienes internet, verás un aviso y podrás volver a intentarlo.

## 12. Problemas frecuentes

| Síntoma | Causa probable | Qué hacer |
|---|---|---|
| Chrome no ofrece «Instalar» | No hay HTTPS, el manifiesto da error o la app ya está instalada | §7 (DevTools → Application → Manifest) |
| `/asde-manifest.webmanifest` da 404 | Enlaces permanentes «simples» o plugin sin actualizar | *Ajustes → Enlaces permanentes → Guardar*; comprobar que es la 0.17.0 o posterior |
| `/asde-sw.js` sale con `age:` o `x-litespeed-cache: hit` | LiteSpeed o el CDN lo están cacheando | §6 |
| Tras subir una versión nueva sigue la app antigua | El navegador aún no ha comprobado el service worker | Cerrar la app del todo y abrirla de nuevo; con Chrome, *F12 → Application → Service Workers → Update* |
| «Sin conexión» sale con el color o el idioma antiguos | El service worker aún no se ha actualizado | Se corrige solo en la siguiente visita con internet |
| El icono es el genérico (tienda morada) | No hay «Icono del sitio» | §1 y reinstalar la app (el icono se fija al instalar) |
| En iPhone no sale «Instalar» | iOS no lo ofrece solo | *Compartir → Añadir a pantalla de inicio* en Safari |
| La barra del móvil sigue morada tras cambiar los colores | El color se lee al abrir la app | Cerrar la app y abrirla de nuevo |
| En la app sigue saliendo la cabecera de la web | La página es pública (acceso, activar, recuperar, solicitar: conservan el logo) o el CSS viejo está en el móvil | Comprobar que la versión es la 0.17.2 o posterior; cerrar la app y abrirla de nuevo |
| «Salir» no cierra la sesión dentro de la app | El enlace firmado caducó (la página llevaba muchas horas abierta) | Recargar con «Recargar» y volver a pulsar «Salir» |
| La barra inferior no sale | Es un ordenador (la ventana de la app ya trae atrás y recargar) o la web no se abrió como app (se abrió en el navegador) | Normal: solo sale en móviles y tablets con la app instalada |

## 13. Lo que **no** se ha podido comprobar todavía

Para ser claros con lo que está probado y lo que no:

- ✅ Probado en Local: manifiesto, rutas, etiquetas, colores, idiomas, página «Sin conexión», apagado, el aviso de instalación y 198 + 74 comprobaciones automáticas; la **lógica** del service worker con simulaciones (red, sin red, POST, JSON, estáticos).
- ✅ **Probado en Chrome de escritorio real (2026-10-03, contra Local):** el service worker se registra, se activa y controla la página con el alcance correcto; **se actualiza solo** de una versión antigua a la nueva (borra la caché vieja y guarda «Sin conexión» en la nueva); la página «Sin conexión» se muestra bien; Chrome **dispara el aviso de instalación** (es decir, la app cumple los requisitos de instalable) y nuestro aviso sale y se cierra.
- ✅ **Modo app comprobado en una página privada real de Chrome (solo medidas, sin tocar datos):** al activar el modo app desaparecen cabecera y pie, el contenido sube pegado arriba, el menú queda fijo y los enlaces de «Salir» pasan a la dirección firmada; y, en el navegador integrado a 375 px, la barra inferior (botones de 44 px, sin desbordes, sin tapar el botón de cookies) y las variantes iPhone, escritorio y «Atrás» sin historial, con simulaciones. «Salir» se probó con PHP (usuario de prueba, nonce falso/ausente/válido), **no** pulsándolo en el navegador.
- ✅ **Pantalla «App móvil» comprobada en el WordPress real de Local, con Chrome (2026-10-03):** se ve bien, y «Comprobar el servidor» da las tres direcciones en verde (200, tipo correcto, sin caché y contenido idéntico al que genera el plugin). Con ella se encontró que en un servidor con muy pocos procesos PHP (Local) la petición a uno mismo puede agotar el tiempo: ahora se repite una vez. Las pruebas automáticas cubren, con respuestas simuladas, los casos de caché (LiteSpeed, Cloudflare, proxy, `Age`), 404, contenido distinto y apagada; **lo que no se ha podido ver es esta pantalla en Hostinger**: es la primera comprobación que conviene hacer allí (§6).
- ⚠️ **Sin ver todavía:** el modo avión de verdad (el «Sin conexión» cuando falla la red; solo probado con simulaciones y mirando la página guardada), la **instalación efectiva** en un Android e iPhone reales (en Chrome de escritorio no se pulsó «Instalar») y el aspecto con la app ya instalada. Es lo primero que hay que mirar en el móvil (§8).
- ⚠️ **No probado en producción** (Hostinger/LiteSpeed): las cabeceras de §6 y la instalación real en iPhone y Android.
- Fuera del alcance de la PWA: publicar en Google Play / App Store (haría falta envolverla con Capacitor, cuentas de desarrollador —Google 25 € una vez, Apple 99 €/año— y pasar la revisión de cada tienda; es un paso posterior que reutiliza todo esto).

## 14. Lista final (imprimible)

- [ ] Copia de seguridad hecha
- [ ] Zip `asde-area-privada-0.17.3.zip` con `src/Pwa`, `assets/pwa`, `templates/pwa` y sin `tests/`
- [ ] Plugin reemplazado y activo; *Instalación y entorno* en verde
- [ ] «Icono del sitio» 512 × 512 puesto
- [ ] Las tres rutas excluidas de LiteSpeed Cache y del CDN (§6)
- [ ] Los tres `curl` del §7 dan 200 y `no-cache`
- [ ] DevTools: Manifest sin errores y service worker activo
- [ ] Android: instalada, entra, sale, «Sin conexión» en modo avión
- [ ] iPhone: añadida a pantalla de inicio, entra, sale, «Sin conexión» en modo avión
- [ ] Texto para familias enviado (§11)

Relacionado: doc 18 (comprobar el hosting y el `.htaccess`), doc 19 (Instalación y entorno), doc 10 (pruebas y entorno), `ARQUITECTURA.md` y `ESTADO.md` de esta carpeta.

## 15. Avisos push (v0.17.5): activarlos y probarlos

Los avisos push son opcionales y vienen **apagados**. Decisiones del grupo (docs `PUSH.md` §9): cuatro grupos de avisos (uniforme, pagos y cargos, cambios de ficha, avisos del equipo), **texto genérico** («Tienes una novedad en el área privada», sin nombres ni importes) y **se suman al email**. En la v0.17.5 ya funcionan **la activación en cada móvil y el envío de prueba**; conectar los sucesos reales (un pedido cambia de estado, etc.) es el paso siguiente (7c).

**Requisitos en producción:**
- HTTPS (como la app) y la app instalada si es un iPhone (iOS 16.4 o posterior): sin instalar, el iPhone no admite avisos.
- El servidor debe poder **salir** a los servicios de avisos (Google `fcm.googleapis.com`, Mozilla, Apple `web.push.apple.com`…). Hostinger suele permitirlo; si un cortafuegos lo impide, el aviso de prueba lo dirá con el código.
- **WP-Cron** funcionando (doc 18): los avisos reales salen por la cola del WP-Cron. El aviso de prueba sale al momento, sin cola.
- OpenSSL de PHP con curvas elípticas (Hostinger lo trae): las claves del servidor se crean solas al encender los avisos.

**Pasos:**
1. *Grupos Scouts → App móvil* → marca **«Avisos push»** → *Guardar* (se crean las claves del servidor).
2. **En el móvil** (con la app instalada si es iPhone): abre el inicio del área, en la tarjeta **«Avisos en este móvil»** pulsa **«Activar avisos»** y acepta el permiso. Elige las categorías que quieras.
3. En *App móvil* pulsa **«Enviar aviso de prueba a mi móvil»**: debe llegar «Tienes una novedad en el área privada» y, al pulsarlo, abrir el área. La pantalla dice a cuántos móviles de tu cuenta se mandó y, si alguno ya no existía, lo quita.

**Importante:** el aviso **no se manda a un número de teléfono**: el navegador del móvil se «suscribe» con la web y el servicio de avisos del navegador entrega el mensaje a ese dispositivo. Por eso **el móvil tiene que abrir la web real (HTTPS)** para suscribirse: un WordPress en el ordenador (Local, `localhost`) no es accesible desde el móvil.

**Si no llega:** (a) ¿se aceptó el permiso? (Ajustes del navegador o del móvil → notificaciones de la web); (b) la pantalla da un código: `403` = el servicio de avisos rechaza las claves del servidor (volver a suscribirse en el móvil), `410`/`404` = el móvil se dio de baja (se quita solo), `5xx`/`429` = el servicio está saturado (probar luego); (c) en iPhone, ¿se abrió desde el icono de la pantalla de inicio? (d) modo «No molestar» o ahorro de batería del móvil.

**Apagar:** quitar la marca de «Avisos push» y guardar: la cola se vacía sin enviar y no se ofrece la tarjeta. Los móviles ya suscritos se conservan (cifrados) por si se vuelven a encender; si se apaga **toda la app**, el service worker de apagado también los da de baja.
