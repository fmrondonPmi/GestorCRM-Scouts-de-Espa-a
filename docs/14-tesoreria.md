# 14 · Tesorería (v0.4.0)

> **Cambios en v0.12 (2026-09-30):** pestaña **Domiciliaciones** (solo Secretaría, Coordinación y Tesorería): datos del acreedor, remesas de **adeudos SEPA** (fichero XML para el banco), aviso previo a las familias y registro de cobros y devoluciones. Las familias firman su autorización en «Mis pagos». Nueva forma de pago **«Domiciliación SEPA»** (movimientos con origen `sepa`) y dos tablas más (14 en total). Todo en el [doc 25](25-domiciliacion-sepa.md).
>
> **Cambios en v0.11 (2026-09-29):** las familias y los socios ven lo que registra Tesorería en **«Mis pagos»**: la cuota de la ronda con sus plazos y los pagos de todas las rondas, sin las notas internas, sin los ajustes uno a uno y sin los anulados. Los **pedidos de uniforme** se cobran aparte (la validación del pago está en *Uniformes*) y no cuentan en la cuota (doc 24).

## Requisitos del usuario (2026-09-26)

- Los asociados se agrupan en **Familias**, con un **nº de gestión propio**: **F + grupo + correlativo** (`F684001`). Sirve sobre todo para Tesorería y como referencia en las transferencias.
- Una página de **control de pagos** y otra de **configuración de la cuota**. **Todo es configurable.**
- **Descuento por hermanos**:
  - inscripción 20 € por hijo;
  - seguro 10 € por hijo;
  - anualidad según nº de hijos (1 → 110 €, 2 → 198 €, 3 → 242 €), con un **máximo de 242 €**.
  - Totales resultantes: 1 hijo 140 €, 2 hijos 258 €, 3 hijos 332 €, 4 hijos 362 €.
- **Pagan los educandos, de Castores a Rovers** (también los Rovers mayores de edad). **Scouters y voluntariado no pagan.**
- **Plazos configurables:** nombre, fecha límite y % de la cuota. Por defecto, uno.
- Una página con el **coste que paga el grupo a Scouts de Balears**. Aquí pagan **todos** los asociados, sin distinción:
  - 20 € por asociado = **17 € de cuota asociativa + 3 € de seguro (promedio)**;
  - el seguro real sale de la **tabla de primas 2026**: menores de 14, 1,92 €; mayores de 14, 2,44 €; scouters, 6,06 €.
  - Ejemplo del usuario, con 110 asociados: 46 × 1,92 = 88,32 €; 42 × 2,44 = 102,48 €; 22 × 6,06 = 133,32 €. Cuota: 17 × 110 = 1.870 €.
- **Acceso:**
  - **todos los scouters** entran y **modifican** (pagos, familias);
  - Secretaría y Coordinación, también;
  - el **tesorero** es el scouter que tiene ese cargo en el CRM.
- **Más adelante:**
  - lectura de **ingresos del banco** (Colonya, vía Redsys PSD2), **solo lectura**, para asociar cada ingreso a un asociado o familia;
  - **gastos** con quién los hizo, dónde, e imagen o PDF.

## Páginas (`/nuestro-grupo-scout/area-privada/tesoreria/…`)

Todas tienen **pestañas** y **selector de ronda**. La ronda va de septiembre a agosto (p. ej. `2026-2027`) y se puede preparar la siguiente.

| Página | Qué hace | Quién |
|---|---|---|
| **Control de pagos** | Resumen (familias, hijos que pagan, a cobrar, cobrado con %, pendiente, recuento por estado) y listado por familia (cuota, pagado, pendiente, estado). Filtros y CSV. Avisa de los **educandos sin familia**, a los que no se está cobrando | `asde_tesoreria_acceso` |
| **Ficha de familia** | Socios (quién paga, quién no y quién no cuenta por no tener membresía activa), desglose de la cuota, plazos, **registrar pago** (fecha, importe, forma, referencia, nota), **ajuste** (descuento/beca o recargo, siempre con motivo) y movimientos con **anular** | `asde_tesoreria_acceso` |
| **Familias** | **Sugerencias automáticas** (educandos sin familia que comparten email; el 26-09 salieron 8: 7 parejas y 1 trío), crear familia con los marcados (puede incluir al padre o madre scouter), editar socios y borrar (solo vacía y sin pagos) | `asde_tesoreria_acceso` |
| **Cuotas y plazos** | Inscripción, seguro, **anualidad por tramos con tope**, secciones que pagan, plazos (avisa si no suman 100 %) y tabla del resultado de 1 a 5 hijos | Ver: acceso · Cambiar: `asde_tesoreria_config` |
| **Domiciliaciones** (v0.12) | Autorizaciones de las familias, remesas de adeudos SEPA, fichero para el banco y cobros (doc 25) | `asde_tesoreria_config` |
| **Coste federativo** | Cuota asociativa, seguro promedio, **edad de corte**, primas por categoría y año y **otros conceptos** (v0.9). Empieza cada ronda con los importes de la organización federada (doc 20). **Cálculo con los socios activos del CRM**: tabla como la de la federación, otros conceptos, total con tabla y con promedio, y comparación con las cuotas esperadas de las familias | Ver: acceso · Cambiar: `asde_tesoreria_config` |

### Roles

- **Scouter:** `asde_tesoreria_acceso`.
- **Secretaría y Coordinación:** acceso y configuración.
- **Tesorería** (rol nuevo): acceso y configuración.
  - Como Secretaría y Coordinación, es una responsabilidad de scouter: exige tener también el rol Scouter.
  - En el alta se **sugiere automáticamente** si el CRM tiene el cargo *(GS) Tesorero/a de Grupo*. Lo mismo con Secretario/a → Secretaría y Coordinador/a de Grupo → Coordinación.
  - Doble factor obligatorio.

## Datos (en WordPress, sin datos personales)

| Tabla / opción | Contenido |
|---|---|
| `{prefix}asde_familias` | id, `numero` (F684001, único), grupo, fechas |
| `{prefix}asde_familia_miembros` | `num_socio_asde` (PK: **un socio, una familia**), familia |
| `{prefix}asde_movimientos` | familia, ronda, tipo (`pago`/`ajuste`), fecha, importe, forma de pago, referencia, nota, origen (`manual`; más adelante `banco`), quién lo registró y cuándo, anulación (quién y cuándo). **No se borran: se anulan** |
| opción `asde_ap_tesoreria` | Configuración por ronda: cuotas, secciones que pagan, plazos y federación |

Los nombres de los socios se leen del CRM al pintar.

## Cálculo (`Tesoreria\Calculadora`)

- **Hijos que pagan:** miembros con membresía activa en una sección que paga.
- **Cuota:** hijos × inscripción + hijos × seguro + anualidad(hijos), con tope en el último tramo.
- **A pagar** = cuota + ajustes (mínimo 0). **Pendiente** = a pagar − pagado.
- **Estados:**
  - *Pagado*;
  - *Pago parcial*;
  - *Pendiente*;
  - *Con plazos vencidos* (lo pagado no cubre lo exigible de los plazos ya vencidos);
  - *Sin cuota*.
- **Coste federativo:**
  - Educandos: menores o mayores de la **edad de corte** (14 en Balears; configurable desde la v0.9) según su edad hoy.
  - «Scouters» incluye al voluntariado de apoyo.
  - Cuota asociativa × total. Seguro por tabla (Σ nº × prima) o por promedio.
  - **Otros conceptos (v0.9):** nombre + importe por asociado, por educando, por adulto o una vez por grupo; un importe negativo es un descuento. Se suman a los dos totales (doc 20).
  - El nombre de la organización sale de *Organización federada* («Lo que el grupo paga a Scouts de Balears…»).
- **Con datos reales** (26-09-2026, 107 activos):
  - seguro por categorías: 51 × 1,92 + 28 × 2,44 + 28 × 6,06 = **335,92 €**;
  - cuota asociativa: **1.819 €**;
  - total: **2.154,92 €** con la tabla (**2.140 €** con el promedio de 20 € por asociado).

## Integración con el alta

Al crear una **cuenta familiar** (usuario nº + T), se crea o amplía automáticamente la **familia de Tesorería** con esos menores.

## Pruebas (2026-09-26)

- **Automáticas:** cuotas de 1 a 5 hijos (140/258/332/362/392), tope de 242 €, coste federativo con las cifras del usuario (88,32 / 102,48 / 133,32 / 324,12 / 1.870 / 330).
- **Estados:** con plazo vencido → atrasado; pago del 1er plazo → parcial; con beca → pagado.
- **Reglas:** padre scouter no paga; sin membresía no cuenta; un socio no puede estar en dos familias; un ajuste sin motivo se rechaza; un pago anulado no cuenta; no se borra una familia con socios o pagos.
- **Sugerencias** por email.
- **En el navegador:** crear familia desde una sugerencia → aparece en el control con 258 € → pago de 100 € → *Pago parcial*, pendiente 158 €. Datos borrados al terminar.

## Pendiente

- **Banco (Colonya):** la API PSD2 de Redsys **no es viable** para la asociación. Propuesta: importar ficheros Norma 43 o CSV y, más adelante, un agregador. Ver la sección siguiente y P-23/P-28.
- **Gastos:** quién, dónde, justificante (imagen o PDF), categoría y ronda. Diseño: tabla `asde_gastos` y adjuntos en la biblioteca de medios privada (fuera de la URL pública) o en una carpeta protegida.
- Prorrateo de altas o bajas a mitad de ronda (hoy la cuota se calcula con los socios activos del momento).
- Recibos o justificantes de pago para las familias. (La **vista de pagos para la propia familia** ya existe desde la v0.11: «Mis pagos», doc 24.)
- Asignar familia a los 79 educandos (el 26-09 no había ninguna): usar las sugerencias y después el resto a mano.

## Banco (Colonya · Redsys PSD2)

> **Decisión y desarrollo (v0.6):** se importan ficheros **Norma 43 o CSV** descargados de Colonya y se concilian los ingresos con las familias. Todo el detalle está en el [doc 16](16-banco-importacion.md). Lo que sigue es la investigación que llevó a esa decisión.

Investigación del 2026-09-26. Lo marcado *[sin verificar]* no se ha podido confirmar en una fuente primaria.

### Qué es el enlace de Redsys

- `market.apis-i.redsys.es` es el portal de documentación y **sandbox** del **HUB PSD2 de Redsys**, que sigue el estándar Berlin Group NextGenPSD2. Conecta más de 80 entidades con proveedores de pagos certificados («TPP»).
- **Colonya** es un nodo del HUB (código `COLONYA`, API v1.1). Solo admite autenticación reforzada (SCA) por redirección y aplica la ampliación a **180 días** entre autenticaciones.
- **Producción:**
  - API: `https://psd2.redsys.es/api-entrada-xs2a/services/COLONYA/v1.1`
  - OAuth: `https://hubpsd2.redsys.es/api-oauth-xs2a/services/rest/COLONYA`
- Flujo de lectura (AIS):
  1. OAuth2 con PKCE;
  2. `POST /consents`;
  3. autorización del titular en la web de Colonya;
  4. `GET /accounts`;
  5. `GET /accounts/{id}/transactions?dateFrom…&bookingStatus=booked`.
- Datos útiles de cada movimiento: fecha, importe, `debtorName` y `debtorAccount` (el ordenante de un ingreso) y `remittanceInformationUnstructured` (el concepto).

### Por qué no sirve la conexión directa

- **En producción** Redsys exige:
  - **mTLS con certificado eIDAS de TPP** (QWAC);
  - **firma de cada petición** con un certificado de sello cualificado (QSealC);
  - que el `client_id` sea el nº de autorización del proveedor en el Banco de España (`PSDES-BDE-…`).
- Estos certificados solo se emiten a **proveedores de pago autorizados o registrados**. Para tenerlos, la asociación tendría que registrarse como proveedor de información sobre cuentas (AISP) en el Banco de España: plan de negocio, seguro de responsabilidad civil, políticas de seguridad… Es **desproporcionado** para un grupo scout.
- **El sandbox** está abierto a cualquiera (usuario de prueba `user1/1234`, certificado genérico), pero **solo tiene datos simulados**: sirve para aprender, no para leer la cuenta real.
- Límites de cualquier vía PSD2 (Reglamento Delegado UE 2022/2360):
  - el titular vuelve a autenticarse con SCA cada **180 días**;
  - como máximo **4 lecturas al día** sin el titular presente;
  - sin SCA nueva solo se ven los **últimos 90 días**.
  - Conviene guardar los movimientos en local.

### Opciones realistas

| Opción | Coste y esfuerzo | Notas |
|---|---|---|
| **1. Importar ficheros Norma 43 (Cuaderno 43)** o CSV/Excel de Colonya Directa | Sin coste. Subida manual semanal o mensual | Formato común AEB/CECA/UNACC (registros 11/22/23/33/88). La **modalidad 3** trae el nombre del ordenante y el concepto SEPA, pero **no su IBAN**. *[Sin verificar]* si Colonya ofrece Norma 43 y en qué modalidad: preguntar en la oficina (P-28) |
| **2. Agregador con licencia**, que actúa como TPP; la asociación es el titular | De pago, con contrato | Colonya aparece en **Enable Banking** (admite empresas; el uso gratuito es solo evaluación o particulares) y en **Afterbanks/Arcopay** (200 €/mes de prueba, contrato anual). **GoCardless Bank Account Data** no admite altas nuevas desde julio de 2025 |
| 3. Registrarse como AISP | Muy alto | Descartado |
| Complemento: **cobrar por adeudo SEPA** (remesas de recibos desde Colonya Directa) | Bajo | La asociación fija la referencia de cada recibo y la conciliación es trivial |

**Propuesta:**
- **Fase 1:** importar ficheros (Norma 43 y CSV) en el plugin, con conciliación de ingresos con familias.
- **Fase 2:** conector con un agregador, si el volumen lo justifica. Las «fuentes» son intercambiables detrás de una misma interfaz.

### Diseño previsto para la fase 1

- **Modelo único de movimiento:**
  - cuenta, fecha contable y fecha valor;
  - importe en céntimos con signo;
  - concepto;
  - nombre del ordenante;
  - IBAN **enmascarado + HMAC** (nunca en claro);
  - código de operación;
  - referencias;
  - fuente, lote y **huella** (para no duplicar movimientos al reimportar).
- **Conciliación:**
  - referencia de familia en el concepto (p. ej. `F684001`), mejor con dígito de control;
  - importe igual a la cuota o a un plazo;
  - nombre del ordenante parecido al de un pagador conocido.
  - Resultado: una **sugerencia** que el tesorero confirma o rechaza.
  - Al confirmar se aprende la regla «pagador → familia».
- **Seguridad y RGPD:**
  - el fichero original no se guarda (se procesa y se descarta);
  - nada va a `uploads` público;
  - auditoría de quién importa (con el hash del fichero), quién confirma y quién exporta;
  - solo el IBAN enmascarado de terceros;
  - informar a las familias.
- **Comprobaciones al importar Norma 43:**
  - los totales del registro 33 cuadran con los movimientos;
  - el recuento del registro 88 es correcto;
  - el saldo inicial coincide con el saldo final del fichero anterior.

### Fuentes principales

- Nodo Colonya (Redsys): https://market.apis-i.redsys.es/psd2/xs2a/nodos/colonya
- Guía técnica de Redsys para TPP, versión Colonya: https://hub-i.redsys.es:16443/psd2-doc/DOC_TPP_v.1.9.6_colonya_vCastellano.pdf
- Colonya, PSD2: https://colonya.com/es/pagina-asistencia/psd2-informacion-para-apis-colonya
- Reglamento Delegado (UE) 2022/2360: https://eur-lex.europa.eu/legal-content/ES/TXT/HTML/?uri=CELEX:32022R2360
- ETSI TS 119 495 (certificados PSD2): https://www.etsi.org/deliver/etsi_ts/119400_119499/119495/01.05.01_60/ts_119495v010501p.pdf
- Banco de España, registro de AISP: https://sedeelectronica.bde.es/sede/es/tramites/registro-prestadores-informacion-cuentas-p222.html
- Norma 43: https://www.caixabank.es/deployedfiles/empresas/Estaticos/pdf/Transferenciasyficheros/q43Junio2012.pdf
- Enable Banking, Colonya: https://tilisy.enablebanking.com/guides/ES/Colonya/ · Arcopay: https://www.arcopay.io/ · GoCardless (altas cerradas): https://bankaccountdata.gocardless.com/new-signups-disabled

## v0.20 · ficha de la familia: cuota plegable y pago con un ingreso del banco (2026-10-06)

- **«Cuota de la ronda» se puede plegar** (usa los apartados plegables de la ficha). Plegado enseña una línea con lo esencial: «A pagar · pagado · pendiente» (o saldo a favor). Sale abierto la primera vez y **el navegador recuerda** cómo lo dejó cada persona (`localStorage`; `area.js` lo hace con cualquier `<details data-asde-recordar="clave">`).
- **«Registrar pago» con un ingreso del banco**: quien ve el banco (Secretaría, Coordinación, Tesorería) tiene el desplegable **«Ingreso del banco (opcional)»** con los ingresos importados **por conciliar**: primero los **sugeridos para esa familia** (su referencia en el concepto o el mismo ordenante que otro pago suyo) y después los demás, los más recientes antes (`Conciliacion::pendientes_para`, como mucho 200). Al elegir uno se rellenan la fecha, el importe, «transferencia» y la referencia (documento del banco), que no se pueden cambiar (`assets/js/tesoreria.js`). Al registrar, el pago se crea con la fecha y el importe **del banco** (aunque sin JavaScript se escriban otros) y el movimiento queda **conciliado** con la familia (lo mismo que conciliar desde el banco: origen «banco»; se deshace desde el banco). Un mismo ingreso no se usa dos veces. Un scouter registra pagos pero no ve el banco: no tiene el desplegable y el servidor se lo niega.
- Pruebas en `tests/banco-gestion.php`, parte D.

## v0.20.1 · el ingreso del banco se busca en una ventana (petición del usuario, 2026-10-06)

El desplegable de la v0.20 no dejaba buscar tecleando. Ahora:

- **«Buscar en el banco…»** (enlace con `data-asde-modal`) abre en la ventana emergente `tesoreria/?familia=N&ronda=R&elegir_banco=1` (`TesoreriaView::elegir_banco()`, plantilla `tesoreria/familia-banco.php`; `Router::MODALES` incluye ya `tesoreria`). Solo para quien ve el banco (`ve_banco()`: `CAP_CONFIG` y el módulo Banco activo); a los demás, un aviso.
- **Buscador por texto**: filtra **mientras se escribe**, en el navegador (`assets/js/tesoreria.js`), sobre el texto de cada ingreso (`Conciliacion::normalizar()`: minúsculas, sin acentos, solo letras y cifras) con la fecha, el importe, el ordenante, el concepto, las referencias y el documento. Tienen que estar **todas** las palabras escritas (o un trozo de cada una), en cualquier orden. Enter no sale de la ventana. Sin JavaScript, el buscador vuelve a pedir la página con `q`: las filas que no coinciden van con `hidden` (`Conciliacion::coincide()`), igual que las oculta el JavaScript.
- **«Buscar por»**: un botón por cada socio y tutor de la familia (tutor 1 y 2 de la ficha) con su **nombre y primer apellido** (`Conciliacion::claves()`; sin partículas: «de la Fuente» → «Fuente»), que rellena el buscador con un clic. Sin JavaScript son enlaces con `q`.
- **Sugeridos por nombre**: además de la referencia en el concepto y el mismo ordenante, se sugiere el ingreso que lleva **todas las palabras de una de esas claves como palabras enteras** («NURIA FERRER MAS» → «Núria Ferrer»; «MARIANO GARCIAS» no es «María García»), con el motivo «Lleva el nombre «…»». Es una sugerencia, no conciliación automática. Solo en esta ventana: el listado del banco no lee los nombres de todas las familias (serían consultas al CRM por cada una).
- **Elegir**: con JavaScript, rellena el campo oculto `banco`, la fecha, el importe y «transferencia» (fijos), enseña el ingreso elegido y «Quitar el ingreso», y cierra la ventana **sin recargar** la ficha. Sin JavaScript, «Elegir» vuelve a la ficha con `?banco=ID` y `TesoreriaView::banco_para()` lo pone (si sigue pendiente y es un ingreso). El servidor lo procesa igual que en la v0.20.
- `Conciliacion::pendientes_para()` ya no corta en 200: la ventana trae todos los ingresos por conciliar y el buscador los filtra.
- Pruebas en `tests/banco-gestion.php`, parte E (claves, sugerencias por nombre, buscador, la ficha con y sin ingreso elegido, la ventana con y sin búsqueda, quién la abre). El JavaScript se comprobó con una simulación (ficha y ventana volcadas con datos inventados y un servidor de PHP): abrir, teclear sin acentos, Enter, atajo, elegir, quitar, enviar y la vista de móvil.
- **P-77** (doc 09), resuelta: el usuario deja las sugerencias por nombre como están (2026-10-06).

## v0.25.0 · la ficha sin recargar y eliminar un pago con su historial (peticiones del usuario, 2026-10-08)

- **«Al conciliar un pago no se refresca el listado»**: en *Banco* el listado ya se cambiaba sin recargar (`parcial.js`, v0.20), pero al conciliar o deshacer se volvía a la lista **sin los filtros**: ahora vuelve a la misma vista filtrada (`View::url_actual()`). Lo que sí recargaba la página entera era la **ficha de la familia** en el control de pagos: ahora es una zona `data-asde-parcial="familia"` y registrar un pago (también con un ingreso del banco), un ajuste o eliminar cambian **solo la ficha**. `tesoreria.js` vuelve a preparar el formulario del pago tras cada cambio (evento `asde:parcial`) y `area.js` recuerda los plegables nuevos.
- **Eliminar un pago o un ajuste** («En la sección de pagos poder eliminar un pago generando su correspondiente historial»): cada movimiento de la ficha tiene **«Eliminar…»**, que pide el **motivo** (obligatorio). No se borra nada: el movimiento queda **anulado** con quién, cuándo y por qué (columna nueva `anulado_motivo` en `asde_tesoreria_movimientos`; `Tesoreria\Pagos::eliminar()`), deja de contar en la cuota y desaparece de los movimientos y de «Mis pagos» de la familia.
  - Si el pago vino de **conciliar un ingreso del banco**, el ingreso **vuelve a estar por conciliar** (`BancoTable::por_pago()`; queda en el registro de actividad como `banco_desconciliado`) y el aviso lo dice con su fecha.
  - Si vino del **cobro de una remesa SEPA**, la remesa lo sigue dando por cobrado (es lo que dijo el banco): se avisa antes y después.
  - «Deshacer» una conciliación en *Banco* anula el pago con el motivo «Se deshizo la conciliación con el banco.» (lo guardado va siempre en castellano).
- **Historial de la ronda** (plegable y recordado): cada registro y cada eliminación, lo más reciente primero, con quién, el origen (a mano, con un ingreso del banco, con el cobro de una remesa) y el motivo de lo eliminado (`Pagos::historial()`).
- Quién puede eliminar: quien registra pagos (como el «Anular» de antes). Supuesto **P-94** (doc 09).
- Pruebas: `tests/banco-gestion.php`, parte F (sin motivo no se elimina, ni desde otra familia; el ingreso vuelve a pendiente; el registro de actividad; eliminarlo dos veces; el aviso de la remesa; el orden del historial y la ficha). Comprobado en el navegador en un WordPress aparte con datos inventados (doc 10).
