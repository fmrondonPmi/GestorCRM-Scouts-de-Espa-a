# 58 · Datos del grupo y nº de socio/a con provincia (v0.56)

> **Estado:** hecho en la v0.56.0 (2026-10-23), a petición del usuario. Lo pidió así: los datos del grupo (nombre, dirección, código postal, CIF, nº de grupo) en una sección propia, editable por el equipo de Coordinación y visible a todos, con su permiso en el gestor de permisos; el nº de socio/a local = **provincia (2 cifras, sin quitar el 0 inicial) + nº de grupo + nº incremental**; y la configuración del código en la pantalla de la organización deja de hacer falta.

## 1. Qué cambia

| Antes | Ahora |
|---|---|
| Los datos del grupo estaban repartidos: nombre y nº en wp-admin → Grupos Scouts; sede, CIF y denominación al darse de alta por invitación (v0.50); la dirección del acreedor, en la configuración de las domiciliaciones SEPA | **Una sola fuente**: la pantalla **«Datos del grupo»** del área privada (`Domain\Grupos`, campos `nombre`, `denominacion`, `cif`, `sede`, `cp`, `poblacion`, `provincia`, `email_contacto`) |
| El nº de socio/a local llevaba un **código de la organización** (Balears: 7) + grupo + 4 cifras, configurado en wp-admin → Organización federada | **Provincia** del grupo (2 cifras) + nº de grupo + 4 cifras. Ya no se configura nada en Organización |

## 2. La pantalla

- Página `datos-del-grupo` (clave `grupo_datos`, `[asde_grupo_datos]`), en el menú para todos (a quien tiene el permiso, en «Gestión del grupo»; al resto, en su espacio).
- La ve **cualquier persona que entra** al área privada (solo lectura). La **cambia** quien tiene **`asde_grupo_datos`**: el rol **Coordinación** (y los administradores de WordPress). El permiso está en el **gestor de permisos** (módulo «Datos del grupo»), así que se puede dar a otros roles o a personas concretas.
- El **nº de grupo** se enseña pero no se edita (es la clave de todo lo del grupo; lo fija el administrador en wp-admin).
- Validaciones: nombre obligatorio; CIF de 9 caracteres (se guarda en mayúsculas y sin separadores); código postal de 5 cifras; provincia de la lista de 52 (`Domain\Provincias`, códigos del INE / códigos postales, con su 0 inicial); email válido.
- **Cambiar la provincia** de un grupo que ya la tenía pide marcar una casilla de confirmación (los números del CRM con la provincia anterior dejarían de reconocerse) y vacía la caché del análisis de números.
- Cada cambio queda en el registro con **los campos** que cambiaron (no los valores).

## 3. El nº de socio/a local

```
provincia (2 cifras, con su 0 inicial) + nº de grupo (3 cifras) + incremental (4 cifras)
07 · 684 · 0390  →  076840390
```

- `Domain\NumeroSocio`: `prefijo( grupo )` = provincia + grupo; `formatear()` genera números nuevos **siempre con la provincia completa**; `normalizar()` conserva el 0 inicial.
- **Compatibilidad (decisión):** los números de antes de la v0.56 eran el mismo número **sin el 0 inicial** (`76840390`, código 7). Siguen siendo **válidos tal cual**: no se tocan los del CRM ni las cuentas de acceso que los usan de usuario, y la tarea «Corregir en el CRM los formatos antiguos» no los reescribe. El contador del grupo cuenta los dos formatos; el acceso con `076840390` encuentra la cuenta `76840390` y al revés (`NumeroSocio::otras_formas()`, `Identidad::resolver()`).
- Ceros de más (`0076840390`) o sin la provincia (`6840390`) se llevan al formato completo; todo lo demás es inválido.
- **Sin provincia no se reconoce ni se genera ningún número.** La provincia de un grupo que aún no la tiene sale del código antiguo de la organización (7 → 07) para que Scouts de Balears siga como hasta ahora sin hacer nada; en otras organizaciones, la elige su Coordinación (la pantalla avisa de lo que falta).
- `Organizacion::codigo()` pasa a `codigo_antiguo()` (solo para ese caso). `Organizacion::configurada()` ya solo exige la organización. En wp-admin → Organización federada desaparece el campo del código y su casilla de confirmación.

## 4. Unificación con lo que ya había

- **Domiciliaciones SEPA** (`Sepa\Config::de()`): la dirección del acreedor (calle, código postal, población, provincia) **sale de los datos del grupo**; lo que se guardó antes en la SEPA solo se aprovecha mientras el grupo no tenga la suya (al abrir «Datos del grupo» se ofrece precargado). La pantalla SEPA ya no pide esos cuatro campos: enseña la dirección con un enlace a «Datos del grupo». El nombre del acreedor sigue siendo propio de la SEPA y, si no lo hay, la denominación fiscal.
- **Membrete** de las listas PDF (`Grupos::membrete()`): denominación, CIF y dirección completa.
- **Alta de grupo por invitación (v0.50):** sigue pidiendo nombre, sede, CIF, denominación y email; la provincia, el código postal y la población se completan después en «Datos del grupo».
- wp-admin → Grupos Scouts sigue editando el nombre y las credenciales del CRM (el nombre es el mismo dato).

## 5. Pruebas

`tests/datos-grupo.php`: provincias, nº de socio/a (con y sin 0, ceros de más, sin provincia, grupo de un usuario, contador), guardar y validar los datos, una sola fuente para la SEPA, permisos por rol y gestor de permisos, la pantalla (solo lectura / formulario), POST (403, nonce, confirmación de la provincia). Los tests que usaban el código de la organización (`organizacion`, `identidad`, `alta-reglas`…) se ajustaron al nuevo formato.

## 6. Supuestos (a confirmar)

| Id | Supuesto | Alternativa |
|---|---|---|
| DG-1 | Los números sin el 0 inicial (`76840390`) **se aceptan** y no se reescriben en el CRM; los nuevos llevan la provincia completa. | Reescribirlos todos a 9 cifras con la tarea «corregir formatos antiguos» (cambiaría también los usuarios de acceso). |
| DG-2 | El nº de grupo no se edita en la pantalla (es la clave del grupo). | Permitir cambiarlo (habría que migrar todo lo guardado por grupo). |
| DG-3 | El incremental tiene 4 cifras (como hasta ahora). | Más cifras. |
| DG-4 | El permiso se llama `asde_grupo_datos` y lo tiene Coordinación; Secretaría y Tesorería, no (se les puede dar en «Accesos»). | Darlo también a Secretaría y Tesorería de fábrica. |
