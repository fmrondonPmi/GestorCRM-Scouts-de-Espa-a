# 48 · Módulo «Tienda» (antes «Uniformes») — análisis y plan

> Estado: **plan aprobado para ejecutar más adelante** (2026-10-17). No hay código hecho.
> Parte de la v0.38.0. Se ejecuta en una rama nueva desde `origin/main` (p. ej. `Tienda`), con las skills `modulo-area-privada` y `version-area-privada`.

## 1. Petición del usuario (2026-10-17)

- Renombrar el módulo **Uniformes** a **tienda online** y que siga siendo un módulo de la aplicación.
- **Coordinación, Tesorería y Secretaría** tendrán el rol de **administración** de la tienda. Esos roles pueden **cargar inventario para vender**.
- Hay elementos **de venta solo para socios**: solo aparecen cuando el usuario ha iniciado sesión.
- Pago de cada compra: **transferencia**, **Bizum** o **en físico entregándolo a Tesorería**.
  - **Es el tesorero quien marca el elemento como pagado.**
  - **El pago en físico es solo para socios.**

Interpretaciones que he hecho (confirmar en las preguntas, §9): «Ordination» = **Coordinación**; «gestando» = **entregando** el dinero a Tesorería.

## 2. Qué hay hoy (análisis del módulo Uniformes, docs 24 y 36)

| Pieza | Dónde | Notas para la tienda |
|---|---|---|
| Lógica | `src/Uniformes/` (`Servicio` 576 líneas, `PedidosTable` 376, `Config` 194) | Está hecha **solo para camisa + insignias**: modelos y tallas, insignias con precio «por confirmar», primera camisa de scouter gratis, camisa servida del inventario |
| Tabla | `{prefix}asde_uniformes` | Un pedido = **una persona** (nº ASDE) + `lineas` (JSON con camisa e insignias). Requiere socio: no admite compradores sin ficha |
| Pantallas | `UniformeView` (pedir y mis pedidos), `UniformesGestionView` (lista, lote, CSV), `UniformesConfigView` + 6 plantillas en `templates/uniformes/` y `assets/js/uniforme.js` | Todas **privadas** (hay que tener sesión) |
| Páginas | `uniforme`, `uniformes` (`gestion-pedidos`), `uniformes_config` en `Frontend\Pages` / `Router` | No hay ninguna pública para esto |
| Permisos | `asde_uniformes_{pedir,gestion,pago,pedido,entrega,config}`; Tesorería = `pago`, Coordinación = `pedido`; los dos con `config` | Tesorería **ya es la única que valida el pago** (se mantiene) |
| Pago | Solo `efectivo` e `ingreso` (`PedidosTable::METODOS`) | **Falta Bizum**; falta distinguir quién puede elegir «en físico» |
| Pasos | Solicitado → Pagado → En el pedido del grupo → Entregado → Recibido | «Pedido del grupo» = pedido al proveedor; sobra para artículos que se venden del stock |
| Inventario | Módulo `inventario` (material del grupo). Una camisa puede **salir del inventario** (v0.29) | Es el **material del grupo**, no el catálogo de venta: no confundir (ver §4.2) |
| Módulo | `Domain\Modulos::UNIFORMES = 'uniformes'`, encendido por defecto; opción `asde_ap_uniformes_<grupo>` | Al renombrar hay que migrar la clave y la opción |
| Cobros reutilizables | `Eventos\Cobros` ya cobra por **efectivo, ingreso y Bizum** (P-146) con referencia de familia | Copiar el patrón (textos, validación, referencia) |
| Pagos | `Tesoreria\HistorialPagos` y `MisPagosView` leen `PedidosTable` | Hay que apuntarlos a las tablas nuevas |
| Páginas públicas | `InscripcionEsperaView` / `AltaScoutersView`: enlace del grupo con clave, campo trampa, sello de tiempo y límite por dispositivo | Patrón para el **catálogo público** y la compra de quien no es socio |
| Grupo en visitas sin sesión | `Domain\Grupo::actual()` toma el primer grupo si no hay sesión | La tienda pública necesita saber de **qué grupo** es (enlace `?grupo=`) |

**Conclusión:** el módulo actual es un caso particular (uniforme) de una tienda. Se puede **generalizar**: la camisa y las insignias pasan a ser productos con variantes. No basta con cambiar el nombre.

## 3. Requisitos (cerrados, con lo que hay que decidir marcado)

| # | Requisito | Cómo se cumple |
|---|---|---|
| R1 | El módulo se llama **Tienda** | Nombre visible, menú, páginas, textos, ayuda y manuales. Clave interna `tienda` |
| R2 | Administración: Coordinación, Tesorería, Secretaría | Capability nueva `asde_tienda_admin` en esos tres roles |
| R3 | La administración **carga inventario para vender** | Alta de productos, variantes, precio y existencias; carga por CSV; ajustar existencias |
| R4 | Productos **solo para socios** | Campo «Visible para» por producto: **todos** o **solo socios**. Los de solo socios no existen para quien no ha iniciado sesión (ni en la lista, ni por enlace directo) |
| R5 | Pago: **transferencia, Bizum o en físico** | Tres formas de pago por pedido, con los datos de pago en la configuración del grupo |
| R6 | **Tesorería marca como pagado** | Capability `asde_tienda_pago` solo en Tesorería. Nadie más marca pagado |
| R7 | **En físico, solo socios** | La opción solo sale a quien tiene sesión y es socio/a (o su familia). Se vuelve a comprobar en el servidor |

## 4. Diseño

### 4.1 Quién ve y hace qué

| Rol | Hace |
|---|---|
| Visitante sin sesión | Ve los productos «para todos». Compra (según P-174) por transferencia o Bizum |
| Socio/a o familia (con sesión) | Ve también los «solo socios». Compra por transferencia, Bizum o en físico. Ve y cancela sus pedidos |
| **Administración** (Coordinación, Tesorería, Secretaría) | Productos y existencias; ver todos los pedidos del grupo; marcar **entregado**; cancelar con motivo (antes de entregar); configurar la tienda |
| **Tesorería** (además) | **Marcar pagado** (forma, fecha, importe, referencia); deshacerlo |
| Scouter | Compra como socio; ve los pedidos de su sección (como hoy) |

Capabilities nuevas (renombran las `asde_uniformes_*`; se migran al subir la versión):

| Nueva | Sustituye a | Roles |
|---|---|---|
| `asde_tienda_comprar` | `asde_uniformes_pedir` | todos con acceso |
| `asde_tienda_gestion` | `asde_uniformes_gestion` | Scouter, Secretaría, Coordinación, Tesorería |
| `asde_tienda_admin` | `asde_uniformes_config` (+ nuevo) | **Coordinación, Tesorería, Secretaría** |
| `asde_tienda_pago` | `asde_uniformes_pago` | **solo Tesorería** |
| `asde_tienda_entrega` | `asde_uniformes_entrega` | Scouter (su sección), Secretaría, Coordinación, Tesorería |
| `asde_tienda_pedido` | `asde_uniformes_pedido` | Coordinación (pedido al proveedor; solo para productos que lo piden) |

> Secretaría **gana** administración (hoy no tiene `config`); Coordinación y Tesorería la conservan.

### 4.2 Inventario para vender ≠ módulo «Inventario»

El módulo **Inventario** es el material del grupo (tiendas de campaña, etc.). El catálogo de la tienda es otra cosa. Para evitar mezclas:

- El catálogo vive en la tienda (**«Productos»**) con sus **existencias**; no se mezcla con el material.
- **Se mantiene el vínculo que ya existe** (v0.29): un producto puede marcar «sale del inventario del grupo» (la camisa) y descuenta de `MaterialesTable`. Es opcional y solo se muestra con el módulo Inventario activo.
- Se evita llamarlo «inventario» en la interfaz de la tienda: se dice **«Productos» y «Existencias»** (P-175 por si se prefiere otro nombre).

### 4.3 Modelo de datos

Tablas nuevas (nacen con `dbDelta`; el módulo `uniformes` migra a ellas):

| Tabla | Para qué |
|---|---|
| `asde_tienda_productos` | grupo, nombre, descripción, categoría, imagen (adjunto), **visibilidad** (`todos` / `socios`), precio, `activo`, límite por persona, **requiere pedido al proveedor** (sí/no), vínculo opcional con inventario |
| `asde_tienda_variantes` | producto, etiqueta (talla/modelo), precio propio opcional, **existencias**, `sin límite` |
| `asde_tienda_pedidos` | grupo, comprador (nº ASDE **o** invitado: nombre, email y teléfono **cifrados** y con caducidad), total, forma de pago, estado, referencia (`T` + grupo + nº), notas y los pasos con quién y cuándo (pagado, pedido, entregado, recibido, cancelado) |
| `asde_tienda_lineas` | pedido, producto/variante, cantidad, **precio de ese día** (se guarda, como hoy) |

Reglas del proyecto que se mantienen: del socio solo su nº ASDE; **nada de salud**; lo personal del invitado, **cifrado y con caducidad** (se purga en la tarea diaria); un grupo no ve a otro.

Configuración del grupo (opción `asde_ap_tienda_<grupo>`, migrada de `asde_ap_uniformes_<grupo>`): tienda abierta o cerrada; texto de cómo pagar; **IBAN y titular** (transferencia); **nº de teléfono Bizum**; tallas y guías de medidas; la regla «primera camisa de scouter gratis».

### 4.4 Flujo de una compra

1. **Catálogo** → ficha del producto → variante y cantidad → **carrito** (en sesión de WordPress, sin datos personales).
2. **Datos** (solo invitado): nombre, email, teléfono. Con sesión se toman del usuario.
3. **Forma de pago:** transferencia (IBAN y referencia), Bizum (teléfono y concepto con la referencia) o **en físico** (solo socios).
4. **Pedido creado:** se descuentan las existencias (reserva) y sale el **nº de pedido** y las instrucciones de pago. Email de confirmación.
5. **Tesorería marca pagado** (forma, fecha, importe, referencia). Sale en *Mis pagos* y en el historial de la familia.
6. **Entregado** (administración) y, si es socio, **«Lo he recibido»** (como hoy).
7. **Cancelar:** el comprador mientras no esté pagado; la administración, con motivo, hasta que se entregue. **Vuelven las existencias.**
8. **Sin pagar tras N días** (configurable): aviso y cancelación automática por la tarea diaria, devolviendo existencias.

El paso «incluido en el pedido del grupo» solo existe en los productos con «requiere pedido al proveedor» (camisa, insignias). El resto (p. ej. una taza que ya está en el almacén) va directo a entregar.

### 4.5 Páginas

| Clave | Pública | Contenido |
|---|---|---|
| `tienda` | **sí** (enlace del grupo) | Catálogo. Muestra los de «todos»; con sesión, también los de «solo socios» |
| `tienda_carrito` | sí | Carrito, datos del invitado y forma de pago |
| `tienda_pedido` | sí (enlace personal con token, como la lista de espera) | Estado del pedido e instrucciones de pago |
| `mis_pedidos` (antes `uniforme`) | no | Pedidos del socio/a y su familia; recibido; cancelar |
| `tienda_gestion` (antes `uniformes`) | no | Lista de pedidos con filtros, marcar pagado/entregado, lote, CSV |
| `tienda_productos` | no | Alta y edición de productos y existencias; carga CSV |
| `tienda_config` (antes `uniformes_config`) | no | Configuración del grupo |

Las URL antiguas (`/uniforme`, `/gestion-pedidos`, `/configuracion` bajo uniforme) **redirigen** (301) a las nuevas.

### 4.6 Seguridad (la parte delicada: abrimos páginas sin sesión)

- **Solo socios = no existe sin sesión.** El filtro se aplica en la consulta de productos, no en la plantilla; el detalle de un producto de socios responde **404** a un visitante.
- Compra de invitado: `nonce`, campo trampa, sello de tiempo y **límite por dispositivo** (patrón de `AltaScoutersView`); email obligatorio; tamaño máximo de carrito y de cantidades.
- «En físico» y «solo socios»: **se valida en el servidor** al crear el pedido y al marcar pagado.
- Tesorería es la única que marca pagado: `current_user_can( 'asde_tienda_pago' )` en cada acción con `nonce`.
- Precio y existencias **siempre del servidor**; el cliente solo manda ids y cantidades. Reserva de existencias con `UPDATE … WHERE existencias >= n` para no vender de más.
- Datos del invitado: cifrados (`Crypto`), con caducidad y borrado; las respuestas no revelan si un email ya compró.
- Auditoría (`Logger`): `tienda_pedido`, `tienda_pagado`, `tienda_entregado`, `tienda_cancelado`, `tienda_producto_*`, `tienda_existencias`.
- Si el módulo está apagado en el grupo, las páginas públicas **no existen** (404), como la lista de espera.

## 5. Plan de ejecución (por fases; cada una deja el plugin funcionando)

Cada fase: **rama desde `origin/main`**, pruebas verdes, versión, textos en ca/eu/gl, ayuda y manual si hay cambios visibles, commit + push + merge al cerrar. Las fases 1–3 pueden ir en una sola versión (**v0.39.0**); las demás, en v0.40.

### Fase 0 · Preparación
1. Crear la rama `Tienda`. Leer docs 24, 32, 36 y 43 (patrón de cobros) y `modulo-area-privada`.
2. P-174 y P-176 **ya decididas** (§9). Quedan P-175 y P-177…P-182: se asumen las propuestas salvo que el usuario diga otra cosa antes de la fase 2.

### Fase 1 · Renombrar y migrar (sin cambiar lo que hace)
1. `Domain\Modulos`: `UNIFORMES` → `TIENDA = 'tienda'`, nombre «Tienda». Migración de la opción `asde_ap_modulos` (si estaba apagado, sigue apagado).
2. Renombrar `src/Uniformes` → `src/Tienda` (namespace), vistas, plantillas `templates/uniformes` → `templates/tienda`, JS y CSS, páginas y rutas, **con redirecciones** de las URL antiguas.
3. Capabilities: crear las `asde_tienda_*`, **copiar** a ellas las que tenían las antiguas y retirarlas en `Install\Roles` (se aplica al subir la versión). Dar `asde_tienda_admin` a **Secretaría**, Coordinación y Tesorería.
4. Migrar la opción `asde_ap_uniformes_<grupo>` → `asde_ap_tienda_<grupo>` y la tabla `asde_uniformes` → `asde_tienda_pedidos` (renombrar, no copiar; con comprobación de filas antes y después). Actualizar `uninstall.php`, `Entorno`, `Mantenimiento`.
5. Menú, inicio, *Pendiente de revisar*, `MisPagosView`, `HistorialPagos`, auditoría (mantener los tipos viejos como alias para no romper el histórico).
6. `tests/uniformes.php` → `tests/tienda.php` con **las 65 comprobaciones iguales** (red de seguridad antes de generalizar). Textos, ayuda y los cinco manuales.

### Fase 2 · Productos y existencias (administración)
1. Tablas `productos` y `variantes`; la camisa y las insignias actuales se **convierten en productos** (migración idempotente), con sus tallas como variantes.
2. Pantalla *Productos* (alta, edición, imagen, precio, visibilidad, activo) y *Existencias* (ajuste con motivo, histórico en auditoría).
3. **Carga por CSV** reutilizando el patrón de `Inventario\Importador` (vista previa, errores por línea, nada se guarda hasta confirmar).
4. Vínculo opcional con el inventario del grupo (camisas, v0.29).
5. Pruebas: permisos de `asde_tienda_admin` por rol, validación, CSV, existencias.

### Fase 3 · Catálogo, carrito y pago (socios)
1. Catálogo con sesión: productos «todos» y «socios», carrito, pedido con varias líneas.
2. Formas de pago: **transferencia, Bizum y en físico** (esta última, solo socios); datos de pago en la configuración; referencia del pedido.
3. **Tesorería marca pagado** con forma, fecha, importe y referencia; deshacer. Los demás roles no ven el botón.
4. Entregado y recibido; cancelar con devolución de existencias.
5. Emails: confirmación del pedido, pagado, entregado (en `Usuarios\RegistroEmails::TIPOS`, idioma de quien lo recibe).
6. Pruebas: precio del servidor, «solo socios» no se cuela, «en físico» rechazado a no socios, existencias bajo concurrencia, solo Tesorería marca pagado.

### Fase 4 · Tienda pública (sin sesión)
1. Páginas públicas `tienda`, `tienda_carrito`, `tienda_pedido` (enlace del grupo con clave, como la lista de espera) y su interruptor en *Módulos*.
2. Compra de invitado (según P-174): datos cifrados, límite por dispositivo, confirmación por email, token personal para ver el pedido.
3. Los de «solo socios» **no aparecen** ni por URL directa. Pruebas HTTP sin sesión.
4. Caducidad: los datos del invitado y los pedidos sin pagar se purgan en `Install\Mantenimiento`.

### Fase 5 · Pulido y cierre
1. *Mis pagos*, historial de familia y **conciliación del banco** (movimientos que coinciden con la referencia del pedido → sugerir «pagado» a Tesorería; no marca solo).
2. Informes: ventas por producto, por forma de pago y pendientes de cobro (Excel).
3. Cancelación automática de pedidos sin pagar; recordatorio a quien debe.
4. Ayuda por página, manuales, traducciones, `docs/48` y `40-mapa` regenerado, `00-INDICE`, Plugin Check, banco de pruebas JS, zip.

## 6. Archivos que se tocan (guía)

`src/Uniformes/*` → `src/Tienda/*` · `src/Frontend/Views/Uniforme*View.php` → `Tienda*View.php` (+ vistas públicas nuevas) · `templates/uniformes/` → `templates/tienda/` · `assets/js/uniforme.js`, `inventario.js` (enlace) · `assets/css/area-privada.css` (bloque final) · `src/Frontend/{Pages,Router,Menu}.php` · `src/Domain/Modulos.php` · `src/Install/{Roles,Installer,Mantenimiento,Entorno}.php` · `src/Tesoreria/HistorialPagos.php` · `src/Frontend/Views/{MisPagosView,AreaPrivadaView}.php` · `src/Idiomas/*` y `languages/` · `uninstall.php` · `ayuda/*/uniforme*.html`, `manual/fuentes*` · `tests/` · docs 02, 24 (nota «sustituido por 48»), 40.

## 7. Riesgos

| Riesgo | Mitigación |
|---|---|
| Romper pedidos de uniforme existentes | Migrar renombrando tablas, comprobar filas, mantener las 65 pruebas y hacer copia antes |
| Vender de más | Reserva atómica de existencias; pruebas de concurrencia |
| Exponer productos de socios | Filtro en la consulta, 404 en detalle, prueba HTTP sin sesión |
| Datos personales de invitados | Mínimos, cifrados, con caducidad y borrado a petición |
| Spam en el pedido público | Trampa, sello, límite por dispositivo, email confirmado |
| URL antiguas rotas | Redirecciones 301 y alias de auditoría |
| Confusión con el módulo Inventario | Nombres distintos en pantalla («Productos» y «Existencias») |
| Alcance de pago online | Fuera de alcance: **no** hay pasarela de tarjeta; solo transferencia, Bizum y físico |

## 8. Fuera de alcance (de momento)

Pago con tarjeta (Redsys, Stripe), gastos de envío y envíos a domicilio, cupones y descuentos, facturas, IVA/factura legal, venta entre grupos. Se anotarán si se piden.

## 9. Preguntas abiertas

| # | Pregunta | Propuesta |
|---|---|---|
| **P-174** ✅ | ¿Pueden **comprar** quienes no son socios (sin iniciar sesión)? | **Decidido (2026-10-17): sí.** Compran por transferencia o Bizum, con datos mínimos y confirmación por email |
| P-175 | ¿«Productos / Existencias» está bien, o prefieres «Inventario»? | «Productos» y «Existencias» |
| **P-176** ✅ | ¿El tesorero marca como pagado **todos** los pagos o solo los de **en físico**? | **Decidido (2026-10-17): todos** (transferencia, Bizum y físico), tras ver el ingreso |
| P-177 | ¿Un mismo producto puede tener **precio distinto** para socios y no socios? | Sí, un campo opcional «precio socio» |
| P-178 | Datos de pago: ¿una sola cuenta IBAN y un solo teléfono Bizum por grupo? ¿De quién? | Sí, en la configuración del grupo |
| P-179 | ¿Días máximos sin pagar antes de cancelar el pedido y devolver existencias? | 14 días, configurable |
| P-180 | ¿Se mantiene «primera camisa de scouter gratis» y «camisa del inventario»? | Sí, como opciones del producto camisa |
| P-181 | ¿Quién marca **entregado**: también la administración y los scouters de sección, como hoy? | Sí, igual que hoy |
| P-182 | ¿Se avisa por email en cada paso (pedido, pagado, entregado)? (ligado a P-41) | Sí, los tres, con el idioma del destinatario |

## 10. Cómo se prueba (resumen)

`tests/tienda.php` (migración de las 65 + nuevas por fase) con el CRM simulado; pruebas HTTP sin sesión para lo público; recorrido en el navegador por rol (visitante, socio, familia, scouter, Coordinación, Tesorería, Secretaría) y a 390 px; banco JS estático; Plugin Check. **Nunca contra el CRM real.**

## 11. Avance

- ✅ **Fase 1 hecha (2026-10-17, rama `Tienda`, v0.39.0 en preparación):**
  - `src/Uniformes` → `src/Tienda`; vistas `Tienda{Pedidos,Gestion,Config,Base}View`; plantillas en `templates/tienda/`.
  - Módulo `tienda` (`Modulos::TIENDA`), tabla `asde_tienda_pedidos`, opción `asde_ap_tienda_<grupo>`.
  - Capabilities `asde_tienda_{comprar,gestion,admin,pago,pedido,entrega}`; **Secretaría ya tiene `asde_tienda_admin`**.
  - Páginas con clave nueva (`tienda_pedidos` «Mis pedidos», `tienda_gestion`, `tienda_config`), mismas páginas (mismo id) y redirección 301 de `/uniforme/…` a `/mis-pedidos/…`.
  - Migración `Install\MigracionTienda` (idempotente), probada en `tests/migracion-tienda.php` (17 comprobaciones). `tests/tienda.php` conserva las 66 de uniformes.
  - Textos nuevos con sus traducciones (ca, eu, gl). Auditoría: los tipos antiguos (`uniforme_*`) se mantienen tal cual.
  - **Pendiente de la fase 1:** textos y capturas de los manuales y la ayuda (se rehacen al cerrar la v0.39, cuando las fases 2 y 3 hayan cambiado las pantallas).

- ✅ **Fase 2 hecha (2026-10-17, rama `Tienda`):** el catálogo y las existencias.
  - Tablas `asde_tienda_productos` y `asde_tienda_variantes` (`ProductosTable`, `VariantesTable`): visibilidad `todos`/`socios` (el filtro está en la consulta: `ProductosTable::visibles()`), precio y precio de socio opcional, máximo por persona, «se encarga al proveedor», «sale del inventario», archivar en vez de borrar.
  - Existencias siempre con una sola sentencia SQL (`reservar`, `devolver`, `ajustar`): nunca bajan de 0 ni se venden de más; `ilimitado` no cuenta unidades; los ajustes manuales llevan motivo y quedan en la auditoría (`tienda_existencias`).
  - `Sembrado`: la camisa (una variante por modelo y talla) y las insignias de Uniformes pasan a ser productos (idempotente; lo que se cambie después se respeta). Hasta la fase 3 los pedidos siguen leyendo la configuración de Uniformes.
  - Pantalla **Productos** (`asde_tienda_admin`: Coordinación, Tesorería y Secretaría): lista con filtros, formulario con variantes, cambio de existencias con +/− y motivo, imagen en la biblioteca de medios, archivar y recuperar.
  - **Carga por CSV** (`ImportadorProductos`): una fila por variante; el producto que existe se actualiza solo con las celdas rellenas; las existencias se sustituyen o, si se marca, se suman; informe con el nº de fila y el motivo de cada error, y CSV de ejemplo.
  - Pruebas: `tests/tienda-catalogo.php` (67 comprobaciones). Textos nuevos traducidos (ca, eu, gl); ayuda de la página en el manual de Coordinación.
  - Decisiones propias (asumen las propuestas de §9): P-175 «Productos» y «Existencias»; P-177 precio de socio opcional por producto.
