# 53 · Módulo «Reintegros»

**Petición del usuario (2026-10-21).** Cada scouter (y Coordinación) puede pedir el reintegro de un gasto que ha pagado de su bolsillo. Rama `Reintegros` (desde `Tienda-Lineas`). Módulo **apagado por defecto** (regla 5), se enciende en wp-admin → Grupos Scouts → Módulos.

## 1 · Lo pedido y lo decidido

- Quién pide: **solo scouters (rol Scouter/Voluntario) y Coordinación**.
- La solicitud lleva: concepto, fecha del gasto, importe, **imagen (o PDF) del ticket o la factura**, **evento** (opcional) y **cómo prefiere cobrar: en efectivo o por transferencia**. Si es por transferencia, el IBAN lo escribe quien pide (P-183 ✅); se guarda **cifrado**, solo lo ven Tesorería y Coordinación y **se borra** cuando el reintegro se paga, se rechaza o se cancela.
- **Por transferencia → email a Tesorería con toda la información** (quién, concepto, importe, fecha, evento, IBAN y enlace para revisarlo). En efectivo, el aviso es más corto (sin IBAN).
- **Aprueba Tesorería.** **Lo marca como pagado Tesorería o Coordinación** (con fecha del pago y referencia opcional).
- Con **evento**, al marcarlo pagado se **crea el gasto del evento** en sus Cuentas (P-184 ✅): validado, pagado «de su bolsillo» por quien lo pidió, ya reembolsado, con el mismo justificante. Si las cuentas del evento están cerradas, el reintegro se paga igual y se avisa de que el gasto no se pudo añadir.

## 2 · Flujo y estados

`solicitado` → `aprobado` (Tesorería) → `pagado` (Tesorería o Coordinación). Además: `rechazado` (Tesorería, con motivo), `cancelado` (quien lo pidió, mientras esté `solicitado`). Nada se borra.

| Rol | Hace |
|---|---|
| Scouter y Coordinación | Pedir; ver y cancelar los suyos |
| Tesorería | Ver todos; aprobar o rechazar; marcar pagado |
| Coordinación | Ver todos; marcar pagado |

Capabilities: `asde_reintegros` (pedir: Scouter y Coordinación), `asde_reintegros_aprobar` (Tesorería), `asde_reintegros_pagar` (Tesorería y Coordinación). Quien tiene aprobar o pagar ve todos los reintegros del grupo.

## 3 · Datos y seguridad

- Tabla `{prefix}asde_reintegros` (grupo, solicitante, concepto, fecha, importe, evento, forma, `iban_enc`, justificante cifrado como el de los eventos —`Eventos\Justificantes`—, estado, motivo, quién revisó y pagó, gasto del evento).
- IBAN **cifrado** (`Crypto`) y **borrado** al terminar (pagado, rechazado o cancelado). El justificante, cifrado en `uploads/asde-privado/<grupo>/` y solo visible para quien lo pidió, Tesorería y Coordinación.
- Nada se ve entre grupos (todo por `Grupo::numero()`); cada acción comprueba capacidad y nonce; la auditoría anota los nombres de los campos, nunca el IBAN.
- Nadie aprueba su propio reintegro (P-185 ✅ propuesta).

## 4 · Supuestos a confirmar

- **P-183 ✅** El IBAN lo escribe quien pide. **P-184 ✅** Con evento, al pagar se crea el gasto del evento.
- **P-185:** el justificante es **obligatorio** (una imagen JPG/PNG/WebP o un PDF de hasta 5 MB); nadie aprueba su propio reintegro; la fecha del gasto no puede ser futura ni de hace más de un año.
- **P-186:** los emails a quien pidió (aprobado, rechazado con motivo, pagado) los recibe en su idioma; a Tesorería, uno por cuenta activa de Tesorería (o al email de contacto del grupo si no hay ninguna).
- **P-187:** el reintegro no toca la contabilidad de Tesorería (no genera un movimiento del banco): Tesorería lo paga por fuera y lo marca aquí.

## 5 · Avance

- ✅ **Hecho (2026-10-21, rama `Reintegros`, v0.47.0):** tabla `asde_reintegros` (`Reintegros\ReintegrosTable`), servicio (`Reintegros\Servicio`: pedir, aprobar, rechazar, cancelar, pagar y el gasto del evento), emails (`Reintegros\Avisos`, plantilla `email/reintegro.php`, tipo «reintegro» en el registro de emails), pantalla «Reintegros» (`ReintegrosView`, `templates/reintegros/index.php`, `assets/js/reintegros.js`), roles, módulo `reintegros` (apagado por defecto), página, menú con contador de pendientes y vaciado del IBAN al desinstalar.
- Pruebas: `tests/reintegros.php` (62: permisos, validaciones, cifrado del IBAN, emails, aprobar/rechazar/cancelar/pagar, gasto del evento con y sin cuentas cerradas, justificante, aislamiento por grupo y auditoría sin IBAN) y `modulos.php` al día. 62 textos nuevos con ca/eu/gl.
- **Pendiente:** ayuda de la pantalla y manuales (`data-paginas="reintegros"`) y capturas; enseñar la vista al usuario y preguntar qué cuadros empiezan desplegados (ahora todos abiertos salvo el historial).
