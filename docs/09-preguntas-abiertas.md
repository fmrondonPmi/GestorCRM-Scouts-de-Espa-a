# 09 · Preguntas abiertas

Solo lo que sigue sin decidir o es un supuesto por confirmar en la última versión. Lo ya decidido está en el doc de cada módulo y en el código; lo que se descartó se ha quitado. Cuando se responde algo, se aplica y se borra de aquí.

## Pendientes de decidir o de hacer fuera del código

| # | Pregunta | Afecta a |
|---|---|---|
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
| P-42 | **SEPA · datos reales del acreedor:** nombre, **identificador del acreedor (ICS)** e IBAN del grupo, que da el banco al contratar las domiciliaciones. Hay que confirmar con **Colonya** que acepta el fichero **XML pain.008 (Cuaderno 19.14)**, por qué canal se sube, si pide el **BIC** y con cuántos días hábiles de margen (la web exige 3) (doc 25) | Tesorería · SEPA |
| P-43 | **SEPA · validez de la firma:** la autorización se firma **electrónicamente** (queda registrado quién, cuándo y el texto aceptado). ¿La acepta Colonya como orden de domiciliación o quiere el papel firmado? También: quién custodia el mandato mientras esté vigente y los 14 meses siguientes (doc 25) | Tesorería · SEPA |

## Supuestos hechos al programar (por confirmar)

| # | Supuesto | Afecta a |
|---|---|---|
| P-106 | **Lista de compra · editar (supuesto, v0.30.0):** pediste el material nuevo «al crear o editar» la lista; una lista **ya enviada sigue sin poderse editar** (se cancela y se hace otra): el material nuevo se escribe al prepararla (y al volver al formulario). ¿Hace falta editar una lista pendiente? | Inventario |
| P-123 | **Sección por edad (supuesto, v0.32.0):** la edad que cumple en el año en que empieza la ronda (septiembre a agosto): Castores 6-7, Lobatos 8-10, Scouts 11-13, Escultas 14-16, Rovers 17-20, Scouters 21+. (doc 39) | Lista de espera |
| P-124 | **Quién ve qué (supuesto, v0.32.0):** los scouters ven **toda** la lista del grupo (no solo sus secciones), con filtro por sección; el documento solo lo ven Secretaría y Coordinación. (doc 39) | Lista de espera |
| P-125 | **Persona de contacto (supuesto, v0.32.0):** se piden su nombre y apellidos, obligatorios por debajo de 18 años (el alta en el CRM los necesita como tutor/a 1). El género no se pide: lo pone Secretaría al dar de alta. (doc 39) | Lista de espera |
| P-126 | **Sin editar (supuesto, v0.32.0):** desde su enlace la persona ve, mantiene y borra sus datos, pero no los edita (escribe al grupo o se vuelve a apuntar). (doc 39) | Lista de espera |
| P-127 | **Plazos (supuesto, v0.32.0):** sin confirmar se borran a los 7 días; el aviso de caducidad sale 30 días antes, una vez por periodo. (doc 39) | Lista de espera |
| P-128 | **Apagado por defecto (supuesto, v0.32.0):** el módulo se enciende por grupo en wp-admin → Módulos; «Dar de alta» necesita además las altas en el CRM activas. (doc 39) | Lista de espera |
| P-129 | **Repetidas (supuesto, v0.32.0):** misma persona y mismo email no se repite (se reenvía el email); con otro email se apunta y sale como «Repetida». (doc 39) | Lista de espera |
| P-130 | **Observaciones (supuesto, v0.32.0):** no van al CRM (no hay campo); Secretaría las lee antes del alta. (doc 39) | Lista de espera |
| P-132 | **La cuenta en la ficha (supuesto, v0.33.0):** la web guarda de la autorización el IBAN **enmascarado** (para enseñarlo en «Mis pagos», Tesorería y los emails de aviso) y una **huella** del IBAN; el IBAN entero, el titular y la entidad, solo en el CRM. (doc 25 §9) | SEPA |
| P-133 | **Anular no borra la cuenta del CRM (supuesto, v0.33.0):** al anular una autorización, los datos bancarios se quedan en las fichas del CRM (pueden usarlos la federación u otros procesos); si la persona quiere que se borren, lo pide al grupo o a la federación. (doc 25 §9) | SEPA |
| P-134 | **Quién ve los datos bancarios en la ficha (supuesto, v0.33.0):** la familia, el propio asociado mayor de edad y Tesorería (con quien configura la Tesorería: Secretaría y Coordinación); los scouters no. Siempre enmascarados. (doc 25 §9) | SEPA |
| P-135 | **Solo cuentas españolas (supuesto, v0.33.0):** el campo `iban_c` del CRM admite 24 caracteres, así que no se aceptan IBAN más largos (de otros países). (doc 04 §7.3 bis) | SEPA |
| P-136 | **«Socio/a desde» (supuesto, v0.34.0):** cuenta desde el alta de la membresía activa y, hacia atrás, las que enlazan con ella sin cortes (un cambio de sección no reinicia la cuenta; quien se dio de baja y volvió cuenta desde la vuelta). Si se prefiere desde la primera membresía de todas (aunque haya huecos), es un cambio de una línea. | Personas |
| P-137 | **Quién ve «Socio/a desde» (supuesto, v0.34.0):** solo quien abre la ficha desde Personas; ni la propia persona ni su familia, porque cuesta una consulta más al CRM (0,5–1,5 s) y se pidió «en Personas». | Personas |
| P-138 | **Bajas hechas en el CRM (comprobar, v0.34.0):** el fallo encaja con una membresía puesta en «baja» en el CRM **sin fecha de baja** (o con una fecha futura): para el CRM sigue activa (calcula `active` y «Secciones actuales» por las fechas, P-74). Confirmarlo con `herramientas-desarrollo/crm-revisar-alta.php` (solo lectura) y el nº de esa persona. Desde la v0.34, «Dar de baja» de la web la encuentra y le pone la fecha. | Altas en el CRM |
| P-165 | **Historial de pagos de la familia (supuesto, v0.37.0):** los pagos de eventos y de uniformes salen en el historial de la ficha de **todas las rondas** y no se eliminan desde allí (se anulan donde se registraron: «Inscripciones» del evento o «Gestión de pedidos»). | Tesorería |
| P-166 | **Pago de un evento desde «Registrar pago» (supuesto, v0.37.0):** solo Tesorería (quien cobra eventos), solo eventos publicados con la inscripción abierta y algo pendiente de la familia; efectivo, transferencia o Bizum; sin ingreso del banco; se reparte entre las inscripciones de la familia y no toca la cuota de la ronda. | Tesorería, Eventos |

## Tienda, Reintegros y modo Oficina

- **P-185 → supuesto:** justificante de Reintegros obligatorio (imagen o PDF de hasta 5 MB); nadie aprueba ni rechaza el suyo; fecha del gasto no futura ni de hace más de un año.
- **P-186 → supuesto:** el email a Tesorería solo sale cuando se pide por transferencia (en efectivo basta el contador del menú); a quien pidió le llegan aprobado, rechazado (con motivo) y pagado.
- **P-187 → supuesto:** un reintegro no genera movimiento en el banco ni en Tesorería; se paga por fuera y se marca en Reintegros.
- **P-188 → supuesto, por confirmar con ASDE:** la web no puede comprobar que la credencial de la oficina sea de solo lectura en el CRM (haría falta escribir); lo afirma quien la configura y la pantalla lo avisa tras probar la conexión.
- **P-189 → supuesto:** el informe de pagos queda fuera de la oficina (son datos de Tesorería, no del CRM).
- **P-191 → pendiente de programar:** el enlace de tipo «actualizar» para que un grupo **ya existente** cambie su credencial o complete sede, CIF y denominación fiscal (P-175) y, con él, esconder la credencial a la administración. Hasta entonces el administrador la edita en Grupos Scouts.
- **P-192 → supuesto:** del CIF solo se usa la denominación y la sede (acreedor y dirección por defecto de SEPA y cabecera de la lista de compra en PDF); el identificador del acreedor (ICS) lo da el banco.
- **P-193 → supuesto (v0.51):** un producto de la tienda creado al marcar una fila del inventario nace «solo socios» y con el precio por confirmar; la migración de lo que tenía la tienda crea filas sin tipo con la nota «Pasado desde la tienda».

## Gestión de accesos (v0.53, doc 56)

- **P-194 → supuesto:** el «Permiso» del modelo es el catálogo del código (`Accesos\Catalogo`), no una tabla: cada permiso es una capability que el código ya comprueba; lo que se guarda en la base de datos son los cambios de los roles, las concesiones y el historial.
- **P-195 → supuesto:** «revocaciones» son las concesiones que alguien retira; **no** se le niega a una persona un permiso que trae su rol (para eso se cambia el permiso del rol para todos o se le cambia de rol).
- **P-196 → supuesto:** la matriz vale para **todo el sitio** (los roles de WordPress son únicos) y solo para los roles de gestión: Scouter, Secretaría, Coordinación, Tesorería e Intendente. Tutores, participantes y Oficina quedan fuera.
- **P-197 → supuesto:** nadie añade un permiso a un rol que él mismo tiene (tampoco al restaurarlo); sí puede quitarlo. Así «nadie se concede permisos a sí mismo» también vale en la matriz. Un administrador de WordPress queda al margen.
- **P-198 → supuesto:** los permisos sueltos solo se dan a cuentas activas del grupo con un rol de gestión (no a familias ni participantes).
- **P-199 → supuesto:** se marcan como **sensibles** los que abren salud, todas las fichas, cuentas de acceso, dinero (tesorería, pagos de la tienda, reintegros, presupuestos de eventos), el alta desde la lista de espera y la sincronización con el CRM (lista en `Catalogo`). Piden motivo; no limitan quién los concede.
- **P-200 → supuesto:** reintegros no tiene un «ver» aparte: «aprobar» y «pagar» se apoyan en cualquiera de los tres permisos del módulo (así Tesorería, que no pide reintegros, cumple lo de fábrica).
- **P-201 → supuesto:** «Gestión de accesos» no lleva interruptor por grupo en Módulos (no cambia nada hasta que alguien lo usa); al actualizar, Secretaría, Coordinación y Tesorería ven «Accesos» en el menú. El historial no se purga.
- **P-202 → decisión del usuario (2026-10-24, v0.57.1):** en los presupuestos, el coste federativo se paga con la **inscripción y el seguro** que cobra el grupo por cada hijo; solo el resto de la cuota (la anualidad) se reparte entre el grupo y la sección («si una familia paga por 1 hijo 140, los 30 de inscripción y seguro son para pagar el 100 % del coste federativo; los 110 restantes, para distribuir entre el grupo y la sección»). Doc 57 §3.
- **P-203 → supuesto (v0.57.1):** lo que sobre o falte entre la inscripción y el seguro cobrados y el coste federativo real de todos (educandos, adultos, educandos sin cuota y lo fijo por grupo) va a las **arcas del grupo**, no a las secciones.
- **P-204 → supuesto (v0.57.1):** una beca o un descuento rebaja primero la anualidad; si la cuota queda por debajo de la inscripción y el seguro, ese educando no aporta nada a su sección (antes aportaba en negativo) y lo que falta lo pone el grupo. Se avisa con el nº de educandos así.
- **P-205 → pendiente de confirmar (v0.57.1):** el error de altas y bajas que «no se ven en la web» se ha arreglado suponiendo que se hicieron **desde la web** (la relectura del CRM en segundo plano pisaba la instantánea, doc 26). Si se hicieron **directamente en el CRM**, la web las enseña al refrescar la instantánea (hasta 5–10 minutos, o al momento con «Actualizar» en Personas): decir si eso basta o hace falta otra cosa.
