# 42 · Lote de la v0.34 (2026-10-15)

Cuatro peticiones del usuario en la misma sesión, una detrás de otra. Sin cambios de base de datos.

## 1. Banco: crear un tipo desde «Marcar como revisado»

**Petición:** «En el banco, al marcar un ingreso que no es una cuota, necesito poder añadir más tipos: que se pueda abrir una ventana modal para crear un nuevo tipo de ingreso».

- Junto al desplegable del tipo de cada fila hay un **«+»**. Con JavaScript abre **encima** de la ventana de «Marcar como revisado» una ventana pequeña («Tipo nuevo»: nombre y clase; la clase viene puesta según la fila sea ingreso o gasto). Al crearlo, el tipo entra en los desplegables en los que encaja (un tipo de ingreso, solo en los ingresos; «Gasto o ingreso», en todos) y en «El mismo tipo para todos», y **queda elegido en esa fila**. No se pierde lo que ya se había elegido ni la nota. Si el nombre ya existe, lo dice en la propia ventana.
- Sin JavaScript, el «+» lleva a «Tipos de gasto e ingreso» con un enlace «← Volver a «Marcar como revisado»» con los mismos movimientos elegidos (`?volver=`; se lee con `esc_url_raw` porque `View::get()` borraría los `%5B%5D` de los `m[]`).
- Servidor: la acción `tipo_crear` de `BancoView` contesta en JSON si se pide (`X-Asde-Json`, como el listado de usuarios), sin dejar el aviso para la página siguiente. La ventana pequeña está en un `<template>` de `banco-revisar.php` (fuera del formulario: no puede ir uno dentro de otro) y `banco.js` la abre con `showModal()`, **fuera de la tarjeta** (plegada, la escondería; desde la v0.29 todo lo plegable empieza plegado).
- Pruebas: `banco-gestion.php` (+6: el «+» de cada fila, la plantilla, el JSON correcto y el repetido, la vuelta sin JavaScript y que una dirección de fuera no vale). JavaScript probado en un banco estático: se abre encima, errores del servidor y respuesta que no es JSON, el tipo entra solo donde encaja, cancelar y Esc cierran solo la pequeña; en el móvil, la grande ocupa la pantalla y la pequeña no (`height: fit-content`).

## 2. Familias: buscador

**Petición:** «Hay que añadir un buscador en la sección de familias».

- Encima de la lista de familias, como en Personas: busca **al escribir desde la 3.ª letra** y solo cambia la lista (`parcial.js`, `data-asde-parcial-form`/`data-asde-buscar`); sin JavaScript, el formulario recarga la página.
- Encuentra por **nº de familia, cuenta familiar y nombre o nº de socio de cualquiera de sus miembros**, sin mayúsculas ni acentos (`FamiliasView`). Con algo buscado, debajo sale «X de N familias»; sin resultados, «Ninguna familia coincide con «…».».
- Pruebas: `mejoras-v034.php` parte C.

## 3. Ficha: «Socio/a desde» y el contador

**Petición:** «En la ficha, en la cabecera donde se ve el nombre, la fecha en la que se dio de alta la membresía y un contador de años, meses y días desde cuándo es socio».

- En la cabecera, junto a «Edad»: **«Socio/a desde 12/09/2018 (8 años, 1 mes y 3 días)»**.
- La fecha sale de las membresías del CRM (`Altas::socio_desde()`): el alta de la membresía activa y, hacia atrás, las que enlazan con ella sin cortes. Un cambio de sección cierra una y abre otra el mismo día (o el siguiente) y **no reinicia** la cuenta; quien se dio de baja y volvió cuenta desde la vuelta (**supuesto P-136**).
- Cuesta **una consulta más al CRM** (0,5–1,5 s): por eso solo sale al abrir la ficha desde Personas, no en la ficha propia ni en la de la familia (**supuesto P-137**). Si la consulta falla, la ficha sale igual, sin el dato.
- Pruebas: `mejoras-v034.php` partes A y B.

## 4. Arreglo: una baja hecha en el CRM

**Lo que vio el usuario:** «Un asociado al que se dio de baja en el CRM directamente sigue apareciendo de alta en la aplicación después de recargar; y al darle de baja me dice que no tiene membresía activa».

- **Por qué:** la web dice que alguien está de alta si su ficha tiene «Secciones actuales», y ese campo lo calcula el CRM con las **fechas** de la membresía (alta de hoy o anterior y sin baja o con la baja más adelante; P-74). «Dar de baja» (`Altas::activas()`), en cambio, exigía además `estado_c = activo`. Una membresía puesta en «baja» en el CRM **sin fecha de baja** (o con una fecha futura) sigue activa para el CRM: la web la enseñaba de alta (bien) y «Dar de baja» no la encontraba (mal). El CRM simulado de las pruebas copiaba el mismo supuesto, así que ninguna prueba lo veía.
- **Arreglo:** una sola regla, `Altas::activa()`, por las fechas, como el CRM; la usan «Dar de baja», «Membresía nueva», «Cambiar de sección» y «Socio/a desde». Ahora «Dar de baja» encuentra esa membresía y le pone la fecha: el CRM la quita de «Secciones actuales» y la web deja de enseñarla de alta. El CRM simulado calcula `active` igual que el real (solo por las fechas).
- **Para confirmarlo** con la persona real (**P-138**): `herramientas-desarrollo/crm-revisar-alta.php` (solo lectura; enseña de cada membresía las fechas, `estado_c` y `active`, y si «Secciones actuales» coincide):
  ```
  $env:ASDE_SOCIO = 'NUMERO'; & 'C:\Program Files\Git\bin\bash.exe' 'C:/Claude/Scouts De Balears  - Area Privada/herramientas-desarrollo/wp-local.sh' eval-file 'C:/Claude/Scouts De Balears  - Area Privada/herramientas-desarrollo/crm-revisar-alta.php'; Remove-Item Env:ASDE_SOCIO
  ```
- Pruebas: `altas-crm-escritura.php` (+2: la membresía en «baja» sin fecha sigue activa y la baja de la web la cierra) y `mejoras-v034.php` (la regla).

## Textos

14 textos nuevos con sus traducciones a ca/eu/gl (a mano): los de la ventana «Tipo nuevo», el buscador de Familias y «Socio/a desde» con años/meses/días.

## Manual

Sin actualizar todavía (los tres cambios visibles: el «+» del banco, el buscador de Familias y «Socio/a desde»).
