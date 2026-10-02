# 15 · Acceso socios, grupos y visibilidad (v0.5; grupos a mano desde la v0.6; acceso con email desde la v0.9; email guardado cifrado desde la v0.10)

## Petición del usuario (2026-09-26)

- El bloque **«Páginas» del pie** solo debe mostrar páginas públicas: ninguna de las que piden iniciar sesión.
- En el **menú superior** se añade **solo** la entrada a la pantalla de acceso a la zona privada.
- La pantalla de acceso tiene, además de usuario y contraseña, un **desplegable para elegir el Grupo Scout**.
- Cada socio tiene un **grupo «padre»** y, cuando haya más grupos, **no debe haber visibilidad entre grupos**.

## Pantalla «Acceso socios»

- **URL:** `/nuestro-grupo-scout/area-privada/acceso/`. Es una página pública con el shortcode `[asde_acceso]`, marcada `noindex`.
- **Campos:**

  | Campo | Detalle |
  |---|---|
  | Grupo Scout | Si solo hay uno, viene elegido |
  | Nº de socio/a o email | El nº admite ceros delante, espacios y la «t» en minúscula (76840390t → 76840390T; el segundo tutor/a, 76840390t2 → 76840390T2, v0.12). El email es el asociado a ese nº en el CRM (v0.9, ver más abajo); da igual en mayúsculas o minúsculas |
  | Contraseña | |
  | Código de verificación | Opcional. Se pide si la cuenta tiene activada la verificación en dos pasos. Admite el código de 6 cifras o un código de recuperación |
  | Mantener la sesión iniciada | Casilla |

- **Flujo** (`Frontend\Views\AccesoView`):
  1. Se comprueba el nonce.
  2. Se comprueba que el grupo existe.
  3. Se comprueba el límite de intentos.
  4. Si se ha escrito un email, se busca de qué cuenta es (`Auth\Identificacion`): por su huella en la web (v0.10) y, para las cuentas que aún no tienen el email guardado, en el CRM del grupo elegido (v0.9).
  5. `wp_signon()` se ejecuta dentro del grupo elegido, con el usuario (nº de socio/a) de esa cuenta.
  6. Wordfence aplica el doble factor.
  7. Se redirige al área o a la página privada que se pidió (`redirect_to` solo admite páginas del área que piden sesión).
- **Mensajes:**
  - Cualquier fallo de credenciales o de grupo muestra el mismo texto: «Nº de socio/a o email, contraseña o grupo incorrectos.» (hasta la v0.8: «Nº de socio/a, contraseña o grupo incorrectos.»). No revela qué dato falla, ni si un email está en el CRM.
  - Si Wordfence pide el código, la pantalla lo solicita y no lo cuenta como fallo.
- **Con la sesión ya iniciada**, «Acceso socios» lleva directamente al área.

## Entrar con el email (v0.9)

**Petición del usuario (2026-09-27):** «el usuario de acceso debe ser o el número de socio o el correo electrónico asociado a ese número de socio».

- **Qué email vale:** el del campo del CRM elegido en el alta de esa cuenta, que es al que llegó la invitación (`asde_email_origen` = «nº Scouts de España:campo», doc 11):
  - cuentas personales: el email de la ficha (`email1`);
  - cuentas familiares: el del tutor/a elegido en el alta (`tutor1_email_c` o `tutor2_email_c` de la ficha del menor).
  - Otro email de la ficha (por ejemplo, el del otro tutor/a) **no** sirve para esa cuenta.
- **Cómo se encontraba la cuenta en la v0.9** (`Auth\Identificacion`), sin guardar emails en WordPress (desde la v0.10 se usa el email guardado cifrado y esto queda solo para las cuentas que aún no lo tienen; ver «Email guardado cifrado»):
  1. Se busca el email en el **CRM del grupo elegido** (`PersonaRepository::por_emails`: email principal o secundario y emails de tutor, fichas activas o no). Siempre con el dato actual: si el email cambia en el CRM, se entra con el nuevo.
  2. De esas fichas se toman los campos donde está exactamente ese email y se buscan las cuentas cuyo `asde_email_origen` coincide.
  3. Se descartan las cuentas de otros grupos y las desactivadas.
- **Dos tutores, dos emails (v0.12):** cada tutor/a de una familia tiene su cuenta (`nº + T` y `nº + T2`) con **su email**; entra con él y recupera la contraseña con él, sin afectar a la otra cuenta.
- **Si el email es de varias cuentas del grupo** (lo normal: un scouter que además es madre o padre tiene su cuenta y la familiar con el mismo email):
  - se entra en la única cuya **contraseña** coincide;
  - si la contraseña vale para varias, no entra y se pide el nº de socio/a, diciendo cuáles son (solo después de comprobar la contraseña, así que no revela nada a quien no la sepa): «Ese email es el de varias cuentas y la contraseña vale para todas. Escribe el nº de socio/a de la cuenta con la que quieres entrar: 76840390 o 76840412T.»
- **Otros casos:**
  - Email que no es de ninguna cuenta del área: si es el email propio de un usuario de WordPress (administradores), se valida su cuenta; si no, el mensaje genérico. En ese caso **no** se llama a `wp_signon()` con el email, para que no quede registrado en el historial de intentos de Wordfence.
  - CRM caído o grupo sin conexión verificada: «Ahora no se puede comprobar el email porque el CRM no responde. Entra con tu nº de socio/a o inténtalo más tarde.» Con el nº de socio/a se sigue entrando.
- **Límite de intentos:** también por email (5 fallos por IP y email cada 15 minutos, dentro de los 20 por IP y hora). Se comprueba antes de consultar el CRM: durante un bloqueo no hay consultas.
- **Auditoría:** el registro «acceso» guarda `con: email` o `con: usuario`, nunca el email.
- **Coste (v0.9):** una consulta al CRM por intento con email (alrededor de 1 s). Con el nº de socio/a no se consulta nada. Desde la v0.10, con el email guardado, tampoco.
- **Descartado en la v0.9:** guardar en WordPress una huella (HMAC) de cada email para no consultar el CRM, para no guardar nada derivado de los datos personales (doc 12). **El usuario cambió la decisión el 2026-09-28:** el email se puede guardar si va cifrado (ver «Email guardado cifrado»).
- **Textos que lo explican:** formulario de acceso («Nº de socio/a o email», «También sirve el email al que te llegó la invitación»), invitación y email de recuperación («También puedes entrar escribiendo esta dirección de email»), activar cuenta, inicio del área («o el email al que te llegó la invitación») y alta de usuarios.
- En el móvil, el campo ya no pone mayúsculas automáticas (`autocapitalize="none"`): la T de las familias se admite en minúscula.

### Recuperar la contraseña con el email

- *Recuperar contraseña* admite también el email.
- **Grupo:** en la v0.9, con varios grupos aparecía el desplegable «Grupo Scout» para buscar el email en su CRM. Desde la v0.10 **no se pregunta**: el email guardado encuentra la cuenta sea del grupo que sea (con un solo grupo, también se buscan en el CRM las cuentas que aún no lo tienen guardado). Enlace «¿Aún no tienes cuenta? Solicita el acceso» (doc 22).
- Se envía un enlace **a cada cuenta** que usa ese email (menos las desactivadas). Cada email dice de qué usuario es.
- La respuesta es siempre la misma: «Si los datos son correctos, en unos minutos recibirás un email…». Avisa de que puede llegar un enlace por cuenta.
- **Límites:** la misma petición no se repite en 5 minutos, y como mucho 10 peticiones por dispositivo (IP) y hora, porque con un email se consulta el CRM. De la IP solo se guarda un HMAC.

### Pruebas (2026-09-27; actualizadas a la v0.10 el 2026-09-28)

`tests/acceso.php`, **41 comprobaciones, TODO OK**. No consulta el CRM: las fichas se simulan con el filtro `asde_ap_pre_personas_por_email` y cualquier otra petición al CRM se corta. Ningún email sale (`pre_wp_mail`). Datos inventados (`@example.invalid`). Las cuentas de prueba se crean como las de antes de la v0.10, sin el email guardado.

- Normalización del nº y del email (conserva el «+»).
- Cuentas por email: personal + familiar según el CRM, y el email encontrado queda guardado cifrado; mayúsculas y espacios; email de la ficha que no es el elegido; desactivadas; otro grupo; desconocido; con el CRM caído, las que ya tienen el email guardado se encuentran igual y, si hacía falta el CRM, se sabe.
- Con qué cuenta se entra: la de la contraseña escrita, misma contraseña en dos cuentas, contraseña incorrecta, usuario de WordPress con email propio, CRM caído (con email guardado se entra; sin él, se avisa), acceso completo con `wp_authenticate`.
- Pantalla de acceso de verdad (`AccesoView::handle_post`): entra y redirige, auditoría sin el email, mensajes, límite de intentos sin consultar el CRM.
- Recuperar contraseña: un enlace por cuenta, nada a desactivadas ni a otro grupo, con nº, repetición en 5 minutos, 10 por hora, y el formulario ya no pregunta el grupo.
- Limpieza: sin usuarios, registros ni contadores de la prueba.

## Email guardado cifrado (v0.10)

**Petición del usuario (2026-09-28):** «El correo electrónico sí se puede guardar en la web, ya que no es un dato sensible, siempre y cuando esté encriptado para evitar que nos roben los datos. Clave de desencriptado: el mismo id de usuario asociado a ese correo.»

- **Qué se guarda** (`Usuarios\EmailCuenta`), en dos metadatos de cada cuenta del área:
  - `asde_email_cifrado`: el email **cifrado** (libsodium *secretbox*, `Crm\Crypto::encrypt_contexto`);
  - `asde_email_huella`: su **huella** (HMAC-SHA256 del email en minúsculas), para encontrar la cuenta sin descifrar nada.
  - El `user_email` de WordPress sigue vacío, así Wordfence y otros plugins no lo usan.
- **La clave** se deriva de la **clave del sitio** y del **id del usuario** (`sodium_crypto_generichash('asde-ap|email|<id>', clave del sitio)`):
  - lo cifrado de un usuario solo se descifra con su id: con el de otro no sale (comprobado en las pruebas);
  - y nunca con la base de datos sola: la clave del sitio está en `wp-config.php` (`ASDE_AP_ENCRYPTION_KEY` o, si no está, las *salts* de WordPress), no en la base de datos.
  - **Por qué no solo el id:** el id está en la misma base de datos (y es un número pequeño y correlativo). Quien se llevara una copia de la base de datos podría descifrarlo todo. Con la clave del sitio, esa copia sola no sirve.
  - Si se cambian las *salts* sin haber definido `ASDE_AP_ENCRYPTION_KEY`, los emails guardados dejan de descifrarse (igual que las contraseñas del CRM, doc 08). La tarea diaria vuelve a guardar los que vienen del CRM.
- **El CRM manda:** si la cuenta tiene email de origen en el CRM (`asde_email_origen`), al escribirle se usa el valor actual del CRM y, si ha cambiado, se guarda el nuevo (`Usuarios\Contacto::email`). La **tarea diaria** (`Mantenimiento::sincronizar_emails`) hace lo mismo con todas las cuentas del grupo; en la auditoría (`emails_sincronizados`) solo queda cuántas.
- **Sin CRM:** el email guardado sirve para escribirle (invitaciones, recuperar contraseña) y para entrar.
- **Cuentas de antes de la v0.10:** al actualizar, una tarea en segundo plano (`asde_ap_emails_iniciales`) guarda el email de todas las cuentas con origen en el CRM. Mientras tanto, para ellas se sigue buscando en el CRM y el email encontrado se guarda. En el WordPress local ya están todas guardadas.
- **Entrar con el email:** la cuenta se busca por la huella, sin consultar el CRM. Solo si quedan cuentas sin email guardado se consulta también el CRM, y solo entonces puede salir «el CRM no responde».
- **Al dar acceso** (doc 11) o al aprobar una solicitud (doc 22), el email elegido se guarda al crear la cuenta.
- **Dónde se ve:** en *Usuarios*, enmascarado (an\*\*\*@ex\*\*\*.invalid).
- **Al desinstalar** el plugin se borran todos (`EmailCuenta::borrar_todo`).
- **Pruebas:** `tests/solicitudes.php`, parte G (cifrado ligado al id, huella sin mayúsculas, el CRM manda, tarea diaria, CRM caído, borrar); `tests/acceso.php` (cuentas de antes de la v0.10).

## Reglas de acceso (`Auth\AccessHooks`)

| Vía | Socios y familias (roles del área, sin permisos editoriales; también desactivados) | Editores y administradores |
|---|---|---|
| **Acceso socios** | Solo en **su grupo** | Los administradores eligen cualquier grupo, que pasa a ser su grupo de trabajo (meta `asde_grupo_contexto`) |
| `wp-login.php`, XML-RPC | **Rechazado**, con un enlace a Acceso socios. La respuesta es la misma con contraseña buena o mala (no sirve para adivinarla) | Como siempre |
| Contraseñas de aplicación | Desactivadas | Como siempre |
| «¿Has olvidado tu contraseña?» de `wp-login.php` | Remite a nuestra página de recuperación (email del CRM; admite nº de socio/a o email) | Flujo estándar de WordPress |

- **Grupo «padre» de un usuario** (`Grupos::de_usuario`): se toma el `asde_grupo_id` guardado en el alta. Si no lo hay, se deduce del nº de socio: código de la organización (7 en Scouts de Balears, doc 20) + nº de grupo + 4 cifras, más T en las familias.
- La comprobación del grupo está en el filtro `authenticate` con prioridad 22. Va después de validar la contraseña (20) y antes del doble factor de Wordfence (25).
- **Límite de intentos** (`Auth\Intentos`):
  - 5 fallos por IP y nº de socio cada 15 minutos;
  - 20 fallos por IP cada hora;
  - se comprueba **antes** de validar la contraseña;
  - de la IP solo se guarda un HMAC.
  - Además, la protección contra fuerza bruta de Wordfence también cuenta estos intentos (en local: 20 fallos en 4 h bloquean la IP 4 h).
- **Wordfence:**
  - El doble factor lo valida Wordfence con el campo `wfls-token` del formulario. Si llega vacío se quita, para que Wordfence *pida* el código en lugar de darlo por erróneo.
  - El **reCAPTCHA** de Wordfence (hoy desactivado en local) no se aplica a esta pantalla. Cuando duda, Wordfence pide verificar por email, y los socios no tienen email en WordPress, así que se quedarían fuera. Hay que confirmar si está activado en producción (P-29).

## Visibilidad (`Frontend\Visibilidad`)

Las **17 páginas del área privada**, incluidas *Acceso socios*, *Activar cuenta* y *Recuperar contraseña*, quedan fuera de:

- `wp_list_pages()`, que usa el bloque «Páginas» del pie de Enfold (`wp_list_pages('title_li=&depth=-1')`), y del widget *Páginas*;
- `get_pages()` en la web (v0.8): lo usa el bloque «Lista de páginas» de los **temas de bloques** (la navegación por defecto de un WordPress nuevo). Sin esto, las 17 páginas salían en la cabecera; comprobado en un WordPress nuevo (doc 19);
- el buscador de la web, incluido el buscador AJAX de Enfold, que pasa por `admin-ajax.php`;
- `/wp-json/wp/v2/pages` para visitantes (los editores las siguen viendo en el editor);
- los sitemaps de WordPress (no hay plugin SEO que tenga los suyos);
- la indexación: `noindex, nofollow`, que ya estaba;
- **los menús**: si alguien añade una página privada a un menú, no se muestra. Solo queda el elemento de acceso.

**Elemento del menú superior:**
- Al actualizar a v0.5.0 se añade **una vez** «Acceso socios» al final del menú de la ubicación `avia` (el menú «Menú» de Enfold).
- Lleva la clase de botón de Enfold `av-menu-button av-menu-button-bordered`, con borde del color principal.
- **Con sesión iniciada pasa a «Área privada»** y enlaza al inicio del área.
- Si se mueve, se renombra o se borra desde *Apariencia → Menús*, se respeta: no se vuelve a crear al actualizar. Solo **«Instalación y entorno»** lo vuelve a poner si lo habían borrado (doc 19). El id se guarda en la opción `asde_ap_menu_item`.
- Va en el menú de la ubicación principal del tema: `avia` en Enfold; en otros temas, `primary`, `main`, `menu-1`… En un WordPress nuevo sin menús, «Instalación y entorno» puede crear el «Menú principal» (Inicio + Acceso socios). Con un tema de bloques hay que añadirlo a mano en el Editor del sitio.
- Al **desactivar** el plugin, el elemento pasa a borrador (se oculta). Al reactivarlo se vuelve a publicar. Al **desinstalar** se borra.
- Límite conocido: si se guarda el menú con el plugin desactivado, WordPress vuelve a publicar el elemento.

## Varios grupos sin visibilidad entre ellos

- **Grupos dados de alta a mano (v0.6, decisión del usuario):**
  - se gestionan en *wp-admin → Grupos Scouts → Grupos y conexión CRM*, solo administradores;
  - de cada grupo: **nº** (3 cifras, clave de todo lo del grupo), **nombre** y **usuario y contraseña del CRM**;
  - la contraseña se guarda **cifrada** (libsodium) y nunca se vuelve a mostrar;
  - la **URL del CRM es común** para todos los grupos;
  - opción `asde_ap_grupos`, sin autoload.
- **Probar conexión:**
  - con las credenciales del grupo se inicia sesión y se busca **su grupo de seguridad** en el CRM (`SecurityGroups` con `tipoasociacion_c = GS` y el mismo nº);
  - **sin encontrarlo, el grupo no lee nada del CRM**;
  - se prueba automáticamente al guardar credenciales y cada día en la tarea de mantenimiento;
  - avisa si el usuario del CRM tiene permisos de escritura o ve varios grupos.
- **Baja de un grupo:**
  - sus usuarios dejan de poder entrar;
  - sus datos (usuarios, familias, pagos, informes) se conservan y vuelven si se da de alta otra vez con el mismo nº;
  - el nº no se puede cambiar: para usar otro, hay que dar de baja el grupo y añadirlo de nuevo.
- **Migración desde la v0.5:** el grupo detectado pasó a tener clave = nº (684) con el usuario y la contraseña que antes eran comunes. Los usuarios cambiaron su `asde_grupo_id` del id del CRM al nº.
- **Grupo de trabajo de cada petición** (`Domain\Grupo`):
  - para los usuarios, su grupo;
  - para los administradores, el que eligieron al entrar;
  - en la tarea diaria, grupo a grupo;
  - en la recuperación de contraseña y las invitaciones, el del destinatario (`Grupo::con()`).
- **Consultas al CRM:**
  - cada una va con las credenciales del grupo (`ClientFactory::reader()`);
  - **siempre** pide el vínculo `SecurityGroups` y descarta las fichas de otros grupos (`PersonaRepository::filtrar_grupo`), aunque las credenciales vieran varios;
  - comprobado: 382 fichas visibles, 382 del grupo.
- **Datos de WordPress separados por grupo:**
  - usuarios (`asde_grupo_id`), con el listado filtrado por grupo;
  - familias y pagos;
  - cuotas y plazos (opción por grupo);
  - informes guardados (columna `grupo`; los predefinidos son comunes);
  - caché de la numeración;
  - registro de emails;
  - movimientos del banco;
  - cambios propuestos.
- **Comprobado** con un 2.º grupo ficticio: el grupo real ve sus 107 socios activos y el otro, 0.

## El usuario del CRM de Myotragus no es global

Con las credenciales de Myotragus, la API devuelve:

- **1 grupo de seguridad visible:** *Grupo Scout Myotragus, 684*;
- **382 fichas visibles** de los **104.042 contactos** del CRM.

Por eso cada grupo tiene sus propias credenciales (P-27, resuelta en la v0.6).

## Pruebas (2026-09-26)

- **Automáticas** (`tests/alta-reglas.php`): 21 nuevas, 73 en total, **TODO OK**:
  - entrar con su grupo;
  - rechazo con otro grupo;
  - contraseña incorrecta;
  - socio rechazado en `wp-login.php` con la misma respuesta;
  - sin contraseñas de aplicación;
  - doble factor sin código, con código erróneo y con código correcto (TOTP generado en la prueba);
  - administrador en cualquier grupo;
  - límite de intentos;
  - varios grupos;
  - formulario;
  - pie, buscador, sitemap, menú sin y con sesión, y URLs de login.
- **HTTP real contra el local:**
  - contraseña incorrecta → mensaje genérico;
  - contraseña correcta → 302 al área;
  - con sesión, el menú dice «Área privada» y *Acceso socios* redirige al área;
  - `wp-login.php` con un socio → «Los socios y las familias entran desde Acceso socios…»;
  - página privada sin sesión → *Acceso socios* con `redirect_to`.
- **Navegador:**
  - el pie muestra solo las 7 páginas públicas;
  - el menú tiene «Acceso socios»;
  - la tarjeta de acceso mide 540 px y está centrada;
  - en móvil (375 px) no hay desbordamiento y el elemento aparece en el menú hamburguesa.
- La cuenta local `prueba-secretaria` ahora tiene su grupo (`asde_grupo_id`) para poder entrar por la nueva pantalla.

- **v0.6** (`tests/grupos-emails-banco.php`, parte A):
  - alta, edición y baja de grupos;
  - contraseña cifrada que se puede descifrar;
  - la lista no expone la contraseña;
  - un grupo sin verificar no lee el CRM;
  - verificación correcta del 684 e incorrecta con credenciales falsas;
  - migración desde la v0.5.

## Pendiente

- Confirmar en producción si el reCAPTCHA de Wordfence está activado (P-29).
- Traducir los textos de Wordfence (P-25).

## Usuario de acceso configurable (v0.14, doc 27)

La pantalla de acceso, la de recuperar la contraseña y la invitación hablan del **nº de socio/a** que corresponda al modo elegido (Scouts de España o local) y **aceptan los dos números**: si no existe un usuario con lo escrito, se busca la cuenta de esa persona con su otro número (tabla de equivalencias `asde_socios`). El grupo se elige en el desplegable y se guarda en la cuenta (`asde_grupo_id`), así que no depende del prefijo del nº local.
