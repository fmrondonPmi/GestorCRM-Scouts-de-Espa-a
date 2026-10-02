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
