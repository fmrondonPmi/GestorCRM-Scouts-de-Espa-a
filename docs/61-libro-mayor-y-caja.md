# 61 · Libro mayor y caja en efectivo

Estado: plan escrito el 2026-10-24; **el usuario contestó las preguntas el 2026-10-25** (apartado 6, que manda sobre lo de los apartados 3 y 4 donde no coincidan). Hecho en la **v0.59.0** (rama `Libro-Mayor-Caja`): `src/Caja/` (`CajaTable`, `Cajas`, `Periodo`, `Automaticos`, `Servicio`, `LibroMayor`), `CajaView` y `LibroMayorView`, plantillas `tesoreria/caja*.php` y `libro-mayor.php`, prueba `tests/caja.php`. Supuestos de la implementación: P-221…P-224; P-225 (cuadros abiertos) decidido: se quedan como están.

## 1. Qué pide el usuario (literal)

> Generar en tesorería libro mayor y también la sección de caja en efectivo. Asociando los reintegros en efectivo de los scouters como un gasto de la caja.

## 2. Qué hay ya: por dónde pasa hoy el dinero en la web

| Dónde | Tabla | Efectivo | Qué falta para una caja |
|---|---|---|---|
| **Banco** (importado de Norma 43 / CSV) | `asde_banco_movimientos` | — (es la cuenta) | Ya tiene fecha, importe, tipo de gasto/ingreso, evento, familia y, desde la v0.58, el movimiento asociado. Es la «cuenta Banco» del libro mayor. |
| **Cuotas** (Registrar pago) | `asde_movimientos` (`metodo` = efectivo, transferencia, bizum…) | `metodo = efectivo` | Nada: el cobro ya dice cuándo y cuánto. |
| **Eventos · cobros** | `asde_evento_movimientos` (`forma` = efectivo, transferencia, bizum, domiciliación) | `forma = efectivo` | Nada. Las devoluciones a familias también tienen forma. |
| **Eventos · gastos** | `asde_evento_gastos` (`pagador` grupo / anticipo / persona; `forma` efectivo, tarjeta…) | pagado por el grupo con `forma = efectivo`; reembolsos con `reembolso_forma = efectivo` | El pagador «El grupo (cuenta o caja)» no dice cuál: con `forma = efectivo` se entiende caja. |
| **Eventos · anticipos de caja** | `asde_evento_anticipos` | siempre (salida al darlo, entrada lo que se devuelve) | Ya se llaman «de caja». |
| **Tienda · pedidos** | `asde_tienda_pedidos` (`metodo` efectivo, ingreso, bizum, tpv) | `metodo = efectivo` («En físico, a Tesorería») | Nada. |
| **Reintegros** (scouters) | `asde_reintegros` (`forma` efectivo / transferencia, estado pagado) | `forma = efectivo` y `estado = pagado` | Lo pide el usuario: **gasto de la caja** con la fecha de pago. |
| **SEPA** | remesas | — (van al banco) | Nada. |

Conclusión: casi todo el efectivo **ya está apuntado** en su módulo. La caja no necesita que nadie lo vuelva a escribir; le faltan solo **el saldo inicial, los apuntes que no son de ningún módulo** (comprar sellos con la caja, un donativo en mano), **los traspasos** con el banco (ingresar el efectivo en la cuenta, sacar del cajero) y **los arqueos** (contar lo que hay).

## 3. Propuesta

### 3.1 Caja en efectivo (pestaña «Caja» de Tesorería)

- **Calculada en vivo** (como el reparto de los presupuestos, doc 57): los apuntes automáticos se leen de las tablas de arriba cada vez; no se copian. Así no puede haber dos versiones del mismo cobro (si se anula un pago en la familia, desaparece también de la caja). **Recomendado** (P-212).
- Tabla nueva **solo para lo propio de la caja**: `{prefix}asde_caja` con `grupo`, `fecha`, `importe` (± ), `clase` (`inicial` | `manual` | `traspaso` | `arqueo`), `tipo_id` (los mismos tipos de gasto e ingreso del banco, `TiposMovimiento`), `concepto`, `evento_id`, `banco_id` (el movimiento del banco del traspaso), `nota`, `creado_por`, `creado`, `anulado_en`, `anulado_motivo` (no se borra: se anula con motivo, como los pagos, v0.25).
- **Apuntes automáticos** (solo lectura en la caja; se cambian en su módulo):
  - entradas: cuotas cobradas en efectivo, cobros de eventos en efectivo, pedidos de la tienda pagados en efectivo, lo devuelto de un anticipo;
  - salidas: **reintegros pagados en efectivo** (petición del usuario), gastos de eventos pagados por el grupo en efectivo, reembolsos en efectivo, anticipos de caja entregados, devoluciones en efectivo a familias.
- **Saldo** = saldo inicial + entradas − salidas, por fecha; lista con filtros en una línea (periodo, entradas/salidas, origen, tipo), saldo acumulado por fila y Excel.
- **Arqueo**: «He contado la caja: hay X €». Se guarda el recuento y la diferencia con el saldo calculado; si no cuadra, se pide un motivo y la diferencia queda como apunte de ajuste.
- **Traspaso con el banco**: «Ingresar en el banco» (salida de caja) y «Sacar del banco» (entrada). Cuando llega el movimiento del banco, se **asocia** con el traspaso (lo de la v0.58), para que el libro mayor no lo cuente como gasto ni como ingreso del grupo.

### 3.2 Libro mayor (pestaña «Libro mayor» de Tesorería)

- **Una cuenta por medio de pago**: *Banco* (los movimientos importados) y *Caja* (3.1); cada una con su saldo inicial del periodo, sus apuntes por fecha y el **saldo acumulado** en cada fila.
- **Resumen por partidas**: total de gastos e ingresos de cada **tipo** (material, campamentos, cuotas, subvenciones…), del banco y de la caja juntos; los traspasos y los movimientos asociados entre sí (un gasto y su devolución) se compensan y no inflan los totales.
- Periodo: la **ronda** (curso scout) por defecto, con la opción del año natural (P-207).
- Descarga en **Excel**: una hoja por cuenta y una del resumen (el `Xlsx` actual hace una hoja: habría que ampliarlo a varias, o una hoja con secciones si se prefiere no tocarlo).
- Solo lectura: lo que se corrige, se corrige en su sitio (banco, caja, cuota, evento…), con un enlace desde cada fila.

### 3.3 Permisos y módulo

- Lo ve quien configura Tesorería (`asde_tesoreria_config`: Tesorería, Secretaría, Coordinación), como el banco. Un permiso nuevo para apuntar en la caja (`asde_caja`), repartible en la Gestión de accesos (doc 56) para que lo tenga, por ejemplo, el/la Intendente.
- Módulo **«Caja y libro mayor»** apagado por defecto (regla 5 del `CLAUDE.md`), dependiente de Tesorería.

## 4. Preguntas para el usuario (doc 09)

- **P-206** ¿El libro mayor por **cuentas** (banco y caja) con un resumen por tipo de gasto/ingreso, o lo quieres por **partidas contables** (cuentas tipo 6xx/7xx del plan de entidades sin ánimo de lucro)?
- **P-207** ¿Periodo por **ronda** (septiembre–agosto), por **año natural** (enero–diciembre, el de Hacienda) o los dos?
- **P-208** El **saldo inicial** de la caja (y del banco al empezar): ¿se escribe a mano el primer día y después se arrastra de un periodo a otro?
- **P-209** ¿Entran solos en la caja todos los cobros y pagos en efectivo de la tabla del apartado 2 (cuotas, eventos, tienda, anticipos, reintegros, reembolsos)? ¿Falta o sobra alguno?
- **P-210** ¿Una sola caja por grupo, o una por sección / por evento (la «caja del campamento»)?
- **P-211** ¿Quién hace los **arqueos** y cada cuánto? ¿Hace falta que lo firme (visto bueno) otra persona?
- **P-212** ¿Te parece bien que la caja se **calcule en vivo** con lo de cada módulo (recomendado) en vez de copiar los apuntes?
- **P-213** Reintegros en efectivo: ¿la salida de caja va con la **fecha de pago** del reintegro (la que se pone al marcarlo pagado)?
- **P-214** Gastos de eventos pagados por «El grupo (cuenta o caja)»: ¿con `forma = efectivo` es caja y con las demás es banco, o prefieres elegirlo al apuntar el gasto?

## 6. Decisiones del usuario (2026-10-25)

| Pregunta | Respuesta |
|---|---|
| P-206 | Libro mayor **por cuentas** (Banco y cada caja, con su saldo acumulado) **y resumen por tipo** de gasto e ingreso. |
| P-207 | Periodo: la **ronda** por defecto y, en un desplegable, también el **año natural**. |
| P-208 | Saldo inicial de cada caja y del banco **a mano una sola vez**; después se arrastra (el inicial de un periodo es el final del anterior). |
| P-209 | Entra solo en la caja **todo** lo del apartado 2: cuotas y cobros/devoluciones de eventos en efectivo, tienda en efectivo, reintegros y reembolsos en efectivo, gastos e ingresos de eventos en efectivo y anticipos de caja (entregados y devueltos). |
| P-210 | **La caja del grupo y una por sección**. |
| P-210b | Lo automático va **siempre a la caja del grupo**; las de sección se mueven con apuntes a mano y traspasos con la del grupo. |
| P-210c | La caja de una sección la **ven y llevan sus scouters**; Tesorería, Secretaría y Coordinación, todas (ajustable en la Gestión de accesos). |
| P-211 | El **arqueo** lo hace quien lleva la caja, sin visto bueno; si no cuadra, con motivo, y la diferencia queda como apunte de ajuste. |
| P-212 | Los automáticos se **calculan en vivo** (no se copian). |
| P-213 | Un reintegro en efectivo sale de la caja con su **fecha de pago** (igual los reembolsos). |
| P-214 | Gasto de evento pagado por «El grupo»: con forma **Efectivo** es caja; con las demás, banco. |
| P-219 | Ingresar o sacar efectivo del banco = **traspaso** en la caja, que después se **asocia** con su movimiento del banco; el libro mayor no lo cuenta como gasto ni como ingreso. |
| P-220 | **Todas las cajas existen desde el principio** (la del grupo y la de cada sección educativa), a 0 € hasta que se ponga su saldo inicial. |

Supuestos de la implementación (a confirmar, P-221…): un reintegro con evento también crea un gasto del evento ya reembolsado: en la caja cuenta **solo el reintegro** (no el gasto, para no restar dos veces); un pedido de la tienda pagado en efectivo y cancelado después deja de contar; los gastos pagados con un anticipo no salen de la caja (salió el anticipo); el concepto y la nota de los apuntes a mano se guardan cifrados.

## 5. Fases propuestas (cuando se confirme)

1. **Caja** (tabla, apuntes manuales, saldo inicial, apuntes automáticos en vivo, reintegros en efectivo como gasto, lista con saldo y Excel). Prueba `tests/caja.php`.
2. **Arqueos y traspasos** con el banco (asociando el movimiento del banco).
3. **Libro mayor** (banco + caja, saldo acumulado, resumen por tipo, Excel de varias hojas). Prueba `tests/libro-mayor.php`.
4. Manual (Tesorería), ayuda, traducciones y capturas.
