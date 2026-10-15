# 44 · Lote de la v0.35.1 (2026-10-15)

Cinco peticiones del usuario en la misma sesión, después de integrar el módulo de eventos (v0.35.0, doc 43). Sin tablas nuevas; una opción nueva por grupo (`asde_ap_eliminadas_<grupo>`).

## 1. Familias: «Eliminar» si no tiene socios

**Petición:** «En el módulo de familias, añadir un eliminar familias si no tiene ningún asociado dentro».

- Ya se podía, pero solo dentro de la familia («Editar» → abajo, «Borrar esta familia»). Ahora la lista de Familias tiene **«Eliminar»** en la fila de cada familia sin socios (con confirmación), y dentro se llama «Eliminar esta familia».
- **Supuesto P-155:** además de no tener socios, no tiene que tener nada que cuelgue de ella, que se quedaría sin familia: **pagos** (como antes), **autorizaciones de domiciliación** y **movimientos del banco asignados**. Si los tiene, la fila dice por qué no se puede (`FamiliasTable::por_que_no_borrar()`).
- Pruebas: `mejoras-v0351.php` parte D.

## 2. Verificación en dos pasos: opcional para todos

**Petición:** «Marcar a todos los roles que el uso de doble factor es opcional».

- `Wordfence::configurar()` pasa a la versión 3: **opcional** para Scouter, Secretaría, Coordinación y Tesorería (antes, obligatoria con 10 días de margen) y, como ya era, para familias y participantes. Se aplica **una vez** al actualizar; después, el administrador la puede volver a hacer obligatoria para algún rol en Wordfence, y el inicio y «Seguridad» lo siguen avisando.
- «Instalación y entorno» lo dice así. Los manuales de scouters y de coordinación usan ya el apartado «Seguridad» de las familias (opcional, recomendada si ves datos de otras personas).
- Wordfence guarda «obligatoria» como la fecha desde la que lo es y «permitida» como una capability del rol (`wf2fa_activate_2fa_self`): `get( 'enabled-roles.<rol>' )` devuelve siempre `false`. La prueba lo lee como Wordfence (y deja el sitio como estaba).
- Pruebas: `mejoras-v0351.php` parte E.

## 3. «Acceso socios»: el código, plegado

**Petición:** «En la pantalla de login contraer la sección de doble factor».

- «Código de verificación» va en un desplegable cerrado. Si Wordfence pide el código (contraseña bien, falta el código), sale **abierto, destacado y obligatorio**, como antes.
- Capturas rehechas: 01, 26 (móvil) y 61 (con idiomas).
- Pruebas: `mejoras-v0351.php` parte F.

## 4. Bajas hechas en el CRM: se ven ya

**Petición:** «Hay que corregir bien: si se da de baja un usuario en el CRM, poder marcarlo en el nuestro como que está de baja, porque no lo he logrado». Con la salida de `crm-revisar-alta.php` (socio 116491): una membresía de Castores con **baja de hoy, `active=0`**, y la ficha **todavía con «Castores» en «Secciones actuales»**.

- Causa: la web decidía quién tiene membresía activa por «Secciones actuales» (`stic_relationship_type_c`), y el CRM la recalcula **más tarde** que la membresía. La v0.34 (doc 42, §4) arregló la baja sin fecha; esta es la otra cara: la fecha ya está, pero el campo de la ficha aún no.
- Ahora las fichas se leen **con sus membresías** (el enlace `stic_contacts_relationships_contacts` en la misma consulta, sin peticiones de más) y las secciones salen de las **membresías vigentes por sus fechas** (`Altas::activa()`, como el CRM): `PersonaRepository::con_secciones_vigentes()`. Vale para la instantánea, las búsquedas, «Personas» y los informes. Si una ficha no trae ninguna membresía (el grupo no las ve), se deja lo que diga el CRM.
- Al revés también: una membresía nueva que el CRM aún no ha puesto en la ficha ya cuenta.
- La instantánea se refresca cada pocos minutos; «Actualizar ahora», en Personas, lo vuelve a leer.
- Pruebas: `mejoras-v0351.php` partes A y B (con el caso del usuario simulado: baja de hoy y «Secciones actuales» sin recalcular). El CRM simulado (`tests/lib/CrmSimulado.php`) devuelve ya las membresías de cada ficha cuando se piden por el enlace.
- De paso: `mejoras-v034.php` no apagaba la instantánea y, con el CRM simulado, dejaba las fichas inventadas en la del grupo 684 del Local (5 minutos). Ahora la apaga, como las demás pruebas que usan el CRM simulado. Y `menus.php` y `lista-espera.php` contaban con los módulos y las «Altas en el CRM» por defecto en el grupo del Local: con «Eventos» o las altas encendidos (como los tiene ahora el usuario) fallaban; ahora los fijan durante la prueba y los dejan como estaban.

## 5. «Eliminar de la web» a quien no tiene membresía activa

**Petición:** «Poder marcar eliminar a un asociado de nuestro sistema; claro, obligatoriamente no debe tener membresía activa».

- En la ficha de quien **no tiene ninguna membresía activa** (Personas → «Sin membresía activa» → «Ver ficha»), Secretaría y Coordinación tienen **«Eliminar de la web»** (con confirmación). No se ofrece en la ficha propia.
- **Supuesto P-156 (qué hace):** en el **CRM no cambia nada** (la ficha y su historia siguen allí). En la web: deja de salir en Personas, **sale de su familia**, su **cuenta propia se desactiva** (`Usuarios::desactivar()`: roles, contraseña, verificación en dos pasos, llaves) y **deja de estar en las cuentas familiares**; la que se queda sin nadie a quien representar, también se desactiva. Queda en el registro (`persona_eliminada_web`).
- **Supuesto P-157:** si vuelve a tener una membresía activa en el CRM, **vuelve a salir sola** (se le quita la marca) y se le puede dar acceso de nuevo, que le manda una invitación.
- Se guarda en `asde_ap_eliminadas_<grupo>` (nº de Scouts de España → cuándo y quién); `uninstall.php` la borra.
- Pruebas: `mejoras-v0351.php` parte C.

## Para producción

Al actualizar a la 0.35.1 se aplica la configuración nueva de Wordfence (opcional para todos). Si en producción algún rol tenía la verificación obligatoria **a propósito**, hay que volver a ponerla en Wordfence → Login Security → Settings.
