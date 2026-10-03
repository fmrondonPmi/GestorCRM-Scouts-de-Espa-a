# Manual · Publicar la app (PWA) en producción

> Para quien administra el sitio (https://baleares.scout.es, WordPress en Hostinger con LiteSpeed). **Claude no publica nada en producción**: este manual es lo que hace una persona. Versión del plugin con la PWA: **0.17.1**. Última revisión: 2026-10-03.

## 0. Qué se está publicando (y qué no)

**Es una PWA, no una app de las tiendas.** La misma web del área privada, pero que se puede **instalar en el móvil** (Android e iPhone) con su icono, se abre **a pantalla completa** y, si no hay internet, enseña una pantalla «Sin conexión» en vez del error del navegador. No hay que publicar nada en Google Play ni en App Store.

| Ya incluido (0.17.1) | Todavía no (secciones 4–5 del plan, `ESTADO.md`) |
|---|---|
| Manifiesto de la app (nombre, colores del grupo, icono, alcance) | Pulido del modo pantalla completa (ocultar la cabecera del tema, zonas seguras del iPhone) |
| Icono: el «Icono del sitio» de WordPress o uno genérico (tienda morada) | Ajustes en administración (hoy se apaga con un fragmento de código, §9) |
| Service worker: guarda solo CSS/JS del plugin y la página «Sin conexión» | Notificaciones push |
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
| El plugin en la **0.17.1** (la PWA entró en la 0.17.0; la 0.16.x y anteriores no la llevan activa) | *Plugins* |
| Un **«Icono del sitio»** cuadrado de **512 × 512 px** (PNG) para que la app lleve el logo del grupo | *Apariencia → Personalizar → Identidad del sitio*. Sin él se usa el icono genérico |

## 2. Antes de subir (en el ordenador)

1. En Local, la suite entera en verde: `herramientas-desarrollo/probar-todo.sh` («TODO OK») y `wp eval-file …/tests/pwa.php` (la suite general no incluye `pwa.php`: se lanza aparte; doc 10).
2. **Copia de seguridad** del sitio de producción (la de Hostinger, *Copias de seguridad*, o la que uséis) **antes** de subir el plugin.
3. Anotar la versión que hay ahora en producción por si hay que volver atrás (el zip anterior se conserva en la carpeta del proyecto).

## 3. El paquete

El zip `asde-area-privada-0.17.1.zip` se construye en el ordenador con `herramientas-desarrollo/construir-zip.ps1` (sin tocar la versión). **Debe llevar** `src/Pwa/`, `assets/pwa/` (iconos, `sw.js`, `sw-apagado.js`, `registro.js`) y `templates/pwa/`, y **no** `tests/`. Para comprobarlo: abrir el zip y mirar que están esas tres carpetas y que `tests/` no está.

## 4. Subir y activar

1. *Plugins → Añadir nuevo → Subir plugin* → elegir el zip → **Instalar ahora**.
2. WordPress avisa de que ya existe: **«Reemplazar el actual con el subido»**. Los datos y los ajustes se conservan.
3. Si estaba activo sigue activo. Al cambiar la versión, el instalador del plugin se ejecuta solo (roles, tablas, páginas; docs 10 y 19).
4. Entrar en *Grupos Scouts → Instalación y entorno* y comprobar que todo sale en verde.

## 5. Ajustes del grupo que afectan a la app

- **Icono del sitio** (§1): se aplica a la app instalada y al icono del iPhone.
- **Colores** (*Grupos Scouts → Colores*): el color principal es el de la barra del móvil y el de la página «Sin conexión». Si se cambia, el service worker se actualiza solo y vuelve a guardar la página con el color nuevo.
- **Idioma:** el nombre de la app y la página «Sin conexión» salen en el idioma de la persona (si el grupo permite elegir) o en el idioma por defecto.
- **Nombre:** «Área privada · <nombre del sitio>» (el nombre del sitio está en *Ajustes → Generales*; la etiqueta «Área privada» se puede personalizar en *Idioma y etiquetas*).

## 6. Caché y servidor: lo que más suele fallar

La app usa **tres direcciones virtuales** en la raíz del sitio (no son ficheros: las genera WordPress). **Ninguna puede quedar en una caché**:

| Dirección | Para qué | Respuesta correcta |
|---|---|---|
| `/asde-manifest.webmanifest` | Manifiesto | `200`, `application/manifest+json`, `Cache-Control: no-cache` |
| `/asde-sw.js` | Service worker | `200`, `text/javascript`, `Cache-Control: no-cache` |
| `/asde-sin-conexion` | Página «Sin conexión» | `200`, `text/html`, `Cache-Control: no-cache` |

El plugin ya manda las señales de «no cachear» (`DONOTCACHEPAGE`, `litespeed_control_set_nocache` y la cabecera), pero **en Hostinger hay que comprobarlo** (§7) y, si alguna sale cacheada o modificada:

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
3. Entrar con un usuario de prueba (con doble factor si es del equipo), navegar por varias pantallas y **salir**. Comprobar que el acceso, la verificación en dos pasos y el cierre de sesión funcionan dentro de la app.
4. Poner el **modo avión**, cerrar y volver a abrir la app o pulsar un enlace: debe salir **«Sin conexión»** con el botón *Reintentar*. Al quitar el modo avión, se recarga sola.

**iPhone (Safari):**
1. Abrir «Acceso socios» en **Safari**: sale al pie el aviso con la **guía** (iOS no deja instalar con un botón). Seguirla: botón **Compartir** → **Añadir a pantalla de inicio** → *Añadir*. «Entendido» cierra el aviso.
2. Abrirla desde el icono: pantalla completa. Probar entrar, navegar y salir como en Android.
3. Modo avión: debe salir «Sin conexión».

**Qué anotar si algo falla:** modelo del móvil, navegador y versión, qué paso y una captura. Los fallos de instalación casi siempre son de §6 (rutas cacheadas) o de HTTPS.

## 9. Desactivar la app o volver atrás

- **Apagarla sin perder nada** (hasta que haya un ajuste en administración): crear el fichero `wp-content/mu-plugins/asde-pwa-apagada.php` con
  ```php
  <?php
  add_filter( 'asde_ap_pwa_activa', '__return_false' );
  ```
  Con eso desaparecen las etiquetas, el manifiesto y la página «Sin conexión», y quien ya tenía la app recibe un service worker de apagado que **borra las cachés y se da de baja solo** (puede tardar hasta 24 h en llegar a cada móvil; el icono de la pantalla de inicio lo puede borrar cada persona). Para volver a encenderla, borrar el fichero.
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

## 13. Lo que **no** se ha podido comprobar todavía

Para ser claros con lo que está probado y lo que no:

- ✅ Probado en Local: manifiesto, rutas, etiquetas, colores, idiomas, página «Sin conexión», apagado, el aviso de instalación y 144 comprobaciones automáticas; la **lógica** del service worker con simulaciones (red, sin red, POST, JSON, estáticos).
- ✅ **Probado en Chrome de escritorio real (2026-10-03, contra Local):** el service worker se registra, se activa y controla la página con el alcance correcto; **se actualiza solo** de una versión antigua a la nueva (borra la caché vieja y guarda «Sin conexión» en la nueva); la página «Sin conexión» se muestra bien; Chrome **dispara el aviso de instalación** (es decir, la app cumple los requisitos de instalable) y nuestro aviso sale y se cierra.
- ⚠️ **Sin ver todavía:** el modo avión de verdad (el «Sin conexión» cuando falla la red; solo probado con simulaciones y mirando la página guardada), la **instalación efectiva** en un Android e iPhone reales (en Chrome de escritorio no se pulsó «Instalar») y el aspecto con la app ya instalada. Es lo primero que hay que mirar en el móvil (§8).
- ⚠️ **No probado en producción** (Hostinger/LiteSpeed): las cabeceras de §6 y la instalación real en iPhone y Android.
- Fuera del alcance de la PWA: publicar en Google Play / App Store (haría falta envolverla con Capacitor, cuentas de desarrollador —Google 25 € una vez, Apple 99 €/año— y pasar la revisión de cada tienda; es un paso posterior que reutiliza todo esto).

## 14. Lista final (imprimible)

- [ ] Copia de seguridad hecha
- [ ] Zip `asde-area-privada-0.17.1.zip` con `src/Pwa`, `assets/pwa`, `templates/pwa` y sin `tests/`
- [ ] Plugin reemplazado y activo; *Instalación y entorno* en verde
- [ ] «Icono del sitio» 512 × 512 puesto
- [ ] Las tres rutas excluidas de LiteSpeed Cache y del CDN (§6)
- [ ] Los tres `curl` del §7 dan 200 y `no-cache`
- [ ] DevTools: Manifest sin errores y service worker activo
- [ ] Android: instalada, entra, sale, «Sin conexión» en modo avión
- [ ] iPhone: añadida a pantalla de inicio, entra, sale, «Sin conexión» en modo avión
- [ ] Texto para familias enviado (§11)

Relacionado: doc 18 (comprobar el hosting y el `.htaccess`), doc 19 (Instalación y entorno), doc 10 (pruebas y entorno), `ARQUITECTURA.md` y `ESTADO.md` de esta carpeta.
