# 17 · Fichas editables y escritura en el CRM (v0.7 – v0.7.3)

## Petición del usuario (2026-09-26)

- Si el usuario con el que la web entra en el CRM **puede editar** los datos que ya se ven en el área privada, hay que añadir **todos los flujos de edición**.
- **Todo campo que no se pueda editar aparece en solo lectura.**
- En Personas, «Asignar nº» debe guardar el número **también en el CRM**.
- Los **números duplicados** se resuelven dando a una de las fichas repetidas el siguiente número libre.

> Esto sustituye a la decisión de la v1 («el CRM solo se lee»). La escritura solo ocurre si el usuario del CRM del grupo tiene permiso; si no, todo sigue en solo lectura.

## Peticiones del 2026-09-27

- **v0.7.1 · ficha:**
  - cada apartado de datos se puede **contraer y expandir**;
  - a los **menores de edad** no se les muestran *Formación tiempo libre*, *Certificado delitos sexuales*, *Fecha certificado DS* ni *Formación Scout*;
  - sin membresía de **Scouter** no se muestra el **Cargo** (apartado Membresía). Desde v0.7.3, tampoco sin la de **Voluntariado**: con cualquiera de las dos, sí se muestra;
  - **Salud** y **Alimentación** van en apartados separados;
  - el **tipo de régimen** es un desplegable con 4 dietas, y la explicación de cada una se ve **al desplegarlo y pasar el ratón por la opción**.
- **v0.7.2 · correcciones masivas en segundo plano:** la pantalla se quedaba «congelada» mientras se corregían los números de todas las fichas. El usuario reinició el sitio y la corrección se cortó (ver «Primera escritura real»). Ahora es una **tarea asíncrona**, con un **monitor** que va diciendo cuántas fichas quedan **sin detener el uso normal de la página**.

## Qué permite el CRM (comprobado el 2026-09-26)

- `get_available_modules` (ACL por módulo) para el usuario del grupo 684:
  - **Contacts:** edición sí, borrado no;
  - **stic_Contacts_Relationships:** edición sí;
  - SecurityGroups: solo lectura.
- `get_module_fields` da el **tipo** de cada campo (varchar, text, phone, date, bool, enum, dynamicenum, radioenum, multienum), las **opciones** de los desplegables, la longitud, si es obligatorio y si es calculado.
- **No informa de permisos por campo.** Por eso, después de guardar se **relee** y lo que no se haya guardado se muestra como «no aceptado por el CRM».

## Piezas

| Clase | Qué hace |
|---|---|
| `Crm\Permisos` | ACL por módulo del usuario del grupo; se guarda 1 h y se renueva al probar la conexión |
| `Crm\Metadatos` | Definición de los campos según el CRM; se guarda 12 h por grupo |
| `Crm\Escritura` | **Única puerta de escritura** (detalle abajo) |
| `Crm\EscritorCrm` | Interfaz (`set_entry` + `get_entry`). La implementa `SinergiaV41Client`; en las pruebas, un CRM simulado |
| `Fichas\CamposFicha` | Campos de la ficha: los mismos del catálogo de informes (lo que ya se puede ver), uno por campo del CRM |
| `Fichas\PermisosFicha` | Qué puede hacer cada usuario con cada campo: EDITAR, PROPONER o VER |
| `Fichas\FichaService` | Carga los valores (solo lo que el usuario puede ver; la salud, solo si puede verla), valida por tipo y guarda |
| `Fichas\Cambios` | Cola de cambios propuestos (doc 07), con los valores cifrados |

**`Crm\Escritura`** en detalle:

- comprueba el permiso del usuario del CRM;
- escribe solo fichas del grupo (la persona viene de `PersonaRepository`, filtrada por grupo de seguridad);
- nunca borra registros; hasta la v0.21 tampoco creaba ninguno. Desde la v0.22 (doc 34), y solo si las altas están activadas para el grupo, `crear()` crea personas y membresías, `actualizar_membresia()` cierra membresías de una persona del grupo y `relacionar()` enlaza la persona con el grupo de seguridad **propio** o con una membresía (lo orquesta `CrmAltas`);
- nunca toca `numerosocio_c`, `stic_relationship_type_c` ni campos de sistema;
- el nº local (`numsocioantiguo_enficha_c`) solo lo escribe la numeración;
- relee para verificar lo guardado;
- deja constancia en la auditoría con los **nombres de los campos** (nunca los valores).

## Quién puede cambiar qué

Es la matriz del doc 02, aplicada sobre el ámbito:

| Usuario | Ve | Edita directamente en el CRM | Propone (lo aprueba otra persona) |
|---|---|---|---|
| Secretaría y administradores | Todo el grupo | Todos los campos (también salud y DNI) | — |
| Coordinación | Todo el grupo | Los **textos** de salud y alimentación (anotaciones) | — |
| Scouter | Los socios de sus secciones (salud en solo lectura) | Contacto y tutores de esos socios | — |
| Cada persona | Su ficha (sin salud) | Su email y sus teléfonos | — |
| Cuenta familiar | Las fichas de sus menores (con salud) | — | Todo salvo la membresía |

**Siempre de solo lectura**, con el motivo junto al 🔒:
- el nº Scouts de España (lo asigna la federación);
- el nº local (se gestiona en Personas);
- la sección (sale de las membresías);
- los campos calculados o de selección múltiple.

Si el usuario del CRM no puede editar, **toda la ficha es de solo lectura** y se avisa.

## Ficha (`/area-privada/ficha/?socio=NNN`)

- **Cómo se llega:**
  - desde Personas, con «Ver ficha»;
  - desde el menú: «Mis datos» (la propia ficha) o «Mi familia» (cuenta familiar, con pestañas si hay varios menores);
  - desde el inicio.
- **Apartados:** datos personales, membresía, contacto, familia/tutores, autorizaciones y formación, **salud** y **alimentación** (separados desde v0.7.1: los campos `alimentacion_*` del catálogo van al suyo). Coordinación anota en los textos de los dos.
- **Plegables (v0.7.1):** cada apartado es un `<details>` que se abre y se cierra con su título (con el número de datos al lado). Hay «Contraer todo / Expandir todo». El navegador de cada persona recuerda qué apartados dejó cerrados (`localStorage`, clave `asde_ficha_plegados`), también al pasar a otra ficha. Si al guardar falta un dato obligatorio en un apartado cerrado, se abre solo. Sin JavaScript, todo se ve abierto.
- **Campos que no se muestran** (`PermisosFicha::ve_campo`, v0.7.1):
  - **menores de 18** (edad calculada con la fecha de nacimiento): *Formación tiempo libre*, *Certificado delitos sexuales*, *Fecha certificado DS* y *Formación Scout* (`CamposFicha::SOLO_ADULTOS`);
  - **sin membresía de Scouter ni de Voluntariado** (`Secciones::ADULTAS`): el *Cargo* (`CamposFicha::SOLO_VOLUNTARIOS`). Confirmado por el usuario el 2026-09-27 (P-32).
  
  No se ven ni se envían; al guardar, lo que no se ve tampoco se puede cambiar.
- **El control de cada campo sale del tipo del CRM:** texto, texto largo, teléfono, email, fecha, casilla sí/no o desplegable con las opciones del CRM. Los obligatorios del CRM (nombre, apellidos, fecha de nacimiento, género) no se pueden vaciar.
- **Tipo de régimen (v0.7.1):** en el CRM es un texto libre. En la ficha es un desplegable con 4 opciones (`CamposFicha::OPCIONES`):
  - **Dieta omnívora:** alimentos de origen animal y vegetal sin mayores restricciones;
  - **Dieta vegetariana:** sin carne ni pescado; puede incluir huevos y lácteos (ovolactovegetariana);
  - **Dieta vegana:** ningún producto de origen animal (tampoco miel, lácteos ni huevos);
  - **Dieta flexitariana:** sobre todo vegetales, con carne o pescado ocasionalmente.

  Con ratón, la lista es propia (`assets/js/ficha.js`) y al pasar por una opción aparece un globo con su explicación; también funciona con el teclado (flechas, Intro, Esc). En pantallas táctiles se usa el desplegable del sistema. En los dos casos, la explicación de la dieta elegida se ve debajo del campo.
  - Solo se aceptan esas 4 opciones (o vacío).
  - Si el CRM ya tenía otro texto, se conserva como opción «(valor actual)» hasta que alguien elija una dieta.
- **Al guardar:**
  - solo se envía lo que ha cambiado;
  - se valida por tipo (fecha real, opción existente, email, teléfono, longitud máxima);
  - si alguien cambió el dato en el CRM mientras se editaba (huella del valor mostrado distinta), **no se sobrescribe** y se avisa.
- **Aviso final:** qué se guardó, qué no aceptó el CRM, qué se propuso y qué errores hubo.

## Cambios propuestos (`/area-privada/cambios-pendientes/`)

- Los revisan **Secretaría y Coordinación** (todo el grupo) y los **scouters** (sus secciones). **Nadie aprueba sus propias propuestas.** En el menú aparece «Cambios (n)».
- La bandeja muestra, para cada cambio:
  - el valor actual en el CRM y el propuesto;
  - un aviso si el dato del CRM **ha cambiado desde la propuesta**. Para aplicarlo hay que marcar «Aplicar igualmente».
- **Aprobar:** se escribe en el CRM y queda *aplicado*. Si el CRM no lo acepta, queda en *error*.
- **Rechazar:** con motivo obligatorio, que la familia ve en la ficha.
- **Retirar:** quien lo propuso puede retirarlo mientras esté pendiente.
- Un cambio nuevo del mismo dato **sustituye** al pendiente anterior.
- Los valores (anterior y nuevo) se guardan **cifrados**.
- Ganchos para notificaciones: `asde_cambio_propuesto`, `asde_cambio_aprobado`, `asde_cambio_rechazado`.

## Números de socio corregidos en el CRM (Personas)

Si el usuario del CRM puede editar:

| Incidencia | Botón por ficha | Botón para todas |
|---|---|---|
| Sin número / no válido | **Asignar nº**: reserva el siguiente y lo guarda en el CRM | — |
| Generado (pendiente) | **Pasar al CRM** | Pasar al CRM los números generados |
| Formato antiguo (076840123, 6840123) | **Corregir en el CRM** (formato 7 + grupo + 4 cifras) | Corregir en el CRM los formatos antiguos |
| Duplicado | **Dar nº nuevo**: esta ficha recibe el siguiente libre y la otra conserva el suyo | Resolver los duplicados |

- **Duplicados, quién conserva el número**, por este orden:
  1. quien entra en la web con ese número (usuario propio o cuenta familiar «nº + T»); **nunca se le cambia**;
  2. quien tiene membresía activa;
  3. el nº de Scouts de España más antiguo.
- **Confirmación:** cada acción pide confirmar y dice qué número quedará.
- **Tabla de equivalencias:** tras escribir en el CRM, el número deja de estar «pendiente».
- **Si el usuario del CRM no puede editar:** «Asignar nº» solo reserva el número, como antes, con el recordatorio de anotarlo en el CRM.
- **Los botones de una ficha** escriben al momento (1–3 s). **Los de «todas»** crean una tarea en segundo plano (siguiente apartado).

## Correcciones masivas en segundo plano (v0.7.2)

Escribir una ficha en el CRM lleva ≈1,2 s (guardar y releer para comprobar), y leer el grupo entero 2–4 s. Corregir 250 fichas en una sola petición eran 5 minutos con la pantalla esperando. En Local, además, la mitad de las páginas se quedaban esperando detrás (doc 10). En producción, el hosting corta las peticiones largas.

**Cómo funciona** (`Socios\TareaNumeracion`):

1. **El botón solo crea la tarea** («Corregir en el CRM los formatos antiguos», «Pasar al CRM los números generados» o «Resolver los duplicados») y la pantalla vuelve enseguida con el aviso «Corrección iniciada en segundo plano». Hay **una tarea a la vez por grupo**; mientras dura, los botones de «todas» se ocultan.
2. **Un trabajador hace tandas de 10 s** (filtro `asde_ap_tarea_segundos`, sin pasar del límite de PHP del hosting):
   - relee el grupo del CRM, así que cada ficha se vuelve a comprobar antes de tocarla;
   - corrige lo que quepa: al acabarse el tiempo no empieza otra ficha, y siempre hace al menos una;
   - guarda el avance y **se relanza a sí mismo** con una petición interna a `admin-ajax.php?action=asde_ap_tarea` que no espera respuesta. La autoriza un token aleatorio de la tarea, que va en el cuerpo: no hay sesión. La acción va en la URL para que la regla `noabort` de LiteSpeed se pueda limitar a estas peticiones (doc 18).
3. **Respaldos:** si la petición interna no llega, WP-Cron (evento `asde_ap_tarea_numeracion`). Si la tarea lleva más de 45 s parada, **el monitor la relanza** al consultar el estado, y a los 90 s hace él mismo una tanda corta. Así avanza aunque el hosting no permita peticiones internas, mientras alguien tenga abierta el área (P-33).
4. **Nunca dos tandas a la vez:** cerrojo atómico (`INSERT IGNORE` en `wp_options`) que caduca solo si una tanda muere.
5. **Nada a medias:** una ficha se corrige entera o no se toca, y repetirla no hace daño.
   - Las que **fallan** se apuntan (nº Scouts de España y motivo) y no se reintentan en bucle.
   - Si no se puede leer el CRM, se reintenta; a las 5 tandas seguidas se detiene con error.
   - Si quien la inició pierde el permiso, se detiene.
6. **Auditoría:**
   - «tarea iniciada»;
   - cada ficha escrita, **a nombre de quien inició la tarea** (el trabajador actúa en su nombre);
   - al final, un resumen (`numeros_corregir_todos`, `numeros_pasar_todos` o `numeros_duplicados_resueltos`) con corregidas, fallidas, pendientes y tandas;
   - «tarea detenida», si alguien la para.
7. **Estado:** opción `asde_ap_tarea_num_<grupo>` (sin autocarga). Solo guarda números de socio y contadores, **sin nombres**.

**Monitor** (`templates/comun/tarea.php` + `assets/js/tarea.js`):

- Recuadro bajo el menú en **todas las páginas del área privada** para quien gestiona Personas (Secretaría, Coordinación, administradores). Se puede seguir trabajando o cambiar de página: la tarea sigue en el servidor.
- Pregunta cómo va cada 3 s (cada 15 s si la pestaña no está a la vista), sin recargar. Muestra una barra y un texto: «Quedan 67 de 106 · 38 corregidas · 1 con error».
- Botones:
  - **Detener** (lo corregido se queda);
  - **Cerrar** (al acabar);
  - en Personas, **Recargar la lista** (la lista de la página es de antes de corregir);
  - en las demás páginas, **Ver en Personas**.
- En **Personas**, las fichas que no se pudieron corregir se listan **con su nombre**: el nombre se pone al pintar la página, nunca se guarda. Cada una enlaza a su ficha.

**En producción:** comprobar con el **diagnóstico** (doc 18) que el hosting permite la petición interna, que no la corta al desconectarse quien la lanza y que WP-Cron funciona. Producción está en Hostinger, que usa LiteSpeed: probablemente haga falta la regla `noabort` en el `.htaccess` (P-33). Si no se permite, la tarea avanza igualmente mientras el monitor esté abierto.

## Primera escritura real (2026-09-27)

Según la auditoría, la hizo la cuenta de administración del grupo desde *Personas*, con los botones de «todas» (hora UTC):

- **05:31:04–05:31:07 · «Resolver los duplicados»:** 3 fichas recibieron el siguiente número libre, y los números repetidos se quedaron en la otra ficha. Comprobado en el CRM (solo lectura): **ya no hay duplicados**.
- **05:31:53–05:34:31 · «Corregir en el CRM los formatos antiguos»:** 138 fichas corregidas; el CRM aceptó todas.
- **05:34:29:** Local **reinició el sitio** (mató PHP, MySQL y nginx; consta en `local-lightning-verbose.log`). La corrección se cortó sin llegar a escribir su resumen. Quizá una ficha más llegó a escribirse en el CRM sin constar en la auditoría; si fue así, tiene su mismo número con el formato correcto.
  - El usuario reinició porque la pantalla parecía congelada: de ahí la v0.7.2.
- **Queda** (2026-09-27): 106 fichas con formato antiguo (17 con membresía activa), 7 no válidos y 20 sin número (todas sin membresía activa). Para terminar, basta con volver a pulsar el botón (P-21).

Con esto queda comprobado que la escritura por API funciona (P-30).

## Pruebas (2026-09-27)

- `tests/fichas-escritura.php`: **82 comprobaciones**, todas OK (v0.7.3: cargo con Voluntariado y diagnóstico del hosting, doc 18). **No escribe en el CRM real**: todas las escrituras van a un CRM simulado (`Escritura::usar_para_pruebas`) y del real solo se lee. Cubren:
  - escritura: campos prohibidos, verificación releyendo, campos no aceptados, auditoría sin valores, grupo sin conexión;
  - permisos por rol: Secretaría, scouter de la sección y de otra, Coordinación, cuenta familiar y participante;
  - v0.7.1: menor sin formación ni certificado; cargo solo con Scouter; adulto scouter con todo; salud y alimentación separadas; Coordinación también en alimentación;
  - validación por tipo y régimen de alimentación (las 4 dietas con su explicación; solo esas; el texto anterior como «valor actual»); guardado solo de lo que cambia; conflicto por cambio simultáneo; propuesta de la familia;
  - cola de cambios: cifrado, sustitución, retirar, rechazar con motivo, conflicto al aprobar y aprobar confirmando;
  - numeración: corregir formato; por tandas (tiempo agotado, detener, saltar las que fallaron); duplicados, sin tocar al que entra en la web y con números nuevos únicos;
  - «Voluntario sin sección»;
  - **segundo plano:**
    - el botón crea la tarea y vuelve;
    - una sola tarea por grupo, y nada sale del proceso de la prueba;
    - una tarea de prueba nunca la ejecuta una petición normal, y el token es obligatorio;
    - tandas y autoría en la auditoría;
    - monitor sin nombres;
    - cerrojo (también caducado);
    - detener y cerrar;
    - todas fallidas sin bucle, y tarea completa con su resumen.
- **Limpieza:**
  - restaura la tabla de números **solo en las fichas que tocó**;
  - borra sus usuarios y cambios;
  - de la auditoría, **solo borra lo suyo**: sus usuarios, o la consola en las acciones que hace la prueba. Antes borraba todo lo posterior a su inicio, y se habría llevado acciones reales hechas a la vez.
- **En el navegador (v0.7.1):**
  - ficha de un menor: 7 apartados plegables, sin formación ni certificado, sin cargo;
  - adulto sin Scouter: con formación y certificado, sin cargo;
  - scouter: con cargo;
  - lo que se contrae se recuerda al pasar a otra ficha;
  - «Expandir todo»;
  - el globo del régimen aparece al pasar el ratón por la opción.
- **En el navegador (v0.7.2), sin tocar el CRM:**
  - la petición interna llega y trabaja en menos de 1 s (probado con una tarea de un grupo inexistente, que se detiene sin leer el CRM);
  - con una tarea de prueba (que la web nunca ejecuta), el monitor aparece en el inicio y en Personas y se actualiza solo;
  - al terminar, muestra el resultado y el botón Cerrar la quita;
  - en Personas se ocultan los botones de «todas» y se listan las fallidas con nombre.

## Pendiente

- **Terminar la corrección de números** (P-21): volver a pulsar el botón; ahora va en segundo plano.
- **Segundo plano en producción** (P-33): pasar el diagnóstico en Hostinger (doc 18).
- **Notas u observaciones** de la propia persona y de Coordinación como registros aparte (módulo Notes del CRM): el usuario del CRM no tiene hoy ese módulo.
- **Membresías** (secciones): se editan en el módulo de relaciones del CRM, fuera del alcance de esta versión.
- **Notificaciones** de cambios propuestos o resueltos (los ganchos están listos).
