# 31 · Enlace de alta para scouters (v0.16)

**Petición del usuario (2026-10-03):** «Necesito un link para invitar scouters. Se validará solo con el DNI. Y ellos mismos tienen que poder seleccionar su rol.»

**Decisiones del usuario (2026-10-03):**

| Pregunta | Decisión |
|---|---|
| ¿Qué roles elige el scouter? | **Scouter y sus secciones** (o voluntario sin sección). Secretaría, Coordinación y Tesorería **se piden** y las confirma el equipo de coordinación. |
| ¿La cuenta se activa sola? | **Sí, al momento**, si el DNI es el de una ficha de Scouter o Voluntariado activa del grupo. |
| ¿A qué email va la activación? | **Al de su ficha del CRM.** Si la ficha no tiene email, el alta la revisa el equipo de coordinación. |
| ¿Cómo es el enlace? | **Uno por grupo, renovable** (si se filtra, se genera otro y el anterior deja de valer). |

## Cómo funciona

1. **El enlace:** en *Solicitudes de acceso*, Secretaría y Coordinación ven la tarjeta **«Enlace para scouters»** con «Copiar el enlace» y «Generar enlace nuevo». La dirección es `/area-privada/alta-scouters/?grupo=<nº>&clave=<24 caracteres>` (`Usuarios\EnlaceScouters`, opción `asde_ap_enlace_scouters`, una clave por grupo). Sin la clave vigente, la página dice que el enlace no vale y no enseña el formulario.
2. **El formulario** (página pública «Alta de scouters», `Frontend\Views\AltaScoutersView`, plantilla `alta-scouters/formulario`): DNI o NIE; «¿Cómo colaboras?» → **scouter de una o varias secciones** (Castores a Rovers) o **voluntario/a sin sección**; responsabilidades opcionales (Secretaría, Coordinación, Tesorería); y su email (solo se usa si su ficha no tiene email).
3. **La comprobación** (`Usuarios\AltaScouterService`): el DNI tiene que ser el de **una sola ficha de Scouter o Voluntariado con membresía activa** del grupo (los educandos, los rovers y los DNI de fuera del grupo no valen).
4. **Qué pasa:**

| Caso | Resultado |
|---|---|
| Scouter con email en su ficha | **Cuenta creada al momento** con el rol Scouter y sus secciones; la activación (con la bienvenida) va **al email de su ficha**. Si pidió responsabilidades, quedan pendientes. |
| Scouter sin email en la ficha | Sin cuenta: **a revisión**, con el email que escribió (al dar el acceso se guarda en su ficha del CRM, como en cualquier solicitud). |
| Voluntario/a sin sección | Sin cuenta: **a revisión** (su rol es justamente la responsabilidad que pide). |
| Ya tiene cuenta activa, o ya hay una solicitud suya | No se hace nada. |
| El DNI no vale | No se hace nada. |

5. **Siempre queda una solicitud** (vía «enlace», de adulto: solo la ven Secretaría y Coordinación) con lo que pide y, si la cuenta ya se creó, «cuenta ya creada». Es el **aviso** al equipo: sale en el contador de *Solicitudes* y en «Pendiente de revisar». Al revisarla, «Elegir roles y dar acceso» abre *Dar acceso* con **los roles y las secciones que pidió ya marcados**; al guardar, la solicitud queda aprobada. Si no hay nada que cambiar, «Ya tiene el acceso que pide: cerrar la solicitud».
6. **La respuesta en pantalla es la misma en todos los casos** («Gracias. Si el DNI o NIE es el de un scouter…»): con el formulario no se puede averiguar si un DNI es del grupo.

## Seguridad

- **El DNI no es un secreto:** por eso la activación va **al email de la ficha** (quien solo sepa un DNI no se queda con la cuenta) y las responsabilidades que dan acceso a todo el grupo **no se activan solas**.
- **El enlace es la otra mitad:** sin la clave vigente no hay formulario; si llega a quien no debía, «Generar enlace nuevo».
- **Contra abusos**, como «Solicitar acceso»: 8 intentos por hora y dispositivo, un campo trampa y un tiempo mínimo para rellenarlo.
- **Registro:** cada intento queda en la auditoría (`alta_scouter_enlace`, sin el DNI) y la renovación del enlace también (`enlace_scouters_renovado`). Lo escrito se guarda cifrado en la solicitud y se borra 30 días después de resolverla.
- La verificación en dos pasos sigue siendo obligatoria para los scouters (10 días de margen).

## Ficheros

`src/Usuarios/EnlaceScouters.php`, `src/Usuarios/AltaScouterService.php`, `src/Frontend/Views/AltaScoutersView.php`, `templates/alta-scouters/formulario.php`; cambios en `Frontend\Pages` (página pública nueva), `Frontend\Router`, `Usuarios\Solicitudes` (vía `enlace`), `Frontend\Views\SolicitudesView` y las plantillas de solicitudes, `templates/alta/detalle.php` (lo pedido ya marcado), `uninstall.php` y el CSS (bloque v0.16).

## Pruebas

`tests/alta-scouters.php` (31 comprobaciones): enlace y clave, formulario con y sin clave, validaciones, cuenta al momento con el email de la ficha, revisión sin email y sin sección, educandos/rovers/DNI de fuera, respuesta idéntica en todos los casos, no duplicar, límite de intentos, lo que ve Secretaría y lo que no ve un scouter, renovar el enlace, «Dar acceso» con lo pedido marcado y confirmar la responsabilidad.

## Límites y supuestos

- Un **voluntario sin sección** siempre pasa por revisión (no tiene un rol que se pueda dar solo).
- Si alguien ya tiene cuenta y quiere **otra responsabilidad**, el enlace no la pide: la sigue dando Coordinación desde *Usuarios*.
- No se envía un email aparte al equipo: el aviso es la solicitud pendiente (contador del menú y del inicio).
