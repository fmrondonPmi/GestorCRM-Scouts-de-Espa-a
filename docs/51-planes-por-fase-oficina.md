# 51 · Planes detallados por fase: modo Oficina y alta de grupos por invitación

Desarrolla el doc 50 (decisiones y respuestas P-168…P-176, todas cerradas el 2026-10-18). **Solo plan, sin código.** Rama `Oficina-Grupo-Modos`. Las versiones son orientativas (la última es la 0.43): una por fase, 0.44 → 0.51.

## 0 · Puntos de enganche del código actual (lo que condiciona todas las fases)

| Pieza | Dónde | Lo que hace hoy | Consecuencia |
|---|---|---|---|
| Grupo de trabajo | `Domain\Grupo::actual()` | Grupo «padre» del usuario (`Grupos::de_usuario`); si no, el contexto guardado (`asde_grupo_contexto`); si no, el primero | Un usuario de la OF no tiene grupo padre: usa el contexto, como un administrador |
| Contexto al entrar | `Frontend\Views\AccesoView` (≈ l.133) | Solo si `manage_options` guarda el grupo elegido | Ampliar a quien tenga `asde_multigrupo` |
| Filtro de acceso por grupo | `Auth\AccessHooks::comprobar_acceso()` | El usuario debe ser del grupo elegido, salvo `manage_options` | Ampliar la excepción a `asde_multigrupo` |
| Ámbito | `Auth\Ambito` | `puede_acceder()` = administrador o algún rol vigente por **ficha** del CRM | Añadir `es_oficina()`: acceso **sin ficha** |
| Cliente del CRM | `Crm\ClientFactory::reader()` / `para_grupo()` | Credenciales **del grupo** (`Grupos::credenciales`) y exige `crm_id` verificado | Para el rol Oficina, la credencial de la OF **con el `crm_id` del grupo elegido**; el filtro por grupo de seguridad sigue siendo obligatorio |
| Alta/edición de grupos | `Domain\Grupos::guardar()` + `Admin\SettingsPage` | El administrador escribe nº, nombre, usuario y contraseña; `clave_enc` cifrada con `Crm\Crypto` | En modo Oficina deja de escribir/ver la credencial |
| Enlaces públicos | `Domain\ClaveDeGrupo` + `ListaEspera\Enlaces`; páginas en `Frontend\Pages` (`PUBLICAS`) | Clave por grupo; vista pública `InscripcionEsperaView` | Patrón para la página de alta de grupo (pero con enlace de un solo uso en vez de clave renovable) |
| Invitación de cuenta | `Usuarios\Invitacion` (HMAC, 7 días) + `EmailCuenta` | Token 192 bits; en la base solo el HMAC | Modelo para las invitaciones de grupo (14 días) |
| Alta de usuarios | `Usuarios\AltaService` (471 l.) | Crea la cuenta desde una ficha del CRM | Se reutiliza para el primer usuario (por DNI) |
| Menú | `Frontend\Menu::grupos()` | Cada entrada sale por capability | El rol Oficina solo tendrá Personas, Informes y Usuarios |
| Escrituras | `Frontend\Router::template_redirect()` | **Único punto** de POST (`handle_post`) | Bloqueo central de solo lectura |
| Informes | `Informes\Catalogo` (niveles `todos/salud/global/pagos`; **sin IBAN**) | Columnas por nivel y capability | La OF ve niveles `todos`, `salud`, `global`; no `pagos` |
| Auditoría | `Audit\Logger::log()` | Registro de acciones | Descargas de la OF, cambios de grupo, altas de grupo |
| SEPA / PDF | `Sepa\Config`, `Inventario\Pdf/ListaPdf`, `Eventos\Justificantes` | Acreedor por grupo en `asde_ap_sepa_<grupo>`; PDF propios | Tomar sede, CIF y denominación fiscal del grupo |
| Instalador | `Install\Installer` (cerrojo, `OPTION_VERSION`) | Crea tablas y roles al subir de versión | Tablas y roles nuevos aquí |

**Reglas del CLAUDE.md que se aplican a todas las fases:** lo nuevo nace apagado o con el valor de siempre (regla 5); un grupo no ve a otro (regla 3); textos con `asde_t()` y traducciones ca/eu/gl; ficheros CRLF/LF respetados (`rep.php`); Plugin Check 0 (los PDF/tablas nuevas con `plugin-check-local.sh`); suite entera «TODO OK» al cerrar cada fase; skills `version-area-privada`, `modulo-area-privada`, `mapa-area-privada`.

---

## Fase 1 · Tipo de instalación (v0.44)

**Objetivo.** Un ajuste «Tipo de instalación: Grupo / Oficina» que no cambia nada en las instalaciones existentes.

**Diseño.**
- `Domain\Organizacion::tipo_instalacion()` (`grupo` | `oficina`; sin guardar = `grupo`) y `es_oficina()`; se guarda con la organización (`asde_ap_organizacion`) y se olvida con `Organizacion::olvidar()`.
- Ajuste en `Admin\OrganizacionPage` (apartado nuevo «Tipo de instalación», con aviso: cambiarlo no borra datos; confirmación al pasar a Oficina). Solo `manage_options`. Constante opcional `ASDE_AP_MODO` en `wp-config.php` que manda sobre la opción (para fijarlo por entorno).
- Efecto del modo, **solo como interruptores** en esta fase (las pantallas llegan después): wp-admin muestra u oculta apartados («Grupos Scouts», «Módulos» por grupo siguen en ambos; «Altas pendientes» e «Invitaciones» solo en Oficina).
- `Frontend\Menu` y `Router`: una función `Organizacion::es_oficina()` consultable; todavía sin diferencias de menú.

**Ficheros.** `Domain/Organizacion.php`, `Admin/OrganizacionPage.php`, `Plugin.php` (registro condicional), `Install/Installer.php` (no hace falta migración), `readme.txt`, cabecera de versión.

**Pruebas (`tests/modo-instalacion.php`).** Por defecto `grupo`; guardar/leer/olvidar; la constante manda sobre la opción; un valor inválido cae en `grupo`; **la suite entera sin cambios en modo Grupo**.

**Docs y textos.** Doc 02 (roles), doc 08 (datos), doc 10 (entorno: cómo montar un Local de OF), textos nuevos con traducción.

**Hecho cuando.** Cambiar el modo en un Local no altera ninguna pantalla actual y la suite da «TODO OK».

---

## Fase 2 · Credencial y rol de la Oficina (v0.45)

**Objetivo.** El WordPress de la OF guarda su credencial del CRM y existe el rol que la usa.

**Diseño.**
- `Domain\Oficina` (nuevo, hermano de `Grupos`): opción `asde_ap_oficina` (sin autoload) con `usuario`, `clave_enc` (`Crm\Crypto`), estado de la última prueba y fecha. `guardar()`, `credenciales()`, `tiene_clave()`, `probar()`.
- `Crm\ClientFactory::para_oficina()`: `SinergiaV41Client` con la URL común y la credencial de la OF. `reader()`: si el usuario actual tiene `asde_multigrupo`, usa `para_oficina()` **pero solo si el grupo elegido está verificado (`crm_id`)**; mismo contrato de «un grupo cada vez».
- Prueba de conexión: login + comprobar que la credencial es **de solo lectura**. Cómo se mide es lo que hay que concretar con ASDE (P-51 del doc 29): intento de **escritura inocua y reversible** no es aceptable; la prueba es de lectura y la comprobación de solo lectura es un **aviso informativo** («confirmado por la OF»), no una prueba automática. Si el CRM expone el rol del usuario en el login, se muestra.
- `Install\Roles`: rol `asde_oficina` («Oficina») con `asde_access_area_privada`, `asde_multigrupo`, `asde_solo_lectura`, `asde_manage_users` (solo ver Personas/Usuarios), `asde_reports`, `asde_view_health`. **Sin** ninguna capability de edición, tesorería, tienda, inventario, eventos, lista de espera.
- Pantalla en `OrganizacionPage` (modo Oficina): «Credencial del CRM de la Oficina» (usuario, contraseña que se escribe y no se vuelve a mostrar, botón «Probar»). Auditoría: guardar/probar la credencial (sin la clave).
- Alta de cuentas de la OF: un administrador de WordPress crea el usuario con el rol «Oficina» (wp-admin → Usuarios, email y contraseña/invitación). Entran por «Acceso socios» como los técnicos del doc 29 (email como identificador).
- 2FA de Wordfence: **obligatoria** para este rol (ve datos de menores de todos los grupos); reutiliza `Integraciones\Wordfence` (v0.35.1 dejó la 2FA opcional: aquí se fuerza por rol).

**Ficheros.** `Domain/Oficina.php` (nuevo), `Crm/ClientFactory.php`, `Install/Roles.php`, `Admin/OrganizacionPage.php`, `Auth/AccessHooks.php` y `Frontend/Views/AccesoView.php` (excepción `asde_multigrupo` junto a `manage_options`, ver §0), `Auth/Ambito.php` (`es_oficina()`, acceso sin ficha), `Integraciones/Wordfence.php`.

**Seguridad.** La clave de la OF nunca va al HTML, ni a la auditoría, ni a logs; se cifra antes de guardar; `credenciales()` solo la llama `ClientFactory`. Prueba de que ninguna plantilla ni opción exportada la contiene.

**Pruebas (`tests/oficina.php`).** Cifrado y descifrado; `para_oficina()` sin credencial = null; rol con exactamente las capabilities previstas y ninguna de edición; el rol Oficina puede elegir grupo en el acceso y un socio normal no; `reader()` de un usuario Oficina usa la credencial de la OF con el `crm_id` del grupo elegido y **falla cerrado** si el grupo no está verificado; 2FA obligatoria.

**Hecho cuando.** Un usuario Oficina entra, elige un grupo y `ClientFactory::reader()` lee con la credencial de la OF filtrando por ese grupo (con el CRM simulado).

---

## Fase 3 · Solo lectura y selector de grupo (v0.46)

**Objetivo.** Garantizar en la web que el rol Oficina no puede escribir nada y puede cambiar de grupo sin salir. Es la fase más delicada.

**Diseño.**
- **Bloqueo central** en `Router::template_redirect()` justo antes de `handle_post()` (y en los AJAX: `Socios\TareaNumeracion` y `Frontend\Tabla::ajax_por_pagina`): si `current_user_can('asde_solo_lectura')`, todo POST/AJAX se rechaza con aviso «Tu cuenta es de solo lectura» y vuelta a la página, **salvo lista blanca**: idioma, «Mostrar nº territorial», tamaño de página, seguridad (2FA), cambio de grupo y cerrar sesión. Las descargas (GET con `formato`) pasan por un control propio (fase 4).
- Lo mismo en capas internas, por si algo se llamara sin el router: `Crm\Escritura` y `Crm\EscritorCrm` **se niegan a escribir** si el usuario actual es solo lectura (defensa en profundidad, además de que la credencial del CRM debe ser de solo lectura).
- `Fichas\PermisosFicha::accion()`: siempre VER para solo lectura (motivo «cuenta de solo lectura»).
- **Plantillas:** una comprobación común (`Ambito::actual()->solo_lectura()`) en una función de ayuda; esconder botones de acción (guardar, aprobar, dar acceso, validar, corregir en el CRM, eliminar…). Es lo más laborioso pero solo aspecto: la seguridad la dan las capas anteriores. Franja fija «Solo lectura · Oficina · Grupo X».
- **Selector de grupo**: en el menú, junto al nombre, desplegable con **los grupos aprobados**; cambiar guarda `asde_grupo_contexto` (nonce), `Ambito::olvidar()`, y recarga la misma página. `Grupo::actual()` valida el contexto contra la lista de grupos **aprobados** (si no está, el primero): es la frontera de seguridad.
- Auditoría de cada cambio de grupo y de cada ficha consultada por la OF (nuevo: hoy las fichas abiertas no se registran).

**Ficheros.** `Frontend/Router.php`, `Frontend/Tabla.php`, `Socios/TareaNumeracion.php`, `Crm/Escritura.php`, `Crm/EscritorCrm.php`, `Fichas/PermisosFicha.php`, `Auth/Ambito.php`, `Domain/Grupo.php`, `Frontend/Menu.php`, `templates/comun/*` y las plantillas con botones de acción, `Audit/Logger.php` (acciones nuevas).

**Pruebas (`tests/solo-lectura.php`).** Recorrer **todas** las `asde_action` de las vistas (`Router::VIEWS`) enviando un POST como usuario Oficina y comprobar que **ninguna** llega a `handle_post`; AJAX bloqueados; escritura en el CRM simulado imposible; lista blanca funciona; un contexto manipulado o un grupo no aprobado cae en el primero permitido; sin botones de acción en el HTML volcado de cada pantalla visible para la OF.

**Hecho cuando.** El recorrido automático de acciones da 0 pasadas y el HTML de las pantallas de la OF no contiene formularios de acción.

---

## Fase 4 · Pantallas de la Oficina (v0.47)

**Objetivo.** Limitar lo que ve la OF a Usuarios, Personas e Informes con datos de asociados.

**Diseño.**
- **Menú**: para `asde_oficina` solo «Personas», «Informes» y «Usuarios» (`Menu::grupos()`; se oculta «Mi espacio», eventos, tienda, inventario, tesorería, lista de espera, cambios, solicitudes). Inicio de la OF con tarjetas solo de esas tres secciones. `Pages::SIN_AMBITO` y la comprobación de acceso se adaptan para dejar entrar sin ficha.
- **Informes** (P-172/P-173): niveles `todos`, `global` y `salud` visibles; **no** `pagos` (cuotas son datos de Tesorería, no del CRM; supuesto a confirmar al probar); sin IBAN (el catálogo ya no lo tiene). Descarga CSV/Excel permitida; cada descarga se **audita** (usuario, grupo, informe, filtros). No puede guardar ni borrar informes (solo lectura).
- **Personas y fichas**: lectura completa de la ficha incluida salud (P-173), sin botones. **Usuarios**: lista y fichas de cuentas del grupo elegido, sin acciones.
- Ocultar en la ficha todo bloque de datos bancarios (la cuenta SEPA de `Sepa\CuentaCrm`).
- Un grupo con el módulo apagado se ve igual: la OF no depende de `Modulos`.

**Ficheros.** `Frontend/Menu.php`, `Frontend/Pages.php`, `Frontend/Views/AreaPrivadaView.php` (inicio), `Informes/Catalogo.php` y vistas de Informes, plantillas de la ficha, `Audit/Logger.php`.

**Pruebas (`tests/oficina-pantallas.php`).** Un usuario Oficina no puede abrir (redirige o 403) tesorería, eventos, tienda, inventario, lista de espera, cambios, solicitudes; ve Personas/Informes/Usuarios; el informe de pagos no aparece; no hay IBAN en ninguna salida; la descarga queda auditada; salud visible.

**Hecho cuando.** Recorriendo todas las páginas con el usuario Oficina, solo las tres previstas responden.

---

## Fase 5 · Invitación de grupo y formulario público (v0.48)

**Objetivo.** El administrador genera un enlace; el grupo rellena el formulario y verifica su credencial en la misma pantalla.

**Datos.** Tabla nueva `{prefix}asde_grupo_invitaciones` (en `Installer`): `id`, `hash` (HMAC del token, 192 bits), `email_destino` (opcional), `estado` (`pendiente`, `usada`, `caducada`, `anulada`), `creada`, `expira` (14 días), `creada_por`, `usada_en`. **Tabla nueva `{prefix}asde_grupo_solicitudes`** para las altas recibidas (ver fase 6; se crea ya aquí para no tener dos migraciones): campos del formulario; el usuario y la contraseña del CRM **cifrados** (`Crypto`), DNI y nombre del primer usuario cifrados, `estado` (`pendiente`, `aprobada`, `rechazada`), `invitacion_id`, fechas.

**Pantalla del administrador** (Configuración del plugin, modo Oficina): botón «Invitar a un grupo» → genera el enlace; dos acciones: «Copiar enlace» (campo de solo lectura + botón) y «Enviar por correo» (pide el email, usa `RegistroEmails::enviar()`, plantilla con aviso de caducidad). Lista de invitaciones con estado y «Anular». El token **solo se muestra una vez** (al generarlo); en la base solo el hash.

**Página pública** `alta_grupo` en `Frontend\Pages` (`PUBLICAS`) con vista `AltaGrupoView` y shortcode propio:
- Acceso con `?t=<token>`; token inválido, usado, anulado o caducado = página neutra (mismo mensaje, sin revelar el motivo), con límite de intentos por IP (`Auth\Limite`/`Intentos`).
- Campos: nº de grupo (3 cifras, no repetido con un grupo existente ni con otra solicitud pendiente), nombre, dirección de la sede, email, **CIF** y **denominación fiscal** (opcionales), usuario y contraseña del CRM, **DNI y nombre del primer usuario** y rol (Secretaría o Coordinación). Nada de esto se guarda en WordPress hasta enviar.
- **Verificación en la misma pantalla (P-170):** botón «Comprobar acceso» (POST) que valida con `Grupos::probar`-equivalente (login con esos datos y búsqueda del grupo de seguridad del nº, `tipoasociacion_c = GS`); mientras no sea correcto, «Enviar» está desactivado y el servidor lo rechaza igualmente. La contraseña **no se devuelve** en el HTML tras comprobar (se vuelve a pedir o se mantiene en un campo de sesión cifrado de corta vida; decidir al implementar, con prueba de que no aparece en el HTML).
- Al enviar con éxito: se consume el token (transacción: marcar `usada` y crear la solicitud de forma atómica, para que dos envíos no creen dos solicitudes), se cifran los secretos y se muestra «Recibido; la Oficina lo revisará».
- Si el enlace se usa y falla la verificación, el token **no** se gasta.

**Seguridad.** HTTPS obligatorio para la página (si no `is_ssl()`, se rechaza); `nocache`; sin eco de contraseñas; `noindex`; límite de intentos; nonce propio; la comprobación contra el CRM tiene límite por IP y por token (evita usar el formulario para probar credenciales ajenas).

**Ficheros.** `Install/Installer.php`, `Domain/GrupoInvitaciones.php`, `Domain/GrupoSolicitudes.php` (nuevos), `Frontend/Pages.php`, `Frontend/Router.php` (`VIEWS`), `Frontend/Views/AltaGrupoView.php`, `templates/alta-grupo/*`, `Admin/GruposInvitacionesPage.php`, `Usuarios/RegistroEmails`/email nuevo.

**Pruebas (`tests/alta-grupo-invitacion.php`).** Token válido/usado/caducado/anulado/falso; reintento tras fallo de credencial no gasta el token; dos envíos simultáneos = una solicitud; nº de grupo repetido rechazado; contraseña cifrada en la tabla y ausente de HTML, auditoría y opciones; límites de intentos; Plugin Check con las tablas nuevas (`plugin-check-local.sh`).

**Hecho cuando.** Con el CRM simulado, el recorrido completo (generar → abrir → comprobar → enviar) deja una solicitud pendiente y el token queda gastado.

---

## Fase 6 · Altas pendientes y aprobación (v0.49)

**Objetivo.** El administrador revisa y aprueba o rechaza; al aprobar nace el grupo.

**Diseño.**
- Página wp-admin «Grupos Scouts → Altas pendientes»: lista con estado; vista de detalle con lo escrito (nº, nombre, sede, email, CIF, denominación fiscal, DNI y nombre del primer usuario, rol elegido, **resultado de la última comprobación del CRM**); **la contraseña y el usuario del CRM no se muestran** («Credencial guardada y verificada el …»).
- **Aprobar:** `Grupos` pasa a poder crear el grupo con credencial **ya cifrada** (nuevo `Grupos::alta_desde_solicitud()`, sin pasar la clave en claro por el formulario del administrador). Añade al grupo los campos nuevos: `sede`, `email`, `cif`, `denominacion_fiscal`. Reverifica la conexión; si falla en ese momento, no se aprueba y se avisa. Los **módulos** del grupo nacen apagados (`Modulos::POR_DEFECTO_APAGADOS`). Registra en auditoría.
- **Rechazar:** con aviso por email genérico (sin motivo, como `EmailRechazo`) y **borrado de la credencial guardada** (solo se conserva la fila mínima: nº, nombre, estado, fecha).
- **Grupos ya existentes (P-175):** conservan su credencial; en modo Oficina el administrador deja de ver/editar la credencial (`SettingsPage` oculta el campo y ofrece «Enviar enlace para cambiar la credencial / completar datos»). Ese enlace es una invitación de tipo `actualizar` que apunta a un grupo existente y que solo permite cambiar credencial y completar sede/CIF/denominación fiscal.
- **Uso de sede, CIF y denominación fiscal (P-174):** se añaden a `Domain\Grupos`, y se usan en cabeceras de PDF (`Inventario\Pdf`, `ListaPdf`, `Eventos\Justificantes`) y como valores por defecto del acreedor en `Sepa\Config` (nombre = denominación fiscal; el CIF alimenta la parte nacional del identificador del acreedor, pero el ICS sigue siendo dato aparte que da el banco; la dirección = sede). Esto es un cambio visible: nace con el valor de siempre si los campos están vacíos.

**Ficheros.** `Domain/Grupos.php`, `Admin/SettingsPage.php`, `Admin/GruposAltasPage.php` (nuevo), `Sepa/Config.php`, `Inventario/Pdf.php`, `Inventario/ListaPdf.php`, `Eventos/Justificantes.php`, emails de aprobación y rechazo.

**Pruebas (`tests/alta-grupo-aprobacion.php`).** Aprobar crea el grupo con `clave_enc` descifrable por el cliente y nunca visible; rechazar borra la credencial; la página de detalle no contiene la clave ni el usuario; grupos existentes no se tocan; PDF y SEPA con y sin los campos nuevos; aprobación con credencial que ya no funciona se rechaza.

**Hecho cuando.** Una solicitud aprobada aparece como grupo verificado con sus datos fiscales y ningún lugar del código ni de la interfaz expone la credencial.

---

## Fase 7 · Primer usuario de acceso (v0.50)

**Objetivo.** Al aprobar, el correo del grupo recibe la invitación del primer usuario (Secretaría o Coordinación).

**Diseño.**
- Tras crear el grupo, con la credencial recién guardada se busca la **ficha por DNI** en el CRM del grupo (`PersonaRepository`/`Crm\Duplicados`): debe existir con **membresía activa** y estar en el grupo.
  - Si existe: se crea la cuenta con el rol elegido mediante `AltaService` (la misma ruta de la solicitud de acceso; nº de usuario según `Identidad`), y se envía `Invitacion::ALTA` **al email del grupo** indicado en el formulario (no al de la ficha; así lo pidió el usuario), con mensaje de bienvenida y caducidad de 7 días.
  - Si no existe o no está activa: el grupo queda aprobado pero **sin usuario**; el administrador ve el aviso «Falta el primer usuario: la ficha con ese DNI no existe o no está activa» y puede reintentar tras corregir el CRM; mientras tanto el grupo no tiene a nadie que entre. Se propone además un botón «Reenviar invitación».
- La invitación se manda una sola vez por solicitud (estado `invitado` en la solicitud), con reintento manual.
- El primer usuario, al activar, puede dar de alta al resto con los flujos de siempre (Solicitudes, Dar acceso).

**Ficheros.** `Usuarios/AltaService.php` (punto de entrada nuevo para «alta sin solicitud previa» si no existe), `Usuarios/Invitacion.php`, `Usuarios/EmailCuenta.php`, `Admin/GruposAltasPage.php`.

**Pruebas (`tests/alta-grupo-primer-usuario.php`).** Ficha existente: cuenta con el rol correcto e invitación al correo del grupo; ficha ausente o de baja: grupo sin usuario y aviso; reenvío; no se duplica la cuenta; el rol solo puede ser Secretaría o Coordinación.

**Hecho cuando.** Con el CRM simulado, aprobar una solicitud con un DNI válido deja una cuenta activable y un correo registrado en `RegistroEmails`.

---

## Fase 8 · Cierre: textos, manuales, docs y revisión (v0.51)

- **Textos** con `asde_t()` en castellano y traducciones ca/eu/gl (agente Sonnet, `merge-tr.php`); `idiomas-catalogos.php`.
- **Manuales** (skill `manuales-area-privada`): apartado en el de administración (invitar a un grupo, aprobar, credencial), nuevo manual **«Oficina»** (qué ve, solo lectura, selector de grupo, descargas auditadas) y la ayuda por página del rol; capturas con datos inventados.
- **Docs:** 00 (índice), 02 (roles), 08 (datos: tablas y opciones nuevas), 09 (preguntas), 10 (entorno: montar un WordPress en modo Oficina), 40 (mapa, regenerar inventario con `mapa-area-privada`), este 51 al día.
- **Calidad:** `/code-review` de la rama, Plugin Check 0 (también `plugin-check-local.sh` por las tablas), `revision-wporg.sh`, suite completa, zip `asde-area-privada-0.51.0` sin `tests/`.
- **Desinstalación:** `uninstall.php` borra las opciones y tablas nuevas.
- **Después, con el usuario:** pedir a ASDE el usuario del CRM de solo lectura para la OF y probar con el CRM real (lecturas del CRM real las lanza el usuario, ver CLAUDE.md).

## Orden y dependencias

1 → 2 → 3 → 4 (Oficina de solo lectura utilizable) **y** 1 → 5 → 6 → 7 (alta de grupos). Las dos ramas de trabajo son independientes entre sí después de la fase 1, pero **comparten `Domain\Grupos` y `Admin\SettingsPage`** (fases 2 y 6), así que se hacen **una detrás de otra** (no en paralelo). Recomendado: 1, 2, 3, 4 primero (la OF puede ver ya los grupos que existen) y luego 5, 6, 7, 8.

## Cosas a confirmar durante la implementación (no bloquean empezar)

- **Cómo comprobar que la credencial de la OF es de solo lectura** (fase 2): depende de lo que exponga el CRM; si no se puede, es un dato que afirma el administrador.
- **Informe de pagos** para la OF: se deja fuera (es dato de Tesorería, no del CRM); si la OF lo quiere, otra petición.
- **Contraseña tras «Comprobar acceso»** (fase 5): reescribirla al enviar, o guardarla cifrada de forma temporal ligada al token. Se decide con una prueba de que no aparece en el HTML.
- **Qué hace el ICS** (fase 6): el CIF del grupo no basta para el identificador del acreedor; solo se rellena el nombre y la dirección.

---

## Avance

- ✅ **Fase 1 (v0.44 → renumerada v0.50.0 al fusionarse con la línea de la tienda):** tipo de instalación Grupo/Oficina.
- ✅ **Fase 2 (2026-10-21, v0.50.0, rama `Oficina-Fase2`):** credencial y rol de la Oficina.
  - `Domain\Oficina` (opción `asde_ap_oficina`, sin autoload, contraseña cifrada con `Crypto`; `datos()` nunca la devuelve; `probar()` entra y cuenta los Grupos Scouts que ve, con el aviso de que la web no puede comprobar que la credencial sea de solo lectura).
  - `ClientFactory::para_oficina()` y `reader()`: solo en una web de oficina, con la capability `asde_multigrupo` y con el grupo elegido verificado (`crm_id`); si no, falla cerrado.
  - Rol `asde_oficina` («Oficina») con `asde_multigrupo`, `asde_solo_lectura`, `asde_manage_users`, `asde_reports` y `asde_view_health`; ninguna de edición, tesorería, tienda, inventario ni eventos. No se ofrece al dar de alta personas en un grupo.
  - `Ambito::es_oficina()`: entra sin ficha, solo en una web de oficina. El acceso y la pantalla de entrada tratan `asde_multigrupo` como a un administrador para elegir grupo.
  - Wordfence: 2FA **obligatoria** para este rol (`Wordfence::VERSION` 5).
  - Pantalla «Credencial del CRM de la oficina» en wp-admin → Organización federada (solo en modo Oficina): guardar, probar y borrar; la contraseña no se vuelve a mostrar.
  - Pruebas: `tests/oficina.php` (38). Pendiente de la fase 3: el bloqueo de escritura que usa `asde_solo_lectura`.
- ✅ **Fase 3 (2026-10-21, v0.50.0):** solo lectura y selector de grupo.
  - `Auth\SoloLectura` (capability `asde_solo_lectura`, lista blanca `oficina_cambiar_grupo`). `Router` rechaza, antes de `handle_post()`, todo envío de formulario de una cuenta de solo lectura (aviso y vuelta a la página). `Crm\Escritura::disponible()` y `puede_crear()` se niegan a escribir (cubre guardar, crear, cambiar membresías y relacionar), incluso con el CRM simulado.
  - Selector de grupo en el menú (solo oficina): `Domain\Oficina::cambiar_grupo()` valida capability, tipo de instalación y que el grupo exista; guarda `asde_grupo_contexto`, olvida el ámbito y audita (`oficina_cambio_grupo`). Franja «Oficina · solo lectura».
  - Los AJAX de numeración ya exigen su capability (la oficina no la tiene); el de tamaño de página es una preferencia propia y sigue permitido.
  - Pruebas: `tests/solo-lectura.php` (17), que recorre las 127 acciones de formulario de las plantillas.
  - **Se queda para la fase 4:** esconder en cada pantalla los botones de acción (hoy ya no salen casi todos porque el rol no tiene las capabilities), auditar las fichas consultadas por la oficina y el control propio de descargas.
- ✅ **Fase 4 (2026-10-21, v0.50.0):** pantallas de la Oficina.
  - Solo abre `Oficina::PAGINAS` (Inicio, Personas, ficha, Informes, Usuarios, Seguridad); el Router redirige al inicio con aviso en cualquier otra, aunque el rol tuviera la capability. El menú ya solo trae Personas, Informes y Usuarios (se rige por capabilities).
  - Visión global del grupo elegido (`Ambito::vision_global()` incluye a la oficina): lectura completa de la ficha, salud incluida. `PermisosFicha::accion()` siempre es VER; las pantallas de membresía están cerradas. Los datos bancarios de la ficha no se ven (solo Tesorería y la familia).
  - Informes: niveles `todos`, `global` y `salud`; nunca `pagos`; sin IBAN. Las descargas ya se auditaban; cada **ficha consultada** se audita ahora (`oficina_ficha_consultada`).
  - **Sin botones de acción:** `SoloLectura::limpiar()` quita de toda pantalla los formularios de envío y los enlaces de alta, acceso y membresía en el envoltorio común (`View::layout`), sin tocar plantillas; la seguridad real sigue siendo el Router, `Escritura` y el rol.
  - Pruebas: `tests/oficina-pantallas.php` (33). Pendiente: probar con un CRM simulado cada pantalla completa como oficina (se hará en el cierre, fase 8) y la ayuda del manual.
- ✅ **Fase 5 (2026-10-21, v0.50.0):** invitación de grupo y formulario público (solo en la web de la oficina).
  - Tablas nuevas `asde_grupo_invitaciones` (hash HMAC del token de 192 bits, 14 días, estados pendiente/usada/anulada y «caducada» calculada) y `asde_grupo_solicitudes` (nº, nombre, sede, email, CIF, denominación fiscal, rol, `crm_id`; usuario y contraseña del CRM, DNI y nombre de la primera persona **cifrados**). Se crean al subir de versión.
  - wp-admin → Grupos Scouts → **Invitar a un grupo** (`GruposInvitacionesPage`): crea la invitación, enseña el enlace **una sola vez**, lo puede enviar por email (`GrupoInvitaciones::enviar_por_email`, tipo nuevo «Invitación de grupo», destino enmascarado en el registro) y deja anular las pendientes.
  - Página pública `alta_grupo` (`AltaGrupoView`): token malo, usado, anulado, caducado o en una web que no es de oficina = **la misma página neutra**; límite de intentos por dispositivo; HTTPS obligatorio en producción; `noindex`; campo trampa, sello de tiempo y nonce.
  - **Comprobar acceso** en la misma pantalla (`VerificacionGrupo`): entra en el CRM con lo escrito y busca el Grupo Scout con ese nº. Si sale bien, la credencial se guarda **cifrada y 30 minutos** ligada a la invitación; **no vuelve nunca en el HTML**, y el envío usa la guardada, no lo que llegue del navegador. Una comprobación fallida no gasta la invitación; límite de 8 comprobaciones/hora por dispositivo y por invitación.
  - **Enviar**: gasta la invitación con una sola sentencia atómica y crea la solicitud (si no se pudo guardar, la invitación se devuelve). Rechaza un nº de grupo que ya existe o que tiene una solicitud pendiente.
  - Desinstalar vacía los secretos de las solicitudes y la credencial de la oficina.
  - Pruebas: `tests/alta-grupo-invitacion.php` (36). 39 textos nuevos con ca/eu/gl.
- ✅ **Fase 6 (2026-10-21/22, v0.50.0):** altas pendientes y aprobación.
  - wp-admin → Grupos Scouts → **Altas de grupos** (`GruposAltasPage`, con el número de pendientes): lista y detalle con lo escrito; **nunca** se muestran el usuario ni la contraseña del CRM, ni el DNI ni el nombre de la primera persona («credencial guardada y comprobada el …»).
  - **Aprobar** (`Domain\GrupoAltas::aprobar`): `Grupos::alta_desde_solicitud()` crea el grupo con la credencial **ya cifrada** (no pasa en claro por ningún formulario) y la **comprueba otra vez**; si ya no funciona, no se crea ni se aprueba. La solicitud suelta la credencial; DNI y nombre se conservan hasta crear el primer acceso (fase 7). Aviso por email al grupo sin credencial. Los módulos del grupo siguen las reglas de siempre (`Modulos::POR_DEFECTO_APAGADOS`) y se ajustan en Grupos Scouts → Módulos.
  - **Rechazar:** borra usuario, contraseña, DNI y nombre; aviso genérico por email, sin motivo; el nº queda libre.
  - Datos nuevos del grupo (`sede`, `cif`, `denominacion`, vacíos = como siempre): `Grupos::membrete()` los pone bajo el nombre en la lista de compra en PDF, y `Sepa\Config::de()` los usa como acreedor y calle por defecto mientras no se escriban otros.
  - Tipo de email nuevo «Alta de grupo». Pruebas: `tests/alta-grupo-aprobacion.php` (22). 8 textos nuevos con ca/eu/gl.
  - **Pendiente (P-175):** el enlace para que un grupo *ya existente* cambie su credencial o complete sede/CIF/denominación fiscal (invitación de tipo `actualizar`) y, con él, esconder la credencial en wp-admin → Grupos en modo Oficina. Hasta entonces el administrador sigue pudiendo editarla ahí.
