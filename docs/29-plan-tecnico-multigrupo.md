# 29 · Plan: cuenta «Técnico/a», varios grupos en solo lectura

**Petición del usuario (2026-10-03):** «Ve generando el plan para un usuario llamado "Técnico/a" el cual tendrá una credencial de acceso que permite visualizar más de un grupo scout, en modo solo lectura.»

**Estado:** plan, sin código. Antes de empezar hay que cerrar las preguntas abiertas P-49 a P-55 (doc 09).

---

## 1 · Qué hay hoy (lo que condiciona el diseño)

| Pieza | Cómo funciona hoy | Qué supone para el técnico |
|---|---|---|
| Grupo de trabajo | `Domain\Grupo::actual()`: el grupo «padre» del usuario (meta `asde_grupo`); un administrador de WordPress usa el que eligió al entrar (meta `asde_grupo_contexto`). Todo el área (socios, usuarios, familias, pagos, informes…) se limita a ese grupo. | Ya existe la idea de «grupo de contexto», pero solo se elige al entrar y solo para administradores. Hay que extenderla al técnico y poder cambiarla sin salir. |
| Acceso | `Auth\Ambito::puede_acceder()`: administrador, o algún rol vigente según su ficha del CRM del grupo (membresía activa o menores a cargo). | Un técnico (personal de la federación) no suele ser socio de los grupos que mira: necesita entrar **sin ficha en el grupo**, como el administrador. |
| Visión del grupo | `Ambito::vision_global()`: administrador, Secretaría o Coordinación. | El técnico necesita visión global del grupo que mira. |
| Lectura del CRM | Cada grupo tiene su usuario de API (`Grupos::credenciales()`), y la copia cifrada de fichas (`Crm\Instantanea`) va **por grupo**. | Cambiar de grupo funciona solo: se lee con las credenciales del grupo elegido y su propia copia. |
| Escrituras | Todas las acciones del área entran por **un único punto**: `Router::template_redirect()` → `handle_post()` de la vista (22 vistas). Aparte, los AJAX de `TareaNumeracion` (corrección en segundo plano) y `Tabla::ajax_por_pagina` (preferencia de tamaño de página). | La regla «solo lectura» se puede aplicar **en un sitio** y no en 22. |
| Permisos de la ficha | `Fichas\PermisosFicha::accion()` decide EDITAR / PROPONER / VER por campo. | Basta con que devuelva siempre VER para el técnico, con el motivo «cuenta de solo lectura». |
| Capabilities | Las de cada rol mezclan ver y hacer (p. ej. `asde_tesoreria_acceso` deja ver **y registrar pagos**). | No basta con elegir capabilities: hace falta una marca de solo lectura que mande sobre todas. |

## 2 · Propuesta

### 2.1 Quién es y cómo entra

- **Rol nuevo `asde_tecnico`** («Técnico/a»), con una capability propia **`asde_solo_lectura`** y otra **`asde_multigrupo`**.
- **Grupos permitidos** en el usuario (meta `asde_grupos_tecnico`): una lista de grupos o «todos los grupos de la web». Solo los puede asignar un **administrador de WordPress** (ninguna Secretaría de grupo puede dar acceso a otros grupos).
- **Alta en wp-admin**, pantalla nueva *Grupos Scouts → Técnicos*: nombre, email, grupos permitidos y botón «Enviar invitación» (se reutilizan `Usuarios\Invitacion` y la activación de cuenta). El usuario de acceso es su **email** (no es socio de los grupos; P-50 por si lo es de la federación).
- **Entrar:** por «Acceso socios», con su email y contraseña. El desplegable de grupo se usa como grupo de inicio (si no es uno de los suyos, el primero de su lista).
- **Verificación en dos pasos obligatoria** desde el primer día (ve datos de menores de varios grupos).

### 2.2 Ver más de un grupo: el selector de grupo

- **Fase 1 (lo pedido):** junto a su nombre, un **selector «Grupo: …»** con sus grupos permitidos. Cambiar de grupo guarda el contexto (`asde_grupo_contexto`, con nonce), olvida la caché de `Ambito` y recarga la página en la que estaba. Todo lo que ve es **de un grupo cada vez**, igual que hoy.
- `Grupo::actual()` para el técnico: el contexto elegido **solo si está en su lista**; si no (lista cambiada, grupo borrado, contexto manipulado), el primero permitido. Esta comprobación es la frontera de seguridad: va con pruebas.
- **Fase 2 (opcional, P-53):** un **panel de resumen multigrupo** en su inicio, con una fila por grupo: socios activos, incidencias de números de socio, solicitudes pendientes, cuota cobrada (%), pedidos de uniforme abiertos. Sale de la copia cifrada de cada grupo y de las tablas de la web, sin datos personales. Hay que medir el coste: cada grupo es al menos una lectura del CRM cuando su copia ha caducado (0,5–1,5 s), así que el panel se calcula por WP-Cron y se guarda unos minutos.

### 2.3 Solo lectura, de verdad

Tres capas, de fuera adentro:

1. **En el CRM (recomendada, P-51):** el técnico lee con un **usuario de API de solo lectura**. Hay dos opciones:
   - **A.** Usar el usuario de API de cada grupo (lo de hoy). Sencillo, pero esos usuarios pueden escribir: la solo lectura depende solo de la web.
   - **B.** Un **usuario de API de la federación, de solo lectura**, que vea todos los grupos, configurado en *Organización federada*. Con él, ni un error del código podría escribir en el CRM. Necesita que ASDE lo cree con ese rol. **Es la opción recomendada** si la federación lo puede dar.
2. **En la web, en un único punto:** en `Router::template_redirect()`, si la cuenta tiene `asde_solo_lectura`, **toda acción POST se rechaza** (aviso «Tu cuenta es de solo lectura» y vuelta a la página) salvo una lista blanca de lo que es solo suyo: idioma, «Mostrar nº territorial», tamaño de página, seguridad (2FA) y salir. Lo mismo en los AJAX de `TareaNumeracion` (no puede lanzar ni detener correcciones) y en `Tabla::ajax_por_pagina` (permitido). Un test recorre **todas** las acciones (`asde_action`) de las 22 vistas y comprueba que ninguna pasa.
3. **En lo que se ve:** `PermisosFicha` devuelve VER en todos los campos («Cuenta de solo lectura»); las plantillas esconden los botones de acción (Guardar, Aprobar, Dar acceso, Registrar pago, Validar, Marcar entregado, Corregir en el CRM…) con una comprobación común (`Ambito::actual()->solo_lectura()`). Es lo más laborioso (muchas plantillas), pero solo es aspecto: la seguridad la dan las capas 1 y 2. Una franja fija «Solo lectura · Grupo X» deja claro dónde está.

### 2.4 Qué ve (propuesta, a confirmar en P-52)

| Pantalla | Propuesta | Notas |
|---|---|---|
| Inicio | Sí | Con «Pendiente de revisar» solo como información. |
| Personas y fichas | Sí, en solo lectura | Las incidencias de números de socio se ven, sin botones. |
| **Salud y alimentación** | **No, por defecto** | Datos de categoría especial (RGPD): solo si hay una razón y un acuerdo que lo cubra (P-52). |
| Informes | Sí, ver y descargar CSV | Sin guardar informes. Sin columnas de salud si no ve salud. ¿Descargar CSV? (P-52). |
| Tesorería | Control de pagos, familias, cuotas y coste federativo | **Sin banco ni domiciliaciones SEPA** (cuentas bancarias). |
| Uniformes | Gestión de pedidos, en lectura | |
| Solicitudes, cambios, usuarios y emails | Sí, en lectura | Los emails ya salen en parte ocultos. |
| Configuración (uniformes, cuotas…) | Solo ver | |

### 2.5 Protección de datos y registro

- Cada **cambio de grupo** y cada **ficha, informe o CSV** que consulte queda en la **auditoría** con el grupo (hoy se registran los informes que se ejecutan o descargan, pero no las fichas que se abren).
- En *Grupos Scouts → Técnicos*, el administrador ve el último acceso de cada técnico y qué grupos ha mirado.
- Hace falta una **base legal** para que personal de la federación vea datos de socios de los grupos (normalmente, la federación como responsable o encargada del tratamiento). No es una decisión técnica: P-49.

## 3 · Plan de trabajo

| Paso | Qué | Dónde | Pruebas |
|---|---|---|---|
| 1 | Rol, capabilities y meta de grupos permitidos; `Ambito`: `es_tecnico()`, `solo_lectura()`, acceso sin ficha, visión global | `Install\Roles`, `Auth\Ambito`, `Usuarios\Usuarios` | `tests/tecnico.php` (nuevo) |
| 2 | `Grupo::actual()` con contexto validado contra la lista; selector de grupo en el menú, con nonce | `Domain\Grupo`, `Frontend\Menu`, `templates/comun/nav.php`, `Router` | cambiar a un grupo no permitido no funciona, ni por la URL ni manipulando la meta |
| 3 | Bloqueo central de escrituras (POST, AJAX) con lista blanca | `Router`, `TareaNumeracion`, `Tabla` | todas las `asde_action` de las 22 vistas rechazadas |
| 4 | Ficha y plantillas en lectura; franja «Solo lectura · Grupo X» | `PermisosFicha`, plantillas | no aparece ningún formulario de acción en las pantallas generadas como técnico |
| 5 | Pantalla wp-admin *Técnicos*: alta, grupos, invitación, último acceso | `Admin\TecnicosPage` (nueva) | alta, invitación, cambio de grupos |
| 6 | 2FA obligatoria y auditoría de consultas | `Integraciones\Wordfence`, `Audit\Logger` | registro de cambio de grupo y de consultas |
| 7 | (Si se elige la opción B) usuario de API de la federación de solo lectura | `Admin\OrganizacionPage`, `Crm\ClientFactory` | el técnico lee con ese usuario; nunca con el del grupo |
| 8 | (Fase 2) panel de resumen multigrupo | `AreaPrivadaView`, WP-Cron | coste medido con `rendimiento.php` |
| 9 | Textos en los cuatro idiomas, manual (sección en el de administración y uno nuevo «para técnicos»), docs | `languages/`, `manual/fuentes/`, docs | `idiomas-catalogos.php` |

**Tamaño:** fase 1 (pasos 1–6 y 9), una versión (v0.16), parecida a la de la v0.14. El paso 7 depende de ASDE; la fase 2, de P-53.

## 4 · Riesgos

- **Que una acción se escape del bloqueo:** se cubre con el bloqueo central y con el test que recorre todas las acciones; con la opción B, aunque se escapara, el CRM no aceptaría la escritura.
- **Rendimiento:** cambiar de grupo obliga a leer el CRM de ese grupo si su copia ha caducado (unos segundos la primera vez). El panel multigrupo (fase 2) se calcula en segundo plano.
- **Confusión de grupo:** la franja fija con el nombre del grupo y el selector siempre visible.
- **Datos de varios grupos en una sola cuenta:** 2FA obligatoria, auditoría y revisión periódica de los técnicos por el administrador.

## 5 · Preguntas abiertas (en el doc 09)

- **P-49** Base legal y quién autoriza que un técnico vea los datos de los grupos.
- **P-50** ¿El técnico es socio (tiene ficha en el CRM de la federación o de algún grupo)? ¿Entra con email o con un número?
- **P-51** ¿ASDE puede dar un usuario de API de solo lectura para la federación (opción B)?
- **P-52** ¿Ve salud y alimentación? ¿Descarga CSV? ¿Ve Tesorería, banco y SEPA, solicitudes, usuarios?
- **P-53** ¿Hace falta el panel de resumen multigrupo (fase 2)? ¿Con qué indicadores?
- **P-54** ¿Grupos permitidos por técnico (lista) o siempre todos los de la web?
- **P-55** ¿Quién da de alta a los técnicos: solo el administrador de WordPress?
