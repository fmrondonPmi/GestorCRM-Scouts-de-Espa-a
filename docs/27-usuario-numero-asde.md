# 27 · El usuario de acceso pasa a ser el nº de Scouts de España (plan y estado)

> **Petición del usuario (2026-10-02):** «vamos a cambiar el usuario: hoy el usuario de acceso y todo está basado en el número territorial y lo vamos a cambiar por el número de Scouts de España; pero debe ser **configurable desde la ventana de configuración del plugin**. Hay que **mantener la funcionalidad de corregir el número territorial**, que será **visible en todas las pantallas si el usuario quiere verlo (no depende del parámetro)**. Debe salir una **advertencia si ese número tiene problemas**, y los **mismos procesos de validación y corrección se mantienen aunque el parámetro esté desactivado**. Primero el plan de acción y después la modificación.»

## 1. Situación de partida

| Qué | Hoy |
|---|---|
| Usuario de acceso (`user_login`) | nº **territorial/local** (`7684NNNN`); cuentas familiares `…T`, `…T2` |
| De dónde sale | `numsocioantiguo_enficha_c` del CRM, normalizado, o **generado** si falta (`Numeracion`) |
| Problemas que **bloquean** el alta | nº duplicado, inválido o ausente (`Numeracion::BLOQUEANTES`) |
| Grupo de un usuario | meta `asde_grupo_id` o, si no, el prefijo del login (`NumeroSocio::grupo_de_login`) |
| Clave estable de la persona | nº **Scouts de España** (`numerosocio_c`): siempre existe, es único y numérico (4–6 cifras) |

## 2. Decisiones de diseño (supuestos: se corrigen si no es lo que querías)

1. **Un parámetro** en *wp-admin → Grupos Scouts → Organización federada*: «Usuario de acceso» = **nº de socio/a Scouts de España** (nuevo) o **nº local/territorial** (el de siempre). Se guarda en `asde_ap_organizacion['identificador']` (`asde` | `local`).
2. **Las instalaciones que ya existen se quedan en `local`** hasta que alguien elija el cambio y lo confirme: así no se rompe ningún acceso al actualizar. Una instalación nueva empieza en `asde`.
3. **Cambiar el parámetro renombra los usuarios existentes** con una herramienta de migración con **vista previa** (quién cambia, quién no puede y por qué), confirmación y registro en la auditoría. Se puede volver atrás (si la ficha tiene un nº local válido).
4. **Nadie se queda fuera:** al entrar se aceptan **los dos números** (el nuevo y el territorial de siempre); el sistema encuentra la cuenta por el vínculo con la ficha (`asde_socio` / `asde_representado`). «Tu usuario» enseña siempre el vigente.
5. **Familias:** `nº ASDE del menor + T` (y `+ T2` el segundo tutor/a). El menor «base» es el de la primera ficha desde la que se creó la cuenta.
6. **Con `asde`, los problemas del nº territorial ya NO impiden dar acceso** (el nº ASDE siempre es válido y único), pero **se avisan** donde se vea la ficha. Con `local`, siguen bloqueando como hoy.
7. **El nº territorial se sigue vigilando siempre:** análisis (duplicados, formatos antiguos, inválidos, faltantes), tabla de equivalencias, tarea diaria, asignar, pasar al CRM, corregir formato, resolver duplicados y las tareas en segundo plano. Nada de eso depende del parámetro.
8. **Visibilidad (preferencia de cada usuario):** con `local` el nº territorial se ve siempre (es el usuario). Con `asde`, cada persona puede activar **«Mostrar nº territorial»** y entonces sale **junto al nº ASDE en todas las pantallas** donde sale una persona. La **advertencia** (⚠ formato antiguo, duplicado, inválido, falta) sale **siempre**, lo vea o no.
9. **Al migrar** se cierran las sesiones abiertas (la cookie de WordPress lleva el nombre de usuario) y los enlaces de «he olvidado la contraseña» pendientes dejan de valer; se avisa antes.

## 3. Plan de trabajo

| Fase | Qué se hace | Dónde |
|---|---|---|
| **1. Núcleo** | Clase `Domain\Identidad`: modo, etiqueta, ejemplo, `login_para()` (socio y familia), `normalizar()`, alias (el otro número → cuenta). Ajuste `Organizacion` (`identificador`, valor por defecto según haya usuarios o no) | `Domain/Identidad.php`, `Domain/Organizacion.php`, `Install/Installer.php` |
| **2. Configuración** | Selector en *Organización federada*, con explicación, nº de usuarios que cambiarían y botón de migración (vista previa → confirmar) | `Admin/OrganizacionPage.php` |
| **3. Acceso** | Reconocer números de 3–12 cifras (+T/T2); resolver el alias al entrar, al recuperar la contraseña y en el grupo del usuario (`Grupos::de_usuario` sin depender del prefijo); textos de la pantalla de acceso, recuperación, activación e invitación según el modo | `Auth/Identificacion.php`, `Domain/NumeroSocio.php`, `Domain/Grupos.php`, vistas y plantillas |
| **4. Alta y solicitudes** | `AltaService`/`SolicitudService` crean el usuario con `Identidad::login_para()`; con `asde` los problemas del nº territorial avisan pero no bloquean; con `local`, como hoy | `Usuarios/AltaService.php`, `SolicitudService.php`, `AltaUsuariosView` |
| **5. Migración** | `Usuarios\MigracionUsuario`: plan (por cuenta: actual → nuevo, o motivo por el que no), aplicar (renombra login, nicename, nickname, display_name; fija el grupo antes; limpia cachés; cierra sesiones), auditoría | `Usuarios/MigracionUsuario.php` |
| **6. Nº territorial visible y vigilado** | Preferencia por usuario («Mostrar nº territorial»), componente común `comun/numeros` (nº ASDE, nº territorial, ⚠) usado en Personas, Ficha, Alta, Usuarios, Solicitudes, Cambios, Tesorería (familias), Uniformes, SEPA, Correos; los procesos de `Numeracion` y `TareaNumeracion` no cambian | `Socios/`, plantillas |
| **7. Pruebas** | `tests/identidad.php` (modo, login, alias, migración, no bloqueo con `asde`, advertencias, preferencia) y las existentes siguen en `local`; pantallas revisadas en el navegador | `tests/` |
| **8. Documentos y entrega** | Docs 12, 15, 11, 20, 22, 00, 10, este; zip nuevo (sin la PWA de la otra sesión) | `docs/` |

## 4. Riesgos y cómo se cubren

- **Cuentas que no se pueden renombrar** (ficha no encontrada, usuario de WordPress que no es del área, login que ya existe): la vista previa las lista y se quedan como están; el acceso por los dos números sigue funcionando.
- **Vuelta atrás a `local`** solo para quien tenga nº territorial válido y único; el resto avisa.
- **Contraseñas que contienen el nº de socio/a** (la activación lo prohíbe): ya se comprueba con el usuario que corresponda.
- **El nº ASDE no revela el grupo:** el grupo ya se elige en la pantalla de acceso y se guarda en `asde_grupo_id`; la migración lo fija antes de renombrar.

## 5. Estado

Hecho en la **v0.14.0** (2026-10-02), con **670 pruebas en 12 ficheros** (50 nuevas en `tests/identidad.php`), todas OK:

| Fase | Estado | Detalle |
|---|---|---|
| 1. Núcleo | ✅ | `Domain\Identidad` (modo, etiquetas, ejemplos, `normalizar_numero`, `convertir`, `resolver`); `Organizacion::identificador()` (`asde` o `local`; sin guardar = `local`, así una instalación anterior no cambia al actualizar) |
| 2. Configuración | ✅ | *Grupos Scouts → Organización federada → Usuario de acceso* (selector) y *Renombrar las cuentas existentes* (vista previa de lo que cambia y de lo que no se puede, casilla de confirmación) |
| 3. Acceso | ✅ | Nº de 4–12 cifras (+T/T2); al entrar y al recuperar la contraseña vale **cualquiera de los dos números** (`Identidad::resolver`); textos de acceso y recuperación según el modo; el grupo de la cuenta sale de su vínculo (`asde_grupo_id`), no del prefijo |
| 4. Alta | ✅ | `AltaService::numero_para_usuario`: con `asde`, el usuario es el nº de la ficha y las incidencias del nº territorial **avisan pero no bloquean**; con `local`, como siempre. T2 sigue el modo aunque la primera cuenta sea antigua |
| 5. Migración | ✅ | `Usuarios\MigracionUsuario` (`plan`, `aplicar`): renombra `user_login`, `user_nicename` y nombre visible; guarda antes el grupo; cierra sesiones; auditoría `usuario_renombrado`. Las que no se pueden se quedan y se explican |
| 6. Nº territorial visible y vigilado | ✅ | `Socios\Numeros` y el enlace «Mostrar / Ocultar nº territorial» en la cabecera (preferencia de cada persona de gestión, meta `asde_ver_numero_local`). Sale en Ficha, Alta, Búsqueda de alta, Usuarios, Solicitudes, Cambios, Tesorería (control, familia, familias, editar) y Uniformes. **La advertencia sale siempre** (formato antiguo, duplicado, no válido, sin número, pendiente de pasar al CRM) para quien gestiona y con membresía activa. Los procesos de `Numeracion` y `TareaNumeracion` no cambian |
| 7. Pruebas | ✅ | `tests/identidad.php`; las demás siguen con `local`. `usuarios-listado.php` ahora restaura la tabla de números |
| 8. Documentos y entrega | ✅ | Docs 12, 15, 20, 22, 09, 10, 00 y este; `asde-area-privada-0.14.0.zip` sin la PWA |

### Cómo se cambia (guion para producción)

1. Subir el zip y entrar en *Instalación y entorno* (no cambia nada por sí solo: queda en nº local).
2. *Grupos Scouts → Organización federada → Usuario de acceso*: elegir «nº de socio/a de Scouts de España» y **Guardar**. Las cuentas **nuevas** ya usan ese número.
3. Mirar *Renombrar las cuentas existentes*: cuántas cambian y cuáles no pueden. Marcar la casilla y pulsar el botón. **Se cierran las sesiones de esas cuentas** (vuelven a entrar con su usuario nuevo o con el de siempre).
4. Avisar a la gente: «tu usuario es ahora tu nº de Scouts de España (el de tu ficha)»; el de siempre también vale.
5. Quien quiera ver el nº territorial: **«Mostrar nº territorial»** en la cabecera del área.

### Supuestos que conviene confirmar

- La preferencia de «Mostrar nº territorial» es **por persona** (de gestión) y empieza **apagada**; las advertencias salen igualmente.
- Con `asde`, el alta **ya no genera** un nº territorial a quien no lo tiene (sigue haciéndose desde *Personas → Asignar nº*).
- Las **familias** no ven números territoriales ni advertencias (no son de su incumbencia).
- Volver a `local` solo es posible para quien tenga un nº local válido y único (el plan lo dice).

### Correcciones de la v0.14.1

- **Límite de intentos por cuenta real** (`Identificacion::canonico`): escribir el nº de Scouts de España o el local es la misma cuenta, así que los intentos fallidos se suman.
- **Migración**: el plan se calcula con una lectura de la tabla de equivalencias y de los usuarios (no por cuenta); una cuenta familiar cuyo nº base parece local pero no está en la tabla se lista como imposible; el menor «base» también se deduce de los que representa la cuenta.
