# CLAUDE.md · Plugin «ASDE Scouts de España – Gestor CRM para Grupos Scouts»

Plugin de WordPress (`asde-area-privada/`, PHP ≥ 8.0, namespace `AsdeBalears\AreaPrivada`) que da a los Grupos Scouts un **área privada** (fichas, usuarios, informes, tesorería, uniformes, SEPA…) con datos leídos de **SinergiaCRM**. Tema: Enfold. Idioma de todo (código visible, docs, mensajes, commits): **español**.

## Dónde está todo

- **Repositorio git** (**privado** en GitHub): `C:\Proyectos\GestorCRM` (remoto `fmrondonPmi/GestorCRM-Scouts-de-Espa-a`, rama `main`). Desde el 2026-10-02 el **plugin, el manual, los docs y este `CLAUDE.md` viven ahí** (se movieron desde la carpeta antigua): `asde-area-privada/` (`src/`, `templates/`, `assets/`, `tests/`, `herramientas/`), `manual/`, `docs/` (+ `README.md`, `LICENSE` GPL-2.0, `.gitignore`, `.gitattributes`). **Todo lo que se cambie del plugin, del manual, de los docs o de este fichero se hace en esa carpeta** y se commitea ahí (commits en español; `git push` solo cuando el usuario lo pida: ya lo pidió para los dos primeros commits y para incluir este fichero). Aunque es privado, **nada de datos reales, credenciales ni zips** (`.gitignore` excluye `*.zip`; en los docs el usuario de la API del CRM y los emails de grupo van como `@example.invalid`). `herramientas-desarrollo/` y los zips **no** están en el repo (decisión del usuario); el WordPress del sitio irá a otro repo.
- Carpeta antigua del proyecto, con lo que **no** va al repo: `C:\Claude\Scouts De Balears  - Area Privada` (**dos espacios** en el nombre: siempre entre comillas). Allí queda un `CLAUDE.md` mínimo que apunta a este.
  - Los scripts de `herramientas-desarrollo/` buscan el repo en `C:\Proyectos\GestorCRM` (variable `ASDE_REPO` para cambiarlo); ya **no** existen allí `asde-area-privada/`, `manual/` ni `docs/`.
  - `docs/00–27` (en el repo, `C:\Proyectos\GestorCRM\docs`) → base de conocimiento. **`docs/00-INDICE.md` es la puerta de entrada** (estado de la versión y tabla de documentos). `docs/pwa/` → trabajo de la PWA (leer `README.md` y `ESTADO.md` de ahí antes de tocarla).
  - `herramientas-desarrollo/` (**solo en la carpeta antigua**: ruta `C:\Claude\Scouts De Balears  - Area Privada\herramientas-desarrollo`; los comandos de abajo con ese nombre se lanzan desde allí) → scripts de desarrollo (no van en el zip ni en el repo): `probar-todo.sh`, `wp-local.sh`, `construir-zip.ps1`, `vistas-movil*.{php,sh}`, `rendimiento-*.php`, y las del manual de uso (`manual-capturas.sh` + `manual-capturas.php`, `manual-envolver.php`, `manual-recortar.php` y `manual-recursos/`; doc 21; el manual está en `manual/`).
  - `manual/` (**en el repo**, `C:\Proyectos\GestorCRM\manual`) → **manual de uso en HTML** (`index.html` + `capturas/`, con datos inventados; versión actual: v0.14.1; no va en el zip). Se regenera con `herramientas-desarrollo/manual-capturas.sh` (doc 21). El publicado en baleares.scout.es/temporal lo sube el usuario: **Claude no publica en producción**.
  - `asde-area-privada-<versión>.zip` → un paquete por versión (los anteriores se conservan). `local-test-credentials.md` → usuario de prueba **solo del Local**.
- WordPress de desarrollo (Local): `C:\Users\fmron\Local Sites\scoutsdebaleares\app\public` → http://localhost:10011. El plugin se **despliega copiando** `asde-area-privada/` (del repo) a `wp-content/plugins/asde-area-privada/`. Producción: https://baleares.scout.es (solo la toca el usuario; el zip se sube por *Plugins → Subir plugin*).

## Arquitectura en cinco líneas

- `Frontend/Views/*View` (extienden `View`) → un shortcode cada una, registrado en `Router::VIEWS`; las páginas se crean en `Pages::DEFINICIONES`. Plantillas en `templates/` (`TemplateLoader`, sobreescribibles desde el tema). Las acciones POST entran por `handle_post()` desde `Router::template_redirect`.
- `Crm/` → cliente SinergiaCRM v4_1 (`SinergiaV41Client`), `PersonaRepository` (lectura de fichas), `Escritura` (**única puerta de escritura** en el CRM), `Instantanea` (caché cifrada de fichas), `Crypto` (libsodium), `Permisos`, `Metadatos`.
- `Domain/` → `Grupo(s)` (cada grupo con sus credenciales; **sin visibilidad entre grupos**), `Persona`, `NumeroSocio`, `Identidad` (qué número es el usuario: nº de Scouts de España o territorial), `Organizacion`, `Secciones`.
- `Usuarios/`, `Auth/`, `Socios/` (numeración), `Fichas/`, `Informes/`, `Tesoreria/`, `Uniformes/`, `Sepa/`, `Pwa/` (de otra sesión) → el resto de módulos.
- Datos propios: tablas `{prefix}asde_*` y opciones `asde_ap_*`. **En WordPress no se guardan datos personales en claro**: lo que haga falta se guarda cifrado (`Crypto`) y caduca.

## Reglas que no se discuten

1. **Datos personales y salud** (doc 08): la salud y las dietas **no se guardan nunca** (se piden al CRM en cada consulta); lo demás, solo cifrado y por poco tiempo (`Instantanea`, solicitudes, emails de cuentas). Nada de datos reales en tests, docs ni capturas.
2. **Nunca se prueba en producción ni se escribe en el CRM real.** Las pruebas usan el CRM simulado (`tests/lib/CrmSimulado.php`). Para medir el CRM real (solo lecturas) hay scripts específicos que no imprimen datos.
3. **Un grupo no ve a otro**: toda lectura filtra por el grupo de seguridad del CRM (`PersonaRepository::filtrar_grupo`) y las cachés van por nº de grupo.
4. **Seguridad en cada acción**: `current_user_can` con la capacidad `asde_*` que toque, nonce (`check_admin_referer`/`wp_verify_nonce`) en todo lo que cambia estado (también en GET: nada de `?accion=1` sin firmar), salida escapada (`esc_html`, `esc_attr`, `esc_url`), SQL con `$wpdb->prepare`. Los mensajes de error de acceso no revelan si el usuario existe.
5. **No se rompe lo existente al actualizar**: lo nuevo que cambia comportamiento nace apagado o con el valor de siempre para las instalaciones anteriores (p. ej. `Organizacion::identificador()` = `local` si no está guardado).
6. **Quien actúa sobre algo ve lo que pasa**: avisos con `Notices`, auditoría con `Logger::log` (nombres de campos, nunca valores).

## Cómo se trabaja una versión

1. **Entender antes de tocar**: `docs/00-INDICE.md` → el doc del módulo. Si el cambio es grande, escribir antes el plan en un doc nuevo (`docs/NN-…md`) y empezar después.
2. **Código**: seguir el estilo de al lado (WordPress Coding Standards, tabs, `array()` largo, comentarios en español que explican el *por qué*). Archivos nuevos en `src/` con autoload por namespace; nada de `require` salvo los `tests/lib`.
3. **Pruebas** (obligatorias, una por cambio): `tests/<tema>.php` con el CRM simulado; cada prueba **restaura** todo lo que toca (opciones, tablas, usuarios, cachés, `asde_socios`) en un `finally`. Se escriben aserciones que puedan fallar (nada de `|| true` ni de ORs que siempre se cumplen).
   - Lanzar todo: `herramientas-desarrollo/probar-todo.sh` (despliega en Local y corre cada fichero; deben dar **«TODO OK»**). Una sola: `herramientas-desarrollo/probar-todo.sh identidad`.
   - La instantánea de fichas está **desactivada en las pruebas** (solo `rendimiento.php` la activa) y el usuario de acceso corre en modo `local` salvo `identidad.php`.
4. **Revisión visual**: las pantallas se miran en un navegador con datos inventados (`herramientas-desarrollo/vistas-movil.php` vuelca el HTML; `vistas-movil-envolver.sh` le pone el CSS de Enfold; servidor estático `php -S`). En escritorio y a 390 px de ancho (el móvil se usa mucho: tablas en tarjetas, sin desbordes).
5. **Entrega**: subir `ASDE_AP_VERSION` y `Version:` en `asde-area-privada.php`; actualizar `docs/` (00, el del módulo, 10 con el recuento de pruebas, 09 si hay decisiones); generar el zip (ver abajo); dejar el resumen al usuario en español, con lo que tiene que decidir o hacer en producción.

### Zip
`construir-zip.ps1` (PowerShell, con la herramienta PowerShell: `& 'ruta\construir-zip.ps1' -Version 0.x.y`) crea `asde-area-privada-<versión>.zip` **sin `tests/`** y con rutas `/`. Si hay trabajo ajeno a medias dentro del plugin (p. ej. la PWA), empaquetar desde una **copia** sin esa parte y apuntar el script a ella con la variable `ASDE_ZIP_SRC`.

## Trampas del entorno (Windows + Local)

- **PHP no está en el PATH** de la shell: `herramientas-desarrollo/wp-local.sh` fija las variables de Local y llama a `wp`. Su carpeta `run/<id>` puede cambiar si Local se reinicia (ajustar el id en el script). Lanzar PHP suelto: añadir `…/lightning-services/php-8.5.1+1/bin/win64` al PATH.
- `php -l` sobre todo el plugin antes de desplegar (`find . -name '*.php'`).
- **Edición de ficheros**: la herramienta *Edit* exige haber *leído* el fichero y falla con bloques largos o con caracteres especiales; para sustituciones grandes usar un script `perl` (cuidado con `\` en los espacios de nombres y con la codificación: los ficheros son UTF-8 sin BOM) o `sed` de una línea. Revisar siempre que no se hayan comido las `\` de `AsdeBalears\AreaPrivada\…`.
- PowerShell: la herramienta bloquea comandos con `Remove-Item` y `powershell.exe -File` choca con la política de ejecución (ejecutar el `.ps1` con `&` desde la herramienta PowerShell).
- Los tests de `wp eval-file` se lanzan con la **ruta relativa a `app/public`** (`wp-content/plugins/…`), no con `--path`.
- Las cachés/transients de las pruebas van en memoria (`wp_using_ext_object_cache(true)`): la web real nunca ve la numeración, la sesión ni las fichas del CRM simulado (incidente del 2026-09-28, doc 10).
- Local atiende PHP con pocos procesos: una petición larga bloquea a las demás; lo masivo va en segundo plano (`TareaNumeracion`, WP-Cron).

## Convenciones de interfaz

- Mejora progresiva: **sin JavaScript todo funciona** (con recargas); el JS solo lo hace más fluido (listado de usuarios en memoria, ventana emergente, visor de imágenes, aviso de carga). Al usuario **no le gusta que se recargue la pantalla entera**: preferir cargar una vez y trabajar en memoria/AJAX.
- Formularios que descargan un fichero llevan `data-asde-sin-carga` (si no, la barra de carga se queda puesta).
- Móvil primero en lo nuevo: sin desbordes horizontales, tablas como tarjetas (`data-label`), pestañas en una fila que se desplaza.
- El CSS nuevo se **añade al final** de `assets/css/area-privada.css` en un bloque comentado con la versión.

## Rendimiento (doc 26)

Cada consulta al CRM cuesta 0,5–1,5 s (el tamaño casi no importa) y el resto del plugin, 10–70 ms: lo que más pesa es **cuántas consultas seguidas hace una página**. Por eso `PersonaRepository::todas/obtener/obtener_varias` se sirven de `Instantanea`; no añadir consultas por fila ni por página sin pasar por ahí. Búsquedas, salud e informes van siempre en vivo.

## Trabajo en paralelo con otra sesión

Otra sesión de Claude (la de la **PWA**; ahora también debe trabajar en `C:\Proyectos\GestorCRM\asde-area-privada`, no en la carpeta antigua) edita el mismo plugin: solo toca `src/Pwa/`, `assets/pwa/`, `tests/pwa.php`, `docs/pwa/` y dos líneas de `src/Plugin.php`. **Releer** `Plugin.php`, el CSS y los docs comunes antes de editarlos, no reescribirlos enteros y usar versiones distintas (la PWA saldrá como 0.13.0). No pisar ni empaquetar su trabajo a medias.

## Documentación: cuándo y dónde

- Cada versión deja constancia en `docs/00-INDICE.md` (bullet de estado + fila del doc nuevo si lo hay) y en el doc del módulo; las decisiones del usuario, en `docs/09-preguntas-abiertas.md`; el recuento y la lista de pruebas, en `docs/10-entorno-local.md`.
- Los supuestos que se hacen sin preguntar se **escriben** (en el doc y en el resumen final) para que el usuario los confirme.
