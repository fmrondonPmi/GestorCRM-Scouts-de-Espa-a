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
- **Copia desplegada:** `wp-content/plugins/asde-area-privada/`. Por ahora se copia a mano tras cada cambio; más adelante, repositorio Git.

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
- **Pruebas** (v0.16.0, **881 comprobaciones en 18 ficheros**, todas OK el 2026-10-03; las demás pruebas dejan la instantánea de fichas **desactivada**: solo `rendimiento.php` la activa). Se lanza cada una con `wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/<fichero>.php` (con la ruta relativa, WP-CLI la busca desde la carpeta en la que estás, no desde `--path`):
  - `alta-scouters.php` (31, v0.16): **enlace de alta para scouters** (clave del enlace y renovación, validaciones, cuenta al momento con el email de la ficha, revisión sin email o sin sección, DNI que no vale, respuesta idéntica, límite de intentos, lo que ve cada rol y «Dar acceso» con lo pedido; doc 31);
  - `idiomas.php` (57, v0.15): **idiomas y etiquetas** (idioma por defecto, elección de cada persona, cookie y cambio con nonce, prioridad, traducción y personalizadas, marcadores, HTML permitido, emails en el idioma de quien los recibe, pantalla de wp-admin, exportar e importar; doc 28). Usa catálogos de prueba inyectados por filtro, no los de `languages/`;
  - `idiomas-catalogos.php` (26, v0.15): **los catálogos del plugin**: `languages/es.php` al día con el código, catalán, euskera y gallego al 100 % con los mismos marcadores y etiquetas, textos de los JS por `asdeT()` y pantallas que salen traducidas. No toca nada del sitio;
  - `menus.php` (23, v0.15): **menús por tipo de usuario** (familia, participante, scouter, Coordinación, scouter que además es tutor, administrador sin ficha) y cómo se pintan;
  - `colores.php` (32, v0.15): **colores** (derivados, contraste, solo se guarda lo cambiado, CSS emitido, pantalla, vista previa, emails);
  - `tema.php` (15, v0.15): **independencia del tema** (Enfold y otro tema simulado, ajustes de página, cabecera plegable, sugerencia en *Instalación y entorno*);
  - `sepa.php` (88, v0.12): **domiciliación SEPA** (IBAN, texto SEPA, ICS, fechas, quién firma, firma en «Mis pagos», remesas, fichero XML `pain.008`, aviso previo, cobros y devoluciones, purga y pantalla de Tesorería; doc 25). Con el CRM simulado, una ronda ficticia (2098-2099) y un acreedor ficticio, y **restaura** la configuración SEPA y la de Tesorería. Con la variable de entorno `ASDE_VOLCAR=<carpeta>` guarda el HTML de las pantallas para revisar el diseño en un navegador (así se revisaron el 2026-09-30, con un servidor estático de PHP; no se toca el WordPress en uso);
  - `usuarios-listado.php` (47, v0.12.2): **listado de usuarios** (doc 03 y 06): los usuarios del grupo (con roles, con invitación pendiente y desactivados; ninguno de otro grupo), los nombres de las personas (con el CRM caído avisa), las filas con los datos para filtrar, la lista y las acciones **en JSON** (reenviar, desactivar, errores, sin permiso, sin JavaScript), el tamaño de página que se recuerda y el paso por el **Router** real. El navegador (filtros, orden, paginación, acciones sin recargar) se revisó a mano con datos inventados (78 filas) y un `fetch` simulado. Con el CRM simulado y las cachés en memoria;
  - `identidad.php` (59, v0.14-v0.14.1): **usuario de acceso configurable** (parámetro, instalación anterior sin cambios, nº de 4–12 cifras, alta con el nº de Scouts de España aunque el territorial tenga problemas, familias T/T2, acceso con los dos números, advertencias y «Mostrar nº territorial», renombrar las cuentas existentes y la ventana de configuración; doc 27). Con el CRM simulado; restaura la organización y la tabla de números;
  - `rendimiento.php` (47, v0.12.3-v0.14.1; también el cerrojo, el índice y el límite de lecturas forzadas): **instantánea cifrada de las fichas** (no llama al CRM al leer, da lo mismo que el CRM en vivo, cifrada y sin salud, fresca/antigua/caducada con refresco en segundo plano, fichas nuevas, guardar desde la web, desactivarla, CRM caído), la comprobación de acceso de cada página sin consultas y la **sesión del CRM que se renueva** (doc 26). Con el CRM simulado y las cachés en memoria;
  - `uniformes.php` (60, v0.11-v0.12.5): pedidos de uniforme (precios, tallas, insignias por confirmar, quién pide y quién gestiona, pago, pedido en lote, entrega, recibido, cancelar, configuración) y «Mis pagos» (doc 24). Con el CRM simulado y las cachés en memoria;
  - `solicitudes.php` (86, v0.10-v0.12.4): solicitudes de acceso (comprobación, formulario público, quién ve qué, aprobar con el email al CRM, rechazar **con aviso por email**), **dos tutores con cuentas separadas (`nº + T` y `nº + T2`)**, email de las cuentas guardado cifrado, ventana emergente y pestañas (docs 22, 15 y 23). Usa el **CRM simulado** de `tests/lib/CrmSimulado.php`: ninguna petición llega al CRM real (hay además una red que corta cualquier petición a un CRM que el simulador no responda, y la prueba comprueba que no ha salido ninguna);
  - `acceso.php` (41, v0.9-v0.10): entrar con el nº de socio/a o con el email (guardado cifrado o, en cuentas antiguas, buscado en el CRM), varias cuentas con el mismo email, límites y recuperar contraseña con el email. No consulta el CRM (fichas simuladas, doc 15);
  - `organizacion.php` (28, v0.9): organización federada, código del nº de socio, migración, WordPress nuevo, coste federativo con otros conceptos (doc 20);
  - `entorno.php` (19): «Instalación y entorno» sobre el Local ya configurado. No cambia nada si todo está bien, repara páginas y aplica los ajustes del sitio solo si se marcan y hacían falta; la hora no la toca. Deja el sitio como estaba; no toca el menú ni el `.htaccess`.
  - `alta-reglas.php` (73): números de socio, Tesorería, alta, cuentas familiares, ámbito, mayoría de edad, informes, recuperación, acceso con grupo, doble factor, límite de intentos, varios grupos y visibilidad;
  - `grupos-emails-banco.php` (63; incluye que una importación que falla se deshace entera): grupos dados de alta a mano, registro de emails, orden y paginación, Norma 43, CSV, importación y conciliación (grupo ficticio 999);
  - `fichas-escritura.php` (81): escritura en el CRM, permisos de la ficha por rol (también campos ocultos a menores y el cargo solo con Scouter o Voluntariado), validación (régimen de alimentación), cola de cambios, numeración con escritura y por tandas, voluntario sin sección, **corrección en segundo plano** y el **diagnóstico del hosting** (casos de LiteSpeed simulados, doc 18). **Nunca escribe en el CRM real**: usa un CRM simulado, y sus tareas en segundo plano no salen del proceso de la prueba (ni petición interna ni WP-Cron) y van marcadas para que una petición normal no las ejecute nunca. Desde el 2026-09-28 el CRM real ya no tiene números en formato antiguo, así que la corrección en segundo plano (parte G) trabaja con 4 fichas inventadas en el CRM simulado de `tests/lib`, y la limpieza se hace aunque la prueba falle a medias (`try/finally`).
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
- **Para instalarlo en otro WordPress:** `asde-area-privada-0.12.1.zip`, en la carpeta del proyecto (la 0.12.1 arregla la vista en móvil, doc 06; la 0.12.0 trae la domiciliación SEPA, los dos tutores por familia y el aviso al rechazar una solicitud; los zips anteriores se conservan). Al actualizar se crean solas las dos tablas nuevas y la página «Domiciliaciones SEPA». Se sube con *Plugins → Añadir nuevo → Subir plugin* y no lleva la carpeta `tests/`. Rutas con `/`, para que se descomprima bien en Linux. Después, *Grupos Scouts → Instalación y entorno* (doc 19).
- **Probar en un WordPress nuevo sin descargar nada** (así se probó la v0.8.0):
  1. copiar los ficheros de WordPress de Local a una carpeta temporal;
  2. crear una base de datos vacía en el MySQL de Local (puerto 10010) con `mysqli` de PHP: el cliente `mysql` del PATH es antiguo y MySQL 8.4 lo rechaza;
  3. `wp config create`, `wp core install` y `wp server --config=<php.ini sin auto_prepend_file>`: el `local-bootstrap.php` de Local, cargado dos veces por el servidor de PHP, da un error fatal en wp-admin;
  4. al acabar, borrar la base de datos y la carpeta.

- **Lanzar todas las pruebas** (desde v0.14.1): `herramientas-desarrollo/probar-todo.sh` despliega el plugin en Local y corre cada `tests/*.php` (menos `pwa.php`, que se lanza aparte: `probar-todo.sh pwa`; **108 comprobaciones** en la v0.17.0, doc `pwa/`); acepta nombres para lanzar solo algunas. Las trampas del entorno y las reglas de trabajo están en el `CLAUDE.md` de la raíz.
