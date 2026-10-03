# 09 · Preguntas abiertas

Hay que resolverlas antes de programar cada parte. Cuando se responde una, se marca ✅ y se anota la decisión.

## Pendientes

| # | Pregunta | Afecta a |
|---|---|---|
| P-08 | ¿La "ficha de participación en eventos" corresponde a las inscripciones (`stic_Registrations`) del CRM? | Eventos |
| P-10 | ¿Se mantiene la URL `/nuestro-grupo-scout/` o pasa a `/grupo-scout/<grupo>/`? | URLs, SEO |
| P-11 | ¿Qué hace el **módulo de sincronización con el CRM** de Coordinación? | Módulo CRM |
| P-14 | ¿Cómo se notifican los cambios propuestos y su resolución: email, aviso en el área o ambos? (los ganchos ya están, doc 07) | Cola de cambios |
| P-17 | ¿Creamos un **tema hijo de Enfold**? No es imprescindible | doc 06 |
| P-21 | **Terminar de corregir los números de socio en el CRM.** El 2026-09-27 se resolvieron los duplicados y se corrigieron 138 formatos antiguos. El **2026-09-28 ya no queda ninguno en formato antiguo** (382 fichas: 358 bien, 4 no válidos, 20 sin número). Quedan los **4 no válidos**, que hay que mirar uno a uno en *Personas* («Asignar nº»), y ver si alguna de las 20 sin número tiene membresía activa | Usuarios |
| P-22 | **Tesorería:** ¿prorrateo para quien entra o sale a mitad de ronda? ¿Hay otros descuentos (familia numerosa, becas fijas)? Hoy se hacen con «ajustes» | Tesorería |
| P-24 | **Gastos:** categorías, si hay un tope por scouter, quién los aprueba y si se reembolsan | Tesorería |
| P-25 | Wordfence: ¿traducimos al español los textos de su página de doble factor (plantilla propia) o los dejamos en inglés? | Seguridad |
| P-28 | **Colonya:** falta un **fichero real** (Norma 43 y CSV) para confirmar la modalidad (la 3 trae el ordenante) y las columnas del CSV. ¿La cuenta tiene firmas mancomunadas? | Tesorería · banco |
| P-29 | **Producción:** ¿está activado el reCAPTCHA de Wordfence en el login? La pantalla *Acceso socios* no lo aplica (su plan B es verificar por email y los socios no tienen email en WordPress) y tiene su propio límite de intentos | Seguridad |
| P-31 | **Notas u observaciones** (del participante y de Coordinación en salud) como registros aparte: ¿módulo *Notes* del CRM (el usuario de API hoy no lo tiene) o campos de observaciones existentes? | Fichas |
| P-33 | **Producción (Hostinger, que usa LiteSpeed):** ¿permite las tareas en segundo plano? Hay que pasar el **diagnóstico** (doc 18): subir `asde-diagnostico-segundo-plano.zip` como plugin, *Herramientas → Diagnóstico segundo plano*, y borrarlo al acabar. Lo más probable es que haga falta la regla `noabort` en el `.htaccess` | Tareas en segundo plano |
| P-34 | **Código de cada organización federada** en el nº de socio local (en Balears, el 7): ¿de dónde sale el de cada una? El usuario dará la fuente; de momento se escribe a mano en *Organización federada* (doc 20) | Números de socio |
| P-37 | **Producción:** poner el **email de contacto** de cada grupo (*Grupos Scouts → el grupo*; «Instalación y entorno» avisa si falta) y decidir **dónde se publica el enlace** de «Solicitar acceso» (web del grupo, WhatsApp de las familias…) (doc 22) | Solicitudes |
| P-38 | **Precio de cada insignia** del uniforme (insignia de grupo, insignia mundial…). Hoy salen «por confirmar» y Tesorería cobra el importe real al validar el pago (doc 24) | Uniformes |
| P-39 | **¿Dónde va la insignia de grupo** de Myotragus? Se ha puesto en el **brazo derecho** (donde la guía de colocación pone la de la federación, y la de Myotragus lleva «ASDE Scouts de Balears»). La mundial, en el pecho izquierdo, como dice la guía (doc 24) | Uniformes |
| P-40 | **Talla especial (3XL):** ¿cuesta lo mismo que las demás (25 €) o lleva recargo? ¿Tarda más? (doc 24) | Uniformes |
| P-41 | **Uniformes:** ¿avisar por email a la familia cuando está pagado o listo para recoger? ¿Conciliar los ingresos del banco también con los pedidos de uniforme (hoy solo con la cuota)? (doc 24) | Uniformes, banco |
| P-42 | **SEPA · datos reales del acreedor:** nombre, **identificador del acreedor (ICS)** e IBAN del grupo, que da el banco al contratar las domiciliaciones. Hay que confirmar con **Colonya** que acepta el fichero **XML pain.008 (Cuaderno 19.14)**, por qué canal se sube, si pide el **BIC** y con cuántos días hábiles de margen (la web exige 3) (doc 25) | Tesorería · SEPA |
| P-43 | **SEPA · validez de la firma:** la autorización se firma **electrónicamente** (queda registrado quién, cuándo y el texto aceptado). ¿La acepta Colonya como orden de domiciliación o quiere el papel firmado? También: quién custodia el mandato mientras esté vigente y los 14 meses siguientes (doc 25) | Tesorería · SEPA |
| P-44 | **SEPA · qué se cobra:** hoy solo la **cuota de la ronda** (por plazos o todo lo pendiente). ¿También los pedidos de uniforme y el coste de actividades? ¿Se añade recargo a las devoluciones? (doc 25) | Tesorería · SEPA |
| P-45 | **SEPA · conciliación con el banco:** los cobros se registran a mano en la remesa («cobrado» o «devuelto»). ¿Se concilian solos con la Norma 43 de Colonya (abono agrupado de la remesa y devoluciones)? Hace falta un fichero real (P-28) (doc 25) | Tesorería · SEPA, banco |
| P-46 | **Idiomas · revisión de las traducciones:** el catalán, el euskera y el gallego son una primera versión sin revisión de hablantes nativos. ¿Quién las revisa? (se corrigen desde wp-admin → Grupos Scouts → Idioma y etiquetas, sin tocar el código; doc 28) | Idiomas |
| P-47 | **Idiomas · nombres propios:** ¿cómo se llaman las secciones y los roles en cada idioma (Castors / Llobatons, Kastorrak / Otsokumeak…)? Se ha puesto una propuesta. ¿Se traducen también las pantallas de wp-admin del plugin (hoy, en castellano)? (doc 28) | Idiomas |
| P-48 | **Menús por rol:** ¿basta con separar el menú (lo personal / la gestión del grupo) o se quieren también páginas distintas? ¿Un scouter debe poder pedir uniforme para otros de su sección? (doc 28) | Menús |
| P-49 | **Técnico/a · base legal:** ¿quién autoriza que personal de la federación vea los datos de los socios de varios grupos, y con qué base (responsable o encargado del tratamiento)? (doc 29) | Técnico |
| P-50 | **Técnico/a · quién es:** ¿es socio (con ficha en el CRM de la federación o de un grupo)? ¿Entra con su email o con un número? (doc 29) | Técnico |
| P-51 | **Técnico/a · usuario de API:** ¿puede ASDE dar un usuario de API de la federación **de solo lectura** que vea todos los grupos? Es la opción recomendada (doc 29, opción B) | Técnico |
| P-52 | **Técnico/a · qué ve:** ¿salud y alimentación? ¿descarga CSV? ¿Tesorería, banco y SEPA, solicitudes, usuarios y emails? Propuesta: todo en lectura salvo salud, banco y SEPA (doc 29) | Técnico |
| P-53 | **Técnico/a · panel multigrupo:** ¿hace falta un resumen con una fila por grupo (socios, incidencias, solicitudes, cuota cobrada, uniformes)? (doc 29, fase 2) | Técnico |
| P-54 | **Técnico/a · qué grupos:** ¿una lista de grupos por técnico o siempre todos los de la web? (doc 29) | Técnico |
| P-55 | **Técnico/a · alta:** ¿solo el administrador de WordPress da de alta a los técnicos? (doc 29) | Técnico |
| P-56 | **Carpeta de Drive · cómo se ve:** ¿incrustada dentro de la web o basta con el enlace a Google Drive? (doc 30) | Drive |
| P-57 | **Carpeta de Drive · quién la configura:** ¿el administrador de la web o también Secretaría/Coordinación desde el área? (doc 30) | Drive |
| P-58 | **Carpeta de Drive · dónde:** ¿tarjeta en el inicio, entrada en el menú o las dos? ¿En «Para ti» o en «Gestión del grupo»? (doc 30) | Drive |
| P-59 | **Carpeta de Drive · cuántas:** ¿una por grupo o también una por sección? (doc 30) | Drive |
| P-60 | **Carpeta de Drive · cómo se comparte:** ¿restringida a las cuentas de Google de los scouters (recomendado) o «cualquiera con el enlace»? (doc 30) | Drive |

## Resueltas

| # | Decisión |
|---|---|
| ✅ P-01 | Módulo "Gestión de familias" del primer mensaje: fue un error al copiar y pegar. (Las **Familias** de Tesorería son otra cosa: ver P-26) |
| ✅ P-02 | Alta **solo por invitación**, hecha por Secretaría, Coordinación o administradores |
| ✅ P-05 | El scouter edita **contacto y tutores** de los socios de sus secciones. El resto de datos personales lo edita Secretaría (v0.7) |
| ✅ P-06 | El participante cambia **directamente** su email y sus teléfonos (v0.7) |
| ✅ P-12 | El usuario de API tiene permiso de edición en Contacts y Relaciones: **se usa para editar desde el área privada** según el rol (v0.7, petición del usuario). Si se le quita, todo pasa a solo lectura |
| ✅ P-23 | **Banco:** se importan ficheros **Norma 43 o CSV** (v0.6, doc 16). La PSD2 directa queda descartada |
| ✅ P-27 | **Grupos dados de alta a mano**, cada uno con **su usuario y contraseña del CRM**; la URL es común (v0.6, doc 15) |
| ✅ Edición | Si el usuario del CRM puede editar: **todos los flujos de edición**, con lo no editable en solo lectura; «Asignar nº» escribe en el CRM; los duplicados reciben el siguiente número libre (v0.7, doc 17) |
| ✅ Sin sección | «**Voluntario sin sección**»: solo Tesorería, Secretaría o Coordinación, sin el rol de Scouter (v0.7) |
| ✅ P-30 | **Primera escritura real en el CRM:** la hizo el propio usuario desde *Personas* el 2026-09-27 (3 duplicados resueltos y 138 formatos corregidos); el CRM aceptó todas. La escritura por API queda comprobada (doc 17) |
| ✅ Segundo plano | Las correcciones masivas son **tareas en segundo plano** con un **monitor** que dice cuántas fichas quedan, sin bloquear la página (v0.7.2, petición del usuario tras la pantalla «congelada») |
| ✅ Ficha | Apartados **plegables**; a los **menores** no se les muestran formación ni certificado de delitos sexuales; el **cargo** solo con membresía de Scouter o Voluntariado; **Salud** y **Alimentación** separadas; **tipo de régimen** con 4 dietas y su explicación al pasar el ratón (v0.7.1) |
| ✅ P-32 | El **cargo** se ve con membresía de **Scouter o de Voluntariado** (`Secciones::ADULTAS`) (v0.7.3) |
| ✅ Instalación | **Botón «Instalación y entorno»** para dejar un WordPress nuevo listo: lo del plugin siempre; los ajustes del sitio solo si se marcan y si hacían falta; lo que requiere credenciales queda como siguiente paso (v0.8.0, doc 19) |
| ✅ Organización | Pantalla **Organización federada** con las 17 organizaciones de scout.es: su nombre sale en los textos, su **código** (configurable) va en el nº de socio local y su **coste federativo** (con **otros conceptos**) es el punto de partida de cada ronda (v0.9, doc 20) |
| ✅ Hora | La zona horaria **no la gestiona el plugin**: es un ajuste de WordPress. Se quitó de «Instalación y entorno» (v0.9, decisión del usuario) |
| ✅ Acceso con email | El usuario de acceso es **el nº de socio/a o el email asociado a ese nº en el CRM** (el del campo elegido en el alta). Se busca en el CRM al entrar, sin guardarlo; si es de varias cuentas, manda la contraseña y, si vale para varias, se pide el nº (v0.9, doc 15) |
| ✅ Cabecera | Botón para **plegar la cabecera morada**, con el menú principal y el del área fijos; **sin barra de título ni migas** en las páginas del área (v0.7, doc 06) |
| ✅ Listados | Personas: **orden por columnas y paginación** de 25, 50, 75 o 100 por página (v0.6) |
| ✅ Emails | **Registro de emails** con su estado y la vista del email, sin guardar el enlace personal (v0.6) |
| ✅ Nombre | El plugin se llama «**ASDE Scouts de España - Gestor CRM para Grupos Scouts**» (la carpeta sigue siendo `asde-area-privada` para no desactivarlo) |
| ✅ P-03 | **Todo es por grupo**: cada socio tiene un grupo «padre» y no hay visibilidad entre grupos. Secretaría y Coordinación gestionan su grupo (2026-09-26) |
| ✅ Acceso | Pantalla **Acceso socios** con desplegable de Grupo Scout + nº de socio + contraseña. Es lo **único** del área privada en el menú superior; el resto de páginas privadas no aparece en el pie, el buscador, los menús ni los sitemaps (v0.5.0, doc 15) |
| ✅ P-04 | Los **scouters ven la salud y la alimentación de su sección, en solo lectura** (2026-09-26) |
| ✅ P-07 | Cuenta propia de participante **desde los 14 años** |
| ✅ P-09 | La salud y la alimentación son campos de la ficha (doc 04 §7.3) y se usan en los informes |
| ✅ P-13 | Sin CRM de pruebas: se trabaja en solo lectura contra el real, con el correo desviado a Mailpit |
| ✅ P-15 | Grupo = grupo de seguridad; sección = membresía / "Secciones actuales"; tutores = campos de la ficha del menor |
| ✅ P-16 | **Ultimate Member eliminado** (nadie lo usaba). Quedan restos inofensivos en la BD (opciones, 4 formularios, tabla `wp_um_metadata`) |
| ✅ P-18 | Las cinco secciones tienen miembros activos |
| ✅ P-19 | **A partir de 18 años solo accede la propia persona.** La familia deja de verla al momento y la tarea diaria retira el vínculo |
| ✅ P-20 | **Doble factor obligatorio** para Scouter, Secretaría, Coordinación y Tesorería (10 días de margen) |
| ✅ Usuario | **Usuario = nº de socio local** (7 + 684 + 4 dígitos; el 7 es configurable desde la v0.9); se genera si falta. Tutores: **nº del menor + T** (cuenta familiar). Desde la v0.9 también se entra con el email del CRM |
| ✅ Datos | **En WordPress no hay datos personales**: tabla nº Scouts de España ↔ nº local; nombres y demás datos se leen del CRM. **Excepción desde la v0.10 (2026-09-28):** el email de cada cuenta se guarda **cifrado** (clave del sitio + id del usuario) con una huella para encontrarlo, y lo escrito en una solicitud de acceso se guarda cifrado y se borra a los 30 días (docs 15 y 22) |
| ✅ Activos | Solo los socios con **membresía activa** aparecen en el área; la página *Personas* muestra también los inactivos |
| ✅ Responsab. | **Secretaría, Coordinación y Tesorería son responsabilidades de un scouter**, y se sugieren por el cargo del CRM |
| ✅ P-26 | **Familias** con nº **F + grupo + correlativo** (F684001) para Tesorería |
| ✅ Cuotas | Configurables: inscripción 20 € + seguro 10 € por hijo + anualidad 110/198/242 (tope). **Plazos configurables.** Pagan **de Castores a Rovers**; scouters y voluntariado no |
| ✅ Federación | Coste configurable: cuota asociativa 17 € + seguro (primas 2026: 1,92 / 2,44 / 6,06 € o promedio de 3 €), para **todos** los asociados. Desde la v0.9, con edad de corte y **otros conceptos** configurables, y los importes por defecto en *Organización federada* |
| ✅ Tesorería | Acceso: todos los scouters (pagos y familias). Configuración: Secretaría, Coordinación y **Tesorería** (rol nuevo) |
| ✅ Email cifrado | **El email de las cuentas se guarda en la web, cifrado** (petición del usuario del 2026-09-28). La clave no es solo el id del usuario, como se propuso, sino **la clave del sitio + el id**: con el id solo, una copia de la base de datos bastaría para descifrarlo todo (docs 08 y 15) |
| ✅ Solicitudes | **Solicitudes de acceso** con un enlace por grupo: documento + fecha de nacimiento (o fecha + nombre, para menores de 14 sin documento), aprueba un scouter de la sección (o Secretaría/Coordinación) y llega la invitación con bienvenida. Si el email no es el de la ficha, **se actualiza en el CRM** al aprobar. Email de contacto configurable por grupo (Myotragus: `grupo@example.invalid`) (v0.10, doc 22) |
| ✅ Ventanas | **Fichas, «Dar acceso» y solicitudes en ventana emergente** sobre la página; pestaña *Alta de usuarios* sustituida por «Dar acceso» en *Personas* y *Usuarios*; «Seguridad» junto al nombre (v0.10, doc 23) |
| ✅ Uniformes | **Pedidos de uniforme** (petición del usuario, 2026-09-29): camisa a 25 € (modelos masculino y femenino, con las tallas de sus tablas de medidas) e insignias configurables con su imagen. **Tesorería** valida el pago (efectivo o ingreso), **Coordinación** el pedido al proveedor, **el grupo** la entrega y **el socio/a o su familia** confirma que la ha recibido. Scouters incluidos (v0.11, doc 24) |
| ✅ Mis pagos | **«Mis pagos»**, pantalla propia y solo de consulta: cuota de la ronda con plazos, pagos registrados por Tesorería (sin notas internas ni anulados) y pagos del uniforme (v0.11, doc 24) |
| ✅ P-35 | **Dos tutores, dos cuentas** (decisión del usuario, 2026-09-30): el primer tutor/a entra con `nº + T` y el segundo con **`nº + T2`**, cada uno con **su email y su contraseña**; ven a los mismos menores y comparten la familia de Tesorería. Al aprobar una solicitud de una familia que ya tiene cuenta se elige entre «otro tutor/a» (cuenta nueva) y «la misma persona con otro email» (sustituir). Máximo dos cuentas por familia (v0.12, docs 22 y 12) |
| ✅ P-36 | **Aviso por email al rechazar una solicitud** (decisión del usuario, 2026-09-30): texto genérico (sin el motivo, que es solo para el grupo) con el **email de contacto del grupo**, casilla marcada por defecto, anotado en el registro de emails (v0.12, doc 22) |
| ✅ Contacto | El **email de contacto es parte de la configuración de cada grupo** (*Grupos Scouts*), no de una pantalla aparte (decisión del usuario, 2026-09-30). Lo usan «Solicitar acceso», el aviso de rechazo y los emails SEPA; «Instalación y entorno» y *Solicitudes* avisan si falta (v0.12) |
| ✅ SEPA | **Domiciliación SEPA** (petición del usuario, 2026-09-30): las familias (y los asociados mayores de edad) **firman la autorización en «Mis pagos»** y Tesorería **genera el fichero de adeudos** para el banco, con aviso previo a las familias y registro de cobros y devoluciones (v0.12, doc 25) |
| ✅ Usuario | **El usuario de acceso pasa a ser configurable: nº de Scouts de España o nº territorial** (petición del usuario, 2026-10-02, v0.14, doc 27). Valen los dos al entrar; el nº territorial se sigue vigilando y corrigiendo, se puede ver en todas las pantallas («Mostrar nº territorial») y su advertencia sale siempre. **A confirmar:** la preferencia de mostrarlo es por persona y empieza apagada; el alta ya no genera nº territorial con `asde`; las familias no ven esos números |
