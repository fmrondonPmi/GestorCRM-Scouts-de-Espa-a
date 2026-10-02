# 22 · Solicitudes de acceso (v0.10)

## Petición del usuario (2026-09-28)

- Dar de alta a los usuarios uno a uno es mucho trabajo. Hace falta **un enlace que pueda usar cualquier asociado**.
- En esa página se escribe el **nº de documento** y la **fecha de nacimiento del asociado**, el **email**, el **nombre completo** y si quien pide es **el socio o su tutor**.
- El sistema comprueba que los dos datos existen. Si es así, la solicitud aparece en una pantalla donde **los scouters** la ven y la **aprueban**.
- Al aprobarla se envía **el email de activación de siempre, con un mensaje de bienvenida**.
- El **detalle** de la solicitud muestra lo escrito y lo que hay en el CRM.
- Si no hay ninguna ficha con ese documento, se dice que no existe. Pero si la fecha coincide con la de algún **menor de 14 años**, se pregunta **su nombre**: si coincide, OK; si no, KO y se le dice que pida el acceso por email a una dirección **configurable** (en Myotragus, `grupo@example.invalid`).
- Si el email escrito **no es el de la ficha** pero documento y fecha son correctos, **se actualiza el email en el CRM** y la invitación va al nuevo.

## Cómo funciona

1. La familia (o el socio/a desde los 14 años) abre el enlace del grupo y rellena el formulario.
2. La web lo comprueba con el CRM del grupo. Solo responde «Solicitud enviada», «escribe su nombre» o «no hemos podido comprobar tus datos» (con el email de contacto). Nunca enseña nada del CRM.
3. La solicitud aparece en **Solicitudes** a quien le toca revisarla: la pestaña lleva el número de pendientes y en *Inicio* sale el aviso «Pendiente de revisar».
4. Quien revisa abre el detalle (en una ventana emergente, doc 23), ve lo escrito junto a lo del CRM y la **aprueba** o la **rechaza**.
5. Al aprobarla: el email se guarda en el CRM si no estaba en la ficha, se crea la cuenta (o se actualiza la que había) y llega la invitación con la bienvenida.

Código: `Frontend\Views\SolicitarView` (formulario público), `Usuarios\SolicitudService` (comprobación y aprobación), `Usuarios\Solicitudes` (tabla), `Frontend\Views\SolicitudesView` (revisión), `Auth\Limite` (límite de intentos).

## El enlace

- Página pública **«Solicitar acceso»**: `/nuestro-grupo-scout/area-privada/solicitar-acceso/?grupo=684` (shortcode `[asde_solicitar]`; la crea el plugin como las demás páginas del área).
- `?grupo=684` deja elegido el grupo. Con un solo grupo conectado al CRM ya viene elegido.
- En *Solicitudes* está el enlace del grupo con el botón **«Copiar el enlace»**, para pasarlo por WhatsApp, email o la web del grupo.
- También se llega desde *Acceso socios* («¿Aún no tienes cuenta? Solicita el acceso») y desde *Recuperar contraseña*.
- Con la sesión iniciada, la página lleva al área privada.

## El formulario

| Campo | Detalle |
|---|---|
| Grupo Scout | Desplegable con los grupos conectados al CRM |
| ¿Quién pide el acceso? | «Soy su madre, padre o tutor/a (socios menores de edad)» o «Soy el socio/a (desde los 14 años)» |
| Nº de documento (DNI o NIE) | El del socio/a. Da igual con puntos, guiones, espacios o en minúsculas. Un menor sin DNI lo deja en blanco |
| Fecha de nacimiento del socio/a | Obligatoria |
| Nombre y apellidos del socio/a | Solo aparece cuando hace falta (menor de 14 sin documento) |
| Tu nombre y apellidos | De quien pide |
| Tu email | Al que llegará la invitación y con el que podrá entrar. Si el grupo tiene otro, al aprobar se actualiza en la ficha |

Pie del formulario: «Usamos estos datos solo para comprobar la solicitud con la ficha del grupo. Se guardan cifrados y se borran 30 días después de resolverla».

## Comprobación con el CRM (`SolicitudService::verificar`)

Solo cuentan las fichas del grupo con **membresía activa**.

| Lo escrito | Resultado |
|---|---|
| Documento y fecha de nacimiento **de la misma ficha** | ✅ Encontrada («por documento») |
| Documento de una ficha, pero otra fecha | ❌ No coincide |
| Sin documento, o un documento que no tiene nadie, y la fecha de algún **menor de 14 años** | Se pide su **nombre y apellidos** |
| … y el nombre es el de uno de esos menores | ✅ Encontrada («por nombre») |
| … y el nombre no es el de ninguno | ❌ No coincide |
| Ninguna de las anteriores | ❌ No existe |

- **Documento:** se compara sin puntos, guiones ni espacios y en mayúsculas, tanto lo escrito como lo del CRM (`PersonaRepository::normalizar_documento`). En el CRM se busca por los dígitos (`LIKE`) y se compara exacto en la web.
- **Nombre del menor:** sin tildes ni mayúsculas. Todas las palabras escritas tienen que estar en su nombre y apellidos, y tienen que estar su primer nombre y su primer apellido. «Hugo Martín» vale para *Hugo Martín Ferrer*; «Hugo Ferrer» no; «Hugo Martín Ruiz» tampoco.
- **Dos menores nacidos el mismo día:** el nombre decide cuál es.
- **Tipo de cuenta** (`comprobar_tipo`):
  - «Soy el socio/a» solo desde los 14 años, y si es participante o adulto voluntario. Con un menor de 14 se le explica que entra con la cuenta de su familia y se marca la otra opción.
  - «Soy su madre, padre o tutor/a» solo para menores de 18. Con un adulto se marca «Soy el socio/a».
- **Ya tiene cuenta propia activa:** «Ya tienes una cuenta en el área privada», con el enlace para recuperar la contraseña.
- **La misma solicitud pendiente** (misma persona, tipo y email): «Ya teníamos tu solicitud».
- **KO:** el mensaje es el mismo con documento inexistente o con fecha distinta, para no revelar si un documento está en el grupo: «No hemos podido comprobar tus datos… Pide el acceso por email a *(email de contacto del grupo)*, con el nombre del socio/a y tu relación con él o ella».
- **CRM caído:** «Ahora no podemos comprobar tus datos. Inténtalo más tarde o pide el acceso por email…».

### Contra abusos

- Como mucho **8 intentos por dispositivo y hora** (`Auth\Limite`). De la IP solo se guarda un HMAC.
- Un **campo trampa** invisible y un **tiempo mínimo** de 3 segundos para rellenar el formulario (sello firmado con la hora de carga; filtro `asde_ap_solicitud_segundos_minimos`). Si salta, la respuesta es la de un KO.
- Nonce de WordPress.

## Quién revisa cada solicitud

Capacidad nueva **`asde_aprobar_solicitudes`**: la tienen Scouter, Secretaría y Coordinación (doc 02).

| Quién | Qué solicitudes ve |
|---|---|
| Secretaría, Coordinación y administradores | Todas las del grupo |
| Scouter | Las de socios de **sus secciones** (las marcadas en su cuenta), si no son adultos |
| Scouters y voluntariado (adultos) | Solo Secretaría o Coordinación, desde «Dar acceso», porque hay que elegir roles y secciones |

El número de pendientes (pestaña e *Inicio*) se calcula con las secciones guardadas en la solicitud, sin consultar el CRM.

## Revisar y aprobar

Pestaña **Solicitudes**: pendientes o resueltas; en cada fila, fecha, socio/a (con su sección y cómo se comprobó), quién pide, email y **Revisar** (se abre en una ventana emergente, doc 23).

El **detalle** compara:

| Dato | Escrito en la solicitud | En el CRM |
|---|---|---|
| Socio/a | El nombre escrito (si se escribió), con ✅ si coincide | Nombre, sección y edad (enlace a la ficha) |
| Comprobación | Documento y fecha, o fecha y nombre (menor sin documento) | |
| Quién pide | Su nombre, con ✅ si es el de un tutor/a | Tutor/a 1 y 2, marcando cuál coincide. Si no coincide ninguno: «confirma con la familia antes de aprobar» |
| Email | El escrito | «Ya está en la ficha» (y en qué campo) o «No está en la ficha», con los emails actuales |

**Aprobar** (familia):

- **Campo del CRM** donde se guardará el email. Por defecto: el campo donde ya esté; si no está, el del tutor/a cuyo nombre coincide; si no, el primero vacío; si no, el del tutor/a 1. Se puede cambiar.
- **Hermanos/as** en el grupo que comparten email con la ficha: se pueden añadir a la misma cuenta familiar.
- **Si la familia ya tiene cuenta** (v0.12, P-35): hay que elegir **quién pide** el acceso. Sin elegir, no se toca nada (ni el CRM). Se propone lo más probable y se puede cambiar:
  - **Otro tutor/a** → se le crea **su propia cuenta** (`nº + T2`), con su email y su contraseña. Ve a los mismos menores, comparte la familia de Tesorería y la otra cuenta **no cambia**. Su email va al **otro campo** de tutor/a del CRM: nunca se pisa el email de la primera cuenta.
  - **La misma persona con otro email** → **sustituir** el email de esa cuenta (se elige cuál si hay dos): entra con el email nuevo y elige una contraseña nueva; la anterior deja de valer. Su email se guarda en el campo del CRM que esa cuenta ya seguía.
  - Si el email de la solicitud **ya es el de una de las cuentas**, es la misma persona: se le vuelve a mandar el acceso, sin crear otra cuenta.
  - Máximo **dos cuentas por familia** (el CRM guarda dos tutores por ficha). Con las dos creadas solo se puede sustituir.
  - Se propone «sustituir» si quien pide es el tutor/a cuyo email es el de una de las cuentas; «otro tutor/a» en cualquier otro caso.

**Aprobar** (socio/a desde los 14): el email va a `email1`. Se crea su cuenta de participante. Si ya tenía cuenta, pasa a entrar con este email y le llega un enlace para elegir contraseña.

**Adultos:** botón «Elegir roles y dar acceso», que abre *Dar acceso* en la misma ventana, con el email de la solicitud ya elegido («el de su solicitud de acceso: se guardará también en la ficha del CRM»). Al dar el acceso, la solicitud queda aprobada.

**Qué pasa al aprobar**, por este orden:

1. Si el email no es el de ese campo de la ficha, **se escribe en el CRM** (`Escritura::guardar`, que lo comprueba releyendo la ficha; auditoría `solicitud_acceso`). Si el usuario del CRM no puede editar o el CRM no lo guarda, sale un aviso para anotarlo a mano, y la invitación se envía igualmente.
2. Se crea la cuenta o se actualiza la que había. Su email se guarda **cifrado** en la web y queda ligado al campo del CRM (doc 15).
3. Llega la **invitación con la bienvenida**:
   - asunto «[Grupo] Bienvenida al área privada: activa tu acceso» (o «…: elige tu contraseña» si la cuenta ya existía);
   - «¡Te damos la bienvenida al área privada del *grupo*! Hemos comprobado tu solicitud de acceso y ya está aprobada…», seguido del texto de siempre (usuario, enlace de 7 días, «también puedes entrar escribiendo esta dirección de email»).
4. La solicitud queda **aprobada**, con quién la aprobó, cuándo y qué cuenta se creó. Auditoría `solicitud_aprobada` (con el campo del CRM si se cambió).

Mensaje final: «Solicitud aprobada: cuenta **76849911T**. Se ha enviado la invitación, con la bienvenida, a an\*\*\*@ex\*\*\*.invalid. El email nuevo ya está en la ficha del CRM.»

**Rechazar:** con un motivo que **solo ve el grupo**. Desde la v0.12 (P-36), con la casilla **«Avisar por email a quien la pidió»** (marcada por defecto) se le manda un email:

- va al **email escrito en la solicitud**;
- el **texto es genérico**: «con los datos que nos has enviado no hemos podido darte acceso… si crees que es un error, escribe a *(email de contacto del grupo)* con el nombre del socio/a y tu relación con él o ella». **No lleva el motivo**;
- lleva el email de contacto del grupo también como **«Responder a»**; sin email de contacto, dice que hable con la Secretaría del grupo;
- queda en el **registro de emails** (tipo «Solicitud rechazada», con el destino enmascarado; se puede ver cómo era). Si la solicitud no tiene un email válido o el envío falla, se rechaza igualmente y se avisa de que no se ha podido avisar;
- se ve en la auditoría (`solicitud_rechazada`, con `aviso`: enviado, error, sin_email o no).

Código: `Usuarios\EmailRechazo`, plantilla `templates/email/solicitud-rechazada.php`.

## Qué se guarda (tabla `{prefix}asde_solicitudes`)

| Columna | Contenido |
|---|---|
| `grupo`, `estado`, `tipo`, `via` | Grupo, pendiente/aprobada/rechazada, socio o familia, por documento o por nombre |
| `socio` | Nº Scouts de España de la ficha encontrada |
| `datos_enc` | Lo escrito (nombre, email, documento, fecha, nombre del menor), **cifrado** con la clave del sitio |
| `email_huella` | HMAC del email, para no duplicar solicitudes |
| `secciones`, `adulto` | Para saber quién la revisa sin consultar el CRM |
| `creada`, `revisada_por`, `revisada_en`, `motivo`, `user_id` | Trazabilidad |

- A los **30 días** de resolverla se borra lo escrito (`datos_enc`), en la tarea diaria (`Solicitudes::purgar`); queda solo el resultado.
- Al desinstalar el plugin se borra lo escrito de todas las solicitudes.

## Email de contacto del grupo

- *wp-admin → Grupos Scouts →* (el grupo) *→ «Email de contacto del grupo»*. Es el que ve quien no ha podido comprobar sus datos.
- En el WordPress local ya está puesto `grupo@example.invalid` (2026-09-28).
- **En producción hay que ponerlo a mano** después de actualizar: el plugin no lo pone solo (serviría para cualquier grupo).
- Sin email de contacto, el mensaje dice «Pide el acceso a la Secretaría de tu grupo».
- **Es parte de la configuración de cada grupo** (decisión del usuario, 2026-09-30): no hay otra pantalla. Además del formulario público lo usan el **aviso de rechazo** y los emails de la domiciliación SEPA (doc 25).
- **Si falta**, *Solicitudes* lo avisa en su parte superior y *Instalación y entorno* lo lista como pendiente («Email de contacto del grupo»).

## Pruebas (2026-09-28)

- **`tests/solicitudes.php`**: 58 comprobaciones, todas OK. Usa un **CRM simulado** (`tests/lib/CrmSimulado.php`) con fichas inventadas, así que ninguna petición llega al CRM real. Las cachés de la prueba quedan en la memoria del proceso (doc 10). Lo que cubre:
  - A · comprobación: documento en cualquier formato, fecha distinta, menor sin DNI (pide nombre), dos menores nacidos el mismo día, nombre que no coincide, KO;
  - B · creación: datos cifrados en la base de datos, duplicada, menor de 14 como socio, adulto como familia, cuenta ya activa;
  - C · formulario público: campo trampa, email no válido, OK con el email, KO con el email de contacto, campo del nombre, tipo corregido, límite de 8 por hora, página pública;
  - D · quién ve qué (Secretaría, scouter de Lobatos, adultos) y pestañas;
  - E · aprobar: email nuevo al CRM en el campo del tutor/a por su nombre, cuenta familiar, invitación con bienvenida, entrar con el email, cuenta familiar existente (sin y con «Sustituir»), adulto, menor por nombre, rechazar;
  - F · adulta desde «Dar acceso» con el email de la solicitud;
  - G · email cifrado (doc 15) y borrado a los 30 días;
  - H · ventana emergente y Personas (doc 23).
- **Prueba en el navegador** con datos inventados: cuatro solicitudes (con documento, con puntos y guion, menor sin DNI comprobado por nombre y voluntaria adulta), vista del scouter (solo su sección), aprobaciones en la ventana (familia con hermana, email nuevo al CRM, adulta desde «Dar acceso») y rechazo. Salieron y se arreglaron:
  - las transiciones de página de Enfold se llevaban el clic y abrían la página entera;
  - dentro de la ventana se perdía el estilo del tema (botones grises);
  - *Personas* decía «Dar acceso» a menores que ya tenían cuenta familiar;
  - sin documento, el aviso decía «No hay ningún socio/a con ese nº de documento».
- ⚠️ **Incidente:** esa prueba en el navegador se hizo en el mismo WordPress local que estaba usando el usuario, y la caché de la numeración quedó con las fichas inventadas: una ficha real salió «Sin número» durante unos minutos. Se limpió todo y se reforzó el plugin (doc 10, «Lecciones»).

## Pendiente

- ✅ **P-35 (v0.12):** dos tutores con cuentas separadas (`nº + T` y `nº + T2`).
- ✅ **P-36 (v0.12):** aviso por email al rechazar.
- **Producción (P-37):** poner el email de contacto del grupo (en su configuración) y decidir dónde se publica el enlace.

## Novedades de la v0.12 (2026-09-30)

- **Dos cuentas por familia:** también desde *Dar acceso* (Secretaría, Coordinación): en la ficha de un menor que ya tiene cuenta familiar, «Otro tutor/a con su propia cuenta» crea la segunda con un email de la ficha que aún no sea de la primera. En *Usuarios* la segunda sale marcada «(cuenta del 2.º tutor/a)».
- **Aviso al rechazar** y aviso de falta de email de contacto (arriba).
- **`tests/solicitudes.php`**: 81 comprobaciones (23 nuevas): partes I (dos tutores, dos cuentas: propuesta, cuenta nueva, campo del CRM sin pisar el otro, entrada con cada email, tercera cuenta, sustituir, misma persona, *Dar acceso*) y J (aviso al rechazar: destino, texto genérico sin el motivo, «Responder a», registro, sin aviso, sin email válido, desde la pantalla, sin email de contacto).

## Solicitud de quien ya tiene cuenta (v0.12.4)

Si la persona que pide acceso **ya tiene cuenta** (por ejemplo un scouter al que se dio de alta antes de que pidiera nada), «Revisar → Elegir roles y dar acceso» abre su pantalla de *Dar acceso* con un aviso de la solicitud pendiente. Dos salidas:

- **Guardar cambios** (sus roles y secciones): la solicitud queda **aprobada**, con su cuenta. El email de la cuenta y el de la ficha del CRM **no se tocan** (la solicitud solo se usa para crear cuentas nuevas).
- **«Ya tiene el acceso que pide: cerrar la solicitud sin cambiar nada»**: la resuelve sin tocar la cuenta (acción `cerrar_solicitud`, con su propio nonce, solo para una solicitud pendiente de esa misma persona y si tiene cuenta).

Antes de la v0.12.4 el formulario no llevaba el número de la solicitud en este caso y la solicitud se quedaba en *Pendientes* para siempre.

## Con el nº de Scouts de España como usuario (v0.14, doc 27)

Al aprobar una solicitud (o dar acceso desde *Dar acceso*), la cuenta se crea con el nº de Scouts de España de la ficha (y `+T` / `+T2` en las familias). Un nº territorial duplicado, no válido o ausente **ya no impide** el acceso: se avisa en la ficha y en los listados de gestión y se corrige desde *Personas*.
