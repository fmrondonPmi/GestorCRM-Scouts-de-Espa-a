# 02 · Roles y permisos

## Roles (según la definición del usuario)

| Rol | Slug WP propuesto | Ámbito de datos |
|---|---|---|
| Scouter / Voluntario | `asde_scouter` | Participantes de **su(s) sección(es)** dentro de su grupo |
| Secretaría | `asde_secretaria` | **Todos** los participantes (de su grupo; ver P-03) |
| Coordinación | `asde_coordinacion` | Todos los participantes (de su grupo; ver P-03) y el módulo CRM |
| Tutor / Padre / Madre | `asde_tutor` | **Solo sus representados** |
| Participante | `asde_participante` | **Solo él o ella** |

~~El ámbito de cada usuario se obtiene del CRM…~~ **Corregido tras ver el CRM real (2026-09-26, doc 04 §7):**

- La **sección de un participante** sí sale del CRM ("Secciones actuales").
- La **sección educativa de un scouter** no está en el CRM: se asigna en el alta (`asde_seccion`).
- Los **representados de un tutor** tampoco están en el CRM de forma fiable: se vinculan a mano en el alta (`asde_representado`).
- **Secretaría y Coordinación** se asignan en el alta: el campo "Cargo" del CRM casi nunca está relleno.
- Implementado en el **alta de usuarios**, ver [11-alta-de-usuarios.md](11-alta-de-usuarios.md).

> **v0.7:** la matriz de abajo ya está aplicada en la **ficha editable** (doc 17). Si el usuario del CRM del grupo puede editar fichas:
>
> - «✅» guarda **directamente en el CRM**;
> - «📝» entra en la **cola de cambios**, que aprueban Secretaría, Coordinación o un scouter de la sección;
> - si el usuario del CRM no puede editar, todo es de solo lectura.
>
> Decisiones tomadas para las celdas con ❓:
>
> - Coordinación edita solo los textos de salud (anotaciones);
> - el scouter edita contacto y tutores de su sección, y ve la salud en solo lectura;
> - el participante cambia directamente su email y sus teléfonos (P-06);
> - el participante no ve su ficha de salud.
>
> **«Voluntario sin sección» (v0.7):** adulto con acceso solo como Tesorería, Secretaría o Coordinación, sin el rol de Scouter (doc 11).

## Matriz de permisos (v1)

Leyenda: ✅ puede · 📝 propone (requiere aprobación) · 👁 solo ver · ➕ añadir notas · — sin acceso · ❓ por confirmar

| Acción \ Rol | Scouter | Secretaría | Coordinación | Tutor | Participante |
|---|---|---|---|---|---|
| Ver datos personales | 👁 su sección | 👁 todos | 👁 todos | 👁 representados | 👁 propios |
| Editar datos de contacto | ✅ su sección | ✅ todos | ❓ | 📝 representados | ✅/📝 solo **teléfono y email** propios (P-06) |
| Editar el resto de datos personales | ❓ "algunas cosas más" | ✅ todos | — | 📝 representados | — |
| Ficha de participación en eventos | ✅ su sección | ✅ | 👁 ❓ | 📝 ❓ | 👁 ❓ |
| Ver ficha de salud / dietas | ❓ (P-04) | ✅ | 👁 | 👁 representados | 👁 propia ❓ |
| Editar ficha de salud / dietas | ❓ | ✅ | ➕ notas/observaciones | 📝 representados | — |
| Añadir observaciones | ❓ | ✅ | ➕ en salud y dietas | 📝 ❓ | ➕ propias |
| Aprobar cambios pendientes | ✅ su sección | ✅ todos | ✅ todos | — | — |
| Módulo de sincronización CRM | — | ❓ | ✅ | — | — |
| Gestionar usuarios del área privada | — | ❓ | ❓ | — | — |

> "✅" significa "editar" desde el punto de vista del usuario. En v1 **toda escritura acaba en la cola de cambios** (ver [01](01-contexto-y-alcance.md#consecuencia-importante-de-v1-solo-lectura)). La diferencia es que el cambio de un rol con ✅ entra ya **aprobado** (queda pendiente solo de pasarlo al CRM), mientras que uno con 📝 entra como **pendiente de aprobación**.

## Capabilities de WordPress propuestas

Granulares, para poder reasignarlas sin tocar código:

```
asde_access_area_privada          # entrar en el área privada
asde_view_self                    # ver ficha propia
asde_edit_self_contact            # teléfono + email propios
asde_add_self_notes               # observaciones propias
asde_view_wards                   # ver representados
asde_propose_ward_changes         # proponer cambios de representados
asde_view_section                 # ver participantes de su sección
asde_edit_section_contact         # editar contacto de participantes de su sección
asde_edit_section_events          # fichas de participación en eventos
asde_view_all                     # ver todos los participantes (del grupo)
asde_edit_all                     # editar todo (secretaría)
asde_view_health                  # ver salud/dietas (sujeto además a ámbito)
asde_annotate_health              # notas en salud/dietas
asde_approve_changes              # aprobar cambios (sujeto a ámbito)
asde_crm_sync                     # módulo de sincronización CRM
asde_manage_users                 # alta/baja/vinculación de usuarios
```

**Una capability sola no basta:** cada comprobación es *capability + ámbito*. Por ejemplo, `asde_view_section` solo autoriza a ver a un participante si comparte una membresía activa de grupo y sección con el scouter. Esto se centraliza en una única clase de política (`AccessPolicy::can($user, $accion, $contactoCrmId)`), que se usa en plantillas, endpoints REST y exportaciones. Ninguna comprobación de permisos debe quedar dispersa por el código.

## Casos a contemplar en el modelo

- Un **scouter en varias secciones**, o que cambia de sección a mitad de ronda.
- Un **tutor con varios representados**, en secciones o incluso en grupos distintos.
- Un **participante con varios tutores** (padre y madre, separados, tutor legal…).
- Una **persona con varios roles**: un scouter que también es padre de un lobato, o un rover mayor de edad que ayuda de scouter. WordPress admite varios roles por usuario, y la política debe hacer la **unión** de permisos.
- Un **participante que cumple 18 años**: deja de tener tutor con acceso (P-07).
- **Bajas**: si la membresía está inactiva en el CRM, se pierde el acceso automáticamente.

## Actualización v0.4.0 (2026-09-26)

| Rol | Capabilities añadidas |
|---|---|
| Scouter | `asde_view_health` (**solo lectura, solo su sección**), `asde_reports`, `asde_tesoreria_acceso` |
| Secretaría / Coordinación | `asde_reports`, `asde_tesoreria_acceso`, `asde_tesoreria_config` |
| **Tesorería** (nuevo, `asde_tesoreria`) | `asde_tesoreria_acceso`, `asde_tesoreria_config`. Responsabilidad de un scouter |

- Secretaría, Coordinación y Tesorería se asignan **solo a scouters**.
- Todos los roles de socio exigen **membresía activa** en el CRM en cada petición (`Auth\Ambito`).
- Las cuentas familiares solo ven a **menores de 18** con membresía activa.

## Actualización v0.10.0 (2026-09-28)

| Rol | Capability añadida | Qué permite |
|---|---|---|
| Scouter | `asde_aprobar_solicitudes` | Revisar y aprobar las **solicitudes de acceso** de socios de sus secciones que no son adultos (doc 22) |
| Secretaría / Coordinación | `asde_aprobar_solicitudes` | Todas las solicitudes del grupo; las de adultos se aprueban desde «Dar acceso», con sus roles |

- La capability se añade sola al actualizar el plugin (`Roles::install` al cambiar de versión).
- Como las demás, va con **ámbito**: `Solicitudes::en_ambito` compara las secciones guardadas en la solicitud con las del scouter (`Ambito::secciones_scouter`). Secretaría, Coordinación y los administradores tienen visión global.
- **Pestañas por rol** (doc 23):
  - un scouter ve *Inicio, Mis datos, Solicitudes, Cambios, Informes y Tesorería*;
  - Secretaría y Coordinación, además *Personas* y *Usuarios*;
  - «Seguridad» pasa junto al nombre.

## Actualización v0.11.0 (2026-09-29): uniformes y «Mis pagos»

| Capability | Roles | Qué permite |
|---|---|---|
| `asde_tienda_comprar` (antes `asde_uniformes_pedir`, v0.39) | Todos | Comprar en la tienda (el uniforme incluido) para uno mismo o para sus menores (doc 48) |
| `asde_mis_pagos` | Todos | Ver «Mis pagos» (solo lectura) |
| `asde_tienda_gestion` | Scouter, Secretaría, Coordinación, Tesorería | Entrar en la gestión de pedidos, con ámbito: un scouter, los de sus secciones que no son adultos, y los suyos; los demás, todos |
| `asde_tienda_entrega` | Scouter, Secretaría, Coordinación, Tesorería | Marcar como entregado (en su ámbito) |
| `asde_tienda_pago` | **Tesorería** | Validar el pago (efectivo o ingreso) y deshacerlo |
| `asde_tienda_pedido` | **Coordinación** | Incluir en el pedido del grupo al proveedor y deshacerlo |
| `asde_tienda_admin` (antes `asde_uniformes_config`) | **Coordinación, Tesorería y Secretaría** (Secretaría, desde la v0.39) | Configurar la tienda: pagos, plazos, datáfono, tienda pública y enlace del menú (los productos, en el inventario de la tienda) |

- Confirmar que se ha recibido no es una capability: solo lo puede hacer el socio/a o su familia, sobre su propio pedido.
- Cancelar: el socio/a o su familia, el suyo, mientras nadie lo ha validado; Tesorería o Coordinación, cualquiera hasta que se entrega (con motivo).

## Actualización v0.12.0 (2026-09-30): domiciliación SEPA y segundo tutor/a

| Capability | Roles | Qué permite |
|---|---|---|
| `asde_sepa_autorizar` | Tutor / Padre / Madre, Participante (y administradores) | Firmar y anular la autorización de domiciliación SEPA de su familia en «Mis pagos» (doc 25). El participante, solo si es **mayor de edad** (`Sepa\Mandatos::puede_firmar`) |
| `asde_tesoreria_config` (ya existía) | Secretaría, Coordinación, Tesorería | Además, la pestaña **Domiciliaciones**: datos del acreedor, remesas, fichero, aviso a las familias, cobros y anular autorizaciones |

- Las dos cuentas de tutor/a de una familia (`nº + T` y `nº + T2`) tienen el mismo rol y los mismos permisos (doc 12).

## Actualización v0.19.0 (2026-10-05): inventario de material

| Capability | Roles | Qué permite |
|---|---|---|
| `asde_inventario` | Scouter, Secretaría, Coordinación, Tesorería (y administradores) | Entrar en **Inventario** (doc 32): ver y editar el material (añadir, cambiar, dar de baja y recuperar), gestionar los tipos de material y preparar listas de compra. Un **voluntario sin sección** entra igual, porque tiene Secretaría, Coordinación o Tesorería |
| `asde_inventario_aprobar` | **Tesorería** (y administradores) | Dar o negar el **visto bueno** a una lista de compra (sin él no se puede imprimir) y deshacerlo mientras nadie haya descargado el PDF |

- Familias y participantes no ven el inventario.
- Cancelar una lista: quien la creó, mientras está pendiente; Tesorería, mientras no se haya descargado el PDF.

## Actualización v0.20.0 (2026-10-06): módulos por grupo

**wp-admin → Grupos Scouts → Módulos** (`Admin\ModulosPage`, solo administradores): una fila por grupo y una casilla por módulo. Lo que se apaga deja de existir para **todas las cuentas de ese grupo**, también para un administrador mientras trabaja en él (wp-admin no cambia). **No se borra nada**: al activarlo de nuevo todo está como estaba. Por defecto todo activo (las instalaciones anteriores no cambian); solo se guarda lo apagado (opción `asde_ap_modulos`).

| Módulo | Capabilities que se quitan | Además |
|---|---|---|
| Tesorería | `asde_tesoreria_acceso`, `asde_tesoreria_config`, `asde_sepa_autorizar` | Apaga también Banco y Domiciliaciones |
| Banco (dentro de Tesorería) | — | Sin pestaña ni pantalla; sin el desplegable de ingresos en «Registrar pago» |
| Domiciliaciones SEPA (dentro de Tesorería) | `asde_sepa_autorizar` | Sin pestaña ni pantalla; «Mis pagos» no ofrece firmar |
| Tienda (antes Uniformes, v0.39) | `asde_tienda_*` | |
| Inventario | `asde_inventario`, `asde_inventario_aprobar` | |
| Informes | `asde_reports` | |
| Solicitudes de acceso | `asde_aprobar_solicitudes` | El grupo no sale en «Solicitar acceso» y el servidor no acepta solicitudes suyas |
| Alta de scouters | — | El enlace del grupo deja de servir y no se ofrece en *Solicitudes* |
| («Mis pagos») | `asde_mis_pagos` | Solo si se apagan **Tesorería y Uniformes** |

- **Cómo:** `Domain\Modulos` quita las capabilities de los módulos apagados con el filtro `user_has_cap` (en el grupo de esa persona; para quien está conectado, el grupo en el que trabaja). Con ellas desaparecen el menú, las tarjetas del inicio, los avisos de «Pendiente de revisar», las pantallas y las acciones, sin tocar cada vista. Banco y Domiciliaciones (que comparten la capability de configurar Tesorería) y los formularios públicos preguntan a `Modulos::activo()`.
- Prueba: `tests/modulos.php`.

## Actualización v0.32.0 (2026-10-14): lista de espera (doc 39)

| Capability | Roles | Qué permite |
|---|---|---|
| `asde_lista_espera` | Scouter, Secretaría, Coordinación, Tesorería | Ver la lista de espera del grupo (toda, con filtro por sección, P-124) y el enlace para apuntarse; enviar un email, apuntar un contacto y cambiar el estado. El documento no lo ven. |
| `asde_lista_espera_alta` | Secretaría, Coordinación | Además: ver el documento, asignar la sección, renovar el enlace del grupo, borrar una inscripción y **dar de alta en el CRM** desde ella (con «Alta de socio/a», que necesita también `asde_manage_users` y las altas en el CRM activas). |

- Módulo **«Lista de espera»** en wp-admin → Grupos Scouts → Módulos, **apagado por defecto** (regla 5): `Domain\Modulos::POR_DEFECTO_APAGADOS`; encendido se guarda `[lista_espera => true]` en `asde_ap_modulos`. Apagado, nadie del grupo tiene esas dos capabilities y el enlace del grupo no enseña el formulario (los enlaces personales siguen sirviendo para ver o borrar los datos).
- La página pública (`inscripcion_espera`) no pide sesión.

## Actualización v0.35 (2026-10-15): eventos (doc 43)

| Capability | Roles | Qué permite |
|---|---|---|
| `asde_eventos_gestion` | Scouter, Secretaría, Coordinación | Crear y gestionar eventos **en su ámbito**: un scouter, los de sus secciones y aquellos en cuyo equipo está; Secretaría, los de sección de todo el grupo. **Sustituye a `asde_edit_section_events`**, que no usaba ningún código (se quita al actualizar) |
| `asde_eventos_grupo` | Coordinación | Además, los eventos **de grupo** y los **externos**, y todos los del grupo |
| `asde_eventos_economia` | Tesorería | Ver todos los eventos y **validar** (o reabrir) su presupuesto, del que sale el precio |
| `asde_eventos_config` | Coordinación, Tesorería | Margen del fondo de emergencia, tipos de actividad y titulaciones de tiempo libre |

- Tesorería es siempre un scouter: como tal, también gestiona los eventos de su sección.
- Las familias y los participantes no ven los eventos todavía: llegan con «Mis eventos» (la segunda parte, también en la v0.35).

## Actualización v0.35 (2026-10-15): inscripciones a los eventos (doc 43)

| Capability | Roles | Qué permite |
|---|---|---|
| `asde_eventos_inscribir` | **Todos** (Scouter, Secretaría, Coordinación, Tesorería, Tutor, Participante) | «Mis eventos»: ver los eventos a los que están convocados la persona o los menores que representa, inscribir, firmar la autorización, responder a una convocatoria, confirmar una plaza de la lista de espera y darse de baja mientras el plazo está abierto |

Y lo que ya había, con lo nuevo:

- `asde_eventos_gestion` (en el ámbito del evento): además, **inscribir** a alguien en nombre de su familia (fuera de las secciones convocadas o de la edad, con motivo), **marcar la autorización en papel** y **dar de baja** con motivo, hasta que acaba el evento. Ve quién recoge a cada menor y las observaciones (cifradas en la base de datos).
- `asde_eventos_economia` (Tesorería): **registrar pagos** (efectivo, ingreso o transferencia, Bizum) de una inscripción o de una familia, **becas y ajustes** con motivo y **anular** un movimiento. No ve quién recoge ni las observaciones, salvo que esté en el equipo del evento.
- Ámbito de «Mis eventos» (como en el uniforme): la cuenta familiar, sus menores (y firma por ellos); el asociado/a de 14 a 17, se preinscribe (firma su familia); el mayor de edad (el día que empieza el evento), se inscribe sin autorización. Un scouter ve «Mis eventos» solo si hay algún evento para él (p. ej. una formación).

## Actualización v0.47.0 (2026-10-21): reintegros (doc 53)

| Capability | Roles | Qué permite |
|---|---|---|
| `asde_reintegros` | Scouter, Coordinación | Pedir el reintegro de un gasto y ver, cancelar y seguir los suyos |
| `asde_reintegros_aprobar` | Tesorería | Aprobar o rechazar (con motivo) los reintegros del grupo; nadie aprueba el suyo |
| `asde_reintegros_pagar` | Tesorería, Coordinación | Marcar como pagado un reintegro aprobado |

Quien aprueba o paga ve todos los reintegros del grupo (con el IBAN mientras no esté pagado). Módulo **apagado por defecto** (`Domain\Modulos::REINTEGROS`).

## Actualización v0.50.0 (2026-10-22): rol Oficina (docs 50 y 51)

Solo tiene sentido en una web **de oficina** (`Organizacion::es_oficina()`); en una web de grupo el rol existe pero no deja entrar.

| Capability | Qué permite |
|---|---|
| `asde_multigrupo` | Elegir el grupo que consulta (selector del menú) y leer con la credencial de la oficina, un grupo cada vez y solo si está verificado |
| `asde_solo_lectura` | Todo envío de formulario se rechaza en el Router (salvo elegir el grupo) y `Crm\Escritura` se niega a escribir |
| `asde_manage_users`, `asde_reports`, `asde_view_health` | Ver Personas, Usuarios, Informes y salud (solo lectura) |

- Entra **sin ficha** del CRM. Solo abre Inicio, Personas, ficha, Informes, Usuarios y Seguridad (`Domain\Oficina::PAGINAS`); ninguna capability de edición, tesorería, tienda, inventario, eventos, lista de espera ni reintegros.
- Informes: niveles `todos`, `global` y `salud`; nunca `pagos`; sin IBAN. Descargas y fichas consultadas, **auditadas**.
- **2FA obligatoria** (Wordfence) para este rol. Las cuentas las crea el administrador de WordPress; Coordinación de un grupo no puede asignar este rol.

## Actualización v0.53.0 (2026-10-22): gestión de accesos (doc 56)

| Capability | Roles | Qué permite |
|---|---|---|
| `asde_accesos` | Secretaría, Coordinación, Tesorería | Entrar en «Gestión de accesos»: cambiar los permisos de cada rol, conceder y retirar permisos sueltos a otras personas de gestión y ver el historial. **No se reparte** (solo la traen esos tres roles) |

- Los permisos de cada rol que se ven en las tablas de arriba son los **de fábrica** (`Install\Roles::definitions()`); los gestores pueden cambiarlos desde la matriz (tabla `asde_permisos_rol`, que `Roles::install()` vuelve a aplicar) y añadir **permisos sueltos** por persona (tabla `asde_permisos_usuario`), con o sin fecha de caducidad.
- Toda acción exige el «ver» de su módulo; nadie se concede permisos a sí mismo ni los añade a su propio rol; los sensibles llevan motivo. Detalle en el doc 56.

## Actualización v0.59.0 (2026-10-25): caja de efectivo y libro mayor (doc 61)

| Capability | Roles | Qué permite |
|---|---|---|
| `asde_caja` | Scouter, Secretaría, Coordinación, Tesorería | Ver y llevar la caja de efectivo de sus secciones (un scouter, las de sus secciones educativas): saldo inicial, apuntes, traspasos a otra caja y arqueos |
| `asde_caja_todas` | Secretaría, Coordinación, Tesorería | Todas las cajas (la del grupo y las de sección), los traspasos con el banco y el libro mayor |

Las dos se reparten en la Gestión de accesos (doc 56, módulo Tesorería) y se quitan si el módulo **«Caja y libro mayor»** (`Domain\Modulos::CAJA`, depende de Tesorería) está apagado: nace **apagado por defecto** (regla 5).
