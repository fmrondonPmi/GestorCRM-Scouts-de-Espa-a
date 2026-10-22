# 56 · Gestión de accesos (v0.53)

Una página del área privada para que **Coordinación, Secretaría y Tesorería** decidan qué puede hacer cada rol y
concedan **permisos sueltos** a otras personas de gestión aunque no tengan ese rol. Pedida el 2026-10-22.

## Decisiones del usuario

- **Rol y permiso son cosas distintas:** cada rol trae permisos por defecto y se pueden añadir concesiones individuales.
- Cada módulo tiene un permiso de **ver** y permisos de **acción** (crear, editar, borrar, marcar pagado…). **Toda acción exige tener antes el de ver.**
- Los permisos **sensibles** los puede conceder cualquiera de los tres roles (Coordinación, Secretaría o Tesorería).
- **Nadie puede concederse permisos a sí mismo.**
- Solo **historial de auditoría** (quién concedió o retiró qué, a quién y cuándo); sin notificaciones cruzadas.
- Concesiones **sin caducidad** o **hasta una fecha**; al vencer se desactivan solas y queda registrado.
- Permisos efectivos = rol + concesiones activas − retiradas. **Una sola función `can()`** en servidor y navegador.

## Modelo de datos

| Pieza | Dónde | Qué guarda |
|---|---|---|
| `Permiso` | `Accesos\Catalogo` (código, no tabla) | Para cada capability repartible: módulo, tipo `ver`/`acción`, qué da, si es sensible y de qué «ver» depende |
| `RolPermiso` | tabla `{prefix}asde_permisos_rol` | Solo lo que los gestores **cambiaron** respecto a los permisos de fábrica (`Install\Roles::definitions()`): rol, permiso, 1/0, quién y cuándo. Si una casilla vuelve a su valor de fábrica, la fila se borra |
| `UsuarioPermiso` | tabla `{prefix}asde_permisos_usuario` | Persona, permiso, grupo, quién concedió y cuándo, caducidad opcional (fecha), motivo, estado `activo`/`revocado`/`caducado`, quién y cuándo lo cerró |
| `AuditoriaAccesos` | tabla `{prefix}asde_auditoria_accesos` | Concede, retira, caduca, añade/quita un permiso a un rol, restaura un rol. Solo ids y códigos de permiso (nada personal); no se borra ni se edita |

Opción `asde_ap_hay_concesiones` (`'1'`/`'0'`): si no hay ninguna concesión activa en el sitio, el filtro de `user_has_cap`
no consulta la base de datos.

## `can()`: un solo cálculo

- **Servidor:** la comprobación sigue siendo `current_user_can()` / `user_can()`; no hay una segunda API.
  - Los cambios de la matriz se escriben en las **capabilities del rol de WordPress** (`PermisosRol::aplicar()`, que `Roles::install()` repite tras cada actualización: una versión nueva no deshace lo elegido).
  - Las concesiones las suma el filtro `user_has_cap` de `Accesos\Concesiones::sumar_concesiones()` (prioridad 10, antes del filtro de módulos apagados, prioridad 20): una concesión solo vale si está activa, no ha vencido (se mira la fecha en cada petición) y la persona tiene antes su «ver» (por rol o por otra concesión).
  - Un módulo apagado en el grupo quita también los permisos sueltos de ese módulo.
- **Navegador:** `assets/js/accesos.js` expone `window.asdeAccesos.puede/cumple/depurar`, que hacen el mismo cálculo con el catálogo que el servidor le entrega (`Catalogo::para_js()`). Lo usa la matriz (marcar un permiso marca lo que necesita; desmarcar un «ver» desmarca sus acciones). El servidor lo vuelve a comprobar siempre.

## Reglas (todas comprobadas en el servidor)

1. Solo entra quien tenga `asde_accesos` (Secretaría, Coordinación y Tesorería; no se reparte).
2. **Conceder:** nunca a uno mismo; solo a cuentas **activas del grupo con un rol de gestión** (Scouter, Secretaría, Coordinación, Tesorería, Intendente); solo permisos del catálogo que la persona **no tenga ya por su rol**; con su «ver» delante; los **sensibles piden motivo**; la fecha no puede ser pasada (el último día vale entero). Volver a conceder el mismo permiso lo **renueva** (no duplica).
3. **Retirar:** lo hace cualquier gestor (también sobre sí mismo). Si otra concesión se apoyaba en ella (una acción que necesitaba ese «ver»), se retira también y queda en el historial.
4. **Caducar:** la tarea diaria (`Mantenimiento`) y la propia pantalla marcan lo vencido, con constancia en el historial (actor «Automático»). Aunque nadie lo marque, deja de valer el día siguiente.
5. **Matriz:** solo roles de gestión; **nadie añade un permiso a un rol que él mismo tiene** (ni restaurándolo; sí puede quitarlo); una acción no se añade a un rol sin su «ver», y al quitar un «ver» se quitan sus acciones. El formulario solo toca los roles que enseña (`roles[]`).
6. Si a una persona le cambian el rol o se borra la cuenta, sus concesiones se revisan (las que ya no cumplen su «ver» se retiran).

## Pantallas (`Frontend\Views\AccesosView`, página `gestion-de-accesos`, menú «Accesos»)

1. **Permisos por rol:** matriz rol × permiso, agrupada por módulo (un cuadro por módulo). Marca «Sensible», marca de «cambiado respecto a los permisos de fábrica», casillas bloqueadas en el rol propio, y «Volver a los permisos de fábrica» por rol.
2. **Accesos por persona:** listado (búsqueda por nombre/usuario y rol) y, por persona, qué le da su rol, qué tiene suelto (hasta cuándo, quién y por qué), «Quitar», y «Conceder» (sin caducidad / hasta una fecha + motivo) en cada permiso que le falta; si falta el «ver», dice cuál.
3. **Historial:** filtros en una línea (qué pasó, a quién, permiso, cuándo), ordenable y paginado.

Nombres de personas: salen de la instantánea del CRM (como «Usuarios»); WordPress no guarda nombres. Cuentas borradas: «Cuenta borrada (id)».

## Qué se reparte (catálogo)

Personas y fichas, Usuarios y solicitudes, Informes, Tesorería, Tienda, Inventario, Lista de espera, Eventos y Reintegros. **No se reparte** lo de identidad y familia (`asde_view_self`, `asde_view_wards`, `asde_propose_ward_changes`, `asde_sepa_autorizar`, `asde_mis_pagos`, `asde_add_self_notes`, `asde_edit_self_contact`, `asde_access_area_privada`), lo de la Oficina (`asde_multigrupo`, `asde_solo_lectura`) ni `asde_accesos`. El inventario completo, con sensibles y dependencias, es `Accesos\Catalogo::lista()`.

## Supuestos por confirmar (doc 09, P-194…P-201)

Ver `09-preguntas-abiertas.md` («Gestión de accesos»).

## Pruebas

`tests/accesos.php` (79 comprobaciones): coherencia del catálogo y de los permisos de fábrica (ningún rol de fábrica tiene una acción sin «ver»), conceder/retirar/renovar, autoconcesión, dependencias y cascada, caducidad (último día, vencida sin marcar, marcada por la tarea, historial), matriz (rol propio, «ver» sin acciones, restaurar, `Roles::install()`), las tres pantallas y sus formularios, módulos apagados y cambio de rol.
