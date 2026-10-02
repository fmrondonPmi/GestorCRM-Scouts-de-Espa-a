# 20 · Organización federada y coste federativo configurable (v0.9)

## Petición del usuario (2026-09-27)

- Una pantalla para configurar **todo lo que es propio de ASDE Scouts de Balears**, para que lo pueda usar cualquier organización federada de Scouts de España.
- Se identifica la organización con un **desplegable** con las organizaciones federadas de https://scout.es/quienes-somos/organizaciones-federadas/.
- El **número de la organización** que va en el nº de socio local: de momento, **configurable**. Más adelante el usuario dirá de dónde sale el de cada una (P-34).
- **Los textos que identifican a la organización salen del desplegable.**
- La **hora no la gestiona el plugin**: es un ajuste de WordPress (*Ajustes → Generales*). Se quitó de «Instalación y entorno».
- El **coste federativo**, configurable, con una **gestión de otros conceptos** para que cada organización ajuste el suyo.

## La pantalla

*wp-admin → Grupos Scouts → Organización federada* (solo administradores; `Admin\OrganizacionPage`, opción `asde_ap_organizacion`, sin autoload).

| Campo | Detalle |
|---|---|
| **Organización federada** | Desplegable con las 17 de scout.es (consultado el 2026-09-27), con su territorio. Su nombre es el que sale en los textos |
| **Código en el nº de socio local** | De 1 a 3 cifras, sin empezar por 0. Muestra en vivo un ejemplo con el primer grupo (en Balears, 7 → 76840001, 76840002…) |
| **Coste federativo por defecto** | Cuota asociativa, seguro promedio, **edad de corte** del seguro, primas por categoría y **otros conceptos** (ver más abajo) |

Organizaciones del desplegable (`Domain\Organizacion::FEDERADAS`):

| | | |
|---|---|---|
| Scouts de Andalucía | Scouts de Aragón | Exploradores del Principado de Asturias |
| **Scouts de Balears** | Scouts-Exploradores de Canarias | Scouts de Cantabria |
| Exploradores de Castilla y León | Scouts de Castilla-La Mancha | Scouts de Ceuta |
| Euskadiko Esploratzaileak | Scouts de Extremadura | Scouts de Galicia |
| Scouts de La Rioja | Exploradores de Madrid | Scouts de Melilla |
| Exploradores de Murcia | Scouts Valencians | |

## Qué depende de la organización

- **Textos:**
  - pantalla de acceso: «Área privada de los grupos de Scouts de Balears.» (sin organización: «Área privada de los Grupos Scouts.»);
  - coste federativo de la Tesorería: «Lo que el grupo paga a Scouts de Balears…»;
  - ejemplos de nº de socio/a en el acceso y en recuperar contraseña (se calculan con el código y el primer grupo).
- **Números de socio** (`Domain\NumeroSocio`): código + nº de grupo + 4 cifras para normalizar los del CRM, generar los nuevos y reconocer el grupo de un usuario por su nº. **Sin código configurado no se generan números** (el alta lo dice) y la numeración no normaliza nada.
- **Coste federativo por defecto** de cada ronda de la Tesorería (abajo).
- «Instalación y entorno» comprueba que la organización está elegida (pieza «Organización federada») y, si no, lo pone como siguiente paso (doc 19).

## Cambiar el código

- Si ya hay grupos, **cambiar el código pide marcar una casilla de confirmación**: los números del CRM con el código anterior dejarán de reconocerse como del grupo.
- Al guardar se vacía la caché de la numeración de cada grupo y se registra `organizacion_guardada` en la auditoría (con `codigo_cambiado`).
- Los usuarios ya creados siguen entrando: su usuario de WordPress no cambia y su grupo está guardado en el alta. También pueden entrar con su email (doc 15).

## Coste federativo

**Con lo que empieza cada ronda:** la Tesorería de cada grupo empieza cada ronda con el coste federativo de la organización y lo puede ajustar en *Tesorería → Coste federativo* (Secretaría, Coordinación y Tesorería).

- Una ronda que ya guardó su coste federativo conserva sus importes aunque la organización cambie los suyos (y puede quitar todos los conceptos).
- Las rondas guardadas antes de la v0.9 conservan cuota y primas; los otros conceptos, que entonces no existían, los toman de la organización.

| Concepto | Cómo se calcula |
|---|---|
| Cuota asociativa | Por asociado con membresía activa |
| Seguro por tabla | Prima de cada categoría × asociados de esa categoría: menores de la **edad de corte**, educandos de esa edad o más, adultos (scouters y voluntariado) |
| Seguro promedio | Importe medio × asociados (alternativa a la tabla) |
| **Otros conceptos** | Nombre + importe + a quién se aplica. Hasta 20 |

- **A quién se aplica cada concepto:** cada asociado, cada educando (Castores a Rovers), cada adulto (scouters y voluntariado) o una vez por grupo.
- **Importe negativo = descuento** (p. ej. una subvención de la organización).
- Los importes admiten coma decimal («2,50»).
- **Totales:** con la tabla = cuota + seguro por tabla + otros conceptos; con el promedio = cuota + seguro promedio + otros conceptos.
- La **edad de corte** (14 en Balears) cambia los nombres de las categorías: «Menores de 14 años», «Educandos de 14 años o más».
- El editor de conceptos es el mismo en la pantalla de la organización y en la Tesorería (`templates/comun/conceptos.php` + `assets/js/conceptos.js`, botón «Añadir concepto»).

## Instalaciones anteriores y WordPress nuevos

- **Al actualizar desde la v0.8 o antes** (sitio con grupos): se guarda sola la organización **Scouts de Balears**, código **7**, con los valores de siempre: cuota 17 €, seguro promedio 3 €, edad de corte 14, primas 1,92 / 2,44 / 6,06 € y sin otros conceptos. No cambia nada para el grupo.
- **En un WordPress nuevo** no se elige nada: lo elige quien lo instala. Hasta entonces, «Instalación y entorno» lo avisa y no se generan números de socio.
- Las rondas guardadas con los nombres antiguos de las primas (`menores14`, `mayores14`) se leen igual.

## Pruebas (2026-09-27)

`tests/organizacion.php`, **28 comprobaciones, TODO OK**: lista de organizaciones, código válido, textos, números con otro código, migración desde la v0.8, WordPress nuevo sin organización (simulado con un filtro, sin borrar nada), coste federativo con otra edad de corte y con otros conceptos (por asociado, educando, adulto, grupo y descuento), y la Tesorería de una ronda. Deja la configuración como estaba.

## Pendiente

- **P-34:** de dónde sale el código de cada organización federada para el nº de socio local (el usuario dará la fuente). De momento se escribe a mano.

## Usuario de acceso (v0.14, doc 27)

En la misma pantalla, **«Usuario de acceso»**: nº de socio/a de Scouts de España (recomendado) o nº local/territorial. Al guardar, las cuentas **nuevas** usan el elegido; para pasar las existentes está **«Renombrar las cuentas existentes»**, con la lista de lo que cambia, de lo que no se puede y por qué, y una casilla de confirmación (se cierran las sesiones de esas cuentas). Una instalación anterior a la v0.14 se queda en nº local hasta que se elija.
