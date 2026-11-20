# 67 · Plan y base de conocimiento: entrar con Google (SSO)

**Estado: solo plan, sin código (2026-11-20).** Petición del usuario: «preparar el conocimiento para hacer login en la aplicación usando el SSO de Google». Este doc reúne cómo funciona Google, cómo encaja en el acceso actual (docs 12, 15, 27, 56, 59) y qué hay que decidir antes de programar. Los supuestos están marcados **[S]** y las decisiones pendientes **GS-n**.

## 1 · Qué es y qué no es

- «SSO de Google» = **«Iniciar sesión con Google»**: OpenID Connect (OIDC) sobre OAuth 2.0. Google solo **demuestra quién es la persona** (su cuenta de Google); **no** da permisos en el área privada ni sustituye al CRM.
- **No es** el acceso a Google Drive (doc 30), que sería otro permiso (scopes de Drive) y otro flujo.
- La autorización sigue donde está: la persona entra solo si tiene **cuenta del área** y **membresía activa en el CRM** (doc 59, RC-NUE-2), con su rol y permisos de siempre (docs 02, 56).

## 2 · Lo que hay que saber de Google

| Tema | Detalle |
|---|---|
| Flujo | **Código de autorización con PKCE**, desde el servidor (WordPress). No usar el flujo implícito. |
| Discovery | `https://accounts.google.com/.well-known/openid-configuration` (de ahí salen los endpoints; no codificarlos a mano). |
| Autorizar | `https://accounts.google.com/o/oauth2/v2/auth` con `client_id`, `redirect_uri`, `response_type=code`, `scope=openid email`, `state`, `nonce`, `code_challenge` (S256). |
| Token | `https://oauth2.googleapis.com/token` (POST servidor a servidor con `client_secret` y `code_verifier`). Devuelve `id_token` (JWT). |
| Claves | JWKS en `https://www.googleapis.com/oauth2/v3/certs` (cachear según `Cache-Control`; rotan). |
| Validar el `id_token` | Firma (RS256), `iss` = `https://accounts.google.com` o `accounts.google.com`, `aud` = nuestro `client_id`, `exp`, `nonce` igual al guardado, y `email_verified = true`. |
| Identificador estable | **`sub`** (único e inmutable por cuenta de Google). **El email puede cambiar o reasignarse: no es la clave.** |
| Otros datos | `email`, `email_verified`, `hd` (dominio de Google Workspace, si lo hay), `name`, `picture`. Solo se pide lo mínimo: `openid email`. |
| Consola | Google Cloud Console → proyecto → *Google Auth Platform* → pantalla de consentimiento + cliente OAuth «Aplicación web». |
| Redirect URI | Registrada **exacta** (esquema, dominio, ruta y `/`): p. ej. `https://baleares.scout.es/nuestro-grupo-scout/area-privada/acceso-google/`. Solo HTTPS (en Local, `localhost` está permitido). Una URI por entorno. |
| Consentimiento | Los scopes `openid` y `email` **no son sensibles**: no exigen verificación de Google, pero la app debe estar **«En producción»** (en «Pruebas» solo entran usuarios de prueba dados de alta a mano). Hay que dar el nombre, logo opcional, dominio y enlaces de política de privacidad y condiciones. Tipo **Externo** (las familias usan Gmail); «Interno» solo vale para un Workspace propio. |
| Límites | Sin coste. Las cuentas de Google con email no verificado no entran. |

Alternativa no elegida: **Google Identity Services** (botón y One Tap por JavaScript, devuelve el `id_token` al navegador). Es más corto pero depende de JS y de cookies de terceros; el proyecto prefiere **mejora progresiva** (CLAUDE.md): el flujo por redirección funciona sin JS. **[S]** Se usa el flujo por redirección con un enlace «Entrar con Google».

## 3 · Cómo es hoy el acceso (lo que hay que respetar)

- Pantalla `[asde_acceso]` (`Frontend\Views\AccesoView`): **grupo** + **nº de socio/a o email** + contraseña (+ código de Wordfence). Valida con `wp_signon()` dentro de `AccessHooks::en_grupo()`.
- `AccessHooks::comprobar_acceso()` (filtro `authenticate`, prioridad 22): comprueba el grupo (**sin visibilidad entre grupos**) y la **membresía activa** (`con_membresia()`); fuera de la pantalla de acceso, los socios no entran por `wp-login.php`.
- **El usuario de WordPress no tiene el email en claro**: el email de cada cuenta se guarda **cifrado** (`Usuarios\EmailCuenta`) y se encuentra por su huella; `Auth\Identificacion::cuentas_con_email()` devuelve las cuentas del grupo con ese email. Hay casos de **varias cuentas con el mismo email** (scouter + cuenta familiar `nºT`, tutor 1 y 2) y la pantalla ya sabe ofrecer elegir (v0.64.3, error `asde_email_varias`).
- Límite de intentos (`Auth\Intentos`/`Limite`), auditoría `Logger::log('acceso', …)` sin valores, mensajes que no revelan si una cuenta existe.
- Doble factor: lo pone **Wordfence** en el filtro `authenticate` (prioridad 25).

## 4 · Diseño propuesto

### 4.1 Principio: Google solo sustituye a la contraseña
La persona **no se crea con Google**: la cuenta del área sigue naciendo por invitación/solicitud (docs 11, 22). Google sirve para **entrar en una cuenta que ya existe**. Si el email de Google no corresponde a ninguna cuenta, mensaje genérico (no se revela si existe).

### 4.2 Vincular la cuenta de Google con la del área
Dos pasos, por seguridad:
1. **Primer uso (vinculación):** la persona entra con su método de siempre (nº/email + contraseña) y, ya dentro, pulsa «Vincular mi cuenta de Google» en *Mi cuenta*. Se hace el flujo OIDC y se guarda el vínculo `sub` → usuario. Solo se vincula si el `email_verified` es verdadero.
2. **Usos siguientes:** botón «Entrar con Google» en la pantalla de acceso → se busca la cuenta **por `sub`**. Sin email que casar, no hay ambigüedad entre cuentas con el mismo email.

**GS-1 (decidir):** ¿se permite también el **primer acceso solo con Google, casando por email** (sin vinculación previa)? Es más cómodo para las familias, pero (a) un email con varias cuentas obliga a elegir, y (b) casar por email asume que Google verificó ese email y que nadie más lo controla. **[S] Recomendado: vinculación previa**, y como alternativa suave, ofrecer vincular en el momento de **activar la cuenta** (`ActivarCuentaView`), cuando la persona acaba de demostrar que controla ese email por el enlace de invitación.

### 4.3 Qué se guarda (doc 08: nada de datos personales en claro)
- El `sub` se guarda como **huella HMAC** (clave del sitio) en `usermeta` o en una tabla `{prefix}asde_google_vinculos` (usuario, huella, fecha, último uso): **suficiente para buscar** y no sirve para reconocer a nadie fuera de la web. **GS-2:** ¿huella (propuesto) o cifrado con `Crypto`? Con huella no se puede enseñar «vinculada con x@gmail.com»; se enseña solo «vinculada el 20/11/2026».
- El email que devuelve Google **no se guarda**; se usa en el momento (p. ej. para comparar en la activación) y se descarta. No se guardan `name` ni `picture`. Ningún token de Google se conserva (no se pide `offline_access`).
- Desvincular: botón en *Mi cuenta* y desde Gestión de accesos para Coordinación (doc 56); se borra la fila y se audita.

### 4.4 Reglas de seguridad (CLAUDE.md, regla 4)
- `state` aleatorio guardado en un transient **cifrado/en servidor** y de un solo uso (caduca en 10 min), ligado al navegador por una cookie firmada; `nonce` y `code_verifier` igual. Sin `state` válido no se hace nada.
- El flujo de vinculación exige sesión + nonce de WordPress; el de entrada admite `redirect_to` solo de páginas del área (como ahora).
- Las llamadas a Google con `wp_remote_post/get` (HTTPS, timeout corto); la caída de Google da un error claro y **siempre queda la contraseña**.
- Después de validar el `id_token` y encontrar la cuenta: se llama **a la misma comprobación que el login normal** (grupo de la cuenta + `con_membresia()`); el login se completa con `wp_set_auth_cookie()` dentro de `AccessHooks::en_grupo()` para no abrir una vía paralela sin esas reglas. Cuentas **desactivadas** o sin membresía: mismo mensaje que hoy.
- Límite de intentos propio (por IP) para el *callback*; `Logger::log('acceso', array('con' => 'google'))`.
- **Doble factor (GS-3):** Wordfence no se ejecuta si no pasamos por `authenticate`. Google ya aplica su propia verificación en dos pasos, pero no todas las cuentas la tienen. **[S] Propuesta:** si la cuenta tiene el 2FA de Wordfence activado, se exige también con Google; si no lo tiene, entra solo con Google. Hay que comprobar en una prueba cómo encaja con `wordfence_ls_require_captcha` y los filtros de `authenticate` (probablemente `wp_authenticate_user`/propio filtro `asde_ap_google_*`).
- Administradores y oficina federal (`manage_options`, `asde_multigrupo`): **[S] sin Google** en la primera versión (siguen con contraseña + 2FA); decidir **GS-4**.

### 4.5 Ajustes y activación
- Nace **apagado** (regla 5): ajuste en *Organización → Acceso* (`asde_ap_google_activo`, por defecto no), con `client_id` y `client_secret`. El secreto se guarda **cifrado** (`Crypto`), nunca en claro ni en el repo; también se admite definirlo en `wp-config.php` (`ASDE_AP_GOOGLE_SECRET`).
- **GS-5:** ¿un cliente de Google **para toda la plataforma** (lo crea ASDE federal, una sola redirect URI por sitio) o **uno por grupo**? Como cada grupo tiene credenciales de CRM propias pero comparten el WordPress y el dominio, **[S] uno solo por instalación**.
- Capability nueva `asde_acceso_google` (quién puede configurarlo) y permiso de usar el botón: lo usa cualquiera con cuenta del área.

### 4.6 App móvil (PWA)
La PWA es **otro plugin** (`asde-area-privada-app`). Este plugin no usa clases de la app: si la app necesita el botón, se añade aquí un enganche genérico (`apply_filters('asde_ap_acceso_google_url', …)`/`do_action`) con su prueba. Los `redirect_uri` de las apps instaladas (modo `standalone`) se resuelven por la misma URL del sitio, así que no hacen falta otras. Fuera de la primera versión.

## 5 · Piezas a tocar (cuando se programe)

| Pieza | Fichero previsto |
|---|---|
| Cliente OIDC (discovery, intercambio de código, validación de `id_token`, caché de JWKS) | `src/Auth/Google/OidcClient.php` |
| Vínculos `sub` ↔ usuario y huella | `src/Auth/Google/Vinculos.php` (+ tabla y desinstalación en `Install`/`uninstall.php`) |
| Pantalla *callback* y botón «Entrar con Google» | `AccesoView` (botón) y una vista/shortcode propio (o `Router::template_redirect`) para el *callback*; página en `Pages::DEFINICIONES` |
| «Vincular/Desvincular» | *Mi cuenta* (vista existente) + `Admin/OrganizacionPage` para los ajustes |
| Capability y menú | `modulo-nuevo` (skill del proyecto) |
| Textos | `traducir-textos` (en inglés + 5 `.po`) |
| Pruebas | `tests/google-sso.php` con un **proveedor OIDC simulado** (como `tests/servidor-crm`): firma RS256 con una clave de prueba, `state`/`nonce` incorrectos, `aud`/`iss`/`exp` malos, email no verificado, cuenta sin membresía, cuenta de otro grupo, cuenta desactivada, vínculo repetido, límite de intentos, Google caído |

Sin librerías externas obligatorias: la verificación RS256 se puede hacer con `openssl_verify` y el JWKS convertido a PEM (hay que escribir ese conversor, unas 40 líneas) o con `firebase/php-jwt` **si** se acepta una dependencia vendorizada (**GS-6**; el plugin hoy no tiene `vendor/`; mejor sin dependencia por la revisión de WordPress.org).

## 6 · Entornos y pruebas
- **No se prueba con el Google real desde la sesión de Claude** ni con cuentas reales: el flujo completo se prueba con el proveedor OIDC simulado. La prueba con Google real (cliente OAuth de desarrollo, URI `http://localhost:10011/…`) la hace el usuario en el Local.
- Producción: el usuario crea el cliente OAuth y pega el `client_id`/secreto; **Claude no publica**.

## 7 · Documentación para las personas usuarias (cuando exista)
Manual de familias/scouters: «Vincular mi cuenta de Google» y «Entrar con Google» (+ qué hacer si cambio de cuenta de Google o la pierdo: entrar con contraseña y desvincular); manual de administración: crear el cliente en la consola de Google, la URI exacta y la pantalla de consentimiento «En producción».
Política de privacidad: Google recibe que esa cuenta entra en el sitio; el sitio recibe solo el `sub` (en huella) y comprueba el email al vincular.

## 8 · Decisiones pendientes

| Id | Pregunta | Propuesta |
|---|---|---|
| GS-1 | ¿Primer acceso solo con Google casando por email, o vinculación previa? | Vinculación previa (+ ofrecerla al activar la cuenta). |
| GS-2 | ¿`sub` como huella HMAC o cifrado? | Huella. |
| GS-3 | ¿Se exige el 2FA de Wordfence también con Google? | Sí, si la cuenta lo tiene activado. |
| GS-4 | ¿Administradores y oficina federal entran con Google? | No en la v1. |
| GS-5 | ¿Un cliente de Google por instalación o por grupo? | Uno por instalación. |
| GS-6 | ¿Dependencia `php-jwt` o verificación propia con `openssl`? | Propia, sin dependencias. |
| GS-7 | ¿Otros proveedores (Microsoft, Apple) más adelante? | Dejar `OidcClient` genérico (endpoints por configuración) por si acaso. |

## 9 · Fuentes
Documentación de Google *OpenID Connect* (`developers.google.com/identity/openid-connect/openid-connect`) y *Google Auth Platform* (consentimiento, clientes OAuth). Los endpoints y claims de §2 están dados de memoria: **comprobar contra esa documentación antes de programar** (no se ha podido consultar en esta sesión).
