# 16 · Banco: importar movimientos y conciliar (v0.6)

## Decisión del usuario (2026-09-26)

- Se importan **ficheros**: Colonya los da en **Norma 43** o en **CSV**.
- La conexión directa con la API PSD2 de Redsys queda descartada: exige ser proveedor de pagos autorizado (doc 14).

## Dónde

- **Tesorería → Banco** (`/tesoreria/banco/`).
- Solo **Secretaría, Coordinación y Tesorería** (capacidad `asde_tesoreria_config`) y administradores.

## Flujo

1. **Leer el fichero.** Se sube el `.n43`, `.q43`, `.aeb`, `.txt`, `.dat` o `.csv` (máximo 5 MB).
   - El formato y el juego de caracteres se detectan solos, y también se pueden elegir.
   - **El fichero no se guarda en ningún sitio.** Se lee y se guarda una vista previa cifrada durante 30 minutos, solo para quien lo subió.
2. **Vista previa:**
   - por cada cuenta: periodo, saldo inicial y final, abonos, cargos y nº de movimientos;
   - errores y avisos;
   - movimientos marcados como *Nuevo*, *Ya importado* o *Nuevo (posible duplicado)*;
   - si es un CSV, las columnas reconocidas, que se pueden cambiar.
3. **Importar.** Solo entran los movimientos nuevos. Si el fichero tiene errores, no se puede importar.
4. **Conciliar los ingresos con las familias** (ver más abajo). Los gastos se marcan como *revisado* mientras no exista el módulo de gastos.

## Norma 43 (`Tesoreria\Banco\Norma43`)

Cuaderno 43 de AEB, CECA y UNACC (junio de 2012): registros de 80 posiciones.

| Registro | Uso |
|---|---|
| 11 | Cabecera de cuenta: entidad, oficina, cuenta, periodo, saldo inicial (clave 1 = deudor, 2 = acreedor), modalidad |
| 22 | Movimiento: fechas de operación y valor, concepto común y propio, clave 1 = cargo / 2 = abono, importe (2 decimales implícitos), documento, referencias |
| 23 | Complementarios (01–05). En la **modalidad 3**, en transferencias (concepto 02 o 04), el 23-01 es el **ordenante** (66 posiciones) y el resto es el concepto |
| 24 | Equivalencia de divisa (se ignora) |
| 33 | Final de cuenta: **se comprueba** que el nº y el total de cargos y abonos y el saldo final cuadran con lo leído |
| 88 | Fin de fichero: **se comprueba** el nº de registros (se admite con o sin el propio 88) |

- **Juego de caracteres:** CP850 (el de la norma), Windows-1252 o UTF-8, detectado automáticamente.
  - Las posiciones se cuentan en bytes: si el fichero viene en UTF-8, se pasa antes a un byte por carácter.
- **Si no cuadra algo** (totales, recuento, un registro fuera de sitio, un 33 que no corresponde a la cuenta), se trata como error y el fichero no se importa. Suele indicar que está incompleto o dañado.

## CSV (`Tesoreria\Banco\CsvBanco`)

- Detecta el **separador** (`;`, `,` o tabulador).
- Localiza la **fila de títulos**, saltando las líneas informativas previas; de esas líneas saca la **cuenta** si aparece un IBAN.
- **Reconoce las columnas por su título:**
  - Fecha (o F. operación, Fecha contable…) y Fecha valor;
  - Concepto (más «Más datos», «Observaciones»…);
  - Importe con signo, o bien Cargo y Abono por separado;
  - Ordenante o beneficiario y Referencia.
- **Si no reconoce las columnas**, se eligen a mano en la vista previa. La elección se **recuerda** para los ficheros con los mismos títulos (opción `asde_ap_banco_csv`, por grupo).
- **Formatos que entiende:**
  - importes: 1.234,56 · -45,00 · 1234.56 · 1,234.56 · 45,00- · +30 €;
  - fechas: dd/mm/aaaa, d-m-aa y aaaa-mm-dd.
- Las líneas sin fecha válida (totales, saldo final, pie) se ignoran.
- Un Excel (`.xlsx`) se reconoce y se pide exportarlo como CSV.

## Datos guardados (`Tesoreria\Banco\BancoTable`)

| Tabla | Contenido |
|---|---|
| `{prefix}asde_banco_lotes` | Una fila por fichero importado: fichero, huella SHA-256 del contenido, cuentas, periodo, saldo final, leídos, nuevos, duplicados, quién y cuándo |
| `{prefix}asde_banco_movimientos` | Cuenta enmascarada, fechas, importe con signo, conceptos común y propio, documento y referencias, concepto y ordenante cifrados, HMAC del ordenante, huella, estado, familia y pago |

- **Cuenta enmascarada:** solo entidad, oficina y las 4 últimas cifras (`2056 0001 ******5678`).
- **Concepto y ordenante cifrados** con libsodium: son nombres de personas. Del ordenante se guarda además un **HMAC** para reconocerlo en pagos siguientes.
- **Sin duplicados:** cada movimiento tiene una huella (HMAC de todos sus datos más el nº de aparición dentro del fichero).
  - Dos movimientos idénticos el mismo día cuentan como dos.
  - Al reimportar o importar un fichero que se solapa con otro, lo repetido se ignora.
  - *Posible duplicado:* misma cuenta, fecha e importe que otro movimiento ya importado (por ejemplo, desde otro formato). Se importa igualmente y se avisa.
- Todo va por grupo: un grupo no ve los movimientos de otro.

## Conciliación (`Tesoreria\Banco\Conciliacion`)

- **Sugerencias**, siempre confirmadas por una persona:
  1. **referencia de familia** en el concepto o las referencias: `F684001`, `F-684-001`, `F 684 001`… Solo del grupo en el que se trabaja;
  2. **mismo ordenante** que un pago ya conciliado con una familia (sugerencia menos fiable).
- **Conciliar:**
  - se elige la familia (desplegable predictivo) y la ronda (por defecto, la de la fecha: de septiembre a agosto);
  - se crea el **pago en el control de pagos** de la familia (origen `banco`, forma «transferencia»);
  - el movimiento queda *Conciliado*.
- **Conciliar todos los sugeridos por referencia:** un botón para los que llevan la referencia de familia.
- **Revisado (no es cuota):** para donativos, subvenciones o gastos.
- **Deshacer:** vuelve a *Pendiente*; si estaba conciliado, **anula** el pago creado (no se borra: queda constancia).
- Auditoría: `banco_importado` (con la huella del fichero), `banco_conciliado`, `banco_revisado` y `banco_deshecho`.

## Pruebas

En `tests/grupos-emails-banco.php`, partes D, E y F. Usan ficheros sintéticos con datos inventados y un grupo ficticio (999) que se borra al terminar:

- Norma 43 en CP850 y en UTF-8;
- totales del 33 y recuento del 88 incorrectos, y fichero cortado;
- CSV con líneas informativas, columnas desconocidas y cargo/abono separados;
- importes y fechas en varios formatos;
- reimportación y ficheros solapados;
- cifrado en la base de datos;
- sugerencias por referencia y por ordenante;
- conciliar, deshacer y revisado;
- aislamiento entre grupos.

## Pendiente

- Probarlo con un **fichero real de Colonya** (Norma 43 y CSV) para confirmar la modalidad y las columnas.
- Módulo de **gastos**: justificantes y categorías (P-24). Los cargos del banco se podrán enlazar con ellos.
- Sugerencia por **importe**, cuando cuadre con la cuota o el plazo pendiente de una familia.
