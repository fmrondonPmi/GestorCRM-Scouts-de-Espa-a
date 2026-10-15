# Reglas de seguridad del Gestor CRM (asde-area-privada)

Las lee el plugin **security-guidance** de Claude Code en su revisión de cada cambio (v0.33.1).
Son las reglas propias del proyecto; las generales (inyección, XSS, CSRF…) ya las sabe.
Más detalle: `CLAUDE.md` («Reglas que no se discuten» y «Revisor de plugins»).

- **Un grupo no ve a otro.** Toda lectura o escritura de una tabla propia (`{prefix}asde_*`) que
  reciba un id de la petición filtra por `grupo = Grupo::numero()` (en la consulta o leyendo antes
  la fila con el `obtener()` de su clase, que ya filtra). Las fichas del CRM pasan por
  `PersonaRepository` (filtra por el grupo de seguridad). Un id sin ese filtro es un IDOR.
- **Cada acción que cambia algo** (`handle_post()` de las vistas, `admin_post_*`, `wp_ajax_*`) lleva
  nonce (`check_admin_referer`/`check_ajax_referer`) **y** permiso (`current_user_can` con la
  capacidad `asde_*` que toque, o el `denegar_post()`/`comprobar()` de su clase). Excepciones a
  propósito: las pantallas públicas (acceso, activar cuenta, recuperar, solicitar, alta de
  scouters, inscripción en la lista de espera) van con nonce o token firmado y sin permiso; el
  trabajador de la numeración (`wp_ajax_nopriv_asde_ap_tarea`) va con token y `hash_equals`.
- **Datos personales:** la salud y las dietas no se guardan nunca en WordPress; lo demás, cifrado
  con `Crypto` y con caducidad. Ni datos personales ni secretos en registros (`Logger::log` guarda
  nombres de campos, nunca valores), en la URL ni en mensajes de error.
- **El CRM se escribe solo por `Crm\Escritura`** (y `Crm\Altas`/`Operaciones` de la v0.22). Las
  credenciales de cada grupo van cifradas en sus opciones; nunca en el código ni en el repo.
- **Salida escapada siempre** (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`), también lo que
  devuelven `asde_t()`/`asde_h()`: las etiquetas personalizadas de wp-admin pasan por `wp_kses`
  pero pueden llevar comillas. En los JS, un texto de `asdeT()` dentro de un atributo HTML va con
  `asdeEsc()`; el HTML que llega del servidor (ventanas, `parcial.js`) ya viene escapado en PHP.
- **SQL:** siempre `$wpdb->prepare()` con `%i` para las tablas; nada de SQL montado en variables.
- **Redirecciones:** `wp_safe_redirect()` / `wp_validate_redirect()` con lo que venga del formulario
  (`volver`). Ficheros subidos: `is_uploaded_file()` y solo se lee su contenido (CSV, Norma 43,
  JSON de etiquetas); imágenes con `media_handle_upload()` y lista de tipos.
- **Peticiones salientes:** solo al servidor de SinergiaCRM que configura el administrador y a la
  propia web (tareas en segundo plano). La app (otro plugin) envía los avisos push con
  `wp_safe_remote_post` y sin redirecciones.
- Las pruebas (`tests/`) usan el CRM simulado; `sslverify => false` contra la propia web del Local
  en una prueba no es un fallo.
