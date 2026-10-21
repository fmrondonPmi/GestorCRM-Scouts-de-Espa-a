# 54 · Pantallas: filtros y acciones en una línea (regla del 2026-10-21)

**Regla del usuario.** En **todas** las pantallas, actuales y nuevas, los campos de filtrado y de acción van **en una sola línea siempre que quepan**, como los filtros de Personas. **Reducir campos es lo primero**: lo que pueda ser un **desplegable** se hace desplegable; si cabe en una línea y no es mucha información, va en una línea. Solo en el móvil (≤ 700 px) pueden bajar a otra línea, sin desbordar. Está en el `CLAUDE.md` («Convenciones de interfaz») y en la skill de usuario `pantallas-area-privada` (con el procedimiento de revisión).

## 1 · Cómo se aplica (v0.48.1)

- **Filtros (GET):** `asde-filter asde-filter--una-linea` en **todos** los filtros del área (11 plantillas más que antes: eventos/menu, inventario/compras y lista, menús/ingredientes, receta y recetas, tienda/gestión, informe y productos, usuarios/emails, tesorería/familia-editar). Sin salto de línea hasta 700 px.
- **Acciones (POST):** `asde-form__fila asde-form__fila--una-linea` para una fila de campos con su botón al final; `asde-form--compacta` pasa a ser **una fila flexible** (etiquetas junto a su campo, ancho justo, botón al final; ayudas, avisos y `<details>` en su propia línea).
- **CSS general** (al final de `area-privada.css`): toda `asde-form__fila` y `asde-form--compacta` neutraliza el `#top input { width: 100% }` de Enfold, enseña la etiqueta junto al campo, reparte el sitio entre los campos de texto y no parte los botones.

## 2 · Reducción de campos hecha

| Pantalla | Antes | Ahora |
|---|---|---|
| Tienda · validar el pago | 4 botones de radio (efectivo, ingreso, Bizum, datáfono) + 3 campos + botón en 3 líneas | **«Cómo» en un desplegable** + fecha + importe + referencia + botón, en una fila |
| Tesorería · ajuste de la familia | Radios «Descuento / Recargo» + 2 campos apilados | Desplegable «Tipo» + importe + motivo + botón, en una fila |
| Eventos · ajuste de una inscripción | Radios «Rebaja / Recargo» | Desplegable «Tipo» |
| Reintegros · pedir | 6 bloques apilados con radios | Dos filas: concepto, fecha, importe y evento; tique, **forma en un desplegable**, IBAN (solo si es transferencia) y botón |
| Lista de espera · apuntar un contacto y cambiar el estado | Campos apilados, una nota en `textarea` | Desplegable + nota + botón en una fila |
| Eventos · reservar material | Material y cantidad apilados | Material + cantidad + botón en una fila |

## 3 · Lo que se deja como está (y por qué)

- Formularios de **alta y edición de datos** (acceso, activar cuenta, alta de scouters, solicitar acceso, ficha, alta de socio, evento, producto, configuración de la tienda y de eventos, cuotas, coste federativo, SEPA…): no son filtros ni acciones sueltas; tienen muchos campos obligatorios que no caben en una línea.
- Formularios con **un solo botón** (borrar, descargar, anular, marcar enviada…): ya son una línea.
- **Membresía, solicitudes de acceso, informes (editor), inventario/material y alta de socio:** flujos en ventana con cuadros de varios pasos; se pueden pasar a una línea después si el usuario lo pide pantalla a pantalla (la regla ya vale para lo que se haga nuevo).

## 4 · Comprobación

Banco estático con la hoja de estilo real y un `#top input { width: 100% }` como el de Enfold: el filtro y las filas de acción van en una línea a escritorio, bajan sin desbordar a 390 px. Pruebas del área al día (tienda, reintegros, lista de espera, eventos, banco).
