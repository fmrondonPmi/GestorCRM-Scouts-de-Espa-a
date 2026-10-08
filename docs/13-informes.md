# 13 · Generador de informes (v0.3.0)

## Requisitos del usuario

- Muy intuitivo y sencillo: consiste en **elegir las columnas** (los datos).
- **Scouters:** ven los asociados de **su sección**. **Secretaría y Coordinación:** visión **global**.
- Todos pueden **crear** informes nuevos.
- Solo socios con **membresía activa**.
- Salud (decisión del 2026-09-26): los scouters la ven de **su sección, solo lectura**.

## Uso (página *Informes*)

1. **Lista de informes:**
   - 4 predefinidos: *Listado de sección*, *Contactos de las familias*, *Alergias y alimentación* y *Autorizaciones*;
   - los compartidos por otras personas del equipo;
   - los propios.
   - Acciones: Ver, Duplicar, Editar y Borrar (solo los propios o, con visión de gestión, cualquiera no predefinido).
2. **Editor** en 3 pasos:
   1. **¿Qué datos?** Columnas agrupadas: Identificación, Membresía, Contacto, Familia/tutores, Autorizaciones y formación, Salud y alimentación. Cada grupo tiene los enlaces «Todas / Ninguna».
   2. **¿De quién?** Secciones (un scouter solo puede elegir las suyas), edad desde/hasta, «solo con datos de salud relevantes» y orden.
   3. **Ver o guardar**, con la opción de compartir con el equipo.
3. **Resultado:** tabla (con scroll horizontal si hay muchas columnas), resumen de filtros, **Descargar CSV** e **Imprimir** (estilos de impresión sin menús).

## Seguridad

- **El resultado nunca se guarda.** Se lee del CRM al verlo. En la BD solo se guarda la *definición* (`{prefix}asde_informes`: columnas y filtros en JSON).
- **Niveles del catálogo** (`Informes\Catalogo`):
  - `todos` → cualquiera con informes;
  - `salud` → `asde_view_health`;
  - `global` → Secretaría, Coordinación o administración. Por ejemplo, el **DNI**.
  - **Los IBAN no están en el catálogo.**
- **Ámbito:** el scouter queda limitado a sus secciones en la **consulta al CRM**, no solo en la pantalla. Un informe compartido muestra a cada persona solo lo que puede ver.
- **CSV:**
  - enlace con nonce;
  - separador `;` y BOM para que Excel lo abra bien en español;
  - protección contra **inyección de fórmulas**: los valores que empiezan por `= + - @` se neutralizan.
- **Auditoría:** `informe_ejecutado` e `informe_exportado`, con columnas, nº de filas y si incluye salud. También creado, modificado y borrado.
- Aviso visible cuando un informe incluye datos de salud.

## Columnas disponibles

- **Identificación:** nº socio, nº Scouts de España, nombre, apellidos, apodo, fecha de nacimiento, edad, género y DNI (global).
- **Membresía:** sección y cargo.
- **Contacto:** email, móvil, teléfono, dirección, población y CP.
- **Familia/tutores:** tutor 1 y 2 (nombre, móvil y email).
- **Autorizaciones y formación:** cesión de imagen, consentimiento de medicación (salud), certificado de delitos sexuales y su fecha, formación scout y de tiempo libre.
- **Salud y alimentación** (25 campos):
  - alergias y alergia a medicamentos;
  - alergia e intolerancia alimentaria;
  - régimen especial y trastorno alimentario;
  - tratamiento y medicación;
  - enfermedades y discapacidad;
  - vacunas, tarjeta sanitaria y seguro.

## Pruebas (2026-09-26)

- Un scouter de Escultas solo recibe Escultas (22). Sin DNI y con salud.
- Secretaría: visión global con DNI y filtro de edad 14–17 (25).
- *Listado de sección*: 107 activos. *Alergias y alimentación*: 36 con datos relevantes.
- Informe improvisado de Lobatos: 20. «Editar / guardar» conserva columnas y filtros, y guarda correctamente.
- CSV: 108 líneas (cabecera + 107).

## v0.25.0 · cuota y pagos de cada socio (petición del usuario, 2026-10-08)

«Que se pueda generar uno con el resumen de los pagos hechos y pendientes de cada asociado, para que cada scouter sepa rápido quién de su sección ha pagado y quién no».

- **Grupo de columnas «Cuota y pagos»** (nivel `pagos` del catálogo: quien entra en Tesorería, `asde_tesoreria_acceso`, si el grupo usa Tesorería): **Familia**, **Estado de la cuota** (pagado, pago parcial, pendiente, con plazos vencidos, sin cuota), **Cuota a pagar**, **Pagado**, **Pendiente**, **Vencido sin pagar** (lo de los plazos ya vencidos que falta) y **Pagos hechos** (fecha e importe de cada uno, sin los eliminados).
- Los datos son de **Tesorería** (no del CRM) y **de la familia de cada socio**: la cuota se calcula como en el control de pagos, con todos sus miembros aunque sean de otras secciones (`Generador::pagos()`). Quien no está en ninguna familia sale «Sin familia en Tesorería».
- **Filtros nuevos** en «¿De quién?»: **Según lo pagado** (todos, han pagado toda la cuota, les falta algo por pagar, con plazos vencidos sin pagar, sin familia en Tesorería) y **Ronda de la cuota** (la actual por defecto). Sin acceso a Tesorería, ni columnas ni filtro (con aviso).
- **Informe predefinido «Pagos de la cuota»**: nº de socio/a, nombre, apellidos, sección, familia, estado, cuota, pagado, pendiente y vencido, ordenado por sección. Se añade **también al actualizar** el plugin (`InformesTable::sembrar_predefinidos()` añade los que falten por nombre). Los predefinidos con columnas que el rol no ve no salen en la lista.
- Un scouter lo ve **solo de sus secciones** (como todos los informes). El resultado dice de qué ronda es la cuota y el filtro aplicado; el CSV lleva lo mismo.
- Supuesto **P-95** (doc 09). Pruebas en `tests/sepa.php` (su CRM simulado ya tiene familias con pagos hechos y pendientes): parte «Informe de pagos por socio».
