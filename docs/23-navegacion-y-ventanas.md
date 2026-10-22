# 23 · Navegación del área y ventanas emergentes (v0.10)

## Petición del usuario (2026-09-28)

- «En vez de que las fichas sean una nueva ventana, que sean **modal** dentro de la misma.»
- «Para dar de alta un usuario hay mucha vuelta: haz una revisión de cómo mejorar la navegación de la aplicación para que sea más intuitiva y sencilla.»

## Qué se ha cambiado

### Pestañas

| v0.9 | v0.10 |
|---|---|
| Inicio · Mis datos / Mi familia · Cambios · Informes · Tesorería · Personas · **Alta de usuarios** · Usuarios · **Seguridad** | Inicio · Mis datos / Mi familia · **Personas** · **Solicitudes (n)** · Cambios (n) · Informes · Tesorería · Usuarios |

- **Personas** pasa delante: es por donde se empieza casi todo (fichas, dar acceso, números).
- **Solicitudes** es nueva (doc 22) y lleva el número de pendientes, igual que *Cambios*.
- **Alta de usuarios** desaparece como pestaña: «Dar acceso» está en la fila de cada persona, en *Usuarios* y en las solicitudes. La página sigue existiendo (búsqueda por nombre o número) y se llega desde *Usuarios* («+ Dar acceso») y desde *Inicio*.
- **Seguridad** (doble factor) pasa junto al nombre, arriba a la derecha: «*Nombre (usuario)* · Seguridad · Salir».
- Cada rol ve solo lo suyo: un scouter ve *Inicio, Mis datos, Solicitudes, Cambios, Informes y Tesorería*; Secretaría y Coordinación, además *Personas* y *Usuarios*.
- **v0.11 (doc 48):**
  - **Mis pagos**, detrás de *Mis datos* / *Mi familia*, para todos;
  - **Uniforme**, para las familias y los participantes (el número es de lo entregado por confirmar);
  - **Uniformes (n)**, para quien gestiona pedidos: scouters, Secretaría, Coordinación y Tesorería. Lleva a la gestión, con el número de lo que le toca hacer, y desde allí están sus propios pedidos («Mis pedidos») y la configuración.
  - El detalle de un pedido se abre en la ventana emergente.

### Dar acceso a una persona

| | Antes (v0.9) | Ahora (v0.10) |
|---|---|---|
| Desde la lista del grupo | *Alta de usuarios* → escribir el nombre → Buscar → Dar acceso → formulario → enviar → volver a la lista | *Personas* → **Dar acceso** en su fila (ventana) → enviar → cerrar |
| Cuando lo pide la familia | No había: Secretaría lo hacía todo | *Solicitudes* → Revisar → **Aprobar** (doc 22) |
| Cuenta que ya existe | *Usuarios* → buscarla → volver a *Alta* | *Personas* o *Usuarios* → clic en el usuario → «Gestionar acceso» (ventana) |

### Otros cambios

- **Inicio:** recuadro «**Pendiente de revisar**» con las solicitudes de acceso y los cambios propuestos por las familias, y accesos directos a *Solicitudes de acceso*, *Personas del grupo* («sus fichas, darles acceso y revisar los números de socio»), *Dar acceso* y *Usuarios*.
- **Personas, columna «Acceso web»:**
  - con cuenta propia: el usuario y su estado;
  - sin cuenta propia pero con **cuenta familiar**: esa cuenta («76849911T · Cuenta familiar · Invitación pendiente»);
  - sin ninguna, y con membresía activa: botón **Dar acceso**.
- **Usuarios:** debajo de cada usuario, su email guardado, enmascarado (doc 15). «Gestionar acceso» abre la ventana.
- **Acceso socios** y **Recuperar contraseña:** enlace «¿Aún no tienes cuenta? Solicita el acceso». *Recuperar contraseña* ya no pregunta el grupo.

## Ventana emergente (`assets/js/area.js`)

**Qué se abre en la ventana:** los enlaces marcados con `data-asde-modal`:

- las **fichas**: nombre y «Ver ficha» en *Personas*, la persona en *Cambios* y en las solicitudes;
- **Dar acceso** y **Gestionar acceso**: *Personas*, *Usuarios*, búsqueda de *Alta*, «Acceso web» de la ficha;
- el detalle de las **solicitudes** («Revisar»).

**Cómo funciona:**

1. Al hacer clic, la página pide ese contenido con la cabecera `X-Asde-Modal: 1`.
2. El servidor (`Frontend\Router::pide_modal`, `View::en_modal`) devuelve **solo el contenido**, sin el menú del área ni la cabecera: `<div class="asde-ap asde-ap--modal">`, con los avisos. Lo marcado con `asde-solo-pagina` (p. ej. «← Personas», «← Solicitudes», «← Volver a la búsqueda») no se ve en la ventana.
3. Los **formularios** de dentro se envían sin salir de la ventana, y la respuesta (con sus avisos) se ve en ella.
4. Los enlaces de dentro que también son de ventana se abren en la misma ventana (p. ej. de la solicitud a «Elegir roles y dar acceso»).
5. Al **cerrarla** (×, tecla Esc o clic fuera), si se ha guardado algo, se recarga la página de detrás para verlo.
6. Arriba: el título del contenido y «**Abrir en su página**».
7. En el móvil ocupa toda la pantalla.

**Por si algo falla:** si el navegador no sabe hacer ventanas (`<dialog>`), no hay JavaScript o la respuesta no es un contenido de ventana (sesión caducada, sin permiso, error), el enlace abre la página normal.

**Dos trampas con Enfold, encontradas en la prueba en el navegador (2026-09-28):**

- Las **transiciones de página** de Enfold capturan los clics en los enlaces y navegan. La ventana ahora escucha el clic en la fase de captura, antes que el tema.
- Si la ventana cuelga de `<body>`, queda fuera del contenedor de colores del tema y pierde su estilo (botones grises, títulos sin color). Ahora se coloca junto al contenido del área. Con `showModal()` se dibuja encima de todo igualmente.

Los scripts de la ficha (`ficha.js`) y del alta (`alta.js`) se inician también sobre el contenido de la ventana (`window.asdeFicha.iniciar`, `window.asdeAlta.iniciar`). Por ejemplo, al marcar Tesorería en «Dar acceso» aparecen las secciones.

## Pruebas (2026-09-28)

- `tests/solicitudes.php`, parte H:
  - qué páginas van en ventana;
  - la cabecera `X-Asde-Modal`;
  - contenido sin menú en la ventana y con menú en la página;
  - detalle de una solicitud;
  - *Personas* con la ventana, «Dar acceso» y las cuentas familiares.
- Pestañas por rol, en la parte D.
- En el navegador (datos inventados):
  - ventana en *Solicitudes* (revisar, aprobar, rechazar, pasar a «Dar acceso» sin salir);
  - ventana en *Personas* (ficha con sus apartados plegables y «Contraer todo»; «Dar acceso» con el formulario);
  - cierre con recarga;
  - pestañas del scouter y de Secretaría.

## Pendiente

- ~~Rehacer las capturas del **manual** (doc 21) con las pantallas nuevas.~~ Hecho el 2026-09-29 en un WordPress de demostración aparte (manual versión 2, doc 21).
