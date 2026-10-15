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
| `asde_uniformes_pedir` | Todos | Pedir la camisa y las insignias para uno mismo o para sus menores (doc 24) |
| `asde_mis_pagos` | Todos | Ver «Mis pagos» (solo lectura) |
| `asde_uniformes_gestion` | Scouter, Secretaría, Coordinación, Tesorería | Entrar en la gestión de pedidos, con ámbito: un scouter, los de sus secciones que no son adultos, y los suyos; los demás, todos |
| `asde_uniformes_entrega` | Scouter, Secretaría, Coordinación, Tesorería | Marcar como entregado (en su ámbito) |
| `asde_uniformes_pago` | **Tesorería** | Validar el pago (efectivo o ingreso) y deshacerlo |
| `asde_uniformes_pedido` | **Coordinación** | Incluir en el pedido del grupo al proveedor y deshacerlo |
| `asde_uniformes_config` | Tesorería, Coordinación | Configurar precio, tallas, insignias, guías y si hay pedidos abiertos |

- Confirmar que se ha recibido no es una capability: solo lo puede hacer el socio/a o su familia, sobre su propio pedido.
- Cancelar: el socio/a o su familia, el suyo, mientras nadie lo ha validado; Tesorería o Coordinación, cualquiera hasta que se entrega (con motivo).

## Actualización v0.12.0 (2026-09-30): domiciliación SEPA y segundo tutor/a

| Capability | Roles | Qué permite |
|---|---|---|
| `asde_sepa_autorizar` | Tutor / Padre / Madre, Participante (y administradores) | Firmar y anular la autorización de domiciliación SEPA de su familia en «Mis pagos» (doc 25). El participante, solo si es **mayor de edad** (`Sepa\Mandatos::puede_firmar`) |
| `asde_tesoreria_config` (ya existía) | Secretaría, Coordinación, Tesorería | Además, la pestaña **Domiciliaciones**: datos del acreedor, remesas, fichero, aviso a las familias, cobros y anular autorizaciones |

- Las dos cuentas de tutor/a de una familia (`nº + T` y `nº + T2`) tienen el mismo rol y los mismos permisos (doc 12).

## Actualización v0.19.0 (2026-10-15): eventos (doc 32)

| Capability | Roles | Qué permite |
|---|---|---|
| `asde_eventos_gestion` | Scouter, Secretaría, Coordinación | Crear y gestionar eventos **en su ámbito**: un scouter, los de sus secciones y aquellos en cuyo equipo está; Secretaría, los de sección de todo el grupo. **Sustituye a `asde_edit_section_events`**, que no usaba ningún código (se quita al actualizar) |
| `asde_eventos_grupo` | Coordinación | Además, los eventos **de grupo** y los **externos**, y todos los del grupo |
| `asde_eventos_economia` | Tesorería | Ver todos los eventos y **validar** (o reabrir) su presupuesto, del que sale el precio |
| `asde_eventos_config` | Coordinación, Tesorería | Margen del fondo de emergencia, tipos de actividad y titulaciones de tiempo libre |

- Tesorería es siempre un scouter: como tal, también gestiona los eventos de su sección.
- Las familias y los participantes no ven los eventos todavía: llegan con «Mis eventos» en la 0.19.1.
