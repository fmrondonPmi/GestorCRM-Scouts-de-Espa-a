# 50 · Plan: instalación «de Grupo» e instalación «de Oficina», y alta de grupos por invitación

**Petición del usuario (2026-10-18):** desdoblar el plugin porque se puede instalar en un WordPress que gestiona un grupo o en uno que gestiona la oficina federal (OF).

**Estado:** plan, sin código. Rama `Oficina-Grupo-Modos` (desde `origin/main`). Preguntas P-168…P-175 respondidas el 2026-10-18 (§5); P-176 (acuerdo de tratamiento) también: ya está implícito.

**Decisiones ya tomadas (2026-10-18):**

1. **Un solo plugin, dos modos** (ajuste «Tipo de instalación»: *Grupo* u *Oficina*). El núcleo (cliente del CRM, cifrado, roles, ventanas, idiomas) es el mismo; cada modo activa sus menús y pantallas.
2. El WordPress de la OF **aloja también el área privada completa de los grupos** (multigrupo, como el actual de Baleares) **y además** la vista de oficina de solo lectura.
3. El grupo escribe **él mismo** usuario y contraseña de su acceso al CRM en el formulario de alta; **el administrador del WordPress no los ve ni los puede cambiar**.

---

## 1 · Lo que se pide, ordenado

### 1.1 Modo «Oficina»

- **Credencial de la OF** en *Organización federada* (a mano, solo un administrador de WordPress): usuario y contraseña de un usuario del CRM **de solo lectura**. Hoy la credencial es siempre la de un grupo (`Domain\Grupos::credenciales()`); la de la OF es una **entrada nueva** (`Domain\Oficina`), cifrada con `Crm\Crypto` igual que las de los grupos.
- **Qué ve** el usuario de la OF (y un técnico/a que entre con él; ver el doc 29, que se reaprovecha):
  - **Usuarios** (las cuentas del área),
  - **Personas** (asociados, en solo lectura),
  - **Informes**, **solo** los que incluyan datos de asociados (los de pagos, banco, SEPA, inventario, eventos… no).
  - Nada más: ni Tesorería, ni SEPA, ni uniformes, ni salud, ni escrituras.
- **Solo lectura de verdad**, por capas (doc 29 §2.3): usuario del CRM de solo lectura (capa 1), bloqueo central de todo POST/AJAX en `Router::template_redirect()` con lista blanca (capa 2) y plantillas sin botones (capa 3). Rol nuevo `asde_oficina` con las capabilities `asde_solo_lectura` y `asde_multigrupo`.
- Qué grupos mira: **todos los dados de alta y aprobados** (con el usuario de la OF, que ve todo el CRM, el filtro por grupo de seguridad sigue aplicándose por grupo elegido: *un grupo cada vez*, con selector, como en el doc 29 §2.2).

### 1.2 Alta de un grupo por invitación (en *Configuración del plugin*, modo Oficina)

1. Botón **«Invitar a un grupo»** → genera un **enlace de un solo uso** (192 bits; en la base solo su HMAC, como `Usuarios\Invitacion`; caduca a los 14 días, P-169) y da dos opciones: **copiarlo** o **enviarlo por correo** (se pide el email; el envío pasa por `RegistroEmails::enviar()`).
2. El grupo abre el enlace: **página pública** (como `inscripcion_espera`, patrón de `ListaEspera\Enlaces` + `Domain\ClaveDeGrupo`) con el formulario:
   - nº de grupo, nombre del grupo, dirección de la sede, email;
   - **CIF** y **denominación fiscal** (opcionales);
   - **usuario y contraseña de acceso al CRM**.
   Al guardar se **prueba la conexión** en el momento (`Grupos::probar`) para avisar si la credencial no vale, pero la solicitud se guarda igualmente (P-170). El enlace se consume.
3. La solicitud queda **«pendiente»** en una página de wp-admin (*Grupos Scouts → Altas pendientes*): el administrador ve lo escrito (la contraseña **nunca**; solo «guardada» y el resultado de la prueba), puede **aprobar** o **rechazar** (con aviso por email genérico, como `EmailRechazo`).
4. **Al aprobar:** el grupo pasa a `asde_ap_grupos` (con `clave_enc` ya cifrada desde el principio: la contraseña se cifra al recibirla y no se guarda en claro ni un instante) y se manda al **mismo correo** la **invitación del primer usuario de acceso**, con rol **Secretaría o Coordinación** (P-171: quién decide el rol y cómo se identifica a esa persona).

### 1.3 Qué cambia en lo que ya existe

| Pieza | Hoy | Cambio |
|---|---|---|
| `Domain\Grupos` | Alta a mano por un administrador, que escribe la credencial | El alta a mano **se mantiene** en modo Grupo; en modo Oficina el administrador **ya no puede escribir ni ver** la credencial de un grupo (solo «Reenviar enlace para cambiarla») |
| `Admin\SettingsPage` | Formulario completo de grupo | En modo Oficina: lista de grupos sin credencial + invitaciones + altas pendientes |
| `Domain\Organizacion` | Datos de la federación | + `tipo_instalacion` (`grupo` por defecto en las instalaciones anteriores: **no se rompe nada al actualizar**, regla 5 del CLAUDE.md) |
| `Install\Roles` | Roles de grupo | + `asde_oficina` (solo lectura) |
| `Frontend\Menu` / `Router` | Menús por rol | En modo Oficina el menú de la OF; el bloqueo de escrituras (doc 29 §2.3) |
| Datos del grupo | nº, nombre, usuario, clave | + dirección de la sede, email de contacto, CIF y denominación fiscal (opcionales) |

---

## 2 · Plan de trabajo (fases, una versión por fase)

| Fase | Qué | Pruebas |
|---|---|---|
| 1 | `Organizacion::tipo_instalacion()`, ajuste en wp-admin, menús que dependen del modo; todo igual en modo Grupo | la suite entera sin cambios en modo Grupo; modo Oficina oculta lo de grupo |
| 2 | `Domain\Oficina` (credencial de la OF cifrada, prueba de conexión), cliente del CRM con esa credencial, rol `asde_oficina` | `tests/oficina.php` nuevo |
| 3 | Solo lectura: bloqueo central de POST/AJAX con lista blanca, plantillas sin botones, franja «Solo lectura · Grupo X», selector de grupo (doc 29 pasos 1-4) | recorrer **todas** las `asde_action` de las vistas y comprobar que ninguna pasa |
| 4 | Pantallas de la OF: Usuarios, Personas e Informes filtrados a los que llevan datos de asociados | no aparece ninguna otra pantalla ni informe de pagos/banco/salud |
| 5 | Invitación de grupo: tabla `asde_grupo_invitaciones` (hash, caducidad, estado), botón copiar/enviar, página pública de alta (limitada por IP, sin enumerar) | enlace usado/caducado/falso; contraseña cifrada y ausente en el HTML |
| 6 | Altas pendientes en wp-admin: aprobar/rechazar, email de rechazo, paso a `asde_ap_grupos`, campos nuevos del grupo | la contraseña nunca sale en pantalla ni en el registro de auditoría |
| 7 | Invitación del primer usuario (Secretaría/Coordinación) al aprobar | cuenta creada, rol correcto, email al correo del grupo |
| 8 | Textos en los cuatro idiomas, manual de administración + manual nuevo «Oficina», docs (00, 02, 08, 09), mapa (doc 40) | `idiomas-catalogos.php`, Plugin Check 0 |

## 3 · Riesgos y puntos delicados

- **Credenciales de grupos fuera de la vista del administrador:** hoy `Grupos::credenciales()` las descifra para cualquiera que llame; en modo Oficina solo debe usarlas el cliente del CRM. Una prueba comprueba que ninguna plantilla, opción exportada ni auditoría las contiene.
- **Página pública que acepta una contraseña de CRM:** es la pieza más sensible. Solo por HTTPS, enlace de un solo uso, límite de intentos, sin eco de lo escrito y cifrado antes de guardar. Si la solicitud se rechaza, se **borra** la credencial.
- **Usuario del CRM de solo lectura de la OF:** hay que pedirlo a ASDE; si no existe, la capa 1 de seguridad no se puede dar y la solo lectura dependería solo de la web (doc 29, P-51).
- **Un grupo no ve a otro:** sigue valiendo (regla 3 del CLAUDE.md); la OF ve *un grupo cada vez*, nunca una mezcla.
- **Dos WordPress distintos** (uno de grupo y otro de OF) comparten el mismo código y los mismos zips: el modo se guarda en base de datos, no en el paquete.

## 4 · Preguntas abiertas (a doc 09)

- **P-168 — Nombre del rol/pantallas:** ¿«Oficina» u otro nombre (p. ej. «Federación»)? ¿Un único usuario de la OF o varias cuentas de WordPress que usan esa credencial?
- **P-169 — Caducidad del enlace de invitación:** propuesta 14 días y de un solo uso.
- **P-170 — Conexión fallida:** ¿se guarda la solicitud aunque la prueba de conexión falle (propuesta) o se impide enviar el formulario?
- **P-171 — Primer usuario:** ¿cómo se identifica a esa persona? Hoy el acceso va ligado a una ficha del CRM (nº de socio). Propuesta: el formulario pide también el **DNI y el nombre** de la persona de Secretaría/Coordinación, y al aprobar se busca su ficha; si no existe, se avisa al administrador. ¿Rol por defecto Secretaría y el grupo elige Coordinación?
- **P-172 — Qué informes llevan «datos de asociados»:** propuesta: los de personas/fichas/secciones/contacto; fuera pagos, banco, SEPA, eventos, inventario. ¿Sin columnas de salud? ¿Descarga en CSV/Excel permitida?
- **P-173 — Salud y alimentación** y **datos bancarios:** no visibles para la OF (propuesta).
- **P-174 — Datos de la sede, CIF y denominación fiscal:** ¿para qué se usan después (cabeceras de PDF, SEPA…) o solo se guardan?
- **P-175 — Grupo ya existente:** los grupos que ya están dados de alta a mano (684…) ¿se quedan con su credencial actual o se les pide que la vuelvan a introducir con un enlace?
- **P-176 — Base legal:** el acceso de la OF a datos de socios de los grupos necesita un acuerdo de tratamiento (doc 29, P-49); no es decisión técnica.

## 5 · Respuestas del usuario (2026-10-18) y cambios en el plan

- **P-168:** modo y rol **«Oficina»**, **varias cuentas** con la misma credencial de solo lectura.
- **P-169:** enlace de **14 días**, un solo uso.
- **P-170:** el formulario **verifica la credencial del CRM en la misma pantalla** y **no deja enviar hasta que sea correcta** (cambia la fase 5: botón «Comprobar», sin guardar nada hasta que pase; el enlace no se gasta mientras falle).
- **P-171:** se pide **DNI y nombre** del primer usuario; el grupo elige Secretaría o Coordinación.
- **P-172:** **todos los informes con datos de asociados**; descarga CSV/Excel permitida y **auditada**.
- **P-173:** la OF **ve salud**; **no ve IBAN** (columnas bancarias ocultas aunque el informe las tenga). 
- **P-174:** sede, CIF y denominación fiscal **se usan ya** en cabeceras de PDF y como acreedor en SEPA (añadir a la fase 6; revisar `Sepa` y los PDF de inventario y eventos).
- **P-175:** los grupos actuales **conservan su credencial**; cambiarla solo con enlace del grupo.
- **P-176:** el acuerdo de tratamiento **ya está implícito**; no hace falta nada más.
