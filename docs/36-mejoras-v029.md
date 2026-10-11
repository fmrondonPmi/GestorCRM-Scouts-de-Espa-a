# 36 · Mejoras de la v0.29 (petición del usuario, 2026-10-11)

Lista de peticiones del usuario (una sola tanda) y cómo se resuelve cada una. Los supuestos
(lo que se ha decidido sin preguntar) van marcados **S-** y se recogen también en el doc 09
(P-98 en adelante) para que el usuario los confirme.

## 1. Descargas que dejaban la página en blanco

**Causa:** el tema Enfold tiene activado el *preloader* con transiciones de página
(`preloader` y `preloader_transitions` en sus opciones; también en el Local, que es copia del
sitio). Al pulsar un enlace, Enfold pone la pantalla en blanco y espera a que cargue la página
nueva; una descarga (CSV, PDF, Excel) **no cambia de página** y el blanco se queda. No era un
error del servidor: el fichero se descargaba, pero la pantalla quedaba tapada.

**Arreglo:** `area.js` atiende en la fase de captura los enlaces de descarga (`formato=…`,
`download`, `data-asde-descarga`) y no deja que el clic llegue al tema; además, esos enlaces
llevan la clase `no-transition` (la que Enfold respeta), por si el JavaScript del área no está.
Vale para todas las descargas del área (informes, Tesorería, uniformes, inventario).

## 2. Informes: Excel e impresión en una ventana

- **«Descargar Excel»** en vez de «Descargar CSV»: un `.xlsx` de verdad (`Informes\Xlsx`, sin
  librerías: el XML del formato y un zip con `ZipArchive` o, si el servidor no lo tiene, con
  PclZip de WordPress). Primera fila en negrita y fija, filtros en la cabecera, anchos de columna
  según el contenido, números como números. Mismas protecciones que el CSV (registro en la
  auditoría, nonce, aviso de datos de salud).
- **«Imprimir»** abre en la ventana emergente una **versión para imprimir** del informe
  (`?…&formato=imprimir`): una página propia, sin el tema, con el título, los filtros, la fecha y
  la tabla entera (todas las columnas, el texto se parte en vez de cortarse; en horizontal si hay
  muchas columnas; la cabecera se repite en cada hoja). La ventana tiene «Imprimir» (imprime solo
  el informe) y «Abrir en otra pestaña». Sin JavaScript, el enlace abre esa página en otra
  pestaña.

## 3. Cuadros plegables

- **En todas las páginas del área** (`area.js`, v0.29): cada cuadro (`section.asde-card`) con
  título se puede contraer con el botón ▾ junto al título (o pulsando el título). Contraído solo
  se ve su cabecera. Se recuerda en el navegador (como los plegables de la v0.20). Un cuadro se
  queda fuera con `data-asde-no-plegable`; `data-asde-plegable="clave"` le da una clave fija para
  recordarlo (si no, se usa la página y el título).
- **Ficha:** los dos cuadros de arriba (nombre y membresía en el CRM) también se pliegan;
  contraída, la cabecera enseña solo el **nombre completo y la sección o el cargo**. «Contraer
  todo / Expandir todo» pasa arriba del todo y pliega también esos cuadros.
- **Personas:** el resumen (membresías activas, siguiente nº libre, incidencias) es plegable; el
  título y «Alta de socio/a» quedan siempre a la vista.

## 4. Listado de personas

- «Dar acceso» pasa a ser un enlace (como «Ver ficha»): filas más bajas.
- El botón «Filtrar» va pegado al cuadro de búsqueda.
- **Busca al escribir** desde la 3.ª letra (y al vaciar el cuadro), sin recargar la página: solo
  se actualiza la tabla (`parcial.js`, zona `personas-lista`). El cuadro no pierde el foco. Lo
  mismo con «Mostrar», la ordenación y las páginas.

## 5. Cambios propuestos

- **«Resueltos» salía vacío:** solo enseñaba las propuestas de las familias ya revisadas. Los
  cambios que hacen directamente Secretaría, Coordinación o los scouters en una ficha se guardan
  en el CRM sin pasar por la bandeja (solo quedaban en la auditoría). Ahora «Resueltos» enseña
  las dos cosas: las propuestas revisadas y los **cambios hechos directamente en las fichas**
  (de la auditoría: qué datos, quién y cuándo; los valores no se guardan, regla 6). S-1: los de
  los últimos 90 días.
- **Agrupados por persona** (pendientes y resueltos): una fila por persona con cuántos cambios
  tiene. «Revisar» abre en la ventana todos los cambios de esa persona con una casilla en cada
  uno, «Seleccionar todos» arriba de la columna y **un solo botón «Aprobar los seleccionados»**
  (y «Rechazar los seleccionados» con un motivo). Se guardan en el CRM de una vez (una sola
  escritura por persona). S-2: el usuario escribió «en la sección de resueltos», pero aprobar
  solo tiene sentido con los pendientes; en «Resueltos» la ventana solo enseña el detalle.

## 6. Uniformes e inventario

- **Tipo «Camisa uniforme»** en el inventario de cada grupo (se crea solo; no se puede borrar).
  Sus materiales llevan dos datos más: **modelo** (chico = camisa masculina, chica = camisa
  femenina, o los modelos que tenga el grupo en la configuración de uniformes) y **talla** (las
  del modelo). El importador de CSV acepta las columnas «Modelo» y «Talla».
- **Gestión de uniformes → «Camisas del inventario»**: lo que hay por modelo y talla, y dos
  herramientas:
  - **Servir del inventario** un pedido con camisa que aún no está en un pedido al proveedor: se
    descuenta una camisa de esa talla y el pedido pasa a «pendiente de entregar» (si lleva
    también insignias, esas siguen yendo al pedido del proveedor; la camisa ya no cuenta en el
    resumen para el proveedor). Se puede deshacer (la camisa vuelve al inventario).
  - **Asignar una camisa del inventario** a un socio/a que no la ha pedido: crea su pedido (con
    el precio de la camisa o el importe que se ponga; 0 = sin coste) ya servido del inventario.
    S-3: lo hace quien incluye en el pedido al proveedor (Coordinación, `asde_uniformes_pedido`)
    y solo si el módulo de inventario está activo en el grupo.
- Base de datos: columnas nuevas `especial` (tipos), `modelo` y `talla` (material) e
  `inventario_id` (pedidos de uniforme); las añade `dbDelta` al actualizar.

## 7. Segunda tanda (mismo día)

- **Personas: «Mostrar», el cuadro de búsqueda y «Filtrar» en la misma línea.** Lo partía el
  tema: Enfold pone `#top select { width: 100%; margin-bottom: 20px }` y el desplegable ocupaba la
  fila entera. La clase `asde-filter--una-linea` lo evita (regla con `#top`); en el móvil, si no
  cabe, baja a otra línea.
- **Informe «Fichas reducidas»** (predefinido, se añade solo al actualizar): la ficha reducida de
  cada socio/a con membresía activa, **una por página al imprimir**, no en tabla. Columnas, en este
  orden: nombre, apellidos, móvil, tutor/a 1, cesión de imagen, alergias, alergia a medicamentos,
  alergia alimentaria, intolerancia alimentaria, alimentos con intolerancia, régimen especial de
  comidas, tipo de régimen, trastorno alimentario y enfermedades (`InformesTable::predefinidos()`).
  - Presentación nueva de los informes, **«Una ficha por persona»** (`Informe::VISTAS`, en los
    filtros de la definición guardada: sin cambiar la tabla). En pantalla, una ficha debajo de otra;
    en la versión para imprimir, cada ficha en su página (`break-after: page`), con el grupo y el
    nombre del informe arriba. Cualquier informe propio puede usarla (editor → «Presentación»).
  - **«Sección»** en el resultado de cualquier informe (`?seccion=`): la sección sin editar el
    informe; el generador sigue limitando a un scouter a sus secciones. Va también en el Excel y en
    la versión para imprimir.
  - Tiene datos de salud: solo lo ve quien puede ver la salud (si no, el informe no sale en la
    lista) y cada consulta, descarga o impresión queda en la auditoría.

## Supuestos (P-98…P-102 del doc 09)

- S-1 (P-98): «Resueltos» enseña los cambios directos de los últimos **90 días**.
- S-2 (P-99): la ventana con casillas y «Aprobar los seleccionados» es la de **pendientes** (el
  usuario escribió «en la sección de resueltos»); en «Resueltos» la ventana solo enseña el detalle.
- S-3 (P-100): servir y asignar camisas del inventario lo hace **Coordinación**
  (`asde_uniformes_pedido` + `asde_inventario`); una camisa asignada **a 0 €** queda pagada «sin
  coste» sin pasar por Tesorería.
- S-4 (P-101): «chico» = camisa masculina y «chica» = camisa femenina (los modelos de ASDE); los
  modelos propios de un grupo salen con su nombre.
- S-5 (P-102): «Fichas reducidas» lleva el **tutor/a 1** solo (como se pidió), sin su teléfono;
  quien lo quiera puede copiar el informe y añadir «Móvil del tutor/a 1».

## Pruebas

`tests/mejoras-v029.php` (71 comprobaciones): Excel, versión para imprimir y fichas, plantillas y
JavaScript de descargas, plegables y Personas, cambios agrupados (una escritura por persona, avisos de
conflicto, rechazo con motivo, cambios directos), camisas del inventario (tipo, validación,
importación, servir, devolver, asignar, cancelar). El JavaScript se probó además en un banco estático
con datos inventados (plegar y recordar, «Contraer todo», «Todos», la descarga que no llega al tema,
buscar desde la 3.ª letra con una sola petición y sin perder el foco, la ventana de imprimir).
