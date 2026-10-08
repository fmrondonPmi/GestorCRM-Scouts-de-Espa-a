# 11 · Alta de usuarios (implementado en v0.2.0)

> **Cambios en v0.3.0–0.4.0** (detalle en [12](12-identidad-y-numeros-de-socio.md) y [14](14-tesoreria.md)). Mandan sobre lo que dice este documento:
>
> - **Usuario = nº de socio local**; se genera automáticamente si la persona no lo tiene.
> - **Cuenta familiar** = nº del menor + T.
> - **Sin email ni nombre en WordPress**: el email de la invitación se elige entre los del CRM (email de la ficha, tutor 1 o tutor 2) y se lee del CRM cada vez. Ya no se escriben a mano el nombre ni el email del tutor.
> - **Secretaría, Coordinación y Tesorería requieren el rol Scouter.** Se sugieren según el cargo del CRM.
> - La búsqueda muestra **solo socios con membresía activa**. Los inactivos están en *Personas*.
> - Al crear una cuenta familiar se crea o amplía su **familia de Tesorería**.
>
> **Cambios en v0.6–0.7:**
>
> - **Registro de emails:** cada invitación o recuperación queda registrada con su resultado (ver abajo).
> - **«Voluntario sin sección»:** nueva opción en las secciones del alta (ver abajo).
> - La ficha de cada socio, con edición según el rol, está en la página **Ficha** (doc 17). El alta sigue en *Alta de usuarios* («Acceso web» desde la ficha).
>
> **Cambios en v0.9:**
>
> - **El email elegido en el alta también sirve para entrar**, además del nº de socio/a (doc 15). El formulario del alta y la invitación lo dicen.
> - Si ese email es el de una cuenta personal y el de una familiar (un scouter que es madre o padre), al entrar con el email se abre la cuenta de la contraseña escrita; si las dos tienen la misma, se pide el nº de socio/a.
>
> **Cambios en v0.10 (2026-09-28):**
>
> - **Ya no es una pestaña.** «Dar acceso» está en la fila de cada persona de *Personas*, en *Usuarios* («+ Dar acceso» y «Gestionar acceso») y en las solicitudes. Se abre en una **ventana emergente** sobre la lista (doc 23). La página de búsqueda sigue existiendo.
> - **Solicitudes de acceso:** las familias y los socios piden el acceso con un enlace y un scouter lo aprueba sin pasar por aquí (doc 22). Para un **adulto**, la solicitud lleva a «Dar acceso» con el email de la solicitud ya elegido: «*email* — el de su solicitud de acceso: se guardará también en la ficha del CRM». Al dar el acceso, el email se escribe en el CRM (si no estaba), la invitación lleva la bienvenida y la solicitud queda aprobada.
> - **El email elegido se guarda cifrado en la web** al crear la cuenta (doc 15). El texto del formulario lo dice.
> - En *Personas*, la columna «Acceso web» muestra también la **cuenta familiar** de los menores que la tienen.

## Registro de emails (v0.6)

- **Qué se registra** (tabla `{prefix}asde_emails`, por grupo): cada email que envía el área privada, sea invitación o recuperación. De cada uno:
  - fecha, usuario y tipo;
  - destino **enmascarado** (`ma***@gm***.com`);
  - asunto;
  - estado: **Enviado** (el servidor de correo lo aceptó), **Error al enviar** (con el motivo que da PHPMailer) o **Sin email en el CRM** (no se envió);
  - quién lo mandó.
- **Dónde se ve:**
  - *Usuarios*: columna «Último email» con enlace «Ver»;
  - página **Registro de emails** (`/area-privada/usuarios/emails/`), con filtros, orden y paginación;
  - avisos del alta y del reenvío, con el destino y un enlace al email.
- **Vista del email:** se reconstruye con la misma plantilla y los mismos datos. El nombre se lee ahora del CRM y el **enlace personal no se guarda nunca** (da acceso a la cuenta): aparece sustituido.
- **En local**, los emails no salen a internet: van a **Mailpit** (http://localhost:10006). El registro lo indica con un enlace. El 26-09 una invitación «no llegó» precisamente por esto: estaba en Mailpit y las pruebas automáticas vaciaban Mailpit al terminar. Ahora solo borran los emails que crean ellas.

## Voluntario sin sección (v0.7)

- Es una opción más en «Secciones en las que colabora»: **Voluntario sin sección**.
- Solo sirve para dar acceso como **Tesorería, Secretaría o Coordinación**, **sin** el rol de Scouter y sin combinarla con secciones educativas.
- Se guarda como `asde_seccion = sin_seccion`.
- Como no es Scouter, no ve fichas por sección. Secretaría y Coordinación siguen teniendo visión de todo el grupo.
- En el formulario, marcarla desmarca las secciones y el rol de Scouter. El servidor vuelve a comprobarlo todo.

Primer módulo de la intranet. Permite dar acceso al área privada a personas del CRM y a sus tutores/as.

## Decisiones del usuario (2026-09-26)

| Tema | Decisión |
|---|---|
| Quién da de alta | **Secretaría y Coordinación**, además de los administradores de WordPress (capability `asde_manage_users`) |
| Vínculo tutor ↔ representado | **Manual en el alta**. El CRM apenas tiene emails de tutor: solo 9–11 fichas los tienen. El vínculo se guarda en la web hasta la v2 |
| Edad mínima para una cuenta de participante | **14 años** (art. 7 LOPDGDD). Por debajo, solo accede su tutor/a |
| Autorregistro | **No, por ahora**. Solo por invitación |

## Páginas

Todas son páginas normales de WordPress que cuelgan de `/nuestro-grupo-scout/`. Heredan cabecera, menú, migas de pan y estilos de Enfold.

| URL | Shortcode | Acceso |
|---|---|---|
| `/nuestro-grupo-scout/area-privada/` | `[asde_area_privada]` | Con sesión iniciada: inicio con accesos directos según el rol |
| `…/area-privada/alta-usuarios/` | `[asde_alta_usuarios]` | `asde_manage_users` |
| `…/area-privada/usuarios/` | `[asde_usuarios]` | `asde_manage_users` |
| `…/area-privada/activar-cuenta/` | `[asde_activar_cuenta]` | Pública, protegida por el token de la invitación |

Todas se sirven con `noindex`, `no-store`, `DONOTCACHEPAGE` y `litespeed_control_set_nocache`, para que no queden en caché en producción.

## Flujo

1. **Buscar** por nombre y apellidos, email (propio o de tutor), DNI o nº de socio.
   - Por defecto solo salen fichas con **membresía activa**. Hay una casilla para incluir antiguos miembros.
   - Se muestran como máximo 30 resultados, ordenados en PHP porque el CRM ignora `order_by`.
2. **Ficha**: datos del CRM en solo lectura (edad, email, móvil, tutor 1 y 2), acceso web actual y tutores con acceso.
3. **Dar acceso a la persona** (roles combinables):
   - **Participante**: sección educativa activa y ≥14 años.
   - **Scouter / Secretaría / Coordinación**: membresía activa como Scouter o Voluntariado de apoyo.
   - Para **Scouter** hay que marcar sus secciones (Castores…Rovers), porque **el CRM no guarda en qué sección colabora cada scouter**.
   - Si ya tiene usuario, los roles se añaden a esa cuenta. Las secciones enviadas sustituyen a las anteriores.
4. **Dar acceso a un tutor/a** (solo para participantes menores de edad):
   - Se proponen Tutor/a 1, Tutor/a 2, el email de la ficha (normalmente el familiar) u otra persona.
   - Se sugieren posibles hermanos/as, es decir, participantes que comparten algún email con la ficha.
   - Si el email ya tiene usuario (por ejemplo, un scouter que también es padre), se le añaden el rol y los representados.
5. **Invitación** por email (enlace personal, de un solo uso, válido 7 días) → la persona elige su contraseña en *Activar cuenta* → entra por **Acceso socios** (menú superior; desde v0.5.0, doc 15).
6. **Usuarios**: listado con roles, ámbito, estado (*Invitación pendiente* con fecha de caducidad, *Activo*, *Desactivado*) y quién dio el alta. Tiene acciones para **reenviar la invitación** y **desactivar** el acceso: se quitan los roles y se cierran las sesiones.

## Cuándo se envía (o no) la invitación

| Caso | ¿Email? |
|---|---|
| Usuario nuevo | Sí |
| Roles o representados nuevos para alguien con la invitación pendiente | Sí. Se envía una nueva con los roles actualizados y el enlace anterior deja de valer |
| Solo cambian las secciones de un scouter | No |
| Usuario que ya activó su cuenta o usuario previo con contraseña (por ejemplo, un administrador) | No: solo recibe los roles |
| Nada que cambiar (mismo rol, mismos representados) | No, y se avisa |

## Datos guardados en WordPress (user meta)

| Clave | Contenido |
|---|---|
| `asde_crm_contact_id` | Ficha del CRM de la propia persona. Los tutores no la tienen |
| `asde_seccion` (varias filas) | Secciones educativas de un scouter |
| `asde_representado` (varias filas) | Fichas que representa un tutor |
| `asde_estado` | `invitado` / `activo` / `desactivado` |
| `asde_grupo_id` | Grupo de seguridad del CRM (Grupo Scout) |
| `asde_alta_por`, `asde_alta_en`, `asde_activada_en` | Trazabilidad |
| `asde_invitacion_hash`, `asde_invitacion_expira` | HMAC del token de invitación (el token en claro solo va en el email) |

Al desactivar un usuario se conservan `asde_seccion` y `asde_representado`, para que un nuevo alta lo restaure. **Todo control de acceso futuro debe exigir el rol, además del meta.**

## Seguridad aplicada

- Capability en la vista y otra vez en el POST. Nonce en cada formulario. Patrón POST → redirección → GET.
- Todo se **vuelve a validar en el servidor releyendo el CRM**: elegibilidad, edad y pertenencia al grupo. Nunca se confía en campos ocultos.
- Las fichas se leen por **listado filtrado** (`get_entry_list` con `contacts.id = '…'`), no con `get_entry`. Así solo se accede a fichas del grupo aunque alguien manipule la URL. Ver doc 04, §7.
- Solo se pueden asignar roles `asde_*`, nunca de administrador. No se puede desactivar a administradores ni a uno mismo.
- Los usuarios que solo tienen roles del área privada no ven `wp-admin` ni la barra de administración, y al iniciar sesión van al área privada. Así tampoco pueden cambiar su email desde el perfil.
- **Auditoría** en `{prefix}asde_auditoria`: `alta_usuario`, `usuario_actualizado`, `invitacion_reenviada`, `usuario_desactivado` y `cuenta_activada`. Solo guarda IDs.
- Activación: el token son 192 bits, se guarda como HMAC-SHA256 y se comprueba con `hash_equals`. Caduca a los 7 días y solo sirve una vez. La contraseña debe tener 10 caracteres o más y no contener el email.

## Pruebas realizadas (local, 2026-09-26)

- **Automáticas** (`asde-area-privada/tests/alta-reglas.php`, 19 comprobaciones). Cubren:
  - la edad mínima;
  - que un participante no puede ser scouter;
  - las secciones obligatorias;
  - que no se admiten secciones inventadas;
  - añadir roles al mismo usuario;
  - la sustitución de secciones;
  - que se envían las invitaciones que tocan y ninguna más;
  - que un tutor no puede usar el email del participante;
  - que no se puede representar a adultos;
  - que un representado inexistente da error;
  - que un tutor ya activo no recibe invitación.
- **En el navegador**:
  - acceso anónimo redirigido al login;
  - búsqueda;
  - ficha de un menor (sin cuenta propia; bloque de tutor con autorrelleno);
  - alta de tutor → email en Mailpit → activación (con error de validación y activación correcta) → enlace reutilizado rechazado;
  - login del tutor: va a su área, sin barra de administración, el alta aparece como restringida y `wp-admin` le redirige;
  - listado con reenviar y desactivar;
  - vista móvil sin scroll horizontal.
- Todos los datos de tutor/participante/scouter de las pruebas eran ficticios (`@example.invalid`) y se borraron al terminar.

## Pendiente o posible mejora

- ✅ Página de login con el estilo del tema: **Acceso socios**, con desplegable de grupo (v0.5.0, doc 15).
- **Activar el 2FA de Wordfence** para los roles Secretaría, Coordinación y Scouter. Los roles ya aparecen en *Wordfence → Login Security → Settings*.
- Edad mínima y días de validez de la invitación configurables desde la página de ajustes.
- Revisar periódicamente las membresías (baja en el CRM → quitar el acceso automáticamente). Irá con el índice local del doc 04.

## v0.25.0 · la sección «Usuarios», con hojas (petición del usuario, 2026-10-08)

«Dar acceso y usuarios del área privada deberían estar unificados en una sección llamada Usuarios, como en Excel, donde puedes tener más de una pestaña».

- **Usuarios**, **Dar acceso** y **Registro de emails** son **hojas** de la sección «Usuarios», con su barra de pestañas encima (`View::libro_usuarios()`, plantilla `usuarios/hojas.php`). En el menú ya era una sola entrada; en el inicio, los dos accesos («Dar acceso» y «Usuarios del área privada») pasan a ser uno, **«Usuarios»**. Fuera los enlaces sueltos «+ Dar acceso», «Registro de emails» y «← Usuarios». Por debajo de 600 px las pestañas son más compactas y caben las tres a 390 px (salió al rehacer las capturas del manual, doc 21).
- Con JavaScript (`assets/js/hojas.js`) se **cambia de hoja sin recargar la página**: la hoja que se abre por primera vez se pide al servidor y de su página se toma la hoja; las ya abiertas se conservan **como se dejaron** (los filtros del listado, la búsqueda de «Dar acceso»). Las búsquedas y los enlaces de una hoja que llevan a una página de la sección se cargan en su hoja; la dirección y el título cambian con cada hoja, y **atrás/adelante** cambian de hoja. Guardar, la ventana emergente y las descargas van como siempre; al cerrar la ventana después de dar acceso, la hoja que se ve se actualiza sola. Sin JavaScript, cada hoja es un enlace a su página (las direcciones no cambian).
- `usuarios.js` arranca también en una hoja traída sin recargar (`window.asdeUsuarios.iniciar()`) y no toca la dirección mientras su hoja está oculta. Los dos scripts se cargan en las tres páginas.
- «Dar acceso» desde *Personas*, desde un usuario o desde una solicitud sigue abriéndose en la **ventana emergente**, sin hojas.
- Supuesto **P-96** (doc 09). Pruebas en `tests/usuarios-listado.php` (las hojas de cada página, sin hojas en la ventana, los scripts) y `tests/menus.php` (el acceso del inicio). Comprobado en el navegador en un WordPress aparte: cambiar de hoja sin recargar, buscar dentro de «Dar acceso», volver a «Usuarios» con el filtro puesto y «atrás».
