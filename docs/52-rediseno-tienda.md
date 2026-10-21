# 52 · Rediseño de la tienda online (uniforme = una venta más)

**Petición del usuario (2026-10-21).** Sigue al doc 48 (módulo Tienda, v0.39–v0.43). Rama `Tienda-Rediseno` (desde `origin/main`). **Estado: plan, sin código.** Se hace como versiones propias, **independientes del modo Oficina** (docs 50 y 51), que va en la rama `Oficina-Grupo-Modos`.

## 1 · Lo pedido

1. **Todo el uniforme es una venta más.** La tienda no tiene nada específico de uniformes y se alimenta de **un inventario propio, no compartido con el Inventario general** (respuesta: «quitarlo todo»).
2. **Enlace en el menú de la web** que lleve a la tienda para quien no es asociado; **configurable desde el área privada, en la sección de la tienda**.
3. **Varias tallas:** se escribe la **cantidad** y en un **desplegable** se elige la **talla**.
4. **Forma de pago nueva: TPV / datáfono.** Primero el **datáfono físico**; el TPV virtual (tarjeta en la web) queda como fase futura.
5. **El pedido al proveedor se mantiene**, pero como **pestaña aparte** para gestionar el pedido específico de camisas.

## 2 · Qué hay hoy (resumen; detalle en el doc 48 §11)

- Productos con variantes y existencias propias (`asde_tienda_productos`, `asde_tienda_variantes`); visibilidad `todos`/`socios`; precio de socio opcional.
- Compra con sesión (carrito en «Mis pedidos», una cantidad **por variante**) y **sin sesión** en la página pública `/tienda/` (enlace del grupo con clave, interruptor «Abrir la tienda pública»).
- Pagos: transferencia, Bizum y «en físico» (solo socios); Tesorería marca pagado.
- **Lo específico de uniformes que sobrevive** (se quita): `Tienda\Sembrado` (siembra camisa e insignias), reglas de camisa en `Servicio` (`precio_camisa`, primera gratis, «sale del inventario» con `MaterialesTable::descontar/devolver_camisa`), `Config` (opción `primera_gratis`, modelos y tallas), producto con `sale_inventario` y `requiere_proveedor`, `assets/js/uniforme.js`, el formulario de uniforme de `TiendaPedidosView`/`templates/tienda/mis-pedidos.php`, el pedido de un scouter para su sección (`asignar.php`), y los vínculos de `Inventario\TiposTable::camisa()`.

## 3 · Diseño

### 3.1 Una sola venta, un solo inventario (petición 1)
- **Quitar el vínculo con el Inventario general:** desaparece la casilla «sale del inventario» del producto, `descontar/devolver_camisa` y su uso en servir/devolver/asignar; en `Inventario` se retira el tipo «camisa» especial (`TiposTable::camisa()`), el material existente se deja como está.
- **Existencias de la tienda** como único inventario de venta (ya existen: `VariantesTable` con `reservar`, `devolver`, `ajustar` y su historial). Se llama «Inventario de la tienda» (P-175, ya cerrada).
- **Quitar reglas de precio especiales:** primera camisa gratis, 25 € a asociados, precio por modelo. El precio de socio opcional del producto cubre «asociados pagan menos». Se retira `Config::primera_gratis`.
- **Migración sin perder nada** (regla 5): los productos «camisa» e «insignias» ya sembrados siguen existiendo como productos normales; los pedidos antiguos conservan sus líneas y precios tal cual. Los pedidos antiguos que ya salieron del inventario no se tocan. Una opción no usada se borra en la desinstalación.
- **Pedido del scouter para su sección** (`asignar.php`): se retira. Quien compra es la cuenta con sesión; un scouter que quiera comprar para un chico compra como cualquier persona. *(Confirmar P-177.)*

### 3.2 Enlace en el menú de la web (petición 2)
- Ajuste **por grupo** en *Configuración de la tienda*: «Mostrar la tienda en el menú de la web» (apagado por defecto), «Texto del enlace» (por defecto «Tienda») y «Dónde» (el menú principal del tema; por defecto el mismo donde está «Acceso socios»).
- Implementación: en `Frontend\Visibilidad::menu()` (el filtro `wp_nav_menu_objects` ya existe) se **añade** un elemento al final del mismo menú que lleva «Acceso socios», con la URL del `Tienda\Enlace` del grupo (clave incluida). Con varios grupos en el WordPress, **un elemento por grupo que lo tenga activado**, con el nombre del grupo si hay más de uno. No se toca la base de datos de menús de WordPress (el elemento es dinámico, como el de «Acceso socios»).
- Requiere que la tienda pública esté abierta y el módulo encendido; si no, el elemento no aparece. Enlace con `rel="nofollow"` si se quiere (P-178).
- **Nota de seguridad:** al ir la clave en el menú público, deja de ser secreta; es un interruptor (se puede apagar y se puede renovar la clave). El campo trampa, el sello de tiempo y el límite por dispositivo siguen protegiendo la compra.

### 3.3 Cantidad + talla en desplegable (petición 3)
- Hoy el catálogo muestra **una fila por variante** con su cantidad. Nuevo: **una tarjeta por producto**; si el producto tiene más de una variante, una línea con **cantidad** + **desplegable de talla** (con las existencias: «M (3 uds.)», «L (agotada)» deshabilitada) y botón **«Añadir»**; se pueden añadir varias líneas del mismo producto con tallas distintas. Un producto con una sola variante (sin talla) solo lleva la cantidad.
- **Carrito:** lista de líneas editables (cantidad, quitar), total y el paso de pago. En el navegador (sin recargar: `parcial.js` o el JS propio de la tienda); **sin JavaScript** funciona con un formulario normal (la línea se envía y la página recarga).
- Misma pantalla para las dos compras (con sesión y pública); validación en el servidor como hoy (precio y existencias siempre del servidor; máximo por persona).
- Móvil primero: línea en una columna a 390 px.
- *El usuario enseñará/aprobará la vista* (regla de pantallas nuevas): el diseño se ve antes de cerrar y se pregunta qué cuadros empiezan desplegados.

### 3.4 TPV / datáfono (petición 4)
- Nuevo método de pago `tpv` («Datáfono / TPV») en `PedidosTable::METODOS`, junto a transferencia, Bizum y efectivo.
- **Datáfono físico:** se elige al pedir; se cobra **en persona** con el datáfono del grupo; **Tesorería marca pagado** (método `tpv`, fecha, importe, referencia opcional del ticket). Sale en *Mis pagos*, el historial de la familia, el informe de ventas «por forma de pago» y el banco (no concilia solo: no es un ingreso de la cuenta hasta que el banco lo abona; P-179 por si se quiere conciliar el abono global).
- **Quién puede elegirlo (P-180, propuesta):** socios con sesión **y** invitados (a diferencia de «en físico», que es solo para socios), porque se paga en la entrega y se identifica por la referencia del pedido. Ajuste por grupo «Aceptar datáfono» (apagado por defecto).
- **TPV virtual (futuro):** pasarela con redirección (Redsys u otra) y confirmación por notificación del banco, marcando pagado automáticamente. No se hace ahora; el diseño del método deja un hueco (`tpv_virtual`).

### 3.5 Pedido al proveedor en su propia pestaña (petición 5)
- Pestaña **«Pedido de camisas»** (y otros productos con «se encarga al proveedor») en `templates/tienda/subnav.php`, con `Tienda\Servicio` reutilizado: lista de las **líneas pagadas pendientes de pedir** agrupadas por producto y talla (el resumen que hoy está dentro de la gestión), botón **«Crear el pedido al proveedor»** (selecciona las líneas, estado «En el pedido»), seguimiento (**pedido hecho → recibido en el grupo → entregado**), y Excel/PDF del pedido para enviar.
- El resto de la gestión (pedidos de clientes, pagos, entrega) ya no lleva ese bloque. Capability: `asde_tienda_pedido` (Coordinación, como hoy) y lectura para Tesorería.
- Cuando llega el pedido, **suma a las existencias de la tienda** lo que sobra del cliente (o queda reservado para los pedidos que lo esperaban). *(Confirmar P-181.)*

## 4 · Fases (una versión cada una, rama `Tienda-Rediseno`)

| Fase | Qué | Versión | Pruebas |
|---|---|---|---|
| T1 | Quitar lo específico de uniformes y el vínculo con el Inventario general (migración segura) | v0.44.x | `tienda-pedidos`, `tienda-catalogo` y `migracion-tienda` actualizadas + `tienda-generica.php` nueva |
| T2 | Cantidad + talla en desplegable, carrito de líneas | siguiente | `tienda-lineas.php`, banco JS estático, 390 px |
| T3 | Método TPV/datáfono físico (ajuste, pedido, marcar pagado, informes, Mis pagos) | siguiente | `tienda-tpv.php` |
| T4 | Pestaña «Pedido de camisas» | siguiente | `tienda-proveedor.php` |
| T5 | Enlace en el menú de la web (ajuste en la configuración de la tienda) | siguiente | `tienda-menu.php` (filtro `wp_nav_menu_objects`) |
| T6 | Textos ca/eu/gl, ayuda y manuales, capturas, docs, mapa y revisión | siguiente | `idiomas-catalogos`, Plugin Check 0 |
| T7 (futura) | TPV virtual (pasarela) | — | — |

Orden recomendado: T1 → T3 → T2 → T4 → T5 → T6 (T3 es pequeña y se puede hacer antes de rehacer la pantalla).

## 5 · Preguntas abiertas

- **P-177 — Pedido del scouter para su sección:** al quitar lo específico de uniformes, se retira. ¿Conforme? *(Propuesta: sí.)*
- **P-178 — Enlace en el menú:** ¿un solo elemento «Tienda» en el menú principal (o uno por grupo con varios grupos)? ¿se abre en la misma pestaña?
- **P-179 — Datáfono y banco:** Tesorería marca pagado a mano; ¿hace falta una conciliación del abono global del datáfono con el banco? *(Propuesta: no por ahora.)*
- **P-180 — Quién elige datáfono:** socios e invitados (propuesta), o solo socios como «en físico».
- **P-181 — Pedido al proveedor:** lo recibido que sobra, ¿suma a las existencias?
- **P-182 — Camisas y reglas de precio:** al retirar «primera camisa gratis» y «25 € a asociados», ¿quién fija los precios nuevos? *(Propuesta: Tesorería/Coordinación en el producto, con precio de socio.)*

## 6 · Respuestas del usuario (2026-10-21)

- Lo específico de uniformes se **quita todo** (opción recomendada), con inventario propio de la tienda.
- Enlace en el menú de la web, **configurable desde la sección de la tienda online** del área privada.
- TPV: **ambos, primero el físico**.
- Cantidad + talla en desplegable, y pedido al proveedor como pestaña aparte para el pedido de camisas.
