# 45 · Lote de la v0.37.0 (2026-10-16)

Cuatro peticiones del usuario sobre la gestión de pagos de Tesorería. Sin tablas ni columnas nuevas (un fichero nuevo: `Tesoreria\HistorialPagos`, que solo lee).

## 1. Historial de pagos de la familia: todo pago, a la vista

**Petición:** «En la gestión de pagos, todo pago asociado a una familia debe poder visualizarse en su historial de pagos».

- Hasta la v0.36 el historial de la ficha (antes «Historial de la ronda») solo tenía lo de `asde_movimientos`: la cuota, sus ajustes y los cobros del banco y de la remesa SEPA. Los pagos de **eventos** (`asde_evento_movimientos`, por inscripción) y de **uniformes** (columnas `pago_*` del pedido) no salían.
- Ahora se llama **«Historial de pagos»** y junta, de las personas de la familia, la cuota (con lo eliminado), los pagos, devoluciones y becas de los eventos y los pagos de los uniformes, lo más reciente primero, con quién lo registró y cuándo. Lo anulado sale tachado. Los de eventos y uniformes **no se limitan a la ronda** (no pertenecen a una ronda).
- Es solo lectura: `Tesoreria\HistorialPagos::otros()` reúne los datos; la plantilla `tesoreria/familia.php` los mezcla con `Pagos::historial()`.
- **P-165 (supuesto):** los pagos de eventos y uniformes salen en el historial **de todas las rondas** y no se pueden eliminar desde la ficha de la familia (se anulan donde se registraron: «Inscripciones» del evento o «Gestión de pedidos»). El cobro de una remesa SEPA de un evento ya sale como pago del evento.
- Pruebas: `eventos-economia.php` parte G0; `banco-gestion.php` (título nuevo).

## 2. «Registrar pago» a un evento

**Petición:** «En el registrar pago debería también poder asociarse un pago a un evento existente y que esté en periodo de inscripción».

- Si el módulo **Eventos** está activo y quien registra puede cobrar eventos (Tesorería: `asde_eventos_economia`), «Registrar pago» tiene el desplegable **«Pago de»**: *La cuota de la ronda* (por defecto) o cada evento **publicado, con la inscripción abierta** (`Inscripciones::plazo() = 'abierta'`), con las cuentas sin cerrar y en el que **la familia tiene algo pendiente**.
- El pago llama a `Cobros::pagar_familia()` (el mismo de «Cobros» del evento): se reparte entre las inscripciones de la familia con algo pendiente y recalcula su estado. **No toca la cuota de la ronda.**
- **P-166 (supuesto):** para un evento solo valen **efectivo, transferencia o Bizum** (las formas de los eventos) y **no** se asocia un ingreso del banco (la conciliación del banco es de la cuota). No se paga más de lo pendiente. Quien no cobra eventos (un scouter) no ve el desplegable.
- Pruebas: `eventos-economia.php` parte G0 (formas no válidas, banco, de más, evento inexistente, pago correcto, historial y anulación).

## 3. Informes de pagos al alcance en Tesorería

**Petición:** «En tesorería debería de estar también la posibilidad de acceder directamente a los informes relacionados con los pagos».

- En la barra de pestañas de Tesorería, dos accesos directos: **«Informe de pagos»** (el informe predefinido «Pagos de la cuota»; `InformesTable::predefinido()`) y **«Todos los informes»**.
- **P-167 (supuesto, para confirmar):** salen a quien **ya puede ver informes y pagos** (`asde_reports` + `Catalogo::ve_pagos()`): scouters, Secretaría y Coordinación. El rol **Tesorería a secas no tiene `asde_reports`** y, sin visión global, un informe solo le daría las secciones que no tiene; no se ha cambiado su ámbito. Si el tesorero/a usa una cuenta solo de Tesorería, hay que decidir si recibe `asde_reports` y ver todo el grupo en los informes de pagos.

**v0.37.1:** el usuario confirmó el P-167: el rol Tesorería recibe `asde_reports` y en los informes ve a **todo el grupo**, pero solo las columnas de identidad (nº, nombre, apellidos, sección) y las de pagos; nunca contactos ni salud.

## 4. Control de pagos: Excel bonito, no CSV

**Petición:** «En control de pagos, el descargar debe ser un Excel bonito, no un CSV».

- «Descargar Excel» (`?formato=xlsx`; el enlace `csv` de antes sigue valiendo y da el Excel). Hoja con **título y subtítulo** (grupo, ronda, fecha), cabecera de color con filtros y fila fija, **bordes y filas alternas**, importes en **euros** (formato de moneda, se pueden sumar), hijos como entero, **estado con color** (verde pagada, ámbar pendiente/parcial, rojo con plazos vencidos, gris sin cuota), **fila de totales** y ajustado a una página de ancho al imprimir.
- `Informes\Xlsx::generar()` / `descargar()` aceptan un quinto parámetro `$opciones` (`titulo`, `subtitulo`, `tipos`, `colores`, `totales`); sin él, la hoja sencilla de los informes sigue igual (se comprobó byte a byte el orden de los atributos).
- El .xlsx no se ha abierto en Excel desde aquí: la prueba comprueba que el zip abre y que los XML están bien formados; conviene abrirlo una vez en Excel/LibreOffice.

## Para producción

Al actualizar a la 0.37.0 no hay cambios en la base de datos. Las traducciones (22 textos) son una primera versión sin revisión nativa.
