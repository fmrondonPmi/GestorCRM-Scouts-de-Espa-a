# 25 · Domiciliación SEPA (v0.12)

## Petición del usuario (2026-09-30)

- En **«Mis pagos»**, los asociados y sus tutores pueden **rellenar una autorización SEPA** (orden de domiciliación de adeudo directo).
- En **Tesorería**, una función para **generar el fichero SEPA** que se envía al banco.

## Resumen del circuito

1. **Tesorería** rellena los **datos del acreedor** (nombre, identificador del acreedor, IBAN) y activa la opción.
2. Cada **familia firma su autorización** en *Mis pagos*: titular, IBAN, dirección y una casilla de firma. Llega un email de confirmación.
3. Tesorería crea una **remesa**: elige *qué se cobra* (todo lo pendiente o hasta un plazo) y la fecha de cobro, revisa la **vista previa** y descarga el **fichero XML** (`pain.008.001.02`, «Cuaderno 19.14»).
4. Tesorería **avisa a las familias** por email (fecha e importe del cargo), sube el fichero al banco y marca la remesa como **enviada**.
5. Cuando el banco cobra, Tesorería **registra los cobros**: se convierten en pagos de la familia (forma «Domiciliación SEPA») y se marcan los **devueltos**.

## La autorización (mandato) de la familia

**Dónde:** *Mis pagos*, debajo de la cuota de cada familia (`MisPagosView`, `templates/pagos/sepa.php`).

**Quién puede firmarla** (`Mandatos::puede_firmar`, permiso nuevo **`asde_sepa_autorizar`**, doc 02):

| Cuenta | Puede |
|---|---|
| Cuenta familiar (madre, padre o tutor/a; `nº + T` o `nº + T2`) | Firmar y anular la de su familia. Las dos cuentas de una familia pueden hacerlo |
| Asociado/a **mayor de edad** (participante desde los 18) | Firmar y anular la de su familia |
| Asociado/a **menor de edad** (14 a 17 años) | Verla; no firmar: «lo firma tu madre, padre o tutor/a desde su cuenta» |
| Scouter, Secretaría, Coordinación, Tesorería | No firman (Tesorería puede **anular**) |

**Formulario:** nombre y apellidos del titular, **IBAN** (se valida la longitud del país y los dígitos de control; solo países de la zona SEPA), dirección (calle, código postal, población, provincia y país), texto legal y una casilla «firmo esta orden de domiciliación». Si algo está mal, el formulario **se vuelve a enseñar con lo escrito** y el motivo (sin perder los datos).

**Texto legal** (v1, `Mandatos::texto`): el de la orden de domiciliación SEPA Core (autorización al acreedor y a la entidad, derecho a reembolso en 8 semanas), más el alcance («cuota y otros pagos del grupo», tipo de pago recurrente), el compromiso de **avisar con N días de antelación** (los de *Datos del acreedor*, 5 por defecto) y que se puede anular cuando se quiera.

**Referencia única:** `M684-000012` (M + grupo + id de 6 cifras).

**Una vigente por familia:** al firmar otra (p. ej. cambio de cuenta), la anterior queda **revocada** («Sustituida por una autorización nueva»). La familia también puede **anular** la suya (y Tesorería, con un motivo).

**Ver e imprimir:** botón «Ver e imprimir la autorización» (`?autorizacion=ID`): todos los datos de la orden, con el **IBAN entero solo para quien puede firmar** (los demás miembros de la familia lo ven enmascarado) y «Imprimir o guardar como PDF».

**Email de confirmación** a quien firma, con la referencia y el IBAN enmascarado. Queda en el **registro de emails** (tipo «Domiciliación SEPA»).

**Próximo cargo:** cuando hay una remesa generada o enviada con cobro futuro, «Mis pagos» enseña el aviso «Próximo cargo: fecha · importe en la cuenta ES66 **** 7891».

## Qué se guarda y cómo (doc 08)

`{prefix}asde_sepa_mandatos`:

| Columna | Contenido |
|---|---|
| `grupo`, `familia_id`, `referencia`, `estado` | Grupo, familia de Tesorería, `M684-…`, `activo` o `revocado` |
| `datos_enc` | **Titular, IBAN y dirección, cifrados** (libsodium; clave = clave del sitio + id del mandato). Nunca en claro |
| `iban_mask`, `iban_huella` | El IBAN **enmascarado** (`ES66 **** **** **** **** 7891`) para enseñarlo, y su HMAC |
| `firmado_por`, `firmado_en`, `fecha_firma`, `firma_huella`, `texto_version` | Quién (id de usuario), cuándo, fecha de la firma, **HMAC de la IP** (nunca la IP) y versión del texto aceptado |
| `usado_en` | Cuándo se envió al banco la primera remesa con ella: hasta entonces, **FRST**; después, **RCUR** |
| `revocado_por`, `revocado_en`, `motivo` | Trazabilidad de la anulación |

- **Conservación:** la norma SEPA pide guardar el mandato mientras esté vigente y **14 meses** después del último adeudo. La tarea diaria **vacía los datos de los mandatos revocados hace más de 14 meses** (`Mandatos::purgar`); los vigentes no se tocan.
- La auditoría anota `sepa_mandato_firmado` y `sepa_mandato_revocado`, **sin IBAN**.
- Al desinstalar el plugin las tablas se conservan (cifradas) como el resto (doc 10); quien no las necesite puede borrarlas a mano.

## Datos del acreedor (Tesorería → Domiciliaciones)

`Sepa\Config`, opción `asde_ap_sepa_<grupo>` (cada grupo tiene los suyos). Solo **Secretaría, Coordinación y Tesorería** (`asde_tesoreria_config`).

| Campo | Detalle |
|---|---|
| Nombre del acreedor | Como saldrá en el banco de las familias (sin tildes) |
| **Identificador del acreedor (ICS)** | Lo da el banco. En España, `ES` + 2 cifras de control + 3 caracteres + NIF = **16 caracteres** (p. ej. `ES26ZZZG12345678`). Se comprueba el formato y, como **aviso**, las cifras de control |
| **IBAN** | Cuenta donde el banco ingresa lo cobrado. **Cifrado** en la opción; en pantalla solo enmascarado y, al guardar con el campo vacío, se conserva |
| BIC | Opcional (desde 2016 no es obligatorio en la zona SEPA; si falta, el fichero lleva `NOTPROVIDED`) |
| Dirección | Calle, código postal, población y provincia |
| Días de aviso | Antes de cada cargo (5 por defecto). Sale en el texto de la autorización |
| Concepto | Con `{grupo}` y `{ronda}`. Al final se añade el nº de familia |
| **Ofrecer la domiciliación a las familias** | Sin marcar (o con datos incompletos) «Mis pagos» no ofrece firmar; quien ya tiene autorización la sigue viendo |

## Nueva remesa

**Pestaña «Domiciliaciones»** de Tesorería (`SepaView`; página `/tesoreria/domiciliaciones/`).

1. **Qué se cobra:** *Todo lo pendiente de la ronda* o *hasta un plazo*: a cada familia se le cobra lo que le falta hasta ese plazo, **contando lo ya pagado** (p. ej. 258 € de cuota, 58 € pagados y plazo del 50 %: 129 − 58 = 71 €). Una familia que ya ha pagado lo que toca no se incluye.
2. **Fecha de cobro:** un **día laborable**, como pronto **dentro de 3 días hábiles** (`Remesas::DIAS_HABILES_MINIMOS`; los bancos necesitan margen y aquí se pone el mismo para todos; no cuenta festivos).
3. **Concepto:** el de la configuración, editable.
4. **Vista previa** (no guarda nada): por familia, socios, titular y cuenta enmascarada, **secuencia** e importe, con el total; y aparte **«No se incluyen»**: familias con algo pendiente y **sin autorización**, o **ya incluidas en otra remesa** que aún no tiene registrados sus cobros.
5. **«Crear la remesa y preparar el fichero»:** se recalcula, se guarda (nº **`R684-0001`**) y se abre su ficha.

**Nunca dos veces lo mismo:** mientras una remesa (generada o enviada) tenga adeudos pendientes de una familia en esa ronda, esa familia no entra en otra. Lo comprueba también `Remesas::crear` (dos tesoreros a la vez, una vista previa antigua).

## El fichero XML (`Sepa\Pain008`)

Formato **ISO 20022 `pain.008.001.02`** (adeudos directos SEPA **Core**), el que los bancos españoles aceptan como Cuaderno 19.14:

- **Cabecera** (`GrpHdr`): identificador del mensaje (`ASDE684-<fecha>-<nº>`), fecha y hora, nº de adeudos, **suma de control** e identificador del acreedor.
- **Un bloque de pago (`PmtInf`) por secuencia:** `FRST` (autorizaciones que se usan por primera vez) y `RCUR` (el resto), con `SEPA`/`CORE`, la **fecha de cobro**, el acreedor (nombre, dirección, IBAN, ICS) y gastos compartidos (`SLEV`).
- **Cada adeudo:** importe en EUR, **referencia extremo a extremo** (`R684-0001-F684001`), **referencia y fecha de firma del mandato**, nombre e IBAN del deudor y concepto (con el nº de familia, para reconocerlo en el banco).
- **Texto:** el esquema solo admite `a-z A-Z 0-9 / - ? : ( ) . , ' +` y espacio: se quitan tildes y ñ y se cambia lo raro por espacios (`Texto::limpiar`). El XML se escribe con DOM (siempre bien formado) y en ASCII.
- Los importes se calculan en **céntimos** (enteros) para no arrastrar decimales.
- Antes de generarlo se comprueba: datos del acreedor completos, IBAN de cada adeudo válido, importes positivos y secuencia correcta.

> **No se ha validado contra el XSD oficial** (`pain.008.001.02.xsd`): no está en el proyecto y descargarlo era una decisión del usuario. La estructura sigue el orden del esquema y las pruebas comprueban sus elementos clave; la primera remesa real conviene **pasarla por la validación de Colonya Directa** antes de fiarse (P-42).

## Ciclo de una remesa

`Sepa\Remesas`, tabla `{prefix}asde_sepa_remesas`.

| Estado | Qué significa | Qué se puede hacer |
|---|---|---|
| **Generada** | Creada; aún no enviada al banco | Descargar (las veces que haga falta), avisar a las familias, **marcar como enviada**, **anular** |
| **Enviada al banco** | Tesorería confirma que la ha subido | Descargar, avisar, **registrar cobros y devoluciones**. Las autorizaciones estrenadas pasan a **RCUR** |
| **Anulada** | Solo si no se había enviado | Se borran sus datos y sus familias vuelven a poder entrar en otra |

- **Descargar** (`Content-Disposition: attachment`) usa los datos **tal como estaban al crearla** (incluido el acreedor). Queda en la auditoría (`sepa_remesa_descargada`) y se cuenta.
- **Avisar a las familias** (`Sepa\Avisos::preaviso`): un email a cada cuenta de la familia (las dos de tutor/a, los asociados mayores de edad y quien firmó), sin repetir direcciones: «`<fecha>` se cargarán `<importe>` en la cuenta ES66 **** 7891 (autorización M684-…)». Anota en la remesa cuándo, dice a cuántas familias no ha podido avisar (sin email) y va al registro de emails.
- **Registrar cobros:** con la remesa enviada, se marca cada adeudo como **cobrado** (por defecto), **devuelto** o se deja pendiente, con la fecha del cobro (no futura).
  - *Cobrado* → **pago de la familia** en Tesorería (forma «**Domiciliación SEPA**», origen `sepa`, referencia = la extremo a extremo, nota con el nº de remesa). Registrarlo dos veces no duplica el pago.
  - *Devuelto* → se marca; la familia vuelve a poder incluirse en una remesa nueva, ya como **RCUR**.
  - Cuando no queda ningún adeudo pendiente, la remesa se da por cerrada.
- Los **adeudos** (titular e IBAN) van en un JSON **cifrado**; se vacían 14 meses después de la fecha de cobro.
- Auditoría: `sepa_remesa_generada`, `_descargada`, `_enviada`, `_anulada`, `sepa_preaviso_enviado`, `sepa_cobros_registrados` y `sepa_configurada`.

## Autorizaciones de las familias (Tesorería)

La misma pestaña lista las familias con su autorización (referencia, IBAN enmascarado, fecha, titular y si «aún sin usar: el primer cobro irá como FRST») y deja **anularla** con un motivo.

## Cambios en otras piezas

- **Método de pago nuevo «Domiciliación SEPA»** (`MovimientosTable::METODOS`), que también se puede elegir al registrar un pago a mano; origen de movimiento `sepa`.
- **Páginas y menú:** página nueva `domiciliaciones` (12 tablas → **14 tablas**) y pestaña «Domiciliaciones» en Tesorería (solo con `asde_tesoreria_config`).
- **Emails:** tipo «Domiciliación SEPA» en el registro; con vista reconstruida (sin cuentas completas).
- **Tarea diaria:** purga de mandatos revocados y de adeudos antiguos.

## Pruebas (2026-09-30)

`tests/sepa.php`, **88 comprobaciones, todas OK**. Usa el CRM simulado con fichas inventadas, una ronda ficticia (2098-2099) con sus cuotas y un acreedor ficticio, y **restaura la configuración SEPA y la de Tesorería**. Cubre:

- **A** · IBAN (válidos, dígito cambiado, longitud, país, enmascarar), texto SEPA, identificador del acreedor (con el ejemplo de la norma) y datos del acreedor cifrados;
- **B** · fecha mínima (3 días hábiles, sin fines de semana) y fechas no válidas;
- **C** · quién puede firmar (tutores, asociado mayor de edad, menor, otra familia, sin permiso);
- **D** · firmar en «Mis pagos»: errores que conservan lo escrito, sin casilla, menor, familia ajena, autorización correcta (referencia, datos cifrados, **sin IBAN, nombre, dirección ni IP en claro**), email de confirmación, pantalla solo con IBAN enmascarado, autorización para imprimir;
- **E** · sustituir, anular (el otro tutor/a puede) y ajena;
- **F** · vista previa: todo lo pendiente, hasta un plazo, ya pagado, sin autorización;
- **G** · crear la remesa (fecha sin margen, sin adeudos, duplicados) y el **XML** (cabecera, bloque de pago, cada adeudo, ASCII, IBAN erróneo);
- **H** · aviso previo (destinatarios sin repetir, contenido, registro), marcar como enviada (FRST → RCUR), próximo cargo en «Mis pagos», cobros y devoluciones (pagos de la familia, sin duplicar);
- **I** · volver a cobrar lo devuelto (RCUR), anular, purga de datos antiguos;
- **J** · pantalla de Tesorería (permisos, pestaña, vista previa, crear desde la pantalla, guardar datos del acreedor, datos incompletos, anular una autorización).

## 9. La cuenta, en la ficha del CRM (v0.33, 2026-10-14; P-131)

Decisión del usuario: «mejor no almacenar nosotros los datos de la cuenta, mejor la federación;
de nuestra parte sí debe quedar la autorización de uso de cobros SEPA, que la podrá dar un
tutor/a o asociado mayor de 18 años; no es problema duplicarlo en cada ficha, lo importante es
que la domiciliación y el fichero SEPA no lo dupliquen».

| Qué | Dónde |
|---|---|
| **IBAN, titular y entidad** | En la ficha del CRM de **cada miembro de la familia** (`iban_c` —24 caracteres, solo cuentas españolas—, `iban_titular_c`, `iban_entidad_c`; doc 04, §7.3 bis). Los escribe la web al firmar, a través de `Crm\Escritura` (`Sepa\CuentaCrm::guardar`). |
| **La autorización** | En la web (`asde_sepa_mandatos`): referencia, quién firmó (cuenta familiar o asociado mayor de edad) y cuándo, la dirección del deudor y el lugar (cifrados), el **IBAN enmascarado** (para enseñarlo) y la **huella** (HMAC) del IBAN autorizado. Ni el IBAN entero ni el titular. |
| **La remesa** | Un adeudo **por autorización (por familia)**, aunque la cuenta esté en varias fichas. Al prepararla, la cuenta se lee del CRM (una consulta) y se toma la de la primera ficha de la familia cuyo IBAN coincide con la huella; si ninguna coincide (la cuenta se ha cambiado en el CRM), la familia sale aparte: «tiene que firmar la autorización de nuevo». Lo guardado de la remesa no lleva ni IBAN ni titular: al **descargar el fichero** se vuelven a leer del CRM y se comprueba la huella (si no coincide, no se genera). |
| **Ver la cuenta** | En la **ficha** de la persona, apartado «Datos bancarios y domiciliación SEPA» (enmascarada, en vivo: no entra en la instantánea): la ven la familia, el propio asociado mayor de edad y Tesorería (`asde_tesoreria_config`); los scouters no. Se cambia firmando una autorización nueva en «Mis pagos». La autorización para imprimir enseña el IBAN entero (leído del CRM) a quien puede firmarla. |
| **Autorizaciones de antes** | Las firmadas antes de la v0.33 guardaban la cuenta cifrada en la web; siguen cobrándose con ella hasta que Tesorería pulsa **«Pasar las cuentas al CRM»** (Domiciliaciones): la cuenta de cada autorización vigente se escribe en las fichas de su familia y se borra de la web; de las anuladas solo se borra. |

Sin permiso del usuario de la API para editar fichas no se puede firmar (se explica). Al anular una
autorización, la cuenta **no** se borra del CRM (P-133). Pruebas: `tests/sepa.php` (114), partes D, F,
G y K.

## Pendiente

- **P-42:** datos reales del acreedor y confirmar con Colonya el formato, el canal y los plazos.
- **P-43:** validez de la firma electrónica frente al banco.
- **P-44:** cobrar también uniformes y otros conceptos; recargo por devolución.
- **P-45:** conciliar los cobros y devoluciones con la Norma 43 (hoy se registran a mano).
- Un **CSV/Excel de la remesa** para revisar fuera de la web (hoy solo la vista previa y el XML).
