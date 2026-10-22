# 55 · La tienda sobre el inventario general (v0.51)

**Petición del usuario (2026-10-22).** Las existencias de la tienda (las camisas en concreto) estaban en un inventario propio, aparte del inventario general. Ahora **el inventario general marca qué se vende en la tienda y la tienda toma de él las existencias**. Esto **revierte** la decisión del doc 48 §3.1 («inventario propio de la tienda»), pedida entonces por no depender de lo específico de uniformes; ahora lo que se pide es un único almacén.

## Respuestas (una pregunta cada vez)

1. **Enlace:** una **casilla en cada fila** del inventario («Se vende en la tienda»). Las filas con la misma descripción forman un producto de la tienda, cada una con su «Nombre en la tienda» (talla, color…).
2. **Lo que ya tenía la tienda:** se **pasa al inventario** (una fila por variante, con sus existencias) y la tienda lee de ahí desde entonces.
3. **Sin existencias:** la tienda siempre se limita por la cantidad del inventario, **pero si no quedan se vende igualmente** y lo que falta **se añade al pedido al proveedor** para revisarlo más adelante.

## Diseño

- `asde_inventario`: columnas `en_tienda` y `variante_tienda`. `asde_tienda_variantes`: columna `material` (la fila enlazada).
- `Tienda\InventarioTienda::sincronizar( material )` (al guardar, dar de baja o recuperar una fila): crea o encuentra el producto por su descripción (nuevo: solo socios y precio por confirmar), crea o mueve la variante enlazada y la retira si se desmarca o se da de baja.
- `VariantesTable`: una variante enlazada **lee** su existencia de la fila; `hay()` es cierto mientras esté activa (se puede vender sin existencias); `tomar()` saca lo que haya (`MaterialesTable::restar`, atómico) y devuelve cuánto; `devolver()` y `ajustar()` operan sobre la fila; no se puede bajar de 0.
- `Servicio::registrar`: la línea guarda `reservadas` (lo que salió del almacén) y `faltan` (lo que se pedirá al proveedor). Cancelar devuelve solo `reservadas`.
- `Proveedor::unidades_a_pedir( línea )`: todo si el producto «se encarga al proveedor»; si no, `faltan`. Lo usan «por pedir», las líneas del pedido, el aviso de la gestión y la regla «no se entrega hasta que llega». Lo que llega de más (sobrante) suma a la fila del inventario.
- Pantallas: casilla y «Nombre en la tienda» en el formulario del material; en el producto de la tienda, las existencias de lo enlazado salen en solo lectura y se cambian en el inventario; el catálogo dice «Se encarga al proveedor» cuando no queda nada.
- **Migración** (`InventarioTienda::migrar`, una vez por grupo al actualizar, opción `asde_ap_tienda_inv_<grupo>`): cada variante sin enlazar pasa a ser una fila (nota «Pasado desde la tienda», sin tipo); las «sin límite» pasan con cantidad 0 y su producto deja de pedir todo al proveedor. Los pedidos antiguos no cambian.

## Pruebas

`tests/tienda-inventario.php` (28): marcar y sincronizar, vender con y sin existencias, pedido al proveedor solo con lo que falta, cancelar, sobrante, límites y migración.

## Pendiente / a vigilar

- El importador CSV de productos de la tienda y la casilla «Sin límite» siguen existiendo para variantes **no** enlazadas; si se dejan de usar se pueden retirar.
- Quedan sin uso desde la tienda el tipo «Camisa uniforme» y sus funciones `descontar_camisa`/`devolver_camisa`/`insignias` del inventario (limpieza pendiente desde el doc 48).
