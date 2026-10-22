# Área privada · Grupo Scout — Base de conocimiento

Proyecto: plugin de WordPress **«ASDE Scouts de España - Gestor CRM para Grupos Scouts»** (`asde-area-privada`): área privada con gestión de usuarios, tesorería, tienda, eventos y más para los Grupos Scouts de ASDE (empezó en Scouts de Balears, Grupo Myotragus 684). **Versión actual: 0.53.0.** El historial de versiones está en el `readme.txt` del plugin y en el git.

- Los datos personales se leen del **CRM federal de ASDE** (SinergiaCRM) y **no se guardan en WordPress**.
- La web guarda solo números de socio, familias, configuración, pagos y lo que cada módulo necesita, y lo sensible va **cifrado** y con caducidad: el email de cada cuenta, lo escrito en solicitudes de acceso y listas de espera, las autorizaciones SEPA, los datos de quien compra sin sesión, los IBAN de los reintegros y las credenciales del CRM (doc 08).
- Reglas de trabajo, convenciones y cómo se prueba: `CLAUDE.md` en la raíz del repo. Para orientarse antes de tocar nada: **doc 40** (mapa e inventario generado).

## Documentos

| Doc | Contenido |
|---|---|
| [01-contexto-y-alcance.md](01-contexto-y-alcance.md) | Organización, módulos, restricciones |
| [02-roles-y-permisos.md](02-roles-y-permisos.md) | Roles, matriz de permisos y capabilities (con una actualización por módulo) |
| [03-arquitectura.md](03-arquitectura.md) | Estructura del plugin y decisiones |
| [04-integracion-sinergiacrm.md](04-integracion-sinergiacrm.md) | API y **modelo real del CRM de ASDE (§7)**: campos, membresías, tutores, salud, particularidades |
| [05-secciones-educativas.md](05-secciones-educativas.md) | Secciones de ASDE y estructura del grupo y la federación |
| [06-tema-y-estilos.md](06-tema-y-estilos.md) | Enfold, paleta, tipografía e integración visual |
| [07-flujo-de-cambios.md](07-flujo-de-cambios.md) | Cola de cambios propuestos con aprobación |
| [08-proteccion-de-datos-y-seguridad.md](08-proteccion-de-datos-y-seguridad.md) | RGPD, salud de menores, qué se guarda y cuánto tiempo |
| [09-preguntas-abiertas.md](09-preguntas-abiertas.md) | **Lo que falta decidir** y los supuestos por confirmar |
| [10-entorno-local.md](10-entorno-local.md) | LocalWP, WP-CLI, Mailpit, usuario de prueba y cómo se lanzan las pruebas |
| [11-alta-de-usuarios.md](11-alta-de-usuarios.md) | Alta de usuarios, invitaciones y listado (Usuarios, Dar acceso, Registro de emails) |
| [12-identidad-y-numeros-de-socio.md](12-identidad-y-numeros-de-socio.md) | Identidad, nº de socio local y de Scouts de España, qué guarda WordPress |
| [13-informes.md](13-informes.md) | Generador de informes: columnas, ámbito, salud, Excel e impresión |
| [14-tesoreria.md](14-tesoreria.md) | Tesorería: familias, cuotas, plazos, control de pagos, coste federativo |
| [15-acceso-y-grupos.md](15-acceso-y-grupos.md) | Acceso socios, grupos, recuperar contraseña y visibilidad |
| [16-banco-importacion.md](16-banco-importacion.md) | Banco: importar Norma 43 o CSV y conciliar |
| [17-fichas-y-edicion-en-el-crm.md](17-fichas-y-edicion-en-el-crm.md) | Ficha editable y escritura en el CRM |
| [18-comprobar-el-hosting.md](18-comprobar-el-hosting.md) | Comprobar el hosting y las tareas en segundo plano |
| [19-instalar-en-un-wordpress-nuevo.md](19-instalar-en-un-wordpress-nuevo.md) | «Instalación y entorno» |
| [20-organizacion-federada.md](20-organizacion-federada.md) | Organización federada, código del nº de socio, coste federativo |
| [21-manual-de-uso.md](21-manual-de-uso.md) | Los manuales en HTML por perfil: fuentes, capturas y generador |
| [22-solicitudes-de-acceso.md](22-solicitudes-de-acceso.md) | Solicitudes de acceso |
| [23-navegacion-y-ventanas.md](23-navegacion-y-ventanas.md) | Navegación y ventanas emergentes |
| [25-domiciliacion-sepa.md](25-domiciliacion-sepa.md) | Domiciliación SEPA (`pain.008`) |
| [26-rendimiento.md](26-rendimiento.md) | Rendimiento: el CRM como cuello de botella y la instantánea de fichas |
| [27-usuario-numero-asde.md](27-usuario-numero-asde.md) | Usuario de acceso configurable (nº de Scouts de España o territorial) |
| [28-menus-tema-colores-idiomas.md](28-menus-tema-colores-idiomas.md) | Menús por rol, independencia del tema, colores e idiomas |
| [30-plan-carpeta-drive.md](30-plan-carpeta-drive.md) | **Plan aparcado (sin código):** ventana a una carpeta de Google Drive |
| [31-enlace-alta-scouters.md](31-enlace-alta-scouters.md) | Enlace de alta para scouters |
| [32-inventario.md](32-inventario.md) | Inventario de material, listas de compra e importación |
| [34-plan-accion-altas-membresias.md](34-plan-accion-altas-membresias.md) | Altas, bajas y membresías en el CRM |
| [35-ayuda-en-cada-pagina.md](35-ayuda-en-cada-pagina.md) | Ayuda de cada página según el rol |
| [39-lista-de-espera.md](39-lista-de-espera.md) | Lista de espera |
| [40-mapa-de-la-aplicacion.md](40-mapa-de-la-aplicacion.md) | **Mapa de la aplicación:** mapa mental, flujo de una petición, dónde vive cada dato, qué tocar para cada cambio e inventario generado |
| [43-modulo-eventos.md](43-modulo-eventos.md) | Módulo de eventos: equipo, presupuesto, inscripciones, cuentas, devoluciones, SEPA |
| [46-lista-espera-campos.md](46-lista-espera-campos.md) | Campos propios del formulario de la lista de espera |
| [47-avisos-configurables.md](47-avisos-configurables.md) | Avisos y normativa de los eventos configurables por entidad |
| [48-modulo-tienda.md](48-modulo-tienda.md) | **Tienda** (estado actual): productos, compra con y sin sesión, pagos, datáfono, pedido al proveedor, enlace en el menú |
| [49-campamentos-y-menus.md](49-campamentos-y-menus.md) | Calendario de tareas, recetario y menús de los campamentos |
| [50-plan-modo-oficina-y-alta-de-grupos.md](50-plan-modo-oficina-y-alta-de-grupos.md) | **Modo Oficina** (estado actual): credencial, rol de solo lectura, invitación y alta de grupos |
| [53-modulo-reintegros.md](53-modulo-reintegros.md) | Reintegros de gastos de los scouters |
| [54-pantallas-en-una-linea.md](54-pantallas-en-una-linea.md) | Regla de pantallas: filtros y acciones en una línea |
| [55-tienda-sobre-el-inventario.md](55-tienda-sobre-el-inventario.md) | La tienda toma sus existencias del inventario general |
| [56-gestion-de-accesos.md](56-gestion-de-accesos.md) | Gestión de accesos: permisos por rol, permisos sueltos por persona (con caducidad) e historial |
| [pwa/README.md](pwa/README.md) | La app móvil (PWA) es un plugin aparte, `asde-area-privada-app`, en su propio repo |

## Qué hay (v0.51)

**Base:** acceso con nº de socio o email, por grupo; roles Scouter, Secretaría, Coordinación, Tesorería, Intendente, tutor/a y participante; fichas editables en el CRM con cola de cambios; personas y números de socio; usuarios, invitaciones y solicitudes de acceso; informes en Excel e impresión; ayuda por página y cinco manuales; idiomas ca/eu/gl; colores y tema configurables; módulos que cada grupo enciende o apaga.

**Tesorería:** familias y cuotas por ronda, control de pagos, banco (Norma 43/CSV, conciliación y revisados con tipo, evento y familia), domiciliación SEPA y coste federativo.

**Tienda y material:** inventario general con listas de compra e importación; tienda con catálogo, tienda pública, datáfono, pedido al proveedor y existencias del inventario; reintegros de gastos.

**Eventos:** eventos con equipo y presupuesto, inscripciones, cuentas y devoluciones, material, aviso opcional al publicar, calendario de tareas, recetario y menús con alérgenos; lista de espera.

**Altas en el CRM** (apagadas por defecto): crear personas y membresías, bajas y cambios de sección.

**Modo Oficina:** instalación de la federación que consulta los grupos en solo lectura y los da de alta por invitación.

## Pendiente

- Capturas de los manuales para lo último (catálogo con talla, pedido de camisas, datáfono, Reintegros, modo Oficina).
- Enlace para que un grupo ya existente cambie su credencial (P-191) y probar el modo Oficina con el CRM real.
- Limpieza del tipo «Camisa uniforme» y sus funciones en el inventario (ya sin uso).
- Producción: diagnóstico de tareas en segundo plano, email de contacto de cada grupo y datos reales del acreedor SEPA (doc 09).
