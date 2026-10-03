# 21 · Manuales de uso en HTML (v0.15: cinco manuales por perfil)

## Peticiones del usuario

- **2026-09-27:** «Prepara un artefacto con el manual en HTML del uso de la aplicación. Añade capturas de flujos y cosas que consideres importantes.» → versión 1 del manual, con la v0.9.
- **2026-09-29:** «Actualiza el artefacto.» → versión 2, con la v0.11 (solicitudes de acceso, ventana emergente, menú nuevo, uniformes y «Mis pagos»). Todas las capturas se rehicieron. El usuario la subió a https://baleares.scout.es/temporal/index.html.
- **2026-10-02:** «En este site (…/temporal/index.html) había subido un manual, ¿puedes generar el mismo manual actualizado?» y, al aclararlo, **«no en el mismo sitio sino en local»** → versión 3, con la v0.14.1, **solo en local**: Claude no toca el sitio publicado; el usuario sube lo que quiera.

- **2026-10-03:** «Separemos los manuales: 1. de gestión del CRM, 2. para los usuarios familias / tutores, 3. uno para asociados menores de edad, 4. uno para scouters solo con sección asociada, 5. uno para el equipo de coordinación (Tesorería, Secretaría y Coordinación)» → **versión 4**, con la v0.15.

## Versión 4 · cinco manuales por perfil (v0.15, 2026-10-03)

| Manual | Fichero | Para quién | Apartados |
|---|---|---|---|
| Gestión del CRM y de la web | `manual/administracion.html` | Administración de WordPress | cómo trabaja la web con SinergiaCRM (qué lee, qué escribe, el usuario de API), grupos y conexión, organización federada y usuario de acceso, instalación, tema, colores, idioma y etiquetas, qué datos guarda la web, diagnóstico, preguntas |
| Familias | `manual/familias.html` | Madres, padres y tutores (cuenta familiar) | para quién, entrar (`nº + T`/`T2`), pedir el acceso, primera vez, inicio, fichas de los hijos y proponer cambios, Mis pagos, firmar la domiciliación SEPA, uniforme, idioma, seguridad (opcional), preguntas |
| Asociados menores de edad | `manual/participantes.html` | Educandos de 14 a 17 años con cuenta propia | para quién, entrar, pedir el acceso, primera vez, inicio, Mis datos (cambia su email y sus teléfonos; no ve salud), Mis pagos (la domiciliación la firma la familia), uniforme, idioma, seguridad, preguntas |
| Scouters | `manual/scouters.html` | Scouters y voluntariado con sección | para quién, entrar, primera vez, seguridad (obligatoria), inicio (Mi espacio / Gestión del grupo), ventana emergente, fichas de su sección, cambios, solicitudes de su sección, informes, Tesorería (pagos y familias), Mi uniforme y gestión de los de su sección, idioma, preguntas |
| Equipo de coordinación | `manual/coordinacion.html` | Tesorería, Secretaría y Coordinación | para quién (tabla de quién hace qué), entrar, primera vez, seguridad, inicio, ventana, personas y números de socio, dar acceso, solicitudes, usuarios y emails, fichas, cambios, informes, Tesorería completa (con banco y SEPA), uniformes (pago, pedido, entrega y configuración), idioma, preguntas |

- **Dónde:** `manual/index.html` es ahora la **portada** con los cinco manuales (en el orden pedido); `manual.css` y `manual.js` son comunes; `capturas/` (65 capturas). Cada manual enlaza con los demás («Los otros manuales»).
- **Cómo se generan:** los apartados están en `manual/fuentes/*.html` (los comunes, como entrar, contraseña, idioma o seguridad, se escriben una vez) y `php herramientas/generar-manuales.php` monta los cinco HTML y la portada: índice lateral a partir de los `<h2>` y los `<h3 id>`, atajo `<captura src="…" url="…" alt="…" larga|movil>pie</captura>` y comprobación de capturas que existen, `id` repetidos y enlaces rotos (también entre manuales). **No se editan a mano los `.html` generados.** `fuentes/` no hace falta para publicar.
- **Supuesto:** «manual de gestión del CRM» se ha entendido como el de **quien administra la web y su conexión con SinergiaCRM** (no un manual de uso del propio CRM).
- **Capturas:** todas rehechas con la v0.15 (menús «Mi espacio» / «Gestión del grupo», «Mi uniforme», selector de idioma, que sale porque por defecto se deja elegir). Nuevas: `51`–`65` y `54b` (inicio del scouter, de la familia y del participante, ficha y contacto del participante, Mi uniforme y gestión de uniformes del scouter, sus solicitudes y su Tesorería, Mis pagos del participante, acceso con el selector de idioma, inicio en catalán, pantallas **Colores** e **Idioma y etiquetas**, inicio de la familia en el móvil).
- **CRM simulado:** tiene `metadatos` (apagado por defecto) para responder a `get_module_fields`; sin metadatos, la ficha propia del participante salía entera en solo lectura (en el sitio real los da el CRM). `manual-capturas.php` lo enciende solo para esa captura.
- **Siguen siendo de la versión 2** `23-grupos`, `23b-grupo-editar` y `25-diagnostico` (la pantalla de grupos del Local enseñaría el usuario real del CRM).
- **No se ha publicado nada**: subir los manuales a la web es cosa del usuario (zip `manual-de-uso-0.15.0.zip` en la carpeta antigua, sin `fuentes/`).

## El manual (versión 3, v0.14.1; sustituido por la versión 4)

- **Dónde (versión 3):** `manual/index.html` + `manual/capturas/*.png` en el **repo git** (`C:\Proyectos\GestorCRM\manual`, desde el 2026-10-02; antes estaban en la raíz de este proyecto) (una sola página con sus imágenes; se abre con doble clic o se sube la carpeta entera) y el paquete **`manual-de-uso-0.14.1.zip`** (lo mismo, con rutas `/`, para subirlo de una vez al hosting y descomprimirlo). Para verlo en el Local: `http://localhost:10011/temporal/index.html` (copia en `app/public/temporal/`, que antes no existía). **El publicado en baleares.scout.es sigue siendo la versión 2 (v0.11)** hasta que el usuario suba la nueva.
- **Para quién:** socios, familias, scouters, Secretaría, Coordinación, Tesorería y administradores.
- **Diseño:** el mismo de la versión 2 (portada morada, índice lateral con el apartado en curso, capturas con marco de navegador y visor ampliado, tema claro/oscuro, móvil).
- **Contenido** (un apartado por tarea, con 54 capturas), con lo nuevo desde la v0.11:
  - **novedades** de la v0.12.0 (SEPA, dos tutores, aviso al rechazar), de la v0.12.1 a la v0.12.5 (móvil, rendimiento, usuarios sin recargar, tabla de medidas, solicitud ya con cuenta) y de la v0.14 (usuario = nº de Scouts de España; 0.14.1, revisión del código);
  - qué puede hacer cada perfil (con la domiciliación SEPA);
  - **entrar** con el nº de Scouts de España, el territorial o el email; familias `nº+T` y `nº+T2`; «¿cuál es mi usuario?»;
  - pedir el acceso; activar la cuenta y recuperar la contraseña;
  - el inicio y el menú (barra de carga, «Mostrar nº territorial»), la ventana emergente;
  - fichas y cambios de las familias;
  - **personas y números de socio:** columnas de los dos números, «Actualizar ahora», **«El nº territorial: verlo y corregirlo»** (preferencia + aviso que sale siempre), incidencias, correcciones en segundo plano con su monitor;
  - **dar acceso:** cuenta propia (con un scouter), cuenta familiar con **dos tutores, dos cuentas**, aviso de nº territorial que no bloquea;
  - **solicitudes de acceso:** aviso por email al rechazar, familia que ya tiene cuenta (otro tutor / misma persona con otro email), **cerrar la solicitud de quien ya tiene acceso**;
  - **usuarios:** filtro por columna, orden, páginas y «Actualizar» sin recargar; con el nº territorial; en el móvil; registro de emails;
  - informes; **«Mis pagos» con la domiciliación SEPA** (firmar, ver/imprimir, anular);
  - uniforme (con la **tabla de medidas en una ventana encima del pedido**), gestión de pedidos y configuración;
  - Tesorería (control de pagos, ficha de familia, familias, cuotas, coste federativo, banco) y **Domiciliaciones SEPA** (datos del acreedor, remesas, fichero XML, aviso a las familias, cobros y devoluciones);
  - seguridad; **administración** (organización federada con el **usuario de acceso** y «Renombrar las cuentas existentes», grupos con el email de contacto, instalación y entorno, diagnóstico);
  - preguntas frecuentes (nuevas: qué número escribir, ver el territorial, cambios en el CRM que tardan, SEPA, tabla de medidas, móvil, segundo tutor; revisada: qué se guarda en la web).
- **Datos:** todas las capturas son del **Local con el CRM simulado y datos inventados** («Grupo Scout Ejemplo, 999», 22 personas inventadas, emails `@example.invalid`, números `79990031`… y `900031`…). El nombre del grupo real, su nº, su email de contacto, su insignia y las cuentas reales del Local **no aparecen** (se cambian al fotografiar y se aparta lo real mientras dura el volcado; ver abajo). El manual se comprobó con un script: sin «Myotragus», «localhost», `@scout.es` ni el nº del grupo real.
- **Reutilizadas de la versión 2** (no han cambiado de aspecto): `23-grupos`, `23b-grupo-editar`, `25-diagnostico`, hechas en el WordPress de demostración antiguo (sus números de socio son los de entonces, `7999000N`).
- **Pendiente de confirmar con el usuario:** si quiere rehacer esas tres capturas con la misma cabecera que las demás (cambia poco).

## Cómo se hacen las capturas (versión 3, reproducible)

Ya no hace falta un WordPress aparte: se vuelcan las pantallas del **Local** con el CRM simulado (`tests/lib/CrmSimulado.php`) y se fotografían con Chrome sin cabeza. Todo está en `herramientas-desarrollo/` (no va en el zip del plugin):

```bash
herramientas-desarrollo/manual-capturas.sh [carpeta de trabajo] [nombres de capturas…]
```

(sin nombres, las 51 capturas automáticas; `SIN_VOLCADO=1` salta el paso 1 y reutiliza el volcado de la carpeta de trabajo, útil para retocar el aspecto. Tarda unos 8 minutos entero.)

1. **`manual-capturas.php`** (con `wp eval-file`): crea 22 personas inventadas con incidencias de numeración (formato antiguo, duplicado, no válido, sin número), cuentas con **usuario = nº de Scouts de España** (pone temporalmente `identificador = asde` en `asde_ap_organizacion`), familias, pagos, SEPA (acreedor, dos autorizaciones, una remesa), un extracto CSV del banco, pedidos de uniforme, cambios propuestos, solicitudes (por documento, por nombre, y dos cuyo usuario ya existe), una corrección en segundo plano al 36 % y dos cuentas con usuario territorial para que «Renombrar las cuentas» tenga qué enseñar. Vuelca el HTML de cada pantalla (también las ventanas emergentes con `View::en_modal()`, el listado con «Mostrar nº territorial» y las páginas de administración). **Lo deja todo como estaba:** borra lo creado y restaura opciones, tablas y la caché de numeración; **aparta** (cambia el grupo de) las cuentas y los emails reales del Local mientras dura el volcado y los devuelve al final (también con `register_shutdown_function` si se interrumpe).
2. **`manual-envolver.php`:** cambia el grupo real por el de demostración (nombre, nº 684 → 999, códigos `F…`, `U…`, nº de socio, emails de contacto, la insignia del grupo por una dibujada), viste cada pantalla con el CSS de Enfold (`manual-recursos/*.min.css`), el del plugin y los colores/botones del tema (el CSS dinámico de Enfold no está en los ficheros), con una cabecera morada de demostración, y construye la ventana emergente (`<dialog class="asde-modal">`) o el visor sobre la página de fondo. Las capturas del móvil van en un `<iframe>` de 390 px. Las páginas de administración llevan el CSS real de wp-admin (de `load-styles.php` del Local) y una barra y un menú lateral de demostración.
3. **Chrome** (`--headless=new --screenshot`, 1280 px; el móvil, a 390 px con doble resolución) fotografía cada página servida con `php -S`.
4. **`manual-recortar.php`:** recorta el vacío de debajo (o un trozo, p. ej. un apartado) con GD.
5. **`manual/index.html`:** el HTML del manual. Se partió del de la versión 2 con un script de montaje de una sola vez; **a partir de ahora se edita a mano** (las rutas de las capturas son `capturas/NN-nombre.png`; el tamaño de cada `<img>` debe coincidir con el PNG).
6. **Comprobación:** anclas y `id` sin repetir, imágenes que existen y se usan, etiquetas equilibradas y que no queda ningún dato del grupo real (grep de «Myotragus», «localhost», «@scout.es», «7684»).

**Trampas encontradas (versión 3):**

- **El PHP de la shell no trae GD:** hay que exportar `PHPRC` al `conf/php` de Local (como hace `wp-local.sh`); si no, `imagecreatefrompng` no existe. El `.sh` ya lo hace.
- **Chrome no baja de ~500 px de ancho de ventana:** el móvil se fotografía con un iframe de 390 px (si no, el contenido se corta por la derecha).
- **Con `--screenshot` el viewport es la ventana:** para una página entera se usa una ventana muy alta (5200 px) y se recorta el vacío; el desplazamiento por ancla no sirve (sale en blanco).
- **El CRM simulado guarda `^Scouts^`** en las secciones (como el real) y la ficha lo enseña tal cual cuando no hay metadatos del CRM: se limpia al envolver. Los **permisos de escritura** del CRM simulado hay que ponerlos (transient `asde_ap_permisos_684`); si no, toda la ficha sale en solo lectura.
- **Caché de `Organizacion`:** tras cambiar la opción hay que llamar a `Organizacion::olvidar()` o la pantalla enseña el valor viejo.
- **`read` de bash junta los campos vacíos** cuando el separador es el tabulador: `capturas.tsv` usa «-» para «sin recorte».
- **Las cuentas, el registro de emails y las imágenes del Local son una copia de lo real:** salen en *Usuarios*, en *Registro de emails* y en la configuración de uniformes (la insignia del grupo) si no se apartan o se cambian.
- **Los estilos dinámicos de Enfold** (colores de enlaces y títulos, botones, tablas, campos) no están en sus ficheros CSS: se reproducen en el envoltorio, **antes** del CSS del plugin para que este gane en igualdad de especificidad.
- **Los radios de wp-admin** no se dibujaban con la hoja de `load-styles.php` (sin el punto del seleccionado): se añade una regla.
- Una solicitud de quien ya tiene cuenta no se puede crear (el servicio contesta «ya tienes cuenta»): para fotografiar ese caso se crea la solicitud y **después** la cuenta, que es como le pasó a Adriana (v0.12.4).
- **Perl y PHP en la shell:** los `\` de los espacios de nombres se pierden al sustituir con perl entre comillas dobles (ver `CLAUDE.md`); usar `use` o la herramienta Edit.

## Cómo se hicieron las de la versión 2 (v0.11) — resumen

Un **WordPress de demostración aparte** (Local, Enfold, plugin, mu-plugin con CRM simulado y entrada automática con un token), capturas desde el navegador de la app con html-to-image y, para la ventana emergente, dos imágenes juntadas en un `<canvas>`. Se borraron al acabar (2026-09-29). La versión 3 sustituye todo eso por los scripts de arriba.

## Arreglos que salieron al hacer las capturas

**v0.9 (2026-09-27):**

- **Botones-enlace en columnas estrechas:** el fondo quedaba cortado al partirse en dos líneas. Ahora son `inline-block`.
- **Banco:** «Conciliar los 1 que llevan…» → «Conciliar el que lleva la referencia de familia» cuando es uno.
- **Alta:** las leyendas del email dicen que también sirve para entrar.
- **Organización federada:** el aviso al cambiar el código ya no dice que se pierde el acceso.

**v0.11.1 (2026-09-29):**

- **Tarjetas de insignias del formulario de uniforme** (el más importante): con Enfold, la casilla de cantidad ocupaba toda la tarjeta y el texto quedaba en una columna estrecha, letra a letra. Enfold pone `width: 100%` y margen inferior a los `input` con `#top`, que gana a la regla del plugin; ahora hay también una regla con `#top`.
- **Casillas de la configuración de uniformes** («Quitar», «Se puede pedir»): una regla del propio plugin (`.asde-form p > label`, en bloque y en negrita) les quitaba el aspecto de casilla. El campo del precio cortaba «por confirmar»: más ancho.
- **Títulos sueltos de las tarjetas** («Tus pedidos», «Nuevo pedido», los de «Mis pagos» y la configuración): pegados a lo que seguía; ahora con margen.
- **Campos de fecha:** Enfold no les da estilo y salían con el borde negro del navegador.
- **Plural en el resumen de la gestión de pedidos:** «1 pendientes de entregar» → «1 pendiente de entregar» (y «1 entregado sin confirmar»). La prueba de uniformes comprueba ahora singular y plural.
- **Textos que seguían nombrando la pestaña «Alta de usuarios»**, que ya no existe: los «Siguientes pasos» de *Instalación y entorno*, la ficha de familia de Tesorería y el título de la búsqueda (ahora «Dar acceso»).
- Versión **0.11.1** para que los navegadores no sigan con la hoja de estilos antigua en caché (va con `?ver=`). Paquete `asde-area-privada-0.11.1.zip`. Las 415 pruebas, OK.

**v0.14.1 (2026-10-02) — al rehacer las capturas:** no salió ningún fallo del plugin. Una observación que queda anotada, sin tocar: la ficha enseña `^Scouts^` cuando el CRM no manda los metadatos de la lista (solo ocurre con el CRM simulado).
