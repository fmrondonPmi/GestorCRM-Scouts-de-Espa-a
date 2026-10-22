# 10 · Entorno local (LocalWP)

Montado el 2026-09-26 a partir de la exportación de Hostinger `C:\tmp\scoutsdebaleares-20260926060922.zip` (WP Migrate 2.7.11).

## Datos del site

| | |
|---|---|
| Site en Local | `scoutsdebaleares` → `C:\Users\fmron\Local Sites\scoutsdebaleares` |
| **URL** | **http://localhost:10011** (admin: `/wp-admin`) |
| WordPress | 7.0.6, el mismo core que producción (copiado del zip) |
| PHP local | 8.5.1 (producción usa 8.3) |
| MySQL local | 8.4 (producción usa MariaDB 11.8), puerto 10010, `root`/`root`, base de datos `local` |
| Usuarios | Los de producción. Se entra con el usuario de siempre y, si lo tiene activo, con el 2FA de Wordfence |

## Por qué fallaba la importación en Local

1. El zip de Hostinger no tiene el formato que espera Local:
   - `database.sql` está en la raíz,
   - los archivos del core están bajo `files///home/u468020296/websites/KH7nOCVkH/public_html/` (rutas absolutas del servidor),
   - `wp-content` está en `files/wp-content/`.
2. El volcado es de **MariaDB** y contiene una collation que MySQL 8 no conoce: `utf8mb3_uca1400_ai_ci`, en la tabla de Wordfence.

## Qué se hizo

1. Se descomprimió el zip en `C:\tmp\scouts-extract\`.
2. Se creó `database-local.sql` sustituyendo `utf8mb3_uca1400_ai_ci` por `utf8mb3_general_ci` (y `utf8mb4_uca1400_ai_ci` por `utf8mb4_unicode_520_ci`).
3. Los archivos del site vacío de Local se apartaron en `app/_original-local/`, y se copiaron el core y `wp-content` de producción. Se conservó el `wp-config.php` de Local.
4. Los mu-plugins de Hostinger (`hostinger-h5g-plugin.php`, `hostinger-preview-domain.php`) se movieron a `app/_disabled-mu-plugins/`.
5. Se importó la base de datos: 65 tablas y 265 entradas.
6. Se hizo un `search-replace` de `https://baleares.scout.es` (también en sus variantes `http://`, JSON-escapada y `//`) a `http://localhost:10011`, excluyendo la columna `guid`.
7. Se desactivaron en local **LiteSpeed Cache** (no hay LiteSpeed en nginx) y **MainWP Child** (para que el panel de MainWP no gestione la copia).
8. Se instaló y activó el plugin `asde-area-privada`.

## Por qué `localhost:10011` y no `scoutsdebaleares.local`

Local no ha podido registrar el dominio `.local`:
- no hay entrada en `C:\Windows\System32\drivers\etc\hosts`,
- su router no escucha en el puerto 80.

Para arreglarlo hacen falta permisos de administrador. La alternativa es ir a *Local → Preferences → Advanced → Router mode* y elegir **localhost**. Mientras tanto se usa el puerto directo de nginx del site.

> ⚠️ Si Local reasigna el puerto (por ejemplo, porque el 10011 está ocupado al arrancar), la web dejará de cargar bien. Se arregla con un `search-replace` de `localhost:10011` al puerto nuevo.

## WP-CLI desde Git Bash

Script auxiliar con el entorno del site (equivale a "Open site shell" en Local):

```bash
#!/bin/bash
export MYSQL_HOME="C:/Users/fmron/AppData/Roaming/Local/run/c62jVSywS/conf/mysql"
export PHPRC="C:/Users/fmron/AppData/Roaming/Local/run/c62jVSywS/conf/php"
export WP_CLI_CONFIG_PATH="C:/Users/fmron/AppData/Local/Programs/Local/resources/extraResources/bin/wp-cli/config.yaml"
export PATH="/c/Users/fmron/AppData/Roaming/Local/lightning-services/mysql-8.4.0+2/bin/win64/bin:/c/Users/fmron/AppData/Roaming/Local/lightning-services/php-8.5.1+1/bin/win64:$PATH"
cd "/c/Users/fmron/Local Sites/scoutsdebaleares/app/public"
php -d display_startup_errors=0 "C:/Users/fmron/AppData/Local/Programs/Local/resources/extraResources/bin/wp-cli/wp-cli.phar" "$@"
```

`-d display_startup_errors=0` oculta el aviso inofensivo `Unable to load dynamic library php_imagick.dll`, que sale por la salida estándar y ensucia los resultados.

## Plugin en desarrollo

- **Código fuente:** carpeta `asde-area-privada/` de este proyecto.
- **Copia desplegada:** `wp-content/plugins/asde-area-privada/`. El código está en el repositorio git del proyecto; tras cada cambio se despliega con `probar-todo.sh` (o copiando la carpeta).

## Pendiente en local

- Crear el tema hijo de Enfold (P-17).
- Revisar si Enfold y los plugins dan avisos de "deprecated" con PHP 8.5. Si molestan, se cambia en Local a PHP 8.3, que es la versión de producción.

## Correo en local: Mailpit

- Local tiene **Mailpit**: web en http://localhost:10006, SMTP en el puerto 10007.
- Como protección extra hay un mu-plugin **solo local**, `wp-content/mu-plugins/local-mailpit.php`, que obliga a PHPMailer a usar Mailpit. Así ningún email de prueba llega a personas reales.
- ⚠️ **No subir a producción.**

## Usuario de prueba y pruebas automáticas

- **`prueba-secretaria`** (rol Secretaría, email ficticio). Sirve para ver el área privada como Secretaría sin usar una cuenta real.
  - Entra por **Acceso socios** (menú superior), con el grupo Myotragus. Desde v0.5.0 tiene `asde_grupo_id`; por `wp-login.php` ya no entra.
  - La contraseña está en `local-test-credentials.md`, en la raíz del proyecto.
  - No está vinculada a ninguna ficha del CRM, así que solo ve el inicio con el motivo.
  - Se borra con `wp user delete prueba-secretaria --reassign=1`.
- **Pruebas** (v0.51, 2026-10-22, **≈ 3150 comprobaciones en ≈ 60 ficheros** de `asde-area-privada/tests/`, una por tema: acceso, identidad, altas en el CRM, fichas, informes, tesorería, banco, SEPA, inventario, lista de espera, eventos, menús, tienda, reintegros, modo Oficina, idiomas, ayuda, estilo de WordPress…). Se lanzan con `herramientas-desarrollo/probar-todo.sh [prueba …]`, que despliega en Local y ejecuta cada una con `wp eval-file`; sin argumentos, todas (≈ 12 min: **no cortarla a medias**, deja datos de prueba). Hay una por cada módulo: al tocar un módulo, mira `tests/<modulo>.php`. Las demás pruebas dejan la instantánea de fichas **desactivada**; solo `rendimiento.php` la activa.
- **Cada prueba deja todo como estaba:**
  - solo borra de Mailpit sus propios emails;
  - restaura la tabla de números **solo en las fichas que tocó**;
  - borra sus usuarios y filas, y de la auditoría **solo lo suyo** (sus usuarios, o la consola en las acciones que hace la prueba). Desde el 2026-09-27 ya no borra «todo lo posterior al inicio», que podía llevarse acciones reales hechas a la vez desde la web.
- **Para desplegar el plugin tras cambiar el código:** copiar la carpeta `asde-area-privada/` a `wp-content/plugins/`. El instalador se ejecuta solo si cambia `ASDE_AP_VERSION`: roles, tabla de auditoría y páginas.
- **Local en Windows atiende PHP con solo 2 procesos** (`php-cgi`, puertos 10008 y 10009, repartidos por nginx). Una petición larga deja a la mitad de las demás esperando: por eso la pantalla parecía «congelada» durante la corrección masiva del 2026-09-27, y por eso las correcciones masivas son ahora tareas en segundo plano con tandas de 10 s (doc 17). **Reiniciar el sitio en Local mata las peticiones en curso** (eso cortó aquella corrección).
- **Lecciones del 2026-09-28 (cachés compartidas):**
  - Una prueba **en el navegador** con el CRM simulado se hizo en este mismo WordPress mientras el usuario lo estaba usando. Aunque el simulador solo actuaba en las peticiones con una cookie secreta, **las cachés, la tabla de números y las familias son de todo el sitio**.
  - Durante unos minutos, la caché de la numeración (`asde_ap_numeracion_684`, 10 minutos) tuvo solo fichas inventadas, y una ficha real salió «Sin número».
  - También quedaron números inventados en la tabla (habrían movido el siguiente nº libre), dos familias en Tesorería y las cachés de sesión y de permisos del CRM.
  - Se borró todo por identificador, se rehízo la numeración con el CRM real (382 fichas; siguiente nº libre, 76840400) y se reforzó el plugin: si la caché no tiene una ficha, `Numeracion::de` rehace el análisis en vez de decir «Sin número».
  - **Desde entonces:**
    - las pruebas en el navegador con datos inventados se hacen en un **WordPress aparte** (como la demostración del manual, doc 21), nunca en el que se usa;
    - las pruebas automáticas que usan el CRM simulado guardan sus cachés **solo en la memoria del proceso** (`wp_using_ext_object_cache( true )`) y restauran todo lo que tocan;
    - mientras corren (unos 30 s), la tabla de números tiene sus filas inventadas: mejor no asignar números en ese rato.
- **Probar en un WordPress nuevo sin descargar nada** (así se probó la v0.8.0):
  1. copiar los ficheros de WordPress de Local a una carpeta temporal;
  2. crear una base de datos vacía en el MySQL de Local (puerto 10010) con `mysqli` de PHP: el cliente `mysql` del PATH es antiguo y MySQL 8.4 lo rechaza;
  3. `wp config create`, `wp core install` y `wp server --config=<php.ini sin auto_prepend_file>`: el `local-bootstrap.php` de Local, cargado dos veces por el servidor de PHP, da un error fatal en wp-admin;
  4. al acabar, borrar la base de datos y la carpeta.
  - **Hecho script (2026-10-07, fase 4 del doc 34):** `herramientas-desarrollo/wp-aparte/montar.sh` (copia WordPress y Enfold del Local a `%TEMP%/asde-wp-aparte` o `ASDE_WP_APARTE`, base de datos `asde_fase4`, el plugin del repo, un mu-plugin que **corta toda petición que no vaya al servidor de la API simulado** (127.0.0.1:8790) y **guarda los emails en `wp-content/correos.log` en vez de enviarlos**, el grupo 999 apuntando al servidor simulado con las altas activadas y tres cuentas de prueba cuyas contraseñas van a `credenciales.txt` de esa carpeta) y `desmontar.sh` (borra la base de datos y la carpeta). Se entra por «Acceso socios» (los socios no pueden usar `wp-login.php`) con el usuario de la cuenta.
  - **Trampa:** con **opcache** activado, el servidor integrado de PHP dio un error fatal dentro de WordPress (`Requests\Cookie\Jar cannot implement CurlHandle`). `montar.sh` lo apaga en su `php.ini`.

- **Lanzar todas las pruebas** (desde v0.14.1): `herramientas-desarrollo/probar-todo.sh` despliega en Local **los dos plugins** (este y, desde la 0.18, la app móvil `asde-area-privada-app` del repo `C:\Proyectos\GestorCRM-App`, variable `ASDE_APP_REPO`) y corre los `tests/*.php` de los dos (menos `pwa.php` de la app, que se lanza aparte: `probar-todo.sh pwa`, 198). **v0.19.0: 986 OK en 21 ficheros de este plugin + 311 en 4 de la app = 1297, 0 fallos** (2026-10-05). Después de la v0.20, con el **cerrojo atómico de la instantánea** (doc 26; `rendimiento.php` pasa de 47 a 55): **1082 OK en 23 ficheros de este plugin + 311 en 4 de la app = 1393, 0 fallos** (2026-10-06). Antes, v0.18.0: 902 OK en 19 ficheros de este plugin + 307 en 4 de la app = 1209. Acepta nombres para lanzar solo algunas (busca el fichero en los dos plugins). Historia: con la app dentro del plugin, 1166 OK en 21 ficheros en la 0.17.5. **v0.19.1: 1015 OK en 21 ficheros de este plugin + 311 de la app = 1326, 0 fallos** (2026-10-05, con la importación del inventario: `inventario.php` 97). **v0.20.0: 1082 OK en 23 ficheros de este plugin + 311 de la app = 1393, 0 fallos** (2026-10-06). **v0.20.1: 1098 OK en 23 ficheros de este plugin + 311 de la app = 1409, 0 fallos** (2026-10-06; `banco-gestion.php` 60). **v0.21.0: 1132 OK en 24 ficheros de este plugin + 311 de la app = 1443, 0 fallos** (2026-10-07; nueva `altas-crm.php`, 34). **v0.22.0: 1171 OK en 25 ficheros de este plugin + 311 de la app = 1482, 0 fallos** (2026-10-07; nueva `altas-crm-escritura.php`, 39). **v0.23.0: 1218 OK en 26 ficheros de este plugin + 311 de la app = 1529, 0 fallos** (2026-10-07; nueva `altas-crm-pantallas.php`, 47).  **v0.23.1: 1226 OK en 26 ficheros de este plugin + 311 de la app = 1537, 0 fallos** (2026-10-07; `altas-crm.php` 35, `altas-crm-escritura.php` 40, `altas-crm-pantallas.php` 53).  **v0.24.0: 1231 OK en 26 ficheros de este plugin + 311 de la app = 1542, 0 fallos** (2026-10-07; `altas-crm.php` 40, con los documentos repetidos). **v0.25.0: 1262 OK en 26 ficheros de este plugin + 311 de la app = 1573, 0 fallos** (2026-10-08; `banco-gestion.php` 73, `sepa.php` 102, `usuarios-listado.php` 53). **v0.25.1: 1263 OK en 26 ficheros de este plugin + 311 de la app = 1574, 0 fallos** (2026-10-08; `usuarios-listado.php` 54). **v0.26.0: 1289 OK en 27 ficheros de este plugin + 311 de la app = 1600, 0 fallos** (2026-10-08; nueva `ayuda.php`, 26). **v0.26.1: 1297 OK en 27 ficheros de este plugin + 311 de la app = 1608, 0 fallos** (2026-10-08; `usuarios-listado.php` 61, `alta-reglas.php` 74). **v0.26.2: 1302 OK en 27 ficheros de este plugin + 311 de la app = 1613, 0 fallos** (2026-10-08; `banco-gestion.php` 74, `estilo-wordpress.php` 12; Plugin Check: 0 hallazgos). **v0.28.0: 1310 OK en 27 ficheros de este plugin + 311 de la app = 1621, 0 fallos** (2026-10-09; `ayuda.php` 34; Plugin Check: 0 hallazgos; la v0.27.0 se descartó). **v0.29.0: 1389 OK en 28 ficheros de este plugin + 311 de la app = 1700, 0 fallos** (2026-10-11, tras la revisión de código; nueva `mejoras-v029.php`, 78; `uniformes.php` 64; `inventario.php` 97 con el CSV de ejemplo de 4 filas; Plugin Check: 0 hallazgos). **v0.32.0: 1551 OK en 31 ficheros de este plugin + 311 de la app = 1862, 0 fallos** (2026-10-14, rama `Lista-Espera`; nueva `lista-espera.php`, 66; `modulos.php` 23 con los módulos apagados por defecto; `sepa.php` 102: su aserción del botón de imprimir seguía buscando `window.print`, que desde la v0.31 es `data-asde-imprimir-pagina`; Plugin Check: 0 hallazgos). **v0.33.0: 1563 OK en 31 ficheros de este plugin + 311 de la app = 1874, 0 fallos** (2026-10-14, rama `Sepa-Cuenta-Crm`; `sepa.php` 114 con la cuenta en la ficha del CRM, parte K nueva; Plugin Check: 0 hallazgos). **v0.33.1: 1565 OK en 31 ficheros de este plugin + 311 de la app = 1876, 0 fallos**, y `pwa.php` aparte 198 OK (2026-10-15, revisión con los plugins de Claude Code; `estilo-wordpress.php` 14 con la parte F; Plugin Check completo por WP-CLI: 0 hallazgos, tras acortar los «Upgrade Notice» de la 0.29 y la 0.30, que pasaban de 300 caracteres). **v0.33.2: 1567 OK en 31 ficheros + 311 de la app = 1878, 0 fallos** (2026-10-15, revisión con ponytail; `grupos-emails-banco.php` 65 con el envío que falla; Plugin Check completo: 0). **v0.34.0: 1594 OK en 32 ficheros + 311 de la app = 1905, 0 fallos** (2026-10-15; nueva `mejoras-v034.php` 19: «Socio/a desde», membresía activa por fechas y buscador de Familias; `banco-gestion.php` 80 con el «+» de los tipos; `altas-crm-escritura.php` 44 con la baja hecha en el CRM sin fecha; Plugin Check completo: 0). **v0.35.0: 1777 OK en 34 ficheros + 311 de la app = 2088, 0 fallos** (2026-10-15, rama `Eventos`, doc 43; nuevas `eventos.php` 92 y `eventos-inscripciones.php` 91, que vienen de la sesión de la nube y encienden el módulo «Eventos» para su grupo; `modulos.php` 24 con el módulo apagado por defecto y «Mis pagos» mientras haya eventos). **v0.35.1: 1808 OK en 35 ficheros + 311 de la app = 2119, 0 fallos** (2026-10-15; nueva `mejoras-v0351.php` 30; `menus.php` y `lista-espera.php` fijan ahora los módulos y «Altas en el CRM» del grupo y los dejan como estaban: con «Eventos» o las altas encendidos en el Local fallaban; `mejoras-v034.php` apaga la instantánea). **v0.36.0: 1880 OK en 36 ficheros + 311 de la app = 2191, 0 fallos** (2026-10-16, doc 43, «Tercera fase»; nueva `eventos-economia.php` 72: cuentas de los eventos, devoluciones, remesa SEPA del evento y cierre, con los justificantes de prueba borrados al acabar; `entorno.php` 19 con las 20 tablas). **v0.36.1: 1887 OK en 36 ficheros + 311 de la app = 2198** (2026-10-16; `eventos-economia.php` 79 con la parte «I bis», reabrir las cuentas; pasadas las afectadas —eventos, inscripciones, economía, sepa, idiomas, ayuda, estilo y entorno—: 469 OK, 0 fallos; el resto, sin cambios desde la 0.36.0). **v0.37.0: 1905 OK en 36 ficheros + 311 de la app = 2216, 0 fallos** (2026-10-16; `eventos-economia.php` 97 con la parte G0: pago de un evento desde «Registrar pago», historial de pagos y Excel con formato; `banco-gestion.php` 80 y `mejoras-v029.php` 79; Plugin Check rápido: 0). Las trampas del entorno y las reglas de trabajo están en el `CLAUDE.md` de la raíz.

- **Estilo de WordPress (v0.18.1):** `tests/estilo-wordpress.php` (14 comprobaciones desde la v0.33.1, con la parte F: textos de `asdeT()` en atributos HTML de los JS solo con `asdeEsc()`; solo lee ficheros) vigila lo que pide el revisor de plugins: textos traducidos escapados al imprimir, comentarios `translators:` y marcadores numerados, mensajes de excepción escapados y finales de línea sin mezclar; desde la **v0.26.2 (parte E, informe de Plugin Check)**: ninguna tabla concatenada en el SQL ni consultas montadas con `$sql .=`, la línea `phpcs:disable … PrefixAllGlobals` en las plantillas con variables propias y variables `$asde_` en `uninstall.php`. Suite del 2026-10-04: **1215 OK, 0 fallos** (principal + app). **Trampa del equipo:** con poca memoria libre en Windows, alguna prueba (distinta cada vez: `rendimiento`, `sepa`, `fichas-escritura`) puede morir con «Ha habido un error crítico» (en `logs/php/error.log`: *Out of memory (allocated 12 MB)*, *Unable to create file mapping*, *fork: Resource temporarily unavailable*); no es del código: **repetir esa prueba sola** (`probar-todo.sh sepa`) y, si pasa, está bien. Conviene cerrar programas pesados antes de lanzar la suite entera.
- **Qué se revisa con el revisor de plugins:** el zip, **no** la carpeta del Local. `probar-todo.sh` despliega en el Local también `tests/` y `herramientas/` (hacen falta para las pruebas) y el revisor se queja de ellos (`echo $label` en las pruebas, `localhost`/`*.local` en textos de prueba, `var_export` del generador de textos…); **ninguno entra en el zip** (`construir-zip.ps1` excluye `tests/` y, en la app, `herramientas/`). Para una revisión limpia, instalar el zip en un WordPress aparte.

- **Probar el modo Oficina (v0.50):** las pruebas `oficina`, `solo-lectura`, `oficina-pantallas`, `alta-grupo-invitacion`, `alta-grupo-aprobacion` y `alta-grupo-primer-usuario` (≈ 195 comprobaciones) no necesitan montar nada: ponen el tipo de instalación en «oficina» durante la prueba y lo restauran, usan el CRM simulado (`tests/lib/CrmSimulado.php`) o un cliente simulado por el filtro `asde_ap_alta_grupo_cliente`, capturan los emails y borran sus usuarios, grupos y filas. Para verlo a mano en un WordPress aparte: `define( 'ASDE_AP_MODO', 'oficina' );` en wp-config.php, un usuario con el rol «Oficina» y la credencial guardada en Organización federada. **Las pruebas con el CRM real las lanza el usuario.**
- **Trampa:** los números de socio inventados de las pruebas (990061…) quedan en la caché local de números (`asde_socios`); si dos pruebas usan el mismo nº con otro grupo, el formato del nº local sale mal. Cada prueba nueva debe usar números de ficha que no use otra.
