# 24 · Pedidos de uniforme y «Mis pagos» (v0.11)

## Petición del usuario (2026-09-29)

- Un **gestor de pedidos de uniforme**: los asociados piden la **camisa scout** (25 €) y pueden **añadir insignias**. Las imágenes, con su nombre, las dejó en `C:\tmp\borrame`.
- Para los **scouters**, una **pantalla de gestión** que indique:
  - que **Tesorería** ha validado el pago (en **efectivo** o por **ingreso**);
  - que **Coordinación** ha validado que la camisa de cada asociado (**scouters incluidos**) está en el **pedido realizado**;
  - un check de **entregada**, que marca el grupo y **confirma el asociado**.
- En la vista de los asociados, **el registro de sus pagos**, en su propia pantalla y **solo para consultar** lo que carga Tesorería.

## Qué se puede pedir

| | Detalle |
|---|---|
| **Camisa scout** | 25 € (configurable). Dos modelos, con las tallas de las tablas de medidas que dio el usuario: **masculina** (6, 8, 10, 12, 14, 16, XS, S, M, L, XL, XXL, 3XL) y **femenina** (12, 14, 16, XS, S, M, L, XL, XXL, 3XL). La 3XL es «talla especial» en las dos tablas y sale marcada |
| **Insignias** | Catálogo configurable por grupo: nombre, dónde va, precio e imagen. En Myotragus: **insignia de grupo** (GS Myotragus 684) e **insignia mundial** (flor de lis). Sin precio, salen como «precio por confirmar» (ver abajo). De 0 a 10 de cada una |
| **Guías** | «Guía de tallas» con la imagen de medidas de cada modelo, y «Dónde va cada insignia» con el mapa de la camisa. Se abren dentro del formulario |

- Un pedido es para **una persona** e incluye la camisa, insignias o las dos cosas. Para dos camisas, dos pedidos.
- **Nº de pedido:** `U` + grupo + número (p. ej. **U6840007**). Es la referencia para pagarlo por ingreso.
- **Precio por confirmar:** una insignia sin precio se puede pedir. El total sale «25,00 € + insignias (precio por confirmar)». Cuando Tesorería pone el precio, los pedidos que ya había lo toman al validar el pago (el importe propuesto se calcula con los precios de hoy). Tesorería siempre puede escribir el importe que ha cobrado de verdad.

## Cómo lo pide un asociado (pestaña «Uniforme»)

1. **Para quién:** la propia persona (si tiene membresía activa) o, en una cuenta familiar, cada uno de sus menores. Los scouters piden la suya igual que los demás. **v0.30 (P-48, doc 37):** un scouter pide también para cualquier socio/a con membresía activa de sus secciones (el precio de la camisa, el de esa persona); con más de 5 personas, «¿Para quién?» es un desplegable agrupado por sección.
2. **Formulario:**
   - camisa sí o no, con el modelo y la talla (con la guía de tallas);
   - insignias con su imagen, dónde va, precio y cantidad (con la guía de colocación);
   - observaciones;
   - **total en vivo** (sin JavaScript, el total se ve al enviarlo).
3. Al enviarlo: «Pedido **U6840007** enviado: 27,50 €. Ahora falta pagarlo; cuando Tesorería lo reciba, lo verás aquí como pagado».
4. **Tus pedidos:** cada uno con sus pasos (Solicitado → Pagado → En el pedido del grupo → Entregado → Recibido), cada paso con su fecha.
   - **Cancelar el pedido**, solo mientras nadie lo ha validado.
   - Cuando el grupo lo marca como entregado: botón **«Lo he recibido»**, y en *Inicio* un aviso: «El grupo te ha entregado un pedido de uniforme. Confirma que lo has recibido».
5. **Cerrado:** si Tesorería o Coordinación cierran los pedidos, el formulario dice «Ahora mismo no se aceptan pedidos de uniforme».

## Las cuatro comprobaciones

| Comprobación | Quién | Cómo |
|---|---|---|
| **Pago** | **Tesorería** (`asde_uniformes_pago`) | Forma de pago (**efectivo** o **ingreso en cuenta**), fecha (no posterior a hoy), importe (propone el total con los precios de hoy) y referencia opcional. Se puede deshacer |
| **Incluido en el pedido del grupo** | **Coordinación** (`asde_uniformes_pedido`) | Uno a uno o **varios a la vez**, con el nombre del pedido al proveedor («Pedido 29/09/2026»). Vienen marcados los ya pagados; si incluye uno sin pagar, se avisa. Se puede deshacer |
| **Entregado** | **El grupo**: el scouter de la sección, Tesorería, Coordinación o Secretaría (`asde_uniformes_entrega`) | Botón «Marcar entregado» en la lista o en el detalle. Si no consta en ningún pedido, se avisa. Se puede deshacer, y eso borra también la confirmación |
| **Recibido** | **El socio/a o su familia**, en su pestaña *Uniforme* | Solo cuando está entregado. Nadie más lo puede marcar |

- Cada comprobación guarda **quién y cuándo**, y todo queda en la auditoría: `uniforme_pedido`, `uniforme_pago`, `uniforme_incluido`, `uniformes_incluidos`, `uniforme_entregado`, `uniforme_recibido`, `uniforme_cancelado`, los `…_deshecho`, `uniformes_configurados` y `uniformes_exportados`.
- **Cancelar:**
  - el socio/a o su familia, mientras nadie lo ha validado;
  - Tesorería o Coordinación, con un motivo, hasta que se entrega.
  - Los cancelados no se borran.

## Quién ve qué

| Rol | Ve | Puede |
|---|---|---|
| Familias y participantes | Sus pedidos (y los de sus menores) | Pedir, cancelar mientras nadie lo ha validado, confirmar que lo han recibido |
| Scouter | Los de socios **de sus secciones** que no son adultos, y los suyos | Marcar entregado (en su ámbito) |
| Tesorería | **Todos** los del grupo | Validar el pago; entregar; cancelar; configurar |
| Coordinación | **Todos** | Incluir en el pedido del grupo; entregar; cancelar; configurar |
| Secretaría | **Todos** | Entregar |

Capabilities nuevas (doc 02): `asde_uniformes_pedir`, `asde_uniformes_gestion`, `asde_uniformes_pago`, `asde_uniformes_pedido`, `asde_uniformes_entrega`, `asde_uniformes_config` y `asde_mis_pagos`.

## Gestión de pedidos (pestaña «Uniformes»)

- **Resumen:** pendientes de pago, de incluir en el pedido, de entregar y entregados sin confirmar (cada uno lleva a su lista), y lo cobrado y por cobrar.
  - «Pendientes de entregar» son los que **ya están en un pedido del grupo** y aún no se han entregado: lo que se puede repartir. Solo en esos sale el botón «Marcar entregado» de la lista. Desde el detalle se puede entregar cualquiera, con un aviso si no consta en ningún pedido.
- **Filtros:**
  - estado: en curso, cada pendiente, recibidos, cancelados o todos;
  - sección;
  - nombre o nº de pedido.
- **Lista:** pedido (abre el **detalle en la ventana emergente**, doc 23), socio/a con su sección, qué pide, importe y una columna por comprobación. Tesorería tiene «Validar» en los que están sin pagar; quien entrega, «Marcar entregado».
- **Pedido en lote** (Coordinación): marca los pedidos, pon el nombre del pedido al proveedor y pulsa «Incluir los marcados en el pedido».
- **Para el próximo pedido al proveedor** (Tesorería, Coordinación y Secretaría): lo pagado que aún no está en ningún pedido, por **modelo y talla** («Camisa masculina: 10 × 3 · M × 2») y las insignias, y cuántos hay sin pagar.
- **Descargar CSV** de la lista filtrada: nº, socio/a, sección, modelo, talla, insignias, importe, pago, pedido, entrega, recepción y estado. Queda en la auditoría.
- En *Inicio*, el recuadro «Pendiente de revisar» dice cuántos le tocan a cada uno. La pestaña lleva el número: «Uniformes (3)».

## Configuración (Tesorería y Coordinación)

*Uniformes → Configuración*:

- **Pedidos abiertos** (sí o no).
- **Precio de la camisa.**
- **Cómo pagar:** un texto que ven las familias, p. ej. dónde y cuándo se paga en efectivo y la cuenta para el ingreso.
- **Modelos y tallas:** nombre, tallas separadas por comas e imagen de la guía de tallas de cada modelo; y las tallas especiales.
- **Insignias:** nombre, dónde va, precio (vacío = por confirmar), imagen, si se puede pedir, quitarla; y hasta dos nuevas cada vez.
- **Guía de colocación:** la imagen de dónde va cada insignia.
- **Imágenes:** JPG, PNG, WebP o GIF de hasta 3 MB, que se guardan en la biblioteca de medios de WordPress. No se admite SVG, por seguridad.
- La configuración es **de cada grupo** (opción `asde_ap_uniformes_<grupo>`), porque cada uno tiene su insignia.

### WordPress local (2026-09-29)

- Configurado el grupo 684 con las imágenes del usuario (adjuntos 451 a 455 de la biblioteca de medios): camisa a 25 €, las dos guías de tallas y el mapa de colocación.
- **Insignia de grupo** (GS Myotragus 684): en el **brazo derecho**. Es donde la guía pone la insignia de la federación, y la de Myotragus la lleva.
- **Insignia mundial** (flor de lis): en el **pecho izquierdo**, como indica la guía.
- Las dos, **sin precio** (por confirmar, P-38).

## «Mis pagos» (pestaña «Mis pagos»)

> **v0.12 (2026-09-30):** «Mis pagos» ya no es solo de consulta: cada familia puede **firmar su autorización de domiciliación SEPA**, verla, imprimirla o anularla, y ve el aviso del **próximo cargo** (doc 25). Los pagos cobrados por domiciliación salen con la forma «Domiciliación SEPA».

Para cualquier cuenta con ficha propia o con menores a cargo, de consulta (`MisPagosView`):

- **Cuota del grupo de la ronda en curso**, por cada familia de Tesorería a la que pertenecen la persona o sus menores:
  - a pagar, pagado y pendiente, con el estado;
  - los **plazos** (hasta qué fecha, importe y si está pagado o vencido);
  - la referencia de la familia para la transferencia (F684001).
- **Pagos registrados**, de todas las rondas:
  - los pagos de la cuota (fecha, ronda, familia, forma de pago, referencia e importe);
  - los pagos del uniforme.
- **Pendiente de pago:** los pedidos de uniforme aún sin pagar.
- **Lo que no se enseña:**
  - las **notas internas** de Tesorería;
  - los **ajustes** uno a uno (descuentos o becas con su motivo), que sí se ven en «a pagar»;
  - los pagos **anulados**;
  - quién los registró.
- Los pagos del uniforme **no** entran en la cuota: son aparte (los ingresos del banco se siguen conciliando solo con la cuota, P-41).

## Qué se guarda

| Dónde | Qué |
|---|---|
| Tabla `{prefix}asde_uniformes` | Grupo, **nº Scouts de España** del socio/a (ni nombre ni otros datos), sus secciones y si es adulto (para saber quién lo gestiona sin consultar el CRM), las líneas del pedido (modelo, talla, insignias y el precio de cada cosa ese día), el total, las observaciones y, de cada comprobación, quién, cuándo y sus datos (forma de pago, importe, referencia, nombre del pedido, motivo de la cancelación) |
| Opción `asde_ap_uniformes_<grupo>` | La configuración del grupo |
| Biblioteca de medios | Las imágenes |

- La tabla se crea al actualizar (14 tablas en «Instalación y entorno» desde la v0.12, doc 19).
- Al desinstalar se conserva la tabla y se borra la configuración; las imágenes se quedan en la biblioteca.

## Pruebas (2026-09-29)

`tests/uniformes.php`, **53 comprobaciones, todas OK**. Usa el CRM simulado con fichas inventadas y guarda las cachés solo en la memoria del proceso (doc 10), así que la web no ve nada de la prueba. Lo que cubre:

- A · precios, tallas, validación del pedido y precio por confirmar;
- B · pedir (familia, participante, scouter), para quien no es de la familia, cancelar, pedidos cerrados;
- C · quién gestiona qué, pendientes y pestañas;
- D · pago (sin permiso, fecha futura, deshacer, efectivo e ingreso), pedido en lote, entrega de su sección y de otra, confirmación de otra familia y de la suya, aviso en el inicio, cancelar con motivo;
- E · «Mis pagos»: cuota, pagos, uniforme, sin notas internas ni anulados, plazos, sin familia, otra familia;
- F · pantallas: formulario, lista, detalle en la ventana, configuración.

## Pendiente

- **P-38:** precio de cada insignia (hoy «por confirmar»).
- **P-39:** confirmar dónde va la insignia de grupo (se ha puesto en el brazo derecho).
- **P-40:** ¿la talla especial (3XL) cuesta lo mismo o lleva recargo?
- **P-41:** ¿avisar por email en cada paso (pagado, entregado) y enlazar los ingresos del banco con los pedidos de uniforme?

## Tablas de medidas de la camisa en el pedido (v0.12.5)

- Debajo de «Modelo y talla» el formulario lleva **«Tabla de medidas: Camisa masculina · Camisa femenina»**. Cada enlace abre la tabla en una **ventana encima del pedido** (`dialog`, `assets/js/uniforme.js`): no se sale de la página ni se pierde lo rellenado. Se cierra con la ×, con Esc o pulsando fuera; «Abrir en otra pestaña» la muestra grande. En el móvil ocupa toda la pantalla y se desplaza. Sin JavaScript, el enlace abre la imagen en otra pestaña.
- Las dos tablas de ASDE (medidas A, B, C… de cada talla) **van dentro del plugin**: `assets/img/medidas-camisa-femenina.png` y `assets/img/medidas-camisa-masculina.png` (`Config::guia_medidas`). Si Coordinación sube otra imagen en *Uniformes → Configuración → Guía de tallas*, esa tiene preferencia.
- Al elegir una talla se destaca (subrayado) el enlace de su modelo.
- «Dónde va cada insignia» usa la misma ventana (antes era un desplegable que alargaba el formulario).
