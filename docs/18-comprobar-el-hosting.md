# 18 · Comprobar el hosting: tareas en segundo plano (v0.7.3)

## Por qué

Las correcciones masivas de números de socio van en **segundo plano** (doc 17): tandas cortas que se relanzan solas con una **petición del servidor a sí mismo que no espera respuesta**, y **WP-Cron** de respaldo. Hay que saber si el hosting de producción lo permite.

**Producción está en Hostinger.** Las cabeceras públicas de `baleares.scout.es` dicen `platform: hostinger` y `server: hws` (comprobado el 2026-09-27). Hostinger sirve las webs con **LiteSpeed**, que por defecto **mata el proceso PHP en cuanto se desconecta quien hizo la petición**. Justo eso hacen la petición interna de las tareas y la de WP-Cron. Se arregla con `noabort` en el `.htaccess` (documentación de LiteSpeed: [PHP Without Timeout](https://docs.litespeedtech.com/lsws/cp/cpanel/long-run-script/)).

## Opción rápida, sin instalar nada: «Salud del sitio»

En producción: **wp-admin → Herramientas → Salud del sitio**.

- **Pestaña Estado:** si aparece «no pudo completar una solicitud de bucle invertido» (loopback) o avisos de eventos programados que fallan o van con retraso, hay problema. Si no aparecen, esas pruebas están en «Pruebas superadas».
- **Pestaña Información:**
  - *Servidor → Arquitectura del servidor web* dice el servidor;
  - *Constantes de WordPress → DISABLE_WP_CRON* dice si el WP-Cron automático está desactivado.

⚠️ **Limitación:** la prueba de loopback de WordPress **espera la respuesta**, así que **no detecta el corte de LiteSpeed**. Para eso hace falta el diagnóstico siguiente.

## Opción completa: el diagnóstico del plugin

`herramientas/asde-diagnostico-segundo-plano.php` es **un solo fichero** que sirve de dos maneras:

- **Dentro del plugin:** *Herramientas → Diagnóstico segundo plano*. Hay un enlace en *Grupos Scouts → Grupos y conexión CRM*.
- **Solo, para probar un hosting antes de instalar el plugin:** `asde-diagnostico-segundo-plano.zip` (en la carpeta del proyecto).
  1. En producción: **Plugins → Añadir nuevo → Subir plugin**, elige el zip e instala.
  2. Actívalo.
  3. Ve a **Herramientas → Diagnóstico segundo plano** y pulsa **Comprobar ahora**.
  4. Al acabar, **desactívalo y bórralo**: al desactivarlo borra lo que guardó.

  No se conecta al CRM, no guarda datos personales y no crea páginas, roles ni tablas. Requiere WordPress 6.0+ y PHP 7.4+. Si se instala también el plugin completo, se carga una sola vez.

**Qué comprueba** (tarda de 10 s a minuto y medio):

| Prueba | Cómo | Si falla |
|---|---|---|
| **1. Petición interna** | Petición del servidor a sí mismo esperando la respuesta (como «Salud del sitio») | Explica el motivo: cortafuegos (403), contraseña del servidor (401), certificado SSL, tiempo agotado… |
| **2. Sigue trabajando al desconectarse quien la lanza** | La misma petición **sin esperar**, como la tarea real. Tiene que llegar y seguir trabajando 6 s (deja una marca cada segundo) | Dice a los cuántos segundos la cortaron y **propone la regla `noabort`** para copiar |
| **3. WP-Cron** | Programa un evento de prueba y comprueba que se ejecuta. Con `DISABLE_WP_CRON`, mira si hay tareas programadas atrasadas | Propone un cron del servidor |

**Resultado global:**

- ✅ **Funcionan:** las tareas se relanzan solas aunque se cierre la página.
- ❌ **El servidor corta las tareas** (la petición llega pero muere) → añadir `noabort`. Mientras tanto, las tareas solo avanzan con el monitor abierto.
- ⚠️ **Sin petición interna pero con WP-Cron:** avanzan, más despacio (una tanda cada vez que se ejecuta el cron).
- ❌ **Ni una ni otra:** solo avanzan con el monitor abierto.

También muestra el servidor web, PHP y su modo, si es LiteSpeed, si ya hay `noabort` en el `.htaccess`, el estado de WP-Cron, las tareas atrasadas y el límite de tiempo de PHP.

**Seguridad:**

- La petición de prueba no tiene sesión: solo vale con un token aleatorio y durante la comprobación (90 s).
- Al terminar, el token se anula.
- La página y sus llamadas exigen ser administrador.

## Si hay que añadir «noabort» (LiteSpeed / Hostinger)

Con el plugin instalado, lo más fácil es *Grupos Scouts → Instalación y entorno*. Si el servidor es LiteSpeed, ofrece añadir la regla, con copia del fichero y vuelta atrás automática si la web deja de responder (doc 19). A mano:

1. En Hostinger: **hPanel → Archivos → Administrador de archivos → `public_html/.htaccess`**. Guarda antes una copia del fichero.
2. Añade al **PRINCIPIO** (antes de `# BEGIN WordPress`):

```apache
# BEGIN ASDE tareas en segundo plano
<IfModule Litespeed>
RewriteEngine On
RewriteCond %{QUERY_STRING} (^|&)action=asde_(ap_tarea|diag_ping)(&|$)
RewriteRule ^wp-admin/admin-ajax\.php$ - [E=noabort:1,E=noconntimeout:1]
RewriteRule ^wp-cron\.php$ - [E=noabort:1,E=noconntimeout:1]
</IfModule>
# END ASDE tareas en segundo plano
```

3. Vuelve a pasar el diagnóstico.

**Por qué así:** LiteSpeed desaconseja aplicar `noabort` a todas las peticiones, porque puede dejar procesos colgados. La regla se limita a:

- las peticiones de las tareas del área privada y del diagnóstico. Por eso la tarea lleva `action=asde_ap_tarea` **en la URL**; el token va en el cuerpo y no queda en los registros;
- `wp-cron.php`, que también arregla WP-Cron para el resto de plugins.

## Si falla WP-Cron

- Crea un **cron del servidor** que llame a `https://baleares.scout.es/wp-cron.php` cada 5 minutos (Hostinger: **hPanel → Avanzado → Cron jobs**).
- Opcionalmente, añade `define( 'DISABLE_WP_CRON', true );` a `wp-config.php`, para que no dependa de las visitas.

## En Local (2026-09-27)

Todo ✅ en 7 s:

- petición interna, 330–500 ms;
- 6 s completos tras la desconexión (nginx no corta, y PHP sigue con `ignore_user_abort`);
- WP-Cron, a los 2 s.

Se probó de las dos maneras: dentro del plugin, y el zip instalado como plugin independiente, desinstalado después sin dejar rastro.

## App móvil (PWA): comprobar el servidor (v0.17.3)

La app móvil usa tres direcciones virtuales en la raíz del sitio (`/asde-manifest.webmanifest`, `/asde-sw.js` y `/asde-sin-conexion`) que **no pueden estar en ninguna caché** (LiteSpeed Cache, CDN, proxy), ni minificadas. En *Grupos Scouts → App móvil → «Comprobar el servidor»* el sitio se pide a sí mismo las tres y dice una por una si responden 200 con el tipo y el `Cache-Control: no-cache` correctos, si responde alguna caché y si el contenido que llega es idéntico al que genera el plugin. Es lo mismo que el apartado 6–7 del manual de publicación (`pwa/MANUAL-PUBLICACION.md`) hacía con `curl`. Con muy pocos procesos PHP (Local) la petición puede agotar el tiempo: se repite una vez.
