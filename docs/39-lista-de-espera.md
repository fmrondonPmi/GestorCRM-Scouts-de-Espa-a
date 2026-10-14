# 39 · Lista de espera (v0.32, rama `Lista-Espera`)

Petición del usuario (2026-10-14): un módulo de **lista de espera** para personas que todavía no
son del grupo. El grupo da un enlace; la familia (o la propia persona, si es mayor de edad) deja
los datos mínimos; el equipo del grupo la ve, la contacta y, cuando hay plaza, Secretaría o
Coordinación la dan de alta en el CRM con esos datos, que se borran en ese momento.

## 1. Qué pidió el usuario y cómo queda

| Petición | Cómo queda |
|---|---|
| Un enlace para una persona no asociada | Enlace del grupo `?grupo=…&clave=…` en la página pública «Inscripción en la lista de espera», como el de alta de scouters (doc 31): una clave aleatoria por grupo; Secretaría y Coordinación lo **renuevan** (el anterior deja de valer). Lo ve y lo copia todo el que ve la lista. |
| Datos mínimos: nombre, apellidos, DNI (obligatorio desde los 14 años), fecha de nacimiento | Igual. El DNI/NIE se valida con la letra (`Crm\Duplicados::validar`); obligatorio desde los 14 años (`Duplicados::documento_obligatorio`, P-82). |
| Contacto: teléfono, email (validado con un email de confirmación) y observaciones | Igual. La inscripción queda **sin confirmar** hasta que se pulsa el enlace del email; las no confirmadas en **7 días** se borran solas. Además se pide el **nombre y apellidos de la persona de contacto** si es menor de 18 años (madre, padre o tutor/a): el alta en el CRM los exige como tutor/a 1 (P-88). Y una casilla de **consentimiento** con la información de protección de datos. |
| Solo para scouters, Coordinación, Tesorería y Secretaría | Capability `asde_lista_espera` (ver, contactar, cambiar el estado) en esos cuatro roles; `asde_lista_espera_alta` (asignar sección, dar de alta, borrar, renovar el enlace) solo en Secretaría y Coordinación. |
| Datos cifrados en nuestra base de datos | Tabla `{prefix}asde_lista_espera`: todo lo escrito va **cifrado** (`Crm\Crypto`) en `datos_enc`, y el historial de contactos en `historial_enc`. En claro solo: grupo, estado, sección asignada, fechas y dos **huellas** (HMAC) del email y de la persona para encontrar repetidos sin descifrar. |
| El email como clave de acceso | Cada persona tiene un **enlace personal** (id + token HMAC con una sal por fila; el token no se guarda) que llega en los emails; al abrirlo, la página pide **su email** (la «clave») y, si coincide, enseña sus datos. Si pierde el enlace: «¿Ya estás en la lista?» → escribe su email y se le reenvían sus enlaces (la respuesta es siempre la misma, exista o no). |
| Ver sus datos e indicar si los mantiene | En su página: sus datos, la fecha hasta la que se guardan, **«Mantener mis datos 12 meses más»** y **«Borrar mis datos»**. |
| Caducidad de 12 meses | `caduca` = confirmación (o renovación) + 12 meses. La tarea diaria avisa por email **30 días antes** (una vez, con el enlace para mantenerlos) y **borra** las caducadas. |
| La edad decide la sección | `Domain\Secciones::por_edad()`: edad que cumple en el año en que empieza la ronda (septiembre a agosto): Castores 6-7, Lobatos 8-10, Scouts 11-13, Escultas 14-16, Rovers 17-20, Scouters 21+ (P-123). Por debajo de 6, «Aún sin edad». Se recalcula sola cada ronda. |
| Estados, entre ellos «contactada» (por email o por teléfono) | Sin confirmar → **En espera** → **Contactada** (con el medio: email enviado desde la web, o llamada / email registrado a mano) → **Plaza ofrecida** → (alta) o **Descartada**. «Enviar email» desde la ficha de la lista lo envía, lo apunta en el historial y la pasa a Contactada; «Registrar contacto» (teléfono u otro email) igual, sin enviar nada. |
| Secretaría y Coordinación: asignar sección y dar de alta | «Asignar sección» (o volver a «la de su edad») y **«Dar de alta en el CRM»**: abre el «Alta de socio/a» de Personas (doc 34) con los datos rellenados; su **primer paso comprueba el DNI contra el CRM** (si ya tiene ficha en el grupo se para; si está en otro grupo, avisa). Al terminar el alta, la inscripción se **borra en ese momento**. Requiere las altas en el CRM activas en el grupo. |
| Rama nueva «Lista Espera» | `Lista-Espera` (git no admite espacios en el nombre de una rama). |

## 2. Diseño

- `src/ListaEspera/Inscripciones.php` — la tabla y sus operaciones (crear, confirmar, listado,
  estado, sección, contacto, renovar, borrar, avisar y purgar).
- `src/ListaEspera/Enlaces.php` — clave del enlace del grupo (renovable) y tokens personales
  (confirmar y acceso) con `hash_hmac` + sal de la fila + `wp_salt()`.
- `src/ListaEspera/Avisos.php` — los emails (confirmar, confirmada, tus enlaces, caducidad,
  mensaje del grupo) con una plantilla común `templates/email/lista-espera.php`, en el idioma en
  que se inscribió; registro de emails con el destino enmascarado (tipo `lista_espera`).
- Vistas: `InscripcionEsperaView` (pública: formulario, confirmar, acceso personal, reenviar
  enlaces; honeypot, sello de tiempo, `Auth\Limite`) y `ListaEsperaView` (privada: listado con
  filtros y orden de llegada por sección, ficha con historial y acciones).
- El alta usa `Frontend\AltaSocio` con un campo `espera` que viaja por los pasos: Personas rellena
  los datos desde la inscripción (descifrados en el servidor, nunca en el HTML de la lista) y la
  borra al acabar.
- Módulo «Lista de espera» en wp-admin → Grupos Scouts → Módulos, **apagado por defecto** (regla
  5): `Domain\Modulos::POR_DEFECTO_APAGADOS`.

## 3. Datos de cada inscripción

| Campo | Dónde |
|---|---|
| Nombre, apellidos, nacimiento, documento, contacto (nombre, apellidos, teléfono, email), observaciones, idioma | `datos_enc` (cifrado) |
| Historial (fecha, quién, qué: email enviado con su asunto y texto, contacto registrado, estado, sección) | `historial_enc` (cifrado) |
| Grupo, estado, sección asignada, medio y fecha del último contacto, fechas (creada, confirmada, caduca), aviso enviado | en claro (no son datos personales) |
| Huella del email y de la persona (documento, o nombre + nacimiento) | `email_huella`, `persona_huella` (HMAC, no reversibles) |

Se borra la fila entera: al darla de alta, al pedirlo la persona, al borrarla Secretaría o
Coordinación, a los 7 días si no se confirma y a los 12 meses de la confirmación o la última
renovación. La auditoría guarda solo el id y la acción.

## 4. Supuestos (a confirmar por el usuario)

- **P-123** Sección por edad: la edad que cumple en el año en que empieza la ronda (septiembre),
  con los cortes 6/8/11/14/17/21.
- **P-124** Los scouters ven **toda** la lista del grupo (no solo sus secciones), con un filtro por
  sección que por defecto muestra todas. El documento solo lo ven Secretaría y Coordinación.
- **P-125** Se pide nombre y apellidos de la persona de contacto (obligatorios por debajo de 18
  años: el alta en el CRM los necesita como tutor/a 1). El género no se pide: lo pone Secretaría
  al dar de alta.
- **P-126** La persona puede ver, mantener y borrar sus datos, pero no editarlos (para corregir,
  escribe al grupo o se borra y se vuelve a inscribir).
- **P-127** Las inscripciones sin confirmar se borran a los 7 días; el aviso de caducidad sale 30
  días antes, una sola vez por periodo.
- **P-128** El módulo nace apagado en todos los grupos: se enciende en wp-admin → Módulos. «Dar de
  alta» necesita además las altas en el CRM activas; sin ellas se puede todo lo demás.
- **P-129** Una misma persona apuntada dos veces (mismo documento, o mismo nombre y nacimiento)
  con el mismo email no se repite (se reenvía el email); con otro email, se apunta y sale marcada
  como «Repetida» en la lista.
- **P-130** Las observaciones no van al CRM (no hay campo): Secretaría las lee antes del alta.
