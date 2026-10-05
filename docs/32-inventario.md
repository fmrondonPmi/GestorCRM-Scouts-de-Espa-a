# 32 · Inventario de material y listas de compra (v0.19.0)

## Petición del usuario (2026-10-05)

- Un módulo nuevo, **«Inventario»**, con todo el material y el equipamiento del grupo. Además de **cantidad** y **descripción**: **precio**, **tipo**, **año de compra** (opcional) y **número de serie**.
- El **tipo** es una gestión aparte (crear tipos nuevos). En el desplegable de tipos hay un **«+»** al lado que abre, en una **ventana modal**, el mantenimiento de tipos.
- Acceso: **scouters con y sin sección** (el «voluntario sin sección» del doc 02) y, por supuesto, **Coordinación, Tesorería y Secretaría**. Familias y participantes, no.
- **Listas de compra**: se marcan los materiales que se quieren comprar. Si la cantidad del material es **< 5**, sale un aviso con la cantidad que hay («¿Seguro que quieres añadirlo a la lista de compra?»), **no bloqueante**.
- La lista se genera en un **PDF para imprimir**. Al generarla se envía un **email a Tesorería** para que dé el **visto bueno**; **sin él no se puede imprimir**.

## Cómo queda

### Capabilities (doc 02)

| Capability | Quién | Para qué |
|---|---|---|
| `asde_inventario` | Scouter, Secretaría, Coordinación, Tesorería (y administradores) | Ver y editar el inventario, los tipos y crear listas de compra |
| `asde_inventario_aprobar` | Tesorería (y administradores) | Dar o negar el visto bueno a una lista de compra |

Un voluntario sin sección tiene Secretaría, Coordinación o Tesorería, así que entra igual que un scouter.

### Datos (tablas propias, sin datos personales)

- `{prefix}asde_inventario` — un material: grupo, descripción, tipo, cantidad, precio por unidad (opcional), año de compra (opcional), nº de serie (opcional), notas, quién y cuándo lo creó y lo cambió, y la **baja** (no se borra: se da de baja con motivo y se puede recuperar).
- `{prefix}asde_inventario_tipos` — los tipos de cada grupo (nombre único por grupo, sin distinguir mayúsculas ni acentos). Un tipo **en uso no se puede borrar** (se puede renombrar).
- `{prefix}asde_inventario_compras` — las listas: nº `LC` + grupo + número (p. ej. **LC6840003**), líneas (copia de cada material en ese momento: descripción, tipo, nº de serie, cantidad en inventario, cantidad a comprar, precio), total estimado, nota, estado (**pendiente**, **aprobada**, **rechazada**, **cancelada**), quién la revisó, cuándo y el motivo del rechazo, y cuántas veces se ha descargado el PDF.

Todo filtra por el nº de grupo: un grupo no ve el inventario de otro.

### Pantallas (pestaña «Inventario» en *Gestión del grupo*)

1. **Inventario** (`inventario`): lista con buscador y filtro por tipo que **filtran en el navegador sin recargar** (sin JavaScript, el mismo filtro con un botón «Ver»); en móvil, tarjetas. «Añadir material» y «Editar» abren el formulario. Resumen: nº de materiales, unidades y valor estimado (cantidad × precio).
   - En cada fila, una casilla para la **lista de compra**. Al marcar un material con **menos de 5 unidades** sale el aviso «Solo hay N unidades de «X» en el inventario. ¿Seguro que quieres añadirlo a la lista de compra?»; si se cancela, se desmarca. Sin JavaScript, el aviso sale en la pantalla siguiente, al lado de ese material.
2. **Formulario de material**: descripción (obligatoria), tipo (desplegable + **«+»**), cantidad (≥ 0), precio por unidad, año de compra (opcional, de 1950 al año actual), nº de serie, notas. Dar de baja (con motivo) y recuperar.
   - El **«+»** abre la pantalla de **tipos** en la ventana emergente (doc 23). Al cerrarla, el desplegable se actualiza **sin recargar** la página (no se pierde lo escrito) y queda elegido el tipo recién creado. Sin JavaScript, el «+» lleva a la página de tipos.
3. **Tipos de material** (`inventario_tipos`): crear, renombrar y borrar (solo los que no se usan), con cuántos materiales tiene cada uno.
4. **Listas de compra** (`inventario_compras`):
   - **Nueva lista** (con los marcados): cantidad a comprar de cada uno (por defecto 1), precio estimado (el del inventario, se puede cambiar), quitar alguno, nota. Total en vivo.
   - Al guardarla: estado **pendiente** y **email a Tesorería** (todas las cuentas activas con el rol Tesorería del grupo, menos quien la crea; si no hay ninguna con email, al email de contacto del grupo). El aviso dice a cuántas personas se ha enviado. Queda en el registro de emails (tipo «Lista de compra de material»).
   - **Listado** con su estado; el **detalle** se abre en la ventana emergente.
   - **Tesorería**: «Dar el visto bueno» o «Rechazar» (motivo obligatorio). Se puede deshacer el visto bueno mientras no se haya descargado el PDF.
   - **PDF** («Descargar PDF para imprimir»): **solo con el visto bueno**. Antes, el enlace no sale, el servidor lo niega y la página del detalle no se imprime (al imprimirla sale «Pendiente del visto bueno de Tesorería»).
   - Quien la creó puede **cancelarla** mientras está pendiente; Tesorería, mientras no se haya impreso.
5. En **Inicio**, Tesorería ve en «Pendiente de revisar» las listas que esperan su visto bueno, y la pestaña lleva el número: «Inventario (2)».

### El PDF

Lo genera el propio plugin (`Inventario\Pdf`, sin librerías externas: PDF 1.4 con la fuente Helvetica estándar y codificación WinAnsi, así que salen bien los acentos, la ñ y el €). A4 vertical: nombre del grupo, «Lista de compra de material», nº, fecha, quién la pidió, visto bueno de Tesorería (quién y cuándo), tabla (material, tipo, nº de serie, en inventario, a comprar, precio, importe), total estimado, nota y «Página X de Y». Se abre en una pestaña nueva para imprimirlo o guardarlo.

### Auditoría

`inventario_material_creado`, `…_modificado`, `…_baja`, `…_recuperado`, `inventario_tipo_creado`, `…_renombrado`, `…_borrado`, `inventario_compra_creada`, `…_aprobada`, `…_aprobacion_deshecha`, `…_rechazada`, `…_cancelada`, `…_pdf`.

## Supuestos (sin preguntar; a confirmar: P-61 a P-66 del doc 09)

- **S-1.** Todos los que entran en el inventario pueden **añadir, editar y dar de baja** material y gestionar los tipos (no hay un rol solo de lectura).
- **S-2.** El aviso de **< 5** es por la cantidad del material en el inventario; el umbral se puede cambiar con el filtro `asde_ap_inventario_umbral`.
- **S-3.** En una lista solo entra material que ya está en el inventario; para comprar algo nuevo, se da de alta antes (con cantidad 0, y saldrá el aviso).
- **S-4.** Una persona de **Tesorería puede dar el visto bueno a una lista que ha creado ella** (el email va al resto de Tesorería).
- **S-5.** Una lista ya creada **no se edita**: se cancela y se hace otra. El visto bueno o el rechazo **no envía email** a quien la creó (lo ve en el listado).
- **S-6.** Dar el visto bueno **no suma** lo comprado al inventario (posible siguiente paso: «Marcar como comprada» y sumar las cantidades).

## Pruebas

`tests/inventario.php` (CRM simulado): quién entra (y quién no), tipos (duplicados, en uso, renombrar), validaciones del material, baja y recuperación, separación entre grupos, aviso de pocas unidades, lista nueva, email a Tesorería (destinatarios), sin visto bueno no hay PDF, solo Tesorería aprueba, rechazo con motivo, PDF válido (cabecera, tabla de referencias cruzadas con los desplazamientos correctos, texto), cancelar, JSON de tipos para el desplegable, menú e inicio.

**76 comprobaciones, todas OK** (2026-10-05); suite completa: 1297 OK, 0 fallos. Además, el cerrojo del instalador (ver abajo) y que las tres páginas no estén repetidas.

**Revisado a mano** (HTML volcado con datos inventados + CSS de Enfold, servidor estático): las 11 pantallas en escritorio y a 390 px (sin desbordes: la tabla pasa a tarjetas); en el navegador, el aviso «¿seguro?» (cancelar desmarca, aceptar deja marcado, con 5 o más no pregunta), los filtros sin recargar, el total en vivo de la lista nueva y el refresco del desplegable al cerrar la ventana de tipos (no recarga, no se pierde lo escrito y queda elegido el tipo nuevo). El PDF, generado y abierto (acentos, «», €, tabla de 53 líneas en dos páginas).

## Arreglo del instalador (v0.19.0)

Al arrancar Local después de copiar la 0.19.0, **tres peticiones a la vez** vieron la versión nueva y las tres ejecutaron `Installer::install()`: las tres páginas del inventario se crearon **por triplicado** (y las copias salían en el listado «Páginas» del pie, porque solo se ocultan las registradas; lo detectó `tests/alta-reglas.php`). Podía pasar igual en producción al subir el zip si entra alguna visita en ese momento.

- `Installer::maybe_upgrade()` toma ahora un **cerrojo** (`asde_ap_instalando`) con un `INSERT IGNORE` sobre la clave única de `wp_options`, que es atómico. `add_option()` **no** sirve: hace `INSERT … ON DUPLICATE KEY UPDATE` y, con dos peticiones a la vez, devuelve «hecho» a las dos.
- Las demás peticiones no esperan: siguen sin instalar (la siguiente que llegue ya ve la versión nueva). Un cerrojo de más de 5 minutos se da por abandonado.
- Las copias que se crearon en Local se borraron (las no registradas, con el shortcode del inventario).
- Pendiente aparte: `Crm\Instantanea::tomar_cerrojo()` usa `add_option()` y tiene la misma debilidad (lo peor que pasa es que dos peticiones lean el CRM a la vez).
