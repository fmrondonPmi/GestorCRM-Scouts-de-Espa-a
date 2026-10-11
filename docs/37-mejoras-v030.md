# 37 · Mejoras de la v0.30: inventario y uniformes (decisiones del usuario, 2026-10-11)

Respuestas del usuario del 2026-10-11 (doc 09, al final) que tocan el **inventario** (doc 32) y
los **pedidos de uniforme** (doc 24). Los supuestos (lo que se ha decidido sin preguntar) van
marcados **S-** y se recogen también en el doc 09 (**P-105 a P-116**) para que el usuario los
confirme.

Base de datos: dos columnas nuevas en `{prefix}asde_inventario_compras` (`comprada_en`,
`comprada_por`), que `dbDelta` añade sola al actualizar. Registro de emails: un tipo nuevo,
`compra_revisada` («Lista de compra revisada»). Auditoría: `inventario_compra_comprada`.

## 1. P-62 · El aviso del inventario, al revés

Antes, al marcar un material para una lista de compra, se preguntaba «¿seguro?» si había **menos**
de 5 unidades. Ahora se pregunta cuando **ya hay 5 o más**: «Ya hay N unidades de «X» en el
inventario. ¿Seguro que hace falta comprar más?» (no bloquea: si se cancela, se desmarca).

- `assets/js/inventario.js` (y sus textos en `TextosJs::LISTA`); sin JavaScript, el mismo aviso
  sale en «Nueva lista de compra» junto a ese material («Ojo: ya hay N unidades…»).
- `Inventario\Servicio::ya_hay_bastantes()`; el umbral sigue siendo `Servicio::umbral()` (5, con el
  filtro `asde_ap_inventario_umbral`). **S-1:** con el umbral a 0 no se pregunta nunca.
- **La etiqueta amarilla de «pocas unidades»** (menos de 5) **se queda** en la lista del inventario.
- El texto de ayuda de la lista (`templates/inventario/lista.php`) y el doc 32 lo dicen al revés que antes.

## 2. P-63 · Material nuevo en la lista de compra

Al preparar una lista se pueden añadir líneas de algo que **no está en el inventario**:
descripción, cantidad y precio estimado (opcional).

- En «Nueva lista de compra», debajo del material marcado, el cuadro **«Material nuevo (no está
  en el inventario)»** con una fila en blanco y **«Añadir otra línea»**: con JavaScript añade la
  fila sin recargar; sin él, vuelve al formulario con lo escrito y una fila más. Una fila sin
  descripción ni precio se ignora; un precio sin descripción es un error (dice la línea).
- El formulario sale aunque no se haya marcado nada del inventario: una lista puede ser **solo
  de material nuevo**. En el listado, **«Lista de material nuevo»**; en el inventario, «Crear una
  lista de compra con los marcados» sin nada marcado pregunta si se quiere una lista solo de
  material nuevo (antes avisaba de que había que marcar algo).
- Se guarda como una línea más con `material` = 0. Sale **en la lista** (con la etiqueta «Material
  nuevo» y sin «En inventario»), **en su PDF** («Material nuevo» en la columna del tipo) y **en el
  email a Tesorería** («Material nuevo (no está en el inventario)», en la tabla común
  `templates/email/compra-tabla.php`). Cuenta para el total estimado.
- **S-2:** «al crear o editar»: las listas siguen sin editarse una vez enviadas (S-5 del doc 32);
  el material nuevo se escribe al prepararla (y al volver al formulario por un error o con «Añadir
  otra línea»). Para cambiar una lista ya enviada, se cancela y se hace otra.
- **S-3:** en la lista, el material nuevo no lleva tipo ni nº de serie; se le pone el tipo después,
  en el inventario.

## 3. P-65 · Email a quien pidió la lista al aprobarla o rechazarla

Cuando Tesorería da el visto bueno o rechaza una lista, se envía un email a **quien la creó**
(`Inventario\AvisoRevision`, plantilla `templates/email/compra-revisada.php`), en su idioma:

- Con el **estado** (visto bueno: «ya la puedes descargar en PDF» y que la marque como comprada
  cuando compre el material; rechazo: con el **motivo**), quién la revisó, la tabla del material y
  el **enlace a la lista** (pide iniciar sesión).
- El email es el de su cuenta (`Contacto::email`: el del CRM o el guardado cifrado,
  `Usuarios\EmailCuenta`). Queda en el **registro de emails** como los demás (tipo «Lista de
  compra revisada», destino enmascarado, vista previa con el estado que tenía al enviarlo).
- Tesorería ve en el aviso qué ha pasado: «Se ha avisado por email a quien la pidió», «Quien la
  pidió no tiene email en el área privada: díselo tú» o que no se ha podido enviar.
- **S-4:** solo a quien la creó (no al resto de Tesorería). No se envía si la revisa la misma
  persona que la pidió (Tesorería con su propia lista) ni si su cuenta ya no está activa. Sin email:
  queda anotado como «Sin email» en el registro. Deshacer el visto bueno o cancelar la lista no
  envían email.

## 4. P-66 · «Marcar como comprada»

En una lista **aprobada**, el cuadro «¿Ya se ha comprado?» con el botón **«Marcar como comprada»**
(pide confirmación: «No se puede deshacer»). Al marcarla (`Inventario\Servicio::comprar()`):

- La lista pasa al estado nuevo **«Comprada»** (quién y cuándo). El cambio de estado es atómico
  (`UPDATE … WHERE estado = 'aprobada'`): **no se puede marcar dos veces** ni sumar dos veces,
  aunque dos personas pulsen a la vez.
- Cada material de la lista **suma la cantidad comprada** a su material del inventario
  (`MaterialesTable::sumar()`, atómico). El **material nuevo** se **da de alta** con esa cantidad,
  su precio estimado, el año de compra actual y la nota «Comprado con la lista de compra LC…»; en
  el detalle de la lista, cada línea dada de alta enlaza a su material.
- Auditoría: `inventario_compra_comprada` con los **ids** de los materiales sumados y de los dados
  de alta (sin descripciones ni datos personales).
- Una lista comprada se sigue pudiendo descargar en PDF (que lo dice), no se cancela ni se deshace
  su visto bueno, y tiene su filtro en el listado.
- **S-5:** la puede marcar cualquiera que entra en el inventario (como S-1 del doc 32: todos
  editan), no solo quien la pidió o Tesorería.
- **S-6:** se suma lo que dice la lista; si se compró otra cantidad, se corrige después en el
  material. El precio, el año y las notas del material que ya estaba no cambian.
- **S-7:** si un material de la lista se dio de baja mientras tanto (o sumarle pasaría de 99.999
  unidades), no se toca: se da de alta uno nuevo con los datos de la línea (y el tipo, el modelo y
  la talla del material).

## 5. P-67 · Importar: los repetidos suman

Al importar un CSV (`Inventario\Importador::importar`), una fila **sin nº de serie** con la misma
descripción (sin distinguir mayúsculas, acentos ni espacios de más) y el **mismo tipo** que un
material que ya está en el inventario, **o que otra fila del mismo fichero**, **suma su cantidad**
a ese material en vez de crear otro (`Importador::clave_material()`).

- Las **camisas del uniforme** (tipo «Camisa uniforme») solo suman si coinciden también el
  **modelo y la talla**.
- El **informe** de la importación dice cuántas filas se han sumado («N filas sumadas a un material
  que ya estaba»), y la auditoría (`inventario_importado`) lo cuenta (`sumados`). «Cómo tiene que
  ser el fichero» lo explica.
- **S-8:** solo suman a materiales sin nº de serie y que no estén dados de baja (una fila igual a
  uno dado de baja entra como material nuevo); los demás datos de la fila (precio, año, notas) no
  cambian el material que ya estaba. Si al sumar se pasaría de 99.999 unidades, la fila sale como
  error, con el motivo.

## 6. P-48 · Un scouter pide uniforme para los de su sección

En «Mi uniforme» (`Frontend\Views\UniformeView`), un **scouter** puede hacer un pedido para
**cualquier socio/a con membresía activa de sus secciones**, además de para sí mismo
(`Uniformes\Servicio::para_quien()`). Las familias, como antes (sus menores).

- El **precio de la camisa** es el de la persona para la que es (`Servicio::precio_camisa()`: la
  primera de un adulto Scouter o Voluntariado, gratis y pagada «sin coste»; los demás, 25 €).
- Con **más de 5 personas**, «¿Para quién?» es un **desplegable agrupado** («Tú», «Tu familia» y una
  por sección, por apellidos; `Servicio::agrupar_para_quien()`), con el precio de la camisa en cada
  opción (`uniforme.js` lo cambia al elegir). Con 5 o menos, los botones de siempre.
- **Rendimiento (doc 26):** las personas salen de `PersonaRepository::todas()` (la instantánea):
  una lectura para todo el grupo, ninguna consulta al CRM por persona; los precios, con una sola
  consulta de los pedidos de todas (`Servicio::precios_camisa()`). El menú, el inicio y las
  pestañas solo necesitan saber si la cuenta pide para alguien: usan `Servicio::propios()` (lo
  suyo y su familia, sin leer el CRM).
- La auditoría del pedido (`uniforme_pedido`) anota `para_otros` cuando es para otra persona.
- **S-9:** «scouter» = cuenta con el rol Scouter vigente; «sus secciones» = las de su cuenta (las
  del alta). Si entre ellas está «Scouters» (Kraal), puede pedir para el resto del Kraal.
  Coordinación, Tesorería o Secretaría sin el rol Scouter no piden para otros desde aquí (Coordinación
  y Tesorería ya asignan camisas del inventario desde la gestión, v0.29).
- **S-10:** en «Tus pedidos» el scouter ve también los que ha pedido para otros y los puede
  cancelar mientras nadie los haya validado; «Lo he recibido» lo sigue confirmando el socio/a o su
  familia, que también ve el pedido (y lo puede cancelar igual). No se avisa por email a la familia
  de un pedido hecho por el scouter.
- **S-11:** el desplegable, a partir de 6 personas; cada persona va en la primera de las secciones
  del scouter en la que está.
- **S-12:** si el CRM no responde, el scouter sigue pudiendo pedir para sí mismo (y su familia).

## Pruebas

`tests/mejoras-v030.php` (CRM simulado, usuarios `prueba-v030-…`, sin instantánea, emails
capturados, limpieza por ids en el `finally` y el material que ya había, con su cantidad de
antes): **72 comprobaciones**, una o varias por punto (el aviso al revés en el servidor y en el
JS, las líneas de material nuevo y su validación, «Añadir otra línea» sin JavaScript, la lista
con material nuevo en el detalle, el PDF y el email a Tesorería, los emails de visto bueno y
rechazo con su registro y su vista previa, sin email y la propia lista de Tesorería, «Marcar como
comprada» con lo que suma y da de alta, una sola vez y con la auditoría, el material dado de baja,
los repetidos al importar —mayúsculas, acentos, espacios, otro tipo, otra fila, nº de serie,
camisas con modelo y talla, el máximo, dados de baja— y su informe, y el uniforme para la sección:
quién sale, una sola lectura del CRM, precios con una consulta, el desplegable agrupado, los
pedidos, otra sección no, la familia como antes y los botones con pocas personas).

Cambiadas por un cambio previsto: `tests/inventario.php` (el aviso de la lista nueva, ahora con 5
o más) y `tests/uniformes.php` (el scouter de Lobatos ya pide para Leo, de su sección, pero no
para Nora, de Scouts; y cancela el que ha pedido él).

Revisado en un banco estático con el HTML de las plantillas y datos inventados: el aviso al marcar
(con 6 y con 5 pregunta, con 2 no; cancelar desmarca), la lista sin nada marcado, «Añadir otra
línea» (nombres, ids y etiquetas de la fila nueva, foco, total sin contar la fila en blanco) y el
precio de la camisa al cambiar de persona en el desplegable; a 390 px, sin desbordes.
