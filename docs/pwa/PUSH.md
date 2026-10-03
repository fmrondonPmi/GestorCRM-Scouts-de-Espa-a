# Notificaciones push · plan (sección 7)

> **Plan sin código.** Decisión del usuario (2026-10-03): las notificaciones de la app serán **push** (avisos en el móvil, no solo email). Este documento recoge cómo se haría, qué limita cada sistema y **qué hay que decidir antes de empezar** (preguntas P-PUSH-1 a P-PUSH-6). Reglas de trabajo: una sección cada vez, sin agentes salvo necesidad (`README.md`).

## 1. Qué es y qué necesita

Una notificación push es un aviso que el móvil enseña **aunque la app esté cerrada** (en la pantalla de bloqueo o en la barra de avisos). Con una PWA se hace con el estándar **Web Push**:

1. El servicio de la web (nuestro `asde-sw.js`) se **suscribe** al servicio de avisos del navegador (Google, Mozilla o Apple) y recibe una *suscripción*: una dirección (*endpoint*) y dos claves.
2. La web guarda la suscripción asociada a la **cuenta** de la persona.
3. Cuando pasa algo, el servidor envía un mensaje **cifrado** a esa dirección; el navegador despierta el service worker, que **muestra el aviso**; al pulsarlo se abre la página que toque.

Estándares: Web Push (RFC 8030), VAPID (RFC 8292, identifica al servidor con un par de claves propio) y cifrado de mensajes (RFC 8291, `aes128gcm`). **No hay cuenta ni pago con Google/Apple**: las claves VAPID las genera el propio plugin.

## 2. Limitaciones por sistema (lo que condiciona el diseño)

| Sistema | Funciona | Condiciones |
|---|---|---|
| **Android (Chrome, Edge, Firefox)** | Sí | Con la app instalada o solo en el navegador; la persona debe **aceptar el permiso** de notificaciones |
| **iPhone / iPad (iOS 16.4 o posterior)** | Sí, **solo con la app instalada** en la pantalla de inicio | El permiso solo se puede pedir **tras un toque** de la persona y desde la app instalada. Por eso las secciones 3–4 (instalar) son requisito. Con iOS anterior a 16.4: no hay push |
| Escritorio (Chrome, Edge, Firefox; Safari 16+) | Sí | Permiso del navegador |

Consecuencias: (a) **no se puede garantizar** que todas las familias reciban push (dependen del móvil, del sistema y de aceptar); **el email se mantiene** para lo importante (hoy ya lo hay: invitaciones, avisos de cargo SEPA…). (b) El permiso se pide con un botón claro, **nunca al abrir la web**. (c) iOS solo vale para quien instale la app.

## 3. Piezas técnicas (propuesta)

- **Claves VAPID**: se generan una vez (curva P-256 con OpenSSL de PHP, ya disponible) y se guardan cifradas (`Crypto`, como las contraseñas del CRM). La clave pública va al navegador.
- **(Cambiado en 7a: usermeta, ver §10.) Tabla `{prefix}asde_push`**: `id`, `user_id`, huella del *endpoint* (para no duplicar), *endpoint* **cifrado**, `p256dh` y `auth` cifrados, idioma, preferencias, creado, último envío, fallos. Se **borra** la suscripción cuando el servicio de avisos contesta 404/410 (el móvil la dio de baja) y al borrar o desactivar la cuenta. Es un dato personal (identifica un dispositivo): cifrado, mínimo y con caducidad (doc 08).
- **Emisor en PHP sin librerías externas** (el plugin no usa Composer): ECDH P-256 (`openssl_pkey_derive`), HKDF (`hash_hkdf`), AES-128-GCM (`openssl_encrypt`), firma ES256 del VAPID (`openssl_sign` + conversión DER → R‖S). Se prueba con los **vectores de prueba de los RFC** (así se sabe que el cifrado es correcto sin necesitar un móvil). Alternativa: incluir la librería `minishlink/web-push` (más código externo que mantener y revisar).
- **Cola de envío**: los avisos no se mandan en la petición de la persona (tardaría y puede fallar): se encolan y salen por el WP-Cron en tandas (como ya hace la corrección de números en segundo plano, doc 17), con reintentos y límite.
- **Service worker** (`sw.js`): dos manejadores nuevos, `push` (muestra el aviso con título, texto, icono de la app y destino) y `notificationclick` (abre o enfoca la página dentro del alcance). Sigue sin guardar nada personal en el móvil (D6).
- **Pantalla de la persona**: botón «Activar avisos en este móvil» (y «Desactivarlos») y casillas por **categoría**; solo se ofrece si el navegador lo soporta, la app está instalada (iPhone) y el grupo lo tiene encendido.
- **Administración** (*App móvil*): interruptor general de push, categorías disponibles, número de dispositivos suscritos (sin nombres), «Enviar aviso de prueba» a mi móvil y comprobación de que el servidor puede salir a los servicios de avisos.
- **Idiomas**: cada aviso sale en el idioma de quien lo recibe (`Idioma::de_usuario`), con textos en el catálogo.

## 4. Privacidad y seguridad (reglas que no se discuten, `CLAUDE.md`)

- **El texto del aviso se ve en la pantalla de bloqueo**, también por quien tenga el móvil en la mano. Con datos de menores, **el contenido debe ser genérico** («Tienes una novedad en el área privada»), sin nombres, importes ni salud. Es la propuesta por defecto (P-PUSH-2).
- El mensaje viaja cifrado de extremo a extremo hacia el navegador: Google/Apple/Mozilla ven que hay un aviso, no su contenido; igualmente **no se incluyen datos personales en el mensaje** y el detalle se ve al abrir la web (con sesión).
- **Consentimiento explícito** (permiso del navegador + botón de la persona), revocable en cualquier momento. Cada cuenta decide por sí misma; las cuentas de menores de 14 a 17 (propias) y las de las familias son independientes.
- Nada se envía a quien no tenga la suscripción; **un grupo no ve ni avisa a otro** (regla 3): los destinatarios salen siempre filtrados por el grupo del suceso.
- Toda acción de administración con nonce, capacidad `asde_*`/`manage_options`, y auditoría (`Logger::log`: categorías y recuentos, nunca contenido ni *endpoints*).
- Se apaga todo con un interruptor (el SW de apagado también da de baja la suscripción) y se puede **desinstalar** sin dejar datos (`uninstall.php`).

## 5. Qué avisos podría haber (candidatos; hay que elegir)

| Para quién | Aviso | Origen en el código |
|---|---|---|
| Familias y participantes | Un pedido de uniforme ha cambiado de estado (pagado, listo, entregado) | `Uniformes` (doc 24) |
| Familias y participantes | Tesorería ha registrado un pago / hay un próximo cargo de la domiciliación SEPA | `Tesoreria`, `Sepa` (docs 14, 25) |
| Familias | Se ha aprobado o rechazado un cambio propuesto en una ficha | `Fichas\Cambios` (doc 17) |
| Scouters / equipo | Hay una solicitud de acceso pendiente de revisar | `Solicitudes` (doc 22) |
| Equipo | Hay cambios de fichas pendientes de aprobar | `Fichas\Cambios` |
| Equipo | Ha terminado la corrección de números de socio en segundo plano | `TareaNumeracion` (doc 17) |

## 6. Cómo se haría, por pasos (una sección cada vez)

| # | Paso | Contenido | Prueba |
|---|---|---|---|
| 7a ✅ (v0.17.4) | **Base:** claves VAPID, tabla, suscribir/desuscribir, botón «Activar avisos», manejador `push`/`notificationclick` en el SW (con aviso genérico), categorías y pantalla de la persona | Suscribirse de verdad desde el Chrome del usuario en Local (localhost vale) |
| 7b ✅ (v0.17.5) | **Emisor:** cifrado Web Push + VAPID en PHP, envío, borrado de suscripciones muertas, cola con el WP-Cron | Vectores de los RFC + un aviso real a Chrome |
| 7c | **Avisos:** conectar los sucesos elegidos (§5), preferencias por categoría, idiomas, filtro por grupo | Pruebas con CRM simulado; un caso por suceso |
| 7d | **Administración y manual:** interruptor, aviso de prueba, comprobaciones en «App móvil» y «Instalación y entorno», apartado en los manuales, `MANUAL-PUBLICACION.md` | Pantalla real + iPhone/Android si hay móvil |

Cada paso termina con pruebas («TODO OK»), docs (`ESTADO.md`, doc 00, doc 21 y los del módulo), versión y zip, como el resto del proyecto. Lo que no se puede ver sin un móvil (iPhone con iOS 16.4+, Android) se anota igual que en el manual de publicación §13.

## 7. Preguntas abiertas para el usuario

| # | Pregunta | Propuesta por defecto |
|---|---|---|
| **P-PUSH-1** | ¿Qué avisos se quieren al principio? (§5) | Empezar con los de **familias y participantes**: uniforme, pagos y cambios de ficha; luego, los del equipo |
| **P-PUSH-2** | ¿Qué dice el aviso en la pantalla de bloqueo? | **Genérico** (sin nombres ni importes): «Tienes una novedad en el área privada». Un texto más concreto («Tu pedido de uniforme está listo») aumenta el riesgo en un móvil compartido |
| **P-PUSH-3** | ¿Los avisos de push sustituyen a los emails o se suman? | **Se suman**: el email se mantiene (no todas las familias tendrán push) |
| **P-PUSH-4** | ¿Quién puede apagarlos? | **Cada persona** para su móvil y por categoría + un **interruptor general** del administrador |
| **P-PUSH-5** | ¿Las cuentas de menores (14–17) reciben push? | Sí, como cuenta propia, con las mismas categorías (y consentimiento propio); revisar con el grupo si prefieren dejarlo solo para familias |
| **P-PUSH-6** | ¿Se quiere poder **mandar un aviso manual** a todo el grupo o a una sección (p. ej. «mañana la reunión es en…»)? | **No al principio**: es lo más sensible (contenido libre a muchas personas); mejor decidirlo cuando lo anterior funcione |

## 8. Esfuerzo y riesgos

- **Esfuerzo:** cuatro pasos (7a–7d), parecidos en tamaño a las secciones 3–5 (cada uno, una versión del plugin). El emisor (7b) es lo más delicado por la criptografía; se cubre con los vectores de los RFC.
- **Riesgos:** (1) iPhone: solo si instalan la app y aceptan; (2) hosting: la web debe poder **salir** a los servicios de avisos (Google/Apple/Mozilla) y tener el WP-Cron funcionando (doc 18); (3) contenido personal en la pantalla de bloqueo (se evita con avisos genéricos); (4) expectativas: push no garantiza entrega inmediata.

## 9. Decisiones del usuario (2026-10-03)

| # | Decisión |
|---|---|
| P-PUSH-1 | **Los cuatro grupos de avisos**: uniforme, pagos y cargos SEPA, cambios de ficha y avisos del equipo (solicitudes pendientes, cambios por aprobar, fin de tareas en segundo plano) |
| P-PUSH-2 | **Texto genérico** en la pantalla de bloqueo («Tienes una novedad en el área privada») |
| P-PUSH-3 | **Se suman al email** (el email no se toca) |
| P-PUSH-4 a 6 | Se toma la **propuesta por defecto** (cada persona por categoría + interruptor general; cuentas de 14–17 incluidas con su propio consentimiento; sin aviso manual al principio). Se pueden cambiar cuando se quiera |

## 10. Decisiones de diseño tomadas al empezar (7a, v0.17.4)

- **Suscripciones en `usermeta`, no en una tabla nueva** (cambia lo propuesto en §3): clave `asde_ap_push`, lista de dispositivos de la cuenta (máx. 5), cada uno **cifrado** con `Crypto::encrypt_contexto( …, 'push|<id>' )` (endpoint + claves). Así no hay migración de esquema, y al borrar el usuario WordPress borra sus suscripciones. Las preferencias por categoría, en `asde_ap_push_cats`.
- **Apagado por defecto** (regla 5 del `CLAUDE.md`): la opción `asde_ap_pwa` gana el campo `push` (falso si no está). Solo el administrador lo enciende (*App móvil*).
- **Solo endpoints de servicios de avisos conocidos** (Google, Mozilla, Apple, Microsoft; ampliable con el filtro `asde_ap_push_hosts`): el servidor luego hace una petición a esa dirección, y sin lista cerrada cualquier cuenta podría apuntarla a una dirección interna (SSRF).
- **Claves VAPID** en la opción `asde_ap_push_vapid` (la privada, cifrada). OpenSSL de PHP en Windows (Local) **no genera claves sin un `openssl.cnf`**: se reintenta con `src/Pwa/openssl-minimo.cnf` (probado con OpenSSL 3.5/PHP 8.5; en Linux/Hostinger funciona sin él).
- **Dónde se activa**: una tarjeta «Avisos en este móvil» en el **inicio del área** (la pone `assets/pwa/push.js`; usa las clases de tarjeta del plugin), con botón «Activar avisos» (el permiso se pide tras el toque) y las categorías.
