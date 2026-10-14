# 08 · Protección de datos y seguridad

> Esto no es asesoramiento legal. Conviene que la persona **Delegada de Protección de Datos** de ASDE, o quien lleve RGPD en la federación, revise el diseño antes de subirlo a producción.

## Por qué este proyecto es sensible

- Hay **datos de menores**.
- Las **fichas de salud y de dietas** (alergias, medicación, intolerancias) son **categorías especiales de datos** (art. 9 RGPD).
- En España, según el art. 7 de la LOPDGDD, los **menores de 14 años** no pueden consentir por sí mismos: el consentimiento lo dan quienes ejercen la patria potestad o tutela. Esto afecta directamente a quién puede tener una cuenta de Participante (P-07).

## Principios de diseño que aplicaremos

1. **Minimización.** Cada rol recibe del CRM solo los campos que necesita. El filtrado se hace en el servidor antes de pintar la página, nunca ocultando cosas con CSS o JS.
2. **Nada de salud en caché.** Los datos de salud y dietas se piden al CRM en cada consulta y no se guardan en transients ni en la base de datos de WP, salvo los cambios pendientes, que van **cifrados** (libsodium, `sodium_crypto_secretbox`, con la clave en `wp-config.php` y fuera de la base de datos).
3. **Caché corta para el resto, cifrada (v0.12.3).** Las fichas del grupo **sin salud** (nombre, nacimiento, email, móvil, secciones, cargo y tutores) se guardan **cifradas y comprimidas 5 minutos** en un transient (`CrmInstantanea`, doc 26), para no hacer una consulta al CRM por página (cada una tarda 0,5–1,5 s). Se desactiva con el filtro `asde_ap_instantanea_segundos` a 0. Los listados de usuarios, membresías y numeración, igual: transients cortos.
4. **Credenciales del CRM fuera de la base de datos.** Van como constantes en `wp-config.php` (`ASDE_CRM_URL`, `ASDE_CRM_CLIENT_ID`, `ASDE_CRM_CLIENT_SECRET`) y **nunca en el repositorio**.
5. **Usuario de API dedicado y de solo lectura en el CRM**, con un rol que limite módulos y acciones. Aunque el código tuviera un fallo, no podría escribir.
6. **Auditoría:** tabla `{prefix}asde_auditoria` con quién, cuándo, qué acción, sobre qué contacto e IP. Registra también las **consultas** de fichas de salud, no solo las modificaciones.
7. **Autenticación:**
   - 2FA obligatorio para Secretaría, Coordinación y Scouters, por ejemplo con el plugin *Two Factor*.
   - Límite de intentos de login.
   - Tutores y participantes nunca ven `wp-admin` ni la barra de administración.
   - **Desactivar una cuenta borra con qué se entraba (v0.26.1, petición del usuario):** contraseña (sustituida por una aleatoria), contraseñas de aplicación, invitación pendiente, verificación en dos pasos y llaves de acceso de Wordfence, y sesiones. Para volver, una invitación nueva (doc 11).
   - **Entrar con el email (v0.9):** la auditoría anota «entró con el email», sin el email. Si el email no es de nadie, no se pasa a `wp_signon()`, para que no quede en el historial de intentos de Wordfence (doc 15).
   - **Email de las cuentas guardado cifrado (v0.10, decisión del usuario del 2026-09-28):**
     - Se cifra con una clave derivada de la **clave del sitio** (en `wp-config.php`, fuera de la base de datos) y del **id del usuario**: lo de un usuario no se descifra con el id de otro, y una copia de la base de datos sola no sirve para descifrar nada.
     - El usuario propuso usar el id como clave. Solo con el id no bastaría: está en la misma base de datos y es un número pequeño y correlativo, así que quien la robara lo descifraría todo.
     - Para encontrar la cuenta al entrar se guarda una **huella** (HMAC con la clave del sitio), no el email en claro. El `user_email` de WordPress sigue vacío.
     - El CRM sigue siendo la fuente: si el email cambia allí, se actualiza (doc 15).
12. **Solicitudes de acceso (v0.10, doc 22):**
    - El formulario público **nunca enseña datos del CRM**: solo dice si se ha podido comprobar o no. Con documento inexistente y con fecha distinta la respuesta es la misma.
    - Lo escrito (nombre, email, documento, fecha, nombre del menor) se guarda **cifrado** y se **borra 30 días después** de resolver la solicitud. Queda solo el resultado.
    - Contra abusos: 8 intentos por dispositivo y hora (de la IP solo se guarda un HMAC), campo trampa y tiempo mínimo.
    - Aprueba quien ya puede ver esa ficha (scouter de su sección, Secretaría, Coordinación). Al aprobar, el email nuevo se escribe en el CRM con auditoría (solo el nombre del campo).
13. **Domiciliación SEPA (v0.12, doc 25):** datos bancarios de las familias.
    - **Desde la v0.33 (P-131) la cuenta no se guarda en la web:** IBAN, titular y entidad van a la **ficha del CRM** de cada miembro de la familia (los custodia la federación) y se leen en vivo, sin entrar en la instantánea. La web guarda la **autorización** (la dirección del deudor y el lugar, **cifrados**; quién firmó y cuándo), el **IBAN enmascarado** y su **huella** (HMAC). Los adeudos de una remesa no llevan cuenta. Ni el IBAN ni el nombre aparecen en la auditoría ni en el registro de emails. (Antes de la v0.33, titular e IBAN iban cifrados en la web; «Pasar las cuentas al CRM» los mueve y los borra.)
    - El IBAN del **acreedor** (el grupo) también va cifrado en su opción.
    - De la firma se guarda quién, cuándo, la versión del texto aceptado y una **huella (HMAC) de la IP**, nunca la IP.
    - Solo pueden firmar la cuenta familiar de esa familia o el asociado mayor de edad; los menores no. El IBAN entero solo se ve al firmar, en la autorización impresa de quien puede firmar y dentro del fichero XML que descarga Tesorería (Secretaría, Coordinación y Tesorería).
    - **Conservación:** mientras la autorización esté vigente y **14 meses** después de anularla o del último cobro (norma SEPA); pasado ese tiempo, la tarea diaria vacía los datos cifrados.
    - **Aviso previo** al deudor de cada cargo (fecha e importe), por email y en «Mis pagos».
    - El fichero XML que se descarga **lleva las cuentas sin cifrar**: solo el tiempo necesario y borrarlo tras subirlo al banco. Cada descarga queda en la auditoría.
8. **Solo HTTPS** en producción. En local se usa HTTPS de LocalWP o, como mínimo, **datos ficticios**.
9. **Entorno local:** no bajar datos reales de salud al portátil. Se desarrolla contra un CRM de pruebas o con datos anonimizados. Si hay que conectar con el CRM real, se hace con el usuario de solo lectura y sin volcar nada a disco.
10. **Endpoints REST propios** (`/wp-json/asde/v1/...`), siempre con `permission_callback` que pase por `AccessPolicy` y con nonce.
11. **Exportaciones** (CSV de cambios, listados para campamentos): se registran en auditoría y solo están disponibles para los roles que las necesitan.

12. **Lista de espera (v0.32, doc 39):** datos de personas que **aún no son del grupo**, dados por ellas mismas o su familia con consentimiento expreso (casilla). Todo lo escrito y el historial de contactos van **cifrados** (`Crm\Crypto`) en `{prefix}asde_lista_espera`; en claro solo estado, sección, fechas y dos huellas HMAC. El email se confirma antes de entrar en la lista. Se borran: sin confirmar a los **7 días**; a los **12 meses** de la confirmación o de la última renovación (aviso 30 días antes); al darlas de alta en el CRM (en el momento); cuando lo pide la persona desde su enlace o lo hace Secretaría/Coordinación. Acceso de la persona sin cuenta: enlace personal (token HMAC, no guardado) **más su email**. El documento solo lo ven Secretaría y Coordinación. La auditoría guarda acciones e ids, nunca datos; el registro de emails, el destino enmascarado. Al desinstalar, la tabla se vacía.

## Documentación que habrá que actualizar (no técnica)

- Registro de actividades de tratamiento: nuevo tratamiento "Área privada web".
- Cláusula informativa y aviso legal del área privada.
- Contrato de encargado del tratamiento con el hosting, si no existe ya.
- Evaluación de impacto (EIPD): recomendable, porque se tratan datos de salud de menores.
