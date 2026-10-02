# 12 · Identidad, números de socio y datos personales (v0.3.0)

> **Cambios en v0.6–0.7:**
>
> - **Personas:** las columnas se pueden ordenar y la lista va paginada. Hay 25, 50, 75 o 100 por página, con botones estándar («, ‹, 1, 2, 3, ›, »), y el tamaño elegido se recuerda para cada usuario (componente `Frontend\Tabla`, que usan también los registros de emails y del banco).
> - **Números corregidos en el CRM:** si el usuario del CRM del grupo puede editar fichas, desde *Personas* se pueden hacer varias cosas, tanto una a una como todas juntas (detalle en el doc 17):
>   - asignar número;
>   - pasar al CRM los generados;
>   - corregir los formatos antiguos;
>   - resolver los duplicados, dando a una de las fichas el siguiente número libre.
> - **Sin sección:** «Voluntario sin sección» permite Tesorería, Secretaría o Coordinación sin el rol de Scouter (doc 11).
>
> **Cambios en v0.9:**
>
> - **El código de la organización ya no es fijo:** el `7` de Scouts de Balears se configura en *Grupos Scouts → Organización federada* (doc 20). Los formatos de esta página siguen valiendo para Balears; en otra organización cambia el primer dígito (o dígitos).
> - **Usuario de acceso = nº de socio/a o el email asociado a ese nº en el CRM** (el del campo elegido en el alta). El email se busca en el CRM al entrar; WordPress sigue sin guardarlo (doc 15).
>
> **Cambios en v0.10 (decisión del usuario, 2026-09-28):**
>
> - **El email de cada cuenta se guarda en WordPress, cifrado**, con una clave derivada de la clave del sitio y del id del usuario, y una huella (HMAC) para encontrar la cuenta. El CRM sigue mandando: si cambia allí, se actualiza (doc 15).
> - Las **solicitudes de acceso** guardan cifrado lo que escribe quien pide y lo borran 30 días después de resolverlas (doc 22).
> - La **cabecera de la ficha** ya no puede decir «Sin número» por una caché de numeración que no tenga esa ficha: se rehace el análisis (`Numeracion::de`, doc 10).

## Decisiones del usuario (2026-09-26)

- **El usuario de acceso de todos es el nº de socio/a local/territorial** de ASDE Scouts de Balears.
- **Formato:** `7` (ASDE Scouts de Balears; configurable desde la v0.9, doc 20) + `684` (nº de grupo) + 4 dígitos correlativos. Ejemplo: **76840390**.
- **Tutores:** **cuenta familiar** con el nº del menor + **T** (p. ej. `76840390T`). Si hay varios hermanos en el grupo, una sola cuenta sirve para todos.
- **Segundo tutor/a (v0.12, decisión del usuario, 2026-09-30):** el otro tutor/a tiene **su propia cuenta**, `nº + T2` (`76840390T2`), con su email y su contraseña. Las dos ven a los mismos menores y comparten la familia de Tesorería. Como mucho **dos por familia**. Se crea al aprobar una solicitud («otro tutor/a») o desde *Dar acceso* (doc 22).
- **Los datos personales se quedan solo en el CRM.** En WordPress solo hay una tabla de equivalencias entre el Nº de socio Scouts de España (clave del CRM) y el nº local.
- **Nuevos socios:** el nº local se genera solo (siguiente libre), para que Secretaría no tenga que buscarlo ni se olvide.
- **Solo cuentan los socios con membresía activa.** Los demás solo aparecen en la página *Personas*.
- **Mayoría de edad:** a partir de los 18 años solo accede la propia persona; la familia deja de verla.
- **Secretaría, Coordinación y Tesorería son responsabilidades de un scouter:** se añaden a un usuario Scouter.

## Números en el CRM (análisis del 2026-09-26, 382 fichas)

| Campo del CRM | Qué es | Estado |
|---|---|---|
| `numerosocio_c` | Nº de socio **Scouts de España** | En las 382 fichas, único y numérico (3082–118554). Es la **clave estable** |
| `numsocioantiguo_enficha_c` | Nº de socio **local/territorial** | Ver tabla siguiente |
| `numsocioantiguo_c` | Nº local "auto desde membresía" | Vacío en todas: no se usa |

Situación de `numsocioantiguo_enficha_c` (entre paréntesis, los activos):

| Formato | Fichas | Tratamiento |
|---|---|---|
| `7684NNNN` | 107 (59) | ✅ Correcto |
| `07684NNNN` (0 inicial) | 242 (38) | Se normaliza → `7684NNNN`. Conviene corregirlo en el CRM |
| `684NNNN` (sin el 7) | 5 (2) | Se normaliza → `7684NNNN` |
| `768NNNN` | 1 (1) | **Inválido**, revisión manual |
| Duplicados | 3 parejas (una con los dos socios activos) | **Bloquean** el acceso de esas personas hasta corregirlo |
| Vacío | 27 (7) | Se genera al dar acceso o con «Asignar nº» |

El número más alto era el 76840391, así que el siguiente es el **76840392**.

> **Situación el 2026-09-27:** los duplicados ya están resueltos en el CRM y quedan 106 formatos antiguos (17 con membresía activa), 7 no válidos y 20 fichas sin número (todas sin membresía activa). Se corrigen desde *Personas*: una a una, o todas con una **tarea en segundo plano** que tiene monitor (v0.7.2, doc 17).

## Tabla de equivalencias `{prefix}asde_socios`

| Columna | |
|---|---|
| `num_socio_asde` (PK) | Nº Scouts de España |
| `num_socio_local` (UNIQUE) | Nº local normalizado |
| `origen` | `crm` (leído del CRM) o `generado` (asignado desde la web, **pendiente de introducir en el CRM**) |
| `creado`, `actualizado` | |

- **Cuándo se sincroniza:** con la tarea diaria, al abrir *Personas* y al analizar el grupo en el alta.
- **Si hay discrepancia, manda el CRM:** cuando un número generado aparece en el CRM, pasa a `crm`.
- **Generación:** máximo entre CRM y tabla, más 1. Se protege con `GET_LOCK` y un índice único para que dos personas nunca reciban el mismo número.

## Qué guarda WordPress de cada usuario del área privada

| Dato | Valor |
|---|---|
| `user_login` | Nº de socio local, o nº del menor + T (o + T2 para el segundo tutor/a) |
| `user_email`, nombre, apellidos | **Vacíos**. `display_name` = usuario |
| `asde_socio` | Nº Scouts de España de la persona |
| `asde_representado` (varias filas) | Nº Scouts de España de los menores de una cuenta familiar |
| `asde_email_origen` | `nºASDE:campo`: en qué ficha y campo del CRM está su email (`email1`, `tutor1_email_c` o `tutor2_email_c`) |
| `asde_email_cifrado`, `asde_email_huella` | Desde la v0.10: su email, cifrado con una clave ligada a su id, y su huella (HMAC) para encontrarlo al entrar (doc 15) |
| `asde_seccion` | Secciones de un scouter |
| `asde_estado`, `asde_alta_*`, `asde_activada_en`, `asde_invitacion_*` | Estado, trazabilidad y tokens (HMAC) |

**Emails:**

- Hasta la v0.9 se leían del CRM **en el momento de usarlos** (invitaciones, recuperación de contraseña y, desde la v0.9, al entrar con el email) y no se guardaba ni el email ni una huella suya.
- **Desde la v0.10 el email de cada cuenta se guarda cifrado** (decisión del usuario, 2026-09-28; doc 15). El CRM sigue mandando: al usarlo se lee del CRM si la cuenta tiene email de origen, y si ha cambiado se guarda el nuevo; la tarea diaria los pone al día.
- Para dar el alta hace falta un email: el de la ficha o, en una solicitud de acceso aprobada, el escrito en ella, que entonces se guarda también en el CRM (doc 22).

**Nombres:**

- También se leen del CRM al pintar: saludo, menú y listado de usuarios (este último, precacheado 15 minutos en una caché temporal: nombre y si tiene membresía activa; «Actualizar» lo relee).
- El resultado se cachea solo durante la petición.

## Acceso y ámbito (`Auth\Ambito`)

Se calcula en cada petición desde el CRM:

- **Roles de socio** (participante, scouter, secretaría, coordinación, tesorería): solo valen si su ficha tiene **membresía activa**.
- **Cuenta familiar:** solo ve a menores con membresía activa y **menores de 18 años**.
- **Sin nada vigente:** se le redirige al inicio con el motivo. Hay mensajes distintos para usuario sin vincular, ficha no encontrada, membresía inactiva y familia sin menores.
- **Tarea diaria** (`Mantenimiento`):
  - retira de las cuentas familiares a quien cumple 18;
  - si una cuenta queda vacía, le quita el rol de tutor.
- **Administradores de WordPress:** acceso completo.

## Login y contraseña

- **Desde v0.5.0 los socios entran solo por «Acceso socios»**, en el menú superior (doc 15): Grupo Scout + nº de socio/a (o, desde la v0.9, su email del CRM) + contraseña, más el código si tienen doble factor.
  - La pantalla normaliza lo escrito: quita ceros iniciales y pone la T en mayúscula; el email, en minúsculas.
  - Si un email es de varias cuentas (la propia y la familiar), entra en la de la contraseña escrita; si vale para varias, pide el nº de socio/a.
  - Cada usuario solo puede entrar en su grupo.
  - `wp-login.php` queda para editores y administradores: rechaza a los socios con un enlace a Acceso socios.
- «¿Has olvidado tu contraseña?» lleva a `/area-privada/recuperar-contrasena/`:
  - admite el nº de socio/a o el email (con el email, un enlace por cada cuenta que lo usa);
  - envía un enlace (24 h) al email de la cuenta (el del CRM, si allí ha cambiado);
  - responde siempre igual, exista o no el usuario;
  - limita las peticiones repetidas (y 10 por hora desde un mismo dispositivo).
- Invitación: enlace de 7 días, de un solo uso. En BD se guarda solo el HMAC.

## Verificación en dos pasos (Wordfence)

- **Obligatoria** para Scouter, Secretaría, Coordinación y Tesorería, con **10 días de margen**. Opcional para cuentas familiares y participantes.
- Se configura en la página **Seguridad** del área privada, que usa el shortcode de Wordfence (sus textos están en inglés).
- El inicio avisa de la fecha límite a quien aún no lo tiene.
- Detalle técnico: `requires_2fa()` de Wordfence devuelve `false` durante el margen y lo indica por referencia. La integración lo tiene en cuenta.

## Cambio en la v0.14 (petición del usuario, 2026-10-02): el usuario puede ser el nº de Scouts de España

- **El usuario de acceso es configurable** (*Grupos Scouts → Organización federada → Usuario de acceso*): el **nº de socio/a de Scouts de España** (`numerosocio_c`, siempre existe y es único; p. ej. `118241`, y para las familias `118241T` y `118241T2`) o el **nº local/territorial** de siempre. Una instalación anterior sigue con el nº local hasta que se elija otra cosa. Detalle y guion de cambio: **doc 27**.
- **Al entrar valen los dos números**; las cuentas existentes se pueden renombrar desde la misma pantalla (vista previa, confirmación y auditoría).
- **El nº territorial se sigue vigilando y corrigiendo siempre** (Personas: asignar, pasar al CRM, corregir formato, resolver duplicados; tarea diaria y tareas en segundo plano), sea o no el usuario. Con el nº de Scouts de España como usuario, sus incidencias **avisan pero no impiden el alta**.
- **Visibilidad:** cada persona de gestión puede activar **«Mostrar nº territorial»** (cabecera del área) y entonces sale junto al nº de Scouts de España en todas las pantallas donde sale una persona; **la advertencia de un problema sale siempre**.
- El grupo de una cuenta se guarda en `asde_grupo_id` (el nº de Scouts de España no lleva el nº de grupo, como sí lo llevaba el local).
