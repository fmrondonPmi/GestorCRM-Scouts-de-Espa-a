# 48 · Módulo «Tienda» (estado en la v0.51)

La tienda vende lo que el grupo tenga: el uniforme es **un producto más**, sin reglas propias. Este documento describe cómo funciona hoy; los detalles de las tres piezas nuevas están en los docs 53 (Reintegros), 54 (pantallas en una línea) y 55 (la tienda sobre el inventario general).

## Quién ve y hace qué

| Rol | Hace |
|---|---|
| Visitante sin sesión | Ve los productos «para todos» en la tienda pública (enlace del grupo, que puede ir también en el menú de la web) y compra por transferencia, Bizum o datáfono |
| Socio/a o familia | Ve también los «solo socios»; paga además «en físico» a Tesorería. Ve y cancela sus pedidos y confirma «Lo he recibido» |
| Scouter | Compra como socio; ve los pedidos de sus secciones y los entrega |
| Coordinación, Tesorería, Secretaría | Productos, configuración y todos los pedidos del grupo; entregar; cancelar con motivo |
| Tesorería | **Marca pagado** (forma, fecha, importe, referencia) y concilia el banco |
| Coordinación | Hace y recibe los pedidos al proveedor |

Capabilities: `asde_tienda_comprar`, `_gestion`, `_admin`, `_pago`, `_entrega`, `_pedido`. Módulo `tienda` (Domain\Modulos): si está apagado en un grupo, las páginas públicas no existen.

## Productos, variantes y existencias

- **Producto** (`asde_tienda_productos`): nombre, categoría, descripción, imagen, visibilidad (`todos` / `socios`), precio, precio de socio opcional, máximo por persona y «se encarga al proveedor».
- **Variante** (`asde_tienda_variantes`): lo que se compra (talla, color o «Única»), precio propio opcional y existencias.
- **Las existencias vienen del inventario general** (doc 55): una fila del inventario marcada «Se vende en la tienda» es una variante. Se vende aunque no queden; lo que falta va al pedido al proveedor. Una variante sin enlazar (poco habitual) lleva sus propias existencias, con la opción «sin límite».
- Precio y existencias son siempre del servidor; la reserva descuenta con una sola sentencia para no vender de más.

## Una compra

1. Catálogo: **cantidad** de cada producto y, si tiene tallas, **talla en un desplegable** («Añadir otra talla»); el mismo formulario sirve con y sin sesión.
2. ¿Para quién? (cuenta familiar: cada hijo; invitado: nombre, email y teléfono, cifrados y con caducidad de 90 días tras entregar o cancelar).
3. Forma de pago: transferencia, Bizum, **datáfono** (si el grupo lo ofrece; lo marca pagado Tesorería al cobrar) o en físico (solo socios).
4. Se crea el pedido (nº tipo `U9990167`), salen las unidades del inventario y llega un email con cómo pagar.
5. Tesorería valida el pago → si lleva algo que pedir al proveedor, Coordinación lo incluye en un **pedido al proveedor** (pestaña «Pedido de camisas»; Excel; marcar recibido; lo que llega de más suma al inventario) → se **entrega** (no antes de que llegue el pedido al proveedor) → el socio confirma «Lo he recibido».
6. Cancelar: el comprador mientras no esté pagado; la administración con motivo hasta que se entregue. Vuelve al inventario solo lo que había salido. Sin pagar tras N días: recordatorio y cancelación automática (configurable).

## Pagos con datáfono y banco

Con el banco activo, Tesorería ve los ingresos que parecen el abono del datáfono (cobros de los últimos 10 días menos la comisión, hasta un 5 %) y los ingresos que citan el nº de un pedido sin pagar, y confirma cada uno: nada se concilia solo.

## Configuración (por grupo)

Pedidos abiertos, datáfono, IBAN, Bizum, texto de cómo pagar, días para recordar y para cancelar lo no pagado, tienda pública abierta (con sus días para pagar y el enlace, renovable) y el **texto del enlace en el menú de la web** (vacío = sin enlace). Productos y precios no se configuran aquí: están en «Inventario de la tienda».

## Seguridad de las páginas sin sesión

- «Solo socios» se filtra en la consulta, no en la plantilla; un visitante recibe 404.
- Compra de invitado: nonce, campo trampa, sello de tiempo y límite por dispositivo; «en físico» y «solo socios» se validan en el servidor.
- El enlace del grupo lleva una clave renovable; el de cada compra, un token HMAC propio. Si la clave está en el menú público deja de ser secreta: se cierra renovándola o apagando la tienda pública.
- Auditoría: `tienda_pedido`, `_pagado`, `_entregado`, `_cancelado`, `_producto_*`, `_existencias`, `_proveedor_*`.

## Pedidos antiguos

Los hechos antes de la v0.44 (con camisa e insignias en sus líneas) se siguen viendo, cobrando, entregando y confirmando.
