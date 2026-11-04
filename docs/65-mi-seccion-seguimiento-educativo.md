# 65 · «Mi sección»: seguimiento educativo (implementado en la v0.63.0)

Estado (2026-11-04): **implementado en la v0.63.0** con el diseño v2 y las decisiones del final de este documento (módulo `mi_seccion`, apagado por defecto). Clases: `Seguimiento\Catalogo` (+ `data/progresion.json`), `Seguimiento\Revisiones` (tabla `asde_seguimiento`, solo se añaden filas), `Frontend\Views\MiSeccionView`, plantillas `templates/mi-seccion/`; capabilities `asde_seguimiento` (scouters y Coordinación) y `asde_seguimiento_editar` (scouters); prueba `tests/mi-seccion.php`. **Pendiente**: la cuadrícula editable del mock-up v2 (la v0.63 trae lista con un cuadradito por aspecto y ficha), captura del manual y consulta de los 166 objetivos de los compromisos (`docs/programa-educativo/catalogo.json`).

- Mock-up: [`docs/mockups/seguimiento-educativo.html`](mockups/seguimiento-educativo.html) (datos inventados; los ámbitos y objetivos son **marcadores provisionales**).
- Petición del usuario (2026-11-04): una sección nueva (**no un módulo**), solo para scouters con sección asignada.

## Qué se pidió

1. Al entrar, el scouter ve el **listado de los asociados de su sección**.
2. Cada fila indica, **calculado por edad**, **en qué año de la sección está**. Ejemplos del usuario: con 8 años puede ser 3.º de Castores o 1.º de Lobatos; con 12, 2.º de Scouts.
3. Al seleccionar al asociado se abre una **ventana** con **una pestaña por ámbito**. Cada pestaña es un cuadro para **seguir los objetivos del programa educativo de Scouts de España**.
4. Antes del diseño final, **mock-up en HTML**.

## Bloqueo: el PDF

El PDF del programa educativo está en `C:\Claude\Scouts De Balears  - Area Privada\` del equipo del usuario. La sesión en la nube no lo ve y no tenía la habilidad `convert_pdf_to_markdown`. **No se han inventado ámbitos ni objetivos.**

Para continuar hace falta una de estas tres cosas:
- Pasar el PDF por `convert_pdf_to_markdown` en el equipo local y usar ese Markdown.
- Subir el PDF al repo (privado), si el usuario lo acepta.
- Pegar el texto de los ámbitos y objetivos.

## Lo que enseña el mock-up

- **Listado «Mi sección · Lobatos»**: buscador (nombre o nº de socio), filtro por año, y columnas Nombre, Edad, Año en la sección (con tres puntos) y Progreso (barra y %). Fila entera clicable. Sin desbordes a 390 px: tabla en tarjetas.
- **Ventana del asociado**: cabecera (nombre, chip de sección, edad, año, nº de socio); resumen de conseguidos, en curso y sin empezar; pestañas por ámbito con contador `conseguidos/total`; por objetivo, estado, fecha y nota opcional; botón «Guardar seguimiento».
- **Estado de cada objetivo**: un clic lo cambia (sin empezar → en curso → conseguido).
- **Filtro de la pestaña**: «Todos los años» o «Este año». Cada objetivo lleva un «año recomendado» (lo que sea que diga el PDF).

## Cálculo del año por edad (propuesta)

Año = edad − edad de entrada + 1, con los rangos de `Domain\Secciones` (`EDAD_DESDE`, `Secciones::por_edad()` y la edad que se cumple en la ronda, como en la lista de espera, P-123):

| Sección | Rango | Años |
|---|---|---|
| Castores | 6–8 | 6, 7, 8 → 1.º, 2.º, 3.º |
| Lobatos | 8–11 | 8, 9, 10 → 1.º, 2.º, 3.º |
| Scouts | 11–14 | 11, 12, 13 → 1.º, 2.º, 3.º |
| Escultas | 14–17 | 14, 15, 16 → 1.º, 2.º, 3.º |
| Rovers | 17–21 | 17–20 → 1.º a 4.º |

- **Edades de borde** (8 en Castores/Lobatos, 11, 14, 17): manda la **sección asignada en el CRM**, no solo la edad. El año se calcula dentro de esa sección.
- Edad fuera del rango de su sección (adelantado o retrasado): se muestra el año acotado (1.º a último) con una marca, para que no salga 0 ni 4.º en una sección de tres años.

## Decisiones pendientes (para el usuario)

1. **Estados del seguimiento**: ¿los tres del mock-up u otra escala (p. ej. con fecha de logro y «propuesto por»)?
2. **Objetivos por año**: ¿el PDF los asigna a un año concreto de la sección o son para toda ella? Condiciona el filtro y la columna «año recomendado».
3. **Quién ve el seguimiento**: propuesta, cada scouter ve **solo su sección**. ¿Puede verlo Coordinación? ¿Se ve entre scouters de la misma sección?
4. **Notas de texto libre**: pueden contener datos personales. Propuesta: guardarlas **cifradas** (`Crypto`) y solo ligadas al nº de socio.
5. **Histórico**: ¿al pasar de año o de sección se conserva el seguimiento anterior? Propuesta: sí, por nº de socio y objetivo, sin borrarlo.

## Esbozo técnico (para cuando se apruebe)

Sin implementar. Sigue las reglas de `CLAUDE.md` y la skill `modulo-nuevo`.

- **Vista y página**: `Frontend/Views/MiSeccionView` (shortcode en `Router::VIEWS`, página en `Pages::DEFINICIONES`), con entrada de menú. Visible solo si el usuario es scouter con sección asignada; si no, mensaje de acceso denegado sin revelar nada. Capability `asde_*` nueva y asignada a los scouters.
- **Lectura**: asociados de la sección con `PersonaRepository` (filtrado por el grupo de seguridad del CRM). Nada de consultas por fila: se sirve de `Instantanea`. La salud y las dietas **no** entran aquí.
- **Ventana**: enlace `data-asde-modal` como el resto de fichas. Con JavaScript cambia de pestaña sin recargar; sin JavaScript funciona con recargas.
- **Datos propios**: tabla `{prefix}asde_seguimiento` (grupo, nº de socio, objetivo, estado, fecha, quién, nota cifrada) y el catálogo de ámbitos y objetivos, **generado a partir del PDF** (fichero de datos en el plugin, no editable por el usuario en la primera versión).
- **Acciones**: guardado con `current_user_can`, nonce, saneado y `$wpdb->prepare` con `%i`. Auditoría con `Logger::log` (nombres de campo, nunca valores).
- **Interfaz**: tabla paginada (10 filas, 5 en móvil; `FrontendTabla` o `data-asde-paginar`), filtros en una línea, móvil primero.
- **Textos**: en inglés con gettext y dominio del plugin; completar los cinco `.po` (skill `traducir-textos`).
- **Pruebas**: `tests/mi-seccion.php` con el CRM simulado; restaura todo en un `finally`. Tabla, capability y página nuevas tocan `tests/estilo-wordpress.php`, `grids-paginados.php` y los inventarios de capabilities y páginas.
- **Manuales**: añadir el apartado al manual de scouters (`manual/fuentes/`) y regenerar.
- **Versión**: sube el segundo número (nueva funcionalidad). Nace **apagado o sin datos** para no alterar instalaciones anteriores.

## Siguientes pasos

1. El usuario aprueba o corrige el mock-up y contesta las decisiones de arriba.
2. Se obtiene el texto del PDF (en local, `convert_pdf_to_markdown`).
3. Se convierte el programa educativo en el catálogo de ámbitos y objetivos.
4. Se actualiza el mock-up con los ámbitos reales y se vuelve a aprobar.
5. Se implementa, con pruebas, traducciones y manual.


## Catálogo del PDF (2026-11-04)

Transcrito en `docs/programa-educativo/catalogo.json` (datos) y `catalogo.md` (lectura): 4 compromisos (social, ambiental, salud, espiritual) × 5 secciones, 166 objetivos con contenidos e indicadores. Hallazgos que cambian el diseño:

- Los «ámbitos» son los 4 compromisos; las pestañas del mock-up pasan a ser 4.
- El PDF asigna objetivos por **sección**, no por año: no existe «año recomendado». El filtro «Este año» y esa columna carecen de base salvo que se decidan aparte.
- Unidad de seguimiento posible: el objetivo (o su indicador de evaluación).
- Pendiente: revisar la transcripción (aviso de la p. 59) y leer pp. 88-100 (progresión y evaluación) para la escala de estados.

## Propuesta v2 (2026-11-04, tras leer todo el programa)

Mock-up: `docs/mockups/seguimiento-educativo-v2.html`. Transcripción completa del PDF: `docs/programa-educativo/programa-educativo.md`.

- **Qué se hace seguimiento**: la **progresión personal** (pp. 88-100): por sección, 8-9 aspectos (relaciones, marco simbólico, proyecto, naturaleza, objetivos personales, compromisos educativos, autonomía/vida de patrulla/unidad/servicio, valores) × 3 etapas (Integración, Participación, Animación), cada celda con su indicador literal. Los 166 objetivos de los compromisos quedan como **consulta** (el propio programa dice que las guías largas no se usaron, p. 88).
- **Principios del programa que condicionan la interfaz** (p. 89): protagonizada por la persona, formativa sin comparar ni meritocracia, flexible (pasar de etapa no exige cumplirlo todo), continua (revisión al menos trimestral). Por eso: sin rankings, orden alfabético, estados «Aún no / En camino / Lo logra», propuesta de cambio de etapa en lugar de cálculo automático, distintivo de etapa entregado al inicio de la etapa (p. 91).
- **Pantallas**: listado + cuadrícula de la sección (un clic cambia un aspecto), ficha con las tres etapas, objetivo personal (rastro/cola/especialidad/PAI) y notas cifradas.
- **Edad**: tramos 6-8, 8-11, 11-14, 14-17, 17-21, flexibilizables por la maduración (p. 82): el año calculado por edad es solo orientativo.

## Decisiones del usuario (2026-11-04)

1. **Qué se sigue**: la progresión personal (8-9 aspectos × 3 etapas); los 166 objetivos solo como consulta.
2. **Quién ve**: los scouters de esa sección ven y editan su sección; Coordinación solo lee.
3. **Histórico**: se conserva siempre, por nº de socio y con fecha de cada revisión; no se borra.
4. **Notas**: entran en la primera versión, cifradas (Crypto) y ligadas al nº de socio.
