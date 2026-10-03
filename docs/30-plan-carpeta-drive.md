# 30 · Plan: ventana a la carpeta compartida de Google Drive (scouters y voluntariado)

**Petición del usuario (2026-10-03):** «Realiza el plan para hacer, en el área de scouters y voluntarios sin sección, la visualización de una ventana que les lleve a una carpeta compartida de Google Drive.»

**Estado:** plan, sin código. Preguntas abiertas P-56 a P-60 (doc 09).

---

## 1 · Qué se quiere

Que los **adultos del grupo** (scouters y voluntariado, también el «voluntario sin sección» de Tesorería, Secretaría o Coordinación) tengan en el área privada un acceso directo a la **carpeta compartida del grupo en Google Drive** (programaciones, actas, autorizaciones, fotos…). Las familias y los participantes **no** lo ven.

## 2 · Lo que hay que saber de Google Drive

- **La web no da permiso para ver la carpeta:** quién la ve lo decide Google, según cómo esté compartida. El área privada solo enseña el enlace (o la carpeta) a quien debe.
- **Dos formas de compartir:**
  - **Restringida** (solo las cuentas de Google añadidas, o un grupo de Google del grupo scout): la más segura. Cada scouter necesita una cuenta de Google con acceso.
  - **«Cualquiera con el enlace»:** cómoda, pero el enlace funciona fuera de la web si alguien lo copia o lo reenvía. **No es recomendable** si hay datos personales (autorizaciones, fotos de menores).
- **Ver la carpeta dentro de la web:** Google permite incrustar la vista de una carpeta (`https://drive.google.com/embeddedfolderview?id=<id>#list` o `#grid`). Funciona bien con carpetas «con enlace». Con carpetas restringidas depende de que el navegador tenga la sesión de Google y acepte cookies de terceros (Safari y muchos móviles no): a menudo sale vacía o pide entrar. Por eso la ventana lleva **siempre** el botón «Abrir en Google Drive».

## 3 · Propuesta

### 3.1 Qué ve el scouter

- **En el inicio**, bloque «Para ti» o «Gestión del grupo» (P-58): una tarjeta **«Carpeta compartida del grupo»** con el icono de Drive y una línea configurable («Programaciones, actas y documentos del grupo»).
- **En el menú**, dentro de «Gestión del grupo», la entrada **«Carpeta del grupo»** (P-58).
- **La ventana:** al pulsar se abre la **ventana emergente del área** (la misma de las fichas) con:
  - la **carpeta incrustada** (lista de ficheros) si el grupo ha elegido verla dentro de la web;
  - el botón **«Abrir en Google Drive»** (pestaña nueva), que es lo que se usa en el móvil (allí abre la app de Drive);
  - una nota: «Si no ves los ficheros, entra con la cuenta de Google con la que te compartieron la carpeta».
- **Sin JavaScript** (o en el móvil), la tarjeta abre Drive directamente.

### 3.2 Quién lo ve

- **Capability nueva `asde_carpeta_grupo`** para los roles Scouter, Secretaría, Coordinación y Tesorería. Así lo ven los scouters y los voluntarios sin sección (que tienen alguno de esos tres roles) y nunca las familias ni los participantes.
- El **técnico** (doc 29) no lo ve salvo que se decida lo contrario.
- Si el grupo no ha puesto ninguna carpeta, no sale nada.

### 3.3 Dónde se configura

- **Por grupo** (cada grupo tiene su Drive), en el formulario del grupo de *Grupos Scouts → Grupos y conexión CRM* (administrador) **y/o** en una pantalla del área para Secretaría y Coordinación (P-57). Lo más sencillo: un campo más en el grupo, como el email de contacto.
- Campos: **enlace de la carpeta** (se acepta lo que se copia de Drive: `https://drive.google.com/drive/folders/<id>`, con o sin `?usp=sharing`, o `…/drive/u/0/folders/<id>`); **texto de la tarjeta** (opcional); **forma de verla**: «dentro de la web (lista / cuadrícula)» o «solo enlace».
- Se valida que sea una carpeta de `drive.google.com` y se guarda **solo el id** de la carpeta. Nunca se acepta otra dirección: no se puede usar para incrustar otra web.
- **Varias carpetas (P-59):** el diseño admite una lista (p. ej. «General» y una por sección: cada scouter vería la general y las de sus secciones). Se empieza por una sola.

### 3.4 Seguridad

- El iframe solo carga `https://drive.google.com/embeddedfolderview?id=<id>`, con el id validado (letras, números, `-` y `_`), escapado y con `referrerpolicy="no-referrer"`.
- El enlace sale en el HTML solo para quien tiene la capability: no está en ninguna página pública ni en la de las familias.
- La pantalla de configuración avisa de que la carpeta debe estar **compartida solo con los scouters** (restringida o con un grupo de Google) y de que «cualquiera con el enlace» la deja abierta a quien tenga el enlace.

## 4 · Plan de trabajo

| Paso | Qué | Dónde | Pruebas |
|---|---|---|---|
| 1 | Capability `asde_carpeta_grupo` en los cuatro roles adultos | `Install\Roles` (se aplica al actualizar) | quién la tiene y quién no |
| 2 | Configuración por grupo: enlace → id validado, texto, modo de vista | `Domain\Grupos` (campo nuevo), formulario del grupo en `Admin\SettingsPage` (y, si se decide, en el área) | enlaces válidos e inválidos (otras webs, ficheros sueltos, `javascript:`), id guardado |
| 3 | Vista nueva `CarpetaView` (`[asde_carpeta]`) con la carpeta incrustada y el botón; página en `Pages` | `Frontend\Views`, `templates/carpeta/`, `Frontend\Pages`, `Router` | familia y participante: «No tienes acceso»; scouter y voluntario sin sección: la ven |
| 4 | Tarjeta del inicio y entrada del menú, con `data-asde-modal` para abrirla en la ventana | `templates/area/inicio.php`, `Frontend\Menu` | sale solo si hay carpeta y capability |
| 5 | CSS de la ventana con el iframe (alto completo, móvil) | `assets/css/area-privada.css` (bloque v0.16) | revisión a 390 px |
| 6 | Textos en los cuatro idiomas; manuales de scouters, coordinación y administración; docs | `languages/`, `manual/fuentes/` | `idiomas-catalogos.php` |

**Tamaño:** pequeño (unos días); puede ir en la misma versión que el doc 29 o antes, porque no depende de él.

## 5 · Preguntas abiertas (en el doc 09)

- **P-56** ¿La carpeta se ve dentro de la web (incrustada) o basta con el enlace a Drive?
- **P-57** ¿Quién pone el enlace: el administrador de la web, o también Secretaría/Coordinación desde el área?
- **P-58** ¿Dónde va: tarjeta en el inicio, entrada en el menú o las dos? ¿En «Para ti» o en «Gestión del grupo»?
- **P-59** ¿Una carpeta por grupo o también una por sección (cada scouter, la de las suyas)?
- **P-60** ¿Cómo comparte el grupo la carpeta (restringida a cuentas de Google o «con enlace»)? Es lo que decide si se ve dentro de la web.
