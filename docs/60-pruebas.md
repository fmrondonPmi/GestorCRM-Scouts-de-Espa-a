# 60 · Revisión de las pruebas automáticas (2026-10-23/24)

Revisión de las pruebas de `asde-area-privada/tests/` sobre la rama `Revision-Codigo` (después de las correcciones
del doc 59). Objetivo: que cada prueba compruebe lo que dice, no dependa de lo que haya en el Local, **no toque el CRM
real ni datos reales** y deje el sitio exactamente como estaba. Solo se han cambiado pruebas y herramientas de prueba:
**ningún cambio en el código del plugin**.

## Cómo se ha revisado

- **Línea base**: `probar-todo.sh` entero (todas las pruebas de los dos plugins).
- **Huella del sitio antes y después de cada prueba** (skill de usuario `pruebas-area-privada`,
  `scripts/con-huella.sh` + `scripts/huella.php`): md5 de cada opción `asde_ap_*`, transients del plugin en la base
  de datos, nº de filas y md5 de cada tabla `asde_*` y los usuarios con sus roles. Si una prueba deja algo distinto,
  sale «DEJA CAMBIOS» con el diff.
- **Pruebas que mueren sin decirlo**: `probar-todo.sh` cuenta las líneas `OK`/`FAIL`; una prueba que muere a medias
  (error fatal sin capturar) sale con `FAIL=0`. El resumen de la skill (`scripts/resumen.sh`) marca **MUERE** a toda
  prueba cuya última línea no es `TODO OK`.
- **Inventario** de clases y métodos públicos de `src/` sin ninguna prueba que los nombre, módulo a módulo.
- **Búsquedas** de patrones dudosos: aserciones con OR que se cumplen siempre o `$ok( true, … )`, pruebas sin
  `finally` o sin `catch`, sin cachés en memoria, sin comprobación de restos, que borran «todo lo posterior al inicio»
  y que usan el CRM antes de activar el simulado.

## Línea base (2026-10-23)

**3.499 OK y 1 FAIL** en 70 pruebas (66 de este plugin + 4 de la app). Detrás de ese número había más:

| Prueba | Qué pasaba |
|---|---|
| `tienda-proveedor` | FAIL «la pestaña lo enseña recibido, sin botón». **No era un fallo del código**: el volcado del manual (`herramientas-desarrollo/manual-capturas.php`) dejaba en el grupo 684 del Local pedidos al proveedor «Camisas de otoño» sin recibir (no restauraba `asde_tienda_proveedor`) y la pestaña enseñaba su botón «Marcar como recibido». |
| `mejoras-v029` | **Moría en silencio** en la parte F (salía con 58 OK y 0 FAIL, sin línea final): llamaba a `Tienda\Servicio::servir/devolver/asignar/precio_camisa`, que no existen desde la v0.44. Esa parte no se ejecutaba desde entonces. |
| `alta-reglas`, `grupos-emails-banco`, `fichas-escritura` | **Leían el CRM REAL** del grupo 684 y trabajaban con fichas reales: `alta-reglas` creaba cuentas de personas reales y les mandaba la invitación (a Mailpit), `grupos-emails-banco` verificaba el 684 contra el CRM real y mandaba una invitación a la primera ficha real con email, `fichas-escritura` elegía fichas reales y, si en el Local no había fichas sin número, con formato antiguo o repetidas, se saltaba esas partes con un `$ok( true, … )`. Además, `alta-reglas` y `grupos-emails-banco` cambiaban la opción de grupos sin `finally`. |
| `mejoras-v029`, `mejoras-v030` | Guardaban `Tienda\Config` (`asde_ap_tienda_<grupo>`) y restauraban la antigua `asde_ap_uniformes_<grupo>`: **cada batería dejaba la configuración de la tienda del 684 con los valores por defecto**. |
| `eventos-intendente`, `menus-evento`, `menus-validacion` | Guardaban `Eventos\Config::defaults()` en `asde_ap_eventos_<grupo>` y no lo devolvían. |
| `alta-grupo-primer-usuario` | Borraba **todos** los usuarios, registros y emails posteriores a su inicio, borraba los grupos 904-907 sin comprobar que no existieran y dejaba en `asde_socios` las fichas 990061 y 990063. |
| `alta-grupo-aprobacion` | Su «un grupo sin esos datos queda como siempre» era un OR que se cumplía siempre; borraba los grupos 901-903 sin comprobarlo antes. |
| `acceso`, `identidad`, `solo-lectura`, `tienda-catalogo`, `alta-grupo-invitacion` | La huella: borraban la instantánea o la caché de numeración reales del 684, dejaban transients en la base de datos o filas en el registro; `alta-grupo-invitacion` borraba los contadores de intentos de todas las personas. `tienda-catalogo` tenía «un producto de otro grupo no se ve (si lo hubiera)», que no comprobaba nada sin datos de otro grupo en el Local. |

Restos encontrados y borrados en el Local (todos de pruebas o del volcado del manual, mirados antes en sus ficheros):
4 pedidos al proveedor «Camisas de otoño» del 684, 1 producto del grupo 999, 151 filas del registro de emails de
usuarios de prueba (`prueba-uni-*`, `prueba-tp-fam`), 14 filas de `asde_socios` de fichas inventadas (9900xx) y 106
filas del registro de auditoría de usuarios de prueba ya borrados. **No se tocaron** los cinco números `99xxx` de 5
cifras de `asde_socios` (son reales) ni `prueba-secretaria`. Queda una fila del registro de emails del 2026-09-30
(«solicitud 46», rechazo) con un `example.invalid` en sus datos: no se sabe si es de una prueba y no se ha borrado.

## Cambios por módulo

- **Tienda**: `tienda-proveedor` trabaja en un grupo ficticio (996, solo en memoria) y acota la aserción a su
  pedido; `tienda-catalogo` crea el producto de otro grupo que antes buscaba; `mejoras-v029` y `v030` restauran la
  opción buena; `mejoras-v029` pierde la parte F muerta (lo de ahora lo prueban `tienda-inventario` y
  `migracion-tienda`). Arreglado también `manual-capturas.php` (fuera del repo): restaura `asde_tienda_proveedor`.
- **Usuarios y acceso**: `alta-reglas` rehecha con fichas inventadas en el grupo 996 (alta, cuentas familiares,
  mayoría de edad, informes, acceso con 2FA, visibilidad), sin Mailpit; `acceso` con cachés en memoria;
  `solo-lectura` borra su registro. Nueva `activar-cuenta`.
- **Fichas y numeración**: `fichas-escritura` con 16 fichas inventadas que cubren todos los estados de la numeración
  (sin número, formato antiguo, repetidos); las partes que antes se omitían ahora fallan si falta el caso;
  `identidad` deja en paz la caché real de numeración.
- **Crm**: `revision-codigo` parte F (RC-CRM-2, con dos grupos ficticios); el CRM simulado entiende `<`, `<=`, `>`
  y `>=` (el filtro de edad de los informes devolvía siempre vacío).
- **Domain / alta de grupos**: `alta-grupo-*` solo borran lo suyo y comprueban antes que sus grupos no existen;
  `grupos-emails-banco` verifica contra el CRM simulado y lo restaura todo en un `finally`. Nueva `admin-grupos`.
- **Eventos y Menús**: `eventos-intendente`, `menus-evento` y `menus-validacion` restauran la configuración de
  eventos; `eventos-avisos-config` termina con «TODO OK».
- **Install**: `entorno` en `try`/`finally` (devuelve los ajustes de WordPress y las páginas aunque se pare).
- **Tesorería**: nueva `tesoreria-cuotas`.
- **Auth**: nueva `enlaces-publicos` (límite de peticiones y claves de los enlaces públicos).
- **Todas**: `catch ( Throwable )` en las 49 pruebas con `try` que no lo tenían (una excepción ya cuenta como FAIL).

## Pruebas nuevas

| Prueba | OK | Qué cubre |
|---|---|---|
| `activar-cuenta` | 36 | `Usuarios\Invitacion` y `ActivarCuentaView`: en la base de datos solo el HMAC del token, token de otro usuario, mal formado o caducado, la página sin enlace o con sesión, errores del formulario (nonce, longitud, repetición, nº de socio dentro), activación de un solo uso y recuperación de una cuenta desactivada (sigue desactivada). |
| `tesoreria-cuotas` | 26 | `CuotasView`, `FederacionView` y `Tesoreria\Config::guardar` (permisos, nonce, importes con coma, negativos, secciones que no existen, plazos, aviso del 100 %, descuentos y topes) y aislamiento entre grupos. |
| `enlaces-publicos` | 23 | `Auth\Limite` (por acción, por IP, ventana) y `Domain\ClaveDeGrupo` en los tres enlaces públicos (no valen en otro grupo ni en otro enlace; renovar solo invalida la suya). |
| `admin-grupos` | 21 | `Admin\SettingsPage`: solo `manage_options` y con nonce, alta verificada contra el CRM simulado, contraseña cifrada que nunca se pinta, editar sin cambiarla, «Probar» con el CRM caído, baja. |
| `revision-codigo` (parte F) | +7 | RC-CRM-2: `Instantanea::refrescar_en_segundo_plano()` deja el grupo que hubiera forzado (también con el CRM caído) y solo escribe la instantánea del grupo refrescado. |

## Hallazgos de código (no corregidos)

- `src/Frontend/Views/CuotasView.php:44` — `handle_post()` pasa siempre `cuotas`, `secciones_pagan` y `plazos`
  a `Config::guardar()` (con `?? array()`): si una petición no trae las cuotas (otro formulario o una petición
  incompleta), se guardan a 0 y sin secciones que pagan. El formulario actual las manda siempre, así que no se nota;
  sería más robusto no pasar las claves que no vienen.
- `src/Tesoreria/Config.php:220` y `src/Domain/Organizacion.php:315` — `min()`/`max()` devuelven enteros cuando el
  tope gana (100, 99999): ese porcentaje o importe se guarda como `int` y los demás como `float`. Sin efecto visible.
- `src/Domain/Grupos.php:109-111` (`normalizar_numero`) — quita todo lo que no es cifra: «99a9» se da de alta como
  el grupo 999. Pensado para «58» o « 684 »; quizá conviene rechazar letras en la pantalla de alta de wp-admin.
- `src/Admin/SettingsPage.php:86, 101, 111, 113` — los avisos de wp-admin («Grupo %s dado de alta.», «Ese grupo no
  existe.»…) no pasan por `__()`, a diferencia del resto de wp-admin (regla de idiomas de `CLAUDE.md`).

## Huecos que quedan

- **wp-admin**: `EntornoPage`, `GruposInvitacionesPage` y las demás pantallas de ajustes solo se prueban de forma
  indirecta (se pintan en `entorno`, `oficina-pantallas`…); sus `handle_*` no tienen prueba propia.
- **`SeguridadView`** (verificación en dos pasos): depende de Wordfence; `alta-reglas` prueba el acceso con 2FA, no
  la pantalla.
- **Limpiezas por `id >` en el registro de emails**: `altas-crm-pantallas`, `eventos-calendario`,
  `eventos-intendente`, `inventario`, `lista-espera`, `menus-validacion`, `reintegros`, `tienda-fase5`,
  `tienda-pedidos` y `tienda-publica` borran todas las filas de `asde_emails` posteriores a su inicio. En el Local,
  sin uso real a la vez, no se nota; habría que filtrar por sus usuarios o por destinos `.invalid`.
- **Pruebas lentas**: las que simulan un bloqueo desde otra conexión esperan los segundos del `GET_LOCK` en cada
  caso (`sepa`, `eventos-inscripciones`, `numeracion-bloqueos`); no se ha cambiado para no tocar el código.
- **Cinco pruebas siguen sin `try`**: `estilo-wordpress`, `ficha-alergenos` y `mejoras-v031` solo leen;
  `organizacion` y `presupuestos-reparto` restauran en un `register_shutdown_function`. Una excepción las mata sin
  línea final (el resumen de la skill lo marca como MUERE). `entorno`, que cambiaba ajustes de WordPress (registro
  público, zona horaria), mandaba una página a la papelera y cambiaba otra sin `finally`, ya va en `try`/`finally`.
- La batería con huella (`con-huella.sh`) tarda ≈ 1 h; solo cubre las pruebas del plugin principal.
- RC-DEC-1/2/3 del doc 59 esperan la decisión del usuario: sin pruebas hasta que se hagan.

## Resultado final (2026-10-24)

**3.634 OK y 0 FAIL en 74 pruebas** (70 de este plugin con 3.323 + 4 de la app con 311), con la huella del sitio
antes y después de cada prueba del plugin principal: **ninguna deja nada cambiado**. `estilo-wordpress` (14) e
`idiomas-catalogos` (26), sin fallos. Ninguna prueba sale ya sin su línea final.

| Prueba | Línea base | Ahora |
|---|---|---|
| `tienda-proveedor` | 30 OK, 1 FAIL | 32 OK |
| `revision-codigo` | 24 | 31 (parte F, RC-CRM-2) |
| `alta-reglas` | 74 (CRM real) | 82 (CRM simulado) |
| `fichas-escritura` | 81 (CRM real) | 85 (CRM simulado) |
| `grupos-emails-banco` | 65 (CRM real) | 68 (CRM simulado) |
| `mejoras-v029` | 58 (moría en la parte F) | 59 |
| `alta-grupo-aprobacion`, `alta-grupo-primer-usuario`, `solo-lectura`, `tienda-catalogo` | 22, 17, 17, 67 | 23, 18, 18, 68 |
| `activar-cuenta`, `tesoreria-cuotas`, `enlaces-publicos`, `admin-grupos` | — | 36, 26, 23, 21 (nuevas) |

## Skill de usuario `pruebas-area-privada`

Creada en esta revisión (`C:\Users\fmron\.claude\skills\pruebas-area-privada\`): esqueleto de prueba nueva
(`plantilla-prueba.php`), reglas de aislamiento y restauración, recetas (bloqueo desde otra conexión, petición que se
adelanta, grupo ficticio, formulario a una vista) y cuatro scripts: `resumen.sh` (OK/FAIL por prueba, MUERE y cambios
respecto de otra ejecución), `con-huella.sh` + `huella.php` (¿deja el sitio como estaba?) y `restos.php` (restos de
pruebas cortadas, solo lectura).
