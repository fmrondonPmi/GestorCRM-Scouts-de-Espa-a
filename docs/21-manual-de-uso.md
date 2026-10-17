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

## Versión 4.1 · la app móvil en los manuales (v0.17, 2026-10-03)

- Nuevo apartado **«Instalar el área privada en el móvil»** (`manual/fuentes/instalar-app.html`) en los manuales de familias, asociados menores, scouters y coordinación, y **«La app móvil»** (`admin-app.html`) en el de administración (activar/apagar, nombre, icono, color, «Comprobar el servidor»). Dos preguntas frecuentes nuevas por manual de usuario y tres en el de administración. Solo texto (sin capturas nuevas).
- El generador pasa a la versión 0.17. Paquete **`manual-de-uso-0.17.0.zip`** (carpeta antigua) y copia en `app/public/temporal/` de Local. **No se ha publicado nada**: lo sube el usuario.
- Pendiente: capturas del aviso «Instala la app» (en el móvil) y de la pantalla «App móvil» si se quieren; hay que hacerlas con `manual-capturas.sh` y el CRM simulado.

## Versión 4.2 · el inventario en los manuales (v0.19.1, 2026-10-05)

- Nuevo apartado **«Inventario de material»** (`manual/fuentes/inventario.html`) en los manuales de **scouters** y del **equipo de coordinación**: el material (buscar, filtrar, dar de baja), los tipos con el «+», **importar desde una hoja de cálculo** (el ejemplo, cómo guardarlo como CSV, el informe y las filas con error), preparar una lista de compra (el aviso de menos de 5 unidades), el visto bueno de Tesorería e imprimir el PDF. Retocados «Para quién es este manual» (la fila Inventario; en coordinación, que Tesorería da el visto bueno), el menú del inicio y «Pendiente de revisar», y dos preguntas frecuentes en cada manual.
- **Capturas nuevas** `67`–`76` (lista, ficha de un material, tipos en la ventana, importar, informe de la importación, lista nueva, listas de compra, detalle pendiente con los botones de Tesorería, detalle aprobado con el PDF y el inventario en el móvil). Las demás se **rehicieron** todas porque el menú de gestión lleva ya «Inventario». El PDF no tiene captura (no hay en el equipo con qué pasarlo a imagen sin instalar nada): se describe con texto.
- `manual-capturas.php` crea un inventario inventado (cinco tipos, diez materiales, tres listas: pendiente, aprobada y rechazada, y el informe de una importación) y **aparta lo que ya hubiera en el inventario del grupo** mientras fotografía (lo devuelve al acabar, también si se interrumpe): en el Local había un material y un tipo creados a mano por el usuario, que salían en la captura de la lista.
- El generador pasa a la versión 0.19. Paquete **`manual-de-uso-0.19.1.zip`** (carpeta antigua) y copia en `app/public/temporal/` de Local. **No se ha publicado nada**: lo sube el usuario.

## Versión 4.3 · banco, cuota plegable y módulos en los manuales (v0.20.0, 2026-10-06)

- **Coordinación** (`tesoreria.html`): en «Ficha de una familia», «Cuota de la ronda» plegable y **«Registrar un pago con un ingreso del banco»** (los sugeridos para la familia, lo que se rellena solo y que queda conciliado). En «Banco», el listado que no recarga, el paso 4 nuevo (revisado con su tipo, en la ventana), **«Marcar varios como revisados»** (`#banco-revisar`: casillas, «El mismo tipo para todos», nota, «Cambiar el tipo», filtro por tipo) y **«Tipos de gasto e ingreso»** (`#banco-tipos`: los de partida, las tres clases y la columna «Suma»). Dos preguntas nuevas: cuánto se gasta en cada cosa y qué pasa si no se ve una pestaña (módulo desactivado).
- **Scouters** (`scouters-tesoreria.html`): la cuota plegable (el desplegable del banco no lo ven).
- **Administración**: apartado nuevo **«Módulos de cada grupo»** (`admin-modulos.html`, tras «Grupos y conexión con CRM»): la pantalla, una tabla con lo que desaparece al desactivar cada módulo, que no se borra nada y que Banco y Domiciliaciones dependen de Tesorería. Fila «Módulos» en «Para quién es este manual» y una pregunta frecuente.
- **Capturas nuevas** `77-banco-tipos`, `78-banco-revisar-ventana`, `79-familia-pago-banco` (la cuota plegada y un ingreso del banco elegido) y `80-modulos`; **rehechas** `16`, `20`, `22`, `24`, `63`, `64` y `69`. `manual-capturas.php` aparta los tipos del banco que ya hubiera en el grupo mientras fotografía (como el inventario) y los devuelve al acabar.
- Al hacer las capturas salió que los formularios de una línea (tipos del banco y del inventario) se apilaban con Enfold (`#top input/select { width:100% }`): arreglado con `.asde-form__fila--compacta` (doc 16).
- El generador pasa a la versión 0.20. Paquete **`manual-de-uso-0.20.0.zip`** (carpeta antigua) y copia en `app/public/temporal/` de Local. **No se ha publicado nada**: lo sube el usuario.

## Versión 4.4 · el ingreso del banco con buscador (v0.20.1, 2026-10-06)

- **Coordinación** (`tesoreria.html`, `#pago-banco`): «Registrar un pago con un ingreso del banco» pasa a ser una lista de pasos (Buscar en el banco… → buscar por el nombre del tutor o del socio, o con los botones «Buscar por» → Elegir → Registrar pago), con qué se sugiere y por qué conviene mirar antes de elegir. Retocada la pregunta «Una familia ha pagado la cuota por transferencia sin la referencia».
- **Capturas**: nueva `81-familia-banco-ventana` (la ventana con el buscador, los nombres de la familia, los sugeridos y los demás); **rehechas** `79-familia-pago-banco` (ahora con el ingreso elegido desde la ventana: `?banco=` en la ficha) y `16-tesoreria-familia` (el recuadro nuevo en «Registrar pago»). `manual-capturas.php` vuelca dos pantallas más (`tesoreria-familia-elegido` y `tesoreria-familia-banco`).
- El generador sigue en la versión 0.20. Paquete **`manual-de-uso-0.20.1.zip`** (carpeta antigua) y copia en `app/public/temporal/` de Local. **No se ha publicado nada**: lo sube el usuario.

## Versión 4.5 · altas, bajas y cambios de sección en el CRM (v0.24.0, 2026-10-07)

- Se hizo **al terminar todas las fases de código del doc 34**, como pidió el usuario («el manual no lo actualices sino hasta que terminemos todas las modificaciones»).
- **Coordinación:** apartado nuevo «Altas, bajas y cambios de sección en el CRM» (`fuentes/altas-crm.html`, detrás de Personas): el alta en cuatro pasos y qué pasa según el documento, la membresía en la ficha (nueva, baja, cambio de sección, documento), las fechas, quién se entera de una baja (emails a Tesorería y a la familia, «Bajas recientes»). En Tesorería, una línea sobre las bajas recientes; tres preguntas frecuentes nuevas.
- **Administración:** en «Módulos», la columna «Altas en el CRM» (apagada; necesita permiso de crear en el CRM; empezar por un piloto) y el apartado nuevo «Documentos repetidos en el CRM» (`fuentes/admin-duplicados.html`).
- **Capturas** nuevas `82` a `89` (alta: documento, datos, revisar, hecho; ficha con la membresía; baja en la ventana; bajas recientes en Tesorería; documentos repetidos) y **rehecha** la `80-modulos` (columna nueva). `manual-capturas.php` vuelca esas pantallas con las altas activadas solo mientras dura el volcado (los pasos del alta, poniendo el estado de la pantalla) y lo deja todo como estaba; `manual-envolver.php`, sus definiciones y «Documentos repetidos» en el menú de wp-admin de demostración.
- El generador pasa a la versión 0.24. Paquete **`manual-de-uso-0.24.0.zip`** (carpeta antigua) y copia en `app/public/temporal/` de Local. **No se ha publicado nada**: lo sube el usuario.

## Versión 4.6 · Usuarios con hojas, informe de pagos y eliminar pagos (v0.25.0, 2026-10-08)

- Petición del usuario: «actualiza los manuales» y, antes, **«genera una skill para hacer y actualizar los manuales»**. La skill es `manuales-area-privada`, de usuario (`~/.claude/skills/manuales-area-privada/`, fuera del repo como `herramientas-desarrollo/`): el proceso de este documento paso a paso (qué ha cambiado, volcado y lista de capturas, mirar cada PNG, textos, generador, comprobaciones, zip y copia en el Local, docs y commit), las trampas (`trampas.md`) y dos scripts: `scripts/comprobar-manual.sh` (datos reales, capturas sin usar o que faltan) y `scripts/empaquetar-manual.ps1` (zip sin `fuentes/` con rutas `/` y copia en `app/public/temporal/`, sin tocar lo demás de esa carpeta). Esta versión del manual es la primera hecha con ella.
- **Coordinación:** «Usuarios» pasa a ser **«Usuarios: quién tiene acceso, dar acceso y registro de emails»**, con las tres hojas (cambiar sin recargar, cada hoja se queda como se dejó, atrás/adelante), la lista y el registro de emails como subapartados; «Dar acceso» explica que también se llega por su hoja; el inicio (tarjeta «Usuarios»), la tabla de «Para quién» y la entrada del manual. En **Tesorería**, «Eliminar un pago o un ajuste» (`#eliminar-pago`: el motivo, el historial, el ingreso del banco que vuelve a estar por conciliar, la remesa SEPA, «Deshacer» del banco) y que la ficha y el banco no recargan ni pierden los filtros. Dos preguntas frecuentes nuevas.
- **Scouters y coordinación** (apartado común `informes.html`): **«Quién ha pagado la cuota»** (`#informe-pagos`): el predefinido «Pagos de la cuota», las columnas de «Cuota y pagos», que los importes son de la familia y los filtros «Según lo pagado» y «Ronda de la cuota». En la Tesorería de los scouters, «Eliminar…» y el enlace al informe; dos preguntas frecuentes nuevas.
- **Capturas** nuevas `90-usuarios-hoja-dar-acceso`, `91-informe-pagos` (a 1440 px de ancho para que quepan sus diez columnas) y `92-familia-eliminar-historial` (solo «Movimientos de la ronda» con «Eliminar…» abierto y el historial); **rehechas** `03`, `08`, `09`, `10`, `11`, `16`, `27`, `41`, `42`, `50` y `79` (las hojas de Usuarios, la tarjeta única del inicio, el informe nuevo en la lista, la ficha de la familia con «Eliminar…» y el historial). `manual-capturas.php` vuelca el informe «Pagos de la cuota» y la ficha de la familia de Hugo y Lucía con un pago repetido eliminado («Registrado dos veces»).
- **Arreglo que salió al fotografiar:** en el móvil (390 px) la tercera hoja («Registro de emails») quedaba cortada; las pestañas son ahora más compactas por debajo de 600 px y caben las tres (CSS del plugin; zip 0.25.0 rehecho, el anterior se conserva como `asde-area-privada-0.25.0-sin-hojas-movil.zip`).
- El generador pasa a la versión 0.25. Paquete **`manual-de-uso-0.25.0.zip`** (carpeta antigua) y copia en `app/public/temporal/` de Local. **No se ha publicado nada**: lo sube el usuario.

## Versión 4.17 · pagos de Tesorería (v0.37.0, 2026-10-16)

- **Scouters y Tesorería** (`tesoreria.html`): el control de pagos se descarga en Excel con formato; accesos directos a los informes de pagos; pago de un evento desde «Registrar pago»; «Historial de pagos» (antes «Historial de la ronda», también en las preguntas frecuentes de scouters y coordinación y en `scouters-tesoreria.html`).
- Sin capturas nuevas: la 92 todavía enseña el título viejo «Historial de la ronda» (rehacerla con la skill). Generador en «0.37». Paquete `manual-de-uso-0.37.0.zip`.

## Versión 4.16 · reabrir las cuentas (v0.36.1, 2026-10-16)

- **Scouters y Coordinación** (`eventos.html`, «Cuentas»): párrafo nuevo, «Reabrir las cuentas» (Tesorería, con motivo; lo borrado al cerrar no vuelve); el cierre ya no dice que no se reabre.
- Sin capturas. Generador: sigue en «0.36». Paquete `manual-de-uso-0.36.1.zip`.

## Versión 4.15 · cuentas de los eventos (v0.36.0, 2026-10-16)

- **Scouters y Coordinación** (`eventos.html`): apartado nuevo «Cuentas» (`#eventos-cuentas`): gastos con justificante, revisión de Tesorería, fondo de emergencia, reembolsos, ingresos, anticipos de caja, anular y cerrar las cuentas; en «Cobros (Tesorería)», «Devolver a la familia» y «Cobrar un evento».
- **Coordinación** (`tesoreria.html`, Domiciliaciones SEPA): párrafo «Cobrar un evento» (`#sepa-evento`).
- **Familias y participantes** (`mis-eventos.html`): las devoluciones salen en el evento y en «Mis pagos».
- Sin capturas nuevas (el apartado de eventos sigue solo con texto). Generador: versión «0.36». Paquete `manual-de-uso-0.36.0.zip`.

## Versión 4.14 · lote de la v0.35.1 (2026-10-15)

- **Todos los manuales con «Acceso socios»** (`entrar-socios.html`, `entrar-familias.html`): el «Código de verificación», plegado. **Capturas rehechas:** 01, 26 (móvil) y 61 (con idiomas).
- **Verificación en dos pasos opcional para todos:** los manuales de scouters y de coordinación usan ya `seguridad-opcional.html` (con una frase para quien ve datos de otras personas); se quita `seguridad-obligatoria.html`. `scouters-perfil.html` y la pregunta de `preguntas-scouters.html` («¿Tengo que activar la verificación en dos pasos?»), al día.
- **Coordinación:** `personas.html`, que quién está de alta sale de las fechas de las membresías y «Eliminar de la web»; `preguntas-coordinacion.html`, la pregunta de las bajas hechas en el CRM rehecha y una nueva («Quiero quitar de la web a alguien que ya no es del grupo»); `tesoreria.html`, «Eliminar» en Familias.
- **Scouters** (`scouters-tesoreria.html`): «Eliminar» en Familias.
- Sin cambio de versión en el generador (sigue «0.35»). Paquete `manual-de-uso-0.35.1.zip`.

## Versión 4.13 · eventos (v0.35.0, 2026-10-15)

Los apartados del módulo de eventos, escritos en la sesión de la nube (doc 43), integrados en la v0.35.

- **Scouters y Coordinación** (`eventos.html`, nuevo): crear un evento, equipo dirigente y normativa, presupuesto y precio, lista de comprobación y publicar, inscripciones, cobros (Tesorería) y configuración. La entrada dice que sale con el módulo «Eventos» encendido.
- **Familias y participantes** (`mis-eventos.html`, nuevo): inscribir y firmar la autorización, preinscripción de 14 a 17, estados de la inscripción, pagar y darse de baja. Las entradas de los dos manuales lo citan.
- **Administración** (`admin-modulos.html`): la fila «Eventos» (apagado por defecto) y que por defecto están activos todos menos la lista de espera y los eventos.
- **Ayuda de cada página:** `data-paginas` en los dos apartados (`eventos`, `eventos_config` y `mis_eventos`), que no existían en la base de la nube (v0.18).
- **Capturas rehechas:** 80 (Módulos, con la columna «Eventos»). Las pantallas de eventos **aún no tienen capturas**: para hacerlas hay que sembrar en `manual-capturas.php` un evento de demostración con equipo, presupuesto e inscripciones y encender el módulo para el grupo 999 durante el volcado.
- El generador pasa a «versión 0.35». Paquete `manual-de-uso-0.35.0.zip`.

## Versión 4.12 · tipo nuevo desde el banco, buscador de Familias y «Socio/a desde» (v0.34.0, 2026-10-15)

Textos y capturas rehechas de la v0.34.0 (doc 42), a petición del usuario. (La v0.33.0 cambió sin apartado propio aquí los textos de la cuenta de la domiciliación en `mis-pagos-sepa.html` y `tesoreria.html`.)

- **Coordinación** (`tesoreria.html`): en «Marcar varios como revisados», el **«+»** junto al tipo de cada fila y la ventana pequeña «Tipo nuevo»; en «Tipos de gasto e ingreso», que también se crean desde ese «+»; en «Familias», el **buscador**. (`coordinacion-fichas.html`): **«Socio/a desde»** junto a la edad. (`altas-crm.html`): «Dar de baja» encuentra también la membresía puesta en «baja» en el CRM sin fecha. (`preguntas-coordinacion.html`): pregunta nueva «He dado de baja a alguien en el CRM y en la web sigue de alta».
- **Scouters** (`scouters-tesoreria.html`): el buscador de Familias; (`scouters-fichas.html`): «Socio/a desde».
- **Capturas rehechas:** 05d (ficha de Aina con «Socio/a desde»), 17 (Familias con el buscador), 78 (ventana «Marcar como revisados» con el «+») y 86/87 (la ficha y la baja de Aina, con su nueva historia). En `manual-capturas.php`, Aina tiene ahora Lobatos (2020-2023) y Scouts (desde el 01/09/2023), sembrados **antes** de los volcados (antes solo tenía la de Scouts y se sembraba después de volcar su ficha, así que la 05d no la enseñaba).
- En `manual-envolver.php`, la 05d abre «Datos personales» (desde la v0.29 los apartados empiezan plegados y su pie habla de los candados).
- **Salió al fotografiar:** (1) una familia real del Local (F684001, con dos nº de socio) se colaba en las pantallas de Tesorería de la demostración: `manual-capturas.php` aparta ahora las familias del grupo mientras dura el volcado (grupo `zzz` y número con `zzz-` delante, porque el número es único) y las devuelve después de borrar las de la demostración, también si se interrumpe. Ninguna captura publicada la tenía (se hizo el 11/10 y no se había vuelto a fotografiar Tesorería). (2) En «Educandos sin familia», el nº territorial y su aviso se montaban sobre la columna de al lado (`.asde-numeros` con `white-space: nowrap`): arreglado en el plugin (CSS de la v0.34, solo dentro de `.asde-columnas`).
- El generador pasa a «versión 0.34» (cambian todos los ficheros de `asde-area-privada/ayuda/`). Paquete `manual-de-uso-0.34.0.zip`; el zip del plugin 0.34.0 se rehace con la ayuda nueva (el anterior queda como `asde-area-privada-0.34.0-sin-manual.zip`).

## Versión 4.11 · lista de espera (v0.32.0, 2026-10-14)

Solo textos, sin capturas nuevas: apartado nuevo `lista-espera.html` (`#lista-espera`, `data-paginas="lista_espera"`: el enlace para apuntarse, la lista con la sección por edad y el orden, contactar y cambiar el estado, asignar la sección y dar de alta) en los manuales de **scouters** y de **coordinación**, y la fila «Lista de espera» (apagada por defecto) en `admin-modulos.html`. El generador pasa a «versión 0.32» (por eso cambian todos los ficheros de `asde-area-privada/ayuda/`). Las v0.30 y v0.31 siguen sin textos en el manual (pendiente).

## Versión 4.11 · tienda y calendario de eventos (v0.39–v0.40, 2026-10-17)

- Solo texto, con la skill `manuales-area-privada`; el generador pasa a la versión 0.40.
- **Familias y participantes** (`uniforme-pedir.html`): «Pedir en la tienda: uniforme y más», con cómo pagar (transferencia, Bizum o en físico para socios), el cuadro «Tienda» con varias líneas en un pedido, existencias, máximo por persona, productos solo para socios, emails y cancelar.
- **Coordinación** (`uniformes-gestion.html`): el pago con Bizum, «Otros productos» en el resumen para el proveedor, IBAN y Bizum en la configuración y «Productos» pasa a «Inventario de la tienda».
- **Scouters y coordinación** (`eventos.html`): «Calendario de tareas» (plantilla de Acampada, semáforo, quién marca, fechas propias, recálculo, tareas automáticas, avisos, imprimir), calculadora de autobuses y categorías sugeridas, y las plantillas en la configuración.
- **Tienda pública** (fase 4): en `uniforme-pedir.html` («Comprar sin cuenta») y en `uniformes-gestion.html` (compras sin sesión en la gestión, la configuración de la tienda pública y el enlace).
- **Capturas nuevas (11):** `95-tienda-catalogo`, `96-tienda-pedidos-pago`, `97-tienda-inventario`, `98-tienda-producto`, `99-tienda-config-publica`, `100-tienda-gestion`, `101-tienda-publica`, `102-tienda-publica-pedido`, `103-eventos-calendario`, `104-eventos-autobuses` y `105-eventos-config-calendario`. **Rehechas:** `35`, `36b`, `37`, `38`, `40`, `44`, `55` y `56` (la tienda sustituye a Uniformes: direcciones `/mis-pedidos/`, la forma de pago en el formulario).
- **Herramientas** (`manual-capturas.php`, fuera del repo): renombradas las vistas de Uniformes a las de la tienda; el volcado crea productos, un pedido con varias líneas, una compra sin sesión, una acampada con el calendario aplicado y deja todo como estaba (aparta los productos y eventos reales del grupo mientras dura). Paquete `manual-de-uso-0.40.0.zip`; no se ha publicado nada.

## Versión 4.10 · lote de mejoras (v0.29.0, 2026-10-11)

Solo textos (las capturas **no** se han rehecho todavía: las de informes, Personas, la ficha, cambios propuestos, gestión de uniformes e inventario enseñan aún las pantallas de la v0.28; ofrecido al usuario). Cambia la ayuda del plugin (se regenera con el manual).

- **Coordinación y scouters:** `informes.html` («Descargar Excel», «Imprimir» en una ventana, «Sección» y el apartado nuevo «Fichas reducidas», `#fichas-reducidas`); `cambios.html` (una fila por persona, «Revisar», casillas, «Aprobar los seleccionados», «Resueltos» con los cambios directos); `personas.html` (buscar al escribir, «Dar acceso» como enlace, resumen plegable); `coordinacion-fichas.html` y `coordinacion-inicio.html` (cuadros plegables); `uniformes-gestion.html` (camisas del inventario: servir, asignar, devolver); `inventario.html` (tipo «Camisa uniforme» con modelo y talla).
- **Familias y asociados menores:** `familias-fichas.html` y `participantes-ficha.html` (el cuadro del nombre también se pliega; «Contraer todo» arriba).

## Versión 4.9 · la ayuda del plugin, solo texto (v0.28.0, 2026-10-09)

- Petición del usuario: «el zip con las ayudas ha quedado demasiado pesado … rehacer todo el manual sin tantas capturas y solo con texto explicativo, siempre en ventanas modales; dejaremos una versión que se sube manualmente con el manual como ayuda extra». La v0.27.0 (el manual con capturas dentro del zip, ~10 MB) se **descartó** y se deshizo su commit.
- **Dentro del plugin, solo texto** (doc 35): el generador escribe los trozos de cada página sin imágenes (el pie de cada captura queda como nota) y, nuevo, el **manual completo de cada rol** (`asde-area-privada/ayuda/<manual>/_completo.html`, con índice), que «Ver el manual completo» abre en la misma ventana. ~300 KB en total; el generador avisa si se cuela una imagen.
- **El manual con capturas** (este) sigue igual y es la **ayuda extra** que se sube a mano (`manual-de-uso-0.28.0.zip`); la ayuda lo enlaza como «Manual con capturas» si su dirección está en wp-admin.
- Textos (con la skill `manuales-area-privada`): «La ayuda de cada página» (`#ayuda`) en familias y coordinación, las preguntas «¿Hay una ayuda dentro del área privada?» de los cuatro manuales de usuario, «Manual de uso» (`#manual-de-uso`) y «¿Cómo instalo una versión nueva?» del de administración. Capturas **rehechas**: `93-ayuda-ventana` y `94-ayuda-familia` (la ventana, ahora en texto) y `22-organizacion` (el campo «Manual con capturas (ayuda extra)»). El generador pasa a la versión 0.28. Paquete `manual-de-uso-0.28.0.zip` y copia en `app/public/temporal/` de Local; no se ha publicado nada.

## Versión 4.8 · desactivar borra la contraseña (v0.26.1, 2026-10-08)

- Solo texto, con la skill `manuales-area-privada`: en «Usuarios del área privada» (`fuentes/usuarios.html`, manual de coordinación), **Desactivar** explica que, además de quitar los roles y cerrar las sesiones, borra la contraseña y la verificación en dos pasos, y que al volver a dar acceso llega una invitación nueva. Cambia también la ayuda de esa página (`asde-area-privada/ayuda/coordinacion/usuarios.html`). Sin capturas nuevas ni rehechas. Paquete **`manual-de-uso-0.26.1.zip`** y copia en `app/public/temporal/` de Local; no se ha publicado nada.

## Versión 4.7 · la ayuda de cada página (v0.26.0, 2026-10-08)

- Petición del usuario: «hagamos un manual por rol de usuario … en cada página su ayuda, y después un enlace a una wiki. Recuerda usar la skill». Hecha con la skill `manuales-area-privada` (doc 35 para la ayuda).
- **El manual se parte por páginas:** cada apartado o `<h3>` de `fuentes/` que explica una página del área lleva `data-paginas` (claves de `Frontend\Pages`), y `generar-manuales.php` escribe con eso los trozos de la ayuda en `asde-area-privada/ayuda/<manual>/<página>.html` (coordinación 21 páginas, scouters 16, familias 5, participantes 5). En los manuales generados no salen esos atributos.
- **Textos:** en el inicio de los cuatro manuales de usuario, «Ayuda» junto al nombre y, en familias y coordinación, el apartado «La ayuda de cada página» (`#ayuda`) con su captura; una pregunta frecuente nueva en cada uno («¿Hay una ayuda dentro del área privada?»); en administración, «Manual de uso» en Organización federada (`#manual-de-uso`), con el aviso de publicar el manual de la misma versión que el plugin.
- **Capturas:** nuevas `93-ayuda-ventana` (coordinación, la ayuda de Tesorería sobre el control de pagos) y `94-ayuda-familia` (la de «Mis pagos» de una familia); **rehechas todas** las del área (la cabecera lleva «Ayuda») y la `22-organizacion` (campo nuevo). `manual-capturas.php` pone como manual publicado de la demostración `https://manual.example.invalid/manual/` y vuelca las dos ventanas de ayuda; `manual-envolver.php` sirve esas capturas desde el repo (`capturas-manual/`).
- El generador pasa a la versión 0.26. Paquete **`manual-de-uso-0.26.0.zip`** (carpeta antigua) y copia en `app/public/temporal/` de Local. **No se ha publicado nada**: lo sube el usuario, junto con el plugin 0.26.0 (la ayuda saca del manual publicado sus capturas).
