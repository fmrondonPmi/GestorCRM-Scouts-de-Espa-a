# 26 · Rendimiento: estudio de la lentitud y su solución (v0.12.3)

> **Petición del usuario (2026-09-30):** «la página va lenta; en el Chrome que tengo abierto tienes una sesión de administrador: haz un estudio de cada página y de todos los flujos, encuentra los cuellos de botella y genera una solución que haga la navegación más fluida».
>
> **Resumen:** el cuello de botella **no es WordPress ni el plugin**: es el **CRM**. Cada consulta tarda de 0,5 a 1,5 s (el viaje de ida y vuelta, da igual lo que se pida) y cada página del área hacía de **1 a 6 consultas seguidas**. La solución (v0.12.3) es leer las fichas del grupo **una vez** y servirlas de memoria unos minutos: casi todas las páginas pasan a **0 consultas** al CRM. Además, la sesión del CRM se renueva mientras se usa y hay un aviso de carga al navegar.

## 1. Cómo se midió

| Qué | Cómo |
|---|---|
| **Producción** (https://baleares.scout.es, sesión de administrador en el Chrome del usuario) | `fetch()` de cada página desde la propia pestaña, sin caché, midiendo el tiempo hasta recibir la respuesta; repetido 3 veces para separar lo frío de lo caliente. Tiempos de carga, peticiones y tamaños con la API de rendimiento del navegador. **Solo lecturas**; no se pulsó ni se cambió nada. |
| **El CRM** (desde el Local, con la conexión real del grupo 684, solo lecturas, sin imprimir datos) | Cada llamada cronometrada: inicio de sesión, una ficha, 5/20/50/100 fichas, todas las activas, todas. Variantes con y sin enlaces, con menos campos, por id… |
| **Lo que hace el plugin por dentro** | Las 36 pantallas renderizadas con el CRM simulado, contando **consultas a la base de datos, llamadas al CRM y milisegundos de PHP**, en frío (cachés vacías) y en caliente. |

Los scripts están en `herramientas-desarrollo/` (`rendimiento-crm.php`, `rendimiento-crm-variantes.php` y `rendimiento-vistas.php`; los dos primeros solo leen del CRM real y no imprimen datos, el tercero usa el CRM simulado).

## 2. Resultados

### 2.1 Páginas de producción (antes de la v0.12.3)

Tiempo hasta recibir la página (ms). «Frío» = la primera vez; «normal» = las siguientes.

| Página | Frío | Normal | Qué hace |
|---|---:|---:|---|
| Informes, Seguridad, Mis datos, Mis pagos, Alta, Cuotas, Uniformes | 500–650 | 500–650 | Solo base de datos: **este es el suelo** del sitio (WordPress + Enfold + plugins) |
| Inicio | 1 190 | ~1 200 | 1–2 consultas al CRM |
| Registro de emails | 1 930 | 1 150 | 1–2 |
| Solicitudes | 5 370 | 1 200–2 100 | 2 |
| Cambios pendientes | 2 520 | 1 650–1 850 | 2–3 |
| Usuarios | 3 430 | 2 000 | 2 |
| Familias / Coste federativo | 2 650 | 2 650 | 2 (todas las fichas activas) |
| Tesorería | 2 000 | **3 700–3 900** | 3 |
| **Personas** | **6 410** | **3 800** | lee **todas** las fichas (382) en 2 tandas + numeración |
| Ficha (ventana) | 6 470 | 1 470 | 1.ª vez: lee todo el grupo para la numeración |
| Dar acceso (ventana) | 4 580 | 3 630 | numeración + búsqueda de hermanos + ficha |

El tiempo **varía mucho** de una vez a otra (Solicitudes: 2,7 → 2,1 → 1,2 s sin cambiar nada): es el CRM, que no responde siempre igual.

### 2.2 El CRM (conexión real del grupo 684)

| Consulta | Tiempo |
|---|---:|
| Iniciar sesión | ~0,9 s |
| **Cualquier consulta mínima** (un módulo pequeño, 5 filas) | **~0,42 s** |
| 1 ficha | 0,45 s (hasta 1,3 s en otros momentos) |
| 50 fichas de golpe | 0,6 s |
| Todas las activas (104) | 0,63–0,7 s (hasta 2 s en frío) |
| Todas (382) | 1,85 s (2 tandas de 200: 0,9 + 0,9) |

- **Hay un coste fijo de ~0,4–0,5 s por llamada**, más ~4 ms por ficha. El tamaño no importa tanto como **cuántas llamadas seguidas** hay.
- Pedir menos campos, quitar el enlace a los grupos de seguridad, buscar por id en vez de por nº… **no cambia nada** (probado): no hay nada que «afinar» en la consulta.
- Pedir las fichas de 500 en 500 en vez de 200 en 200 **tampoco ayuda** (la 1.ª llamada de 382 tarda lo mismo que dos de 200).

### 2.3 Lo que hace el plugin (CRM simulado, sin latencia)

- **PHP + base de datos: 10–70 ms por página y 20–37 consultas SQL.** Despreciable: no hay consultas lentas ni bucles que arreglar.
- **Llamadas al CRM por página** (en frío / en caliente): Inicio 1/1 · Usuarios 2/2 · Personas 3/2 · Tesorería 3/3 · Coste federativo 3/3 · Solicitudes 2/2 · Cambios 3/2 · Correos 2/2 · **Ficha 6/4** · Dar acceso 4/3 · Detalle de solicitud 4/3 · Mis pagos 2 (**dos veces la misma consulta**) · Detalle de pedido de uniforme 2 (la misma dos veces).
- Las causas de tantas llamadas:
  1. **En cada página**, para cualquier persona con ficha, se consulta su ficha para saber quién es y si puede entrar (1 llamada).
  2. Cada vista vuelve a pedir lo suyo (la lista de fichas, sus familiares…), sin reutilizar lo de las demás.
  3. La **numeración** lee todas las fichas del grupo (2 tandas, ~4 s) y se guardaba 10 minutos: **cada 10 minutos, quien abre una ficha o «Dar acceso» espera 4–5 s de más**.
  4. La sesión del CRM caducaba a los 15 minutos **aunque se estuviera usando**: cada 15 minutos alguien esperaba además ~1 s de inicio de sesión.
  5. Por diseño **no se guardaba nada** de las fichas entre una petición y la siguiente.

### 2.4 La parte del navegador (la web, no el plugin)

- **152 peticiones** por página: **71 hojas de estilo y 67 scripts**, de ellos **113 son de Enfold sin unir ni comprimir** (63 CSS + 50 JS) y 117 bloquean el pintado. La primera visita es la que lo paga; luego van de la caché del navegador.
- El HTML pesa 265 KB (180 KB son scripts dentro de la página, sobre todo el del aviso de cookies).
- **WP Statistics** manda una petición propia a cada página (0,5 s, sin bloquear) también en el área privada.
- El plugin del área es un 1 CSS + 5 JS: no es el problema.
- El servidor es un LiteSpeed de Hostinger (`server: hws`) con compresión Brotli. Caché de página: correctamente **desactivada** en el área privada.

## 3. La solución (v0.12.3)

### 3.1 Instantánea cifrada de las fichas (la mejora grande)

`Crm\Instantanea` + `PersonaRepository`: **las fichas del grupo se leen una vez y se sirven de memoria**.

- `todas()`, `obtener()` y `obtener_varias()` (todo lo que usan Personas, Usuarios, Tesorería, Familias, Coste federativo, Cambios, Solicitudes, Correos, Mis pagos, Uniformes, SEPA, y la comprobación de quién eres en **cada** página) ya **no llaman al CRM**.
- Siguen yendo al CRM en cada consulta: **salud y dietas** (nunca se guardan), las **búsquedas** (nombre, email, documento, fecha) y **los informes**.
- **Qué se guarda:** solo la lista blanca de campos (nombre, nacimiento, email, móvil, secciones, cargo y tutores); **cifrado** con libsodium y comprimido (41 KB con las 382 fichas), en un transient que caduca solo. Es lo que prevé el doc 08 punto 3 («caché corta para lo no sensible»); los datos de salud, como siempre, no.
- **Frescura:** 5 minutos. Pasados, se sigue sirviendo la antigua mientras **se vuelve a leer en segundo plano** (WP-Cron), así nadie espera; a los 10 minutos ya no se sirve (si el cron no funcionara, una visita paga la lectura de ~2–4 s cada 10 minutos, en vez de cada página).
- **Lo que se guarda desde la web** (editar una ficha, aprobar un cambio, corregir un número) se aplica también a la instantánea **al momento**.
- **Fichas nuevas:** si un nº no está en la instantánea (alguien recién dado de alta en el CRM) se pregunta al CRM, así nadie se queda sin entrar.
- **Lo que cambia para el usuario:** lo que se edite directamente en el CRM (por otro lado) aparece en la web **en unos minutos**, no al instante. Una **baja** tarda también unos minutos en quitar el acceso. **Personas** y **Usuarios** tienen «Actualizar ahora».
- **Comprobado con los datos reales del Local:** la instantánea da **exactamente las mismas fichas** que el CRM en vivo (382 todas / 104 activas), y leer de ella tarda **0,2 ms** una ficha, **2 ms** 50 fichas y **23 ms** las 382.
- **Ajustes:** filtro `asde_ap_instantanea_segundos` (por defecto 300; **0 la desactiva** y se lee siempre del CRM, como antes).

### 3.2 Sesión del CRM que se renueva mientras se usa

Antes: 15 minutos desde el inicio de sesión, aunque se usara. Ahora se alarga cada vez que se usa (como mucho cada 5 minutos): se acabaron los ~1 s de inicio de sesión cada 15 minutos. Si el CRM la cerrase antes, ya se reintentaba sola.

### 3.3 Aviso de carga

Al pulsar un enlace o enviar un formulario del área aparece una **barra arriba** y el cursor pasa a «cargando». No acorta la espera, pero la página deja de parecer colgada en las pocas que aún tardan (informes, búsquedas). Respeta «reducir movimiento».

### 3.4 Lo que ya se había hecho

El listado de usuarios (v0.12.2) filtra, ordena y pagina en el navegador con los datos en memoria, y sus nombres salen ahora de esta instantánea (se quitó su caché propia, que guardaba los nombres sin cifrar).

## 4. Qué se espera (estimación; se comprueba con el mismo script)

| Página | Antes (normal) | Después (estimado) |
|---|---:|---:|
| Inicio, Emails, Solicitudes, Cambios | 1 200–1 850 | ~550–650 |
| Usuarios | 2 000 | ~600 |
| Tesorería, Familias, Coste federativo | 2 650–3 900 | ~600–700 |
| Personas | 3 800 | ~700 (la 1.ª tras 10 min sin uso: ~4 000) |
| Ficha (ventana) | 1 470 (6 470 cada 10 min) | ~1 000–1 200 (1 consulta: la salud) |
| Dar acceso (ventana) | 3 630 | ~1 500 (1 consulta: hermanos) |
| Informes (ejecutar) | 1 500–2 600 | sin cambios (lista en vivo, con salud) |

El **suelo de ~0,5 s** es el del propio WordPress + Enfold + plugins; queda la mejora de la web (apartado 5).

## 5. Lo que queda y no es del plugin (decisión del dueño de la web)

No se ha tocado nada de la web en uso. Son ajustes del sitio, por orden de provecho:

1. **Enfold → Theme Options → Performance:** activar **«unir y comprimir los archivos CSS y JS»** (113 ficheros → unos pocos). Es lo que más ayuda a la **primera visita** y al móvil. Probar después todas las páginas.
2. **WP Statistics:** excluir `/nuestro-grupo-scout/area-privada/*` (quita una petición de 0,5 s por página y, de paso, no cuenta visitas de un área privada).
3. **Caché de objetos persistente** (Redis o el de LiteSpeed Cache, si el plan de Hostinger lo incluye): los transients (sesión del CRM, instantánea, numeración) dejan de pasar por la base de datos. El suelo de ~0,5 s podría bajar algo.
4. **WP-Cron:** comprobar que funciona (Hostinger lo suele lanzar con las visitas). Si no, la instantánea se renueva en la visita siguiente a los 10 minutos.
5. **Wordfence:** el análisis en vivo («Live Traffic»/escaneos) suma tiempo a cada petición de un administrador; revisar su configuración.

## 6. Cómo comprobar el resultado tras subir la 0.12.3

En la consola del navegador (F12), con la sesión iniciada, en cualquier página del área:

```js
const base = '/nuestro-grupo-scout/area-privada/';
for (const p of ['', 'personas/', 'usuarios/', 'solicitudes/', 'cambios-pendientes/', 'tesoreria/', 'tesoreria/familias/', 'informes/', 'informes/']) {
  const t0 = performance.now(); const r = await fetch(base + p, {cache: 'no-store'}); await r.text();
  console.log((p || '(inicio)') + ': ' + Math.round(performance.now() - t0) + ' ms');
}
```

Repetirlo 2 o 3 veces (la primera llena la instantánea). Informes es la referencia del «suelo».

## 7. Decisiones y riesgos

- **Privacidad:** se guardan **cifradas y unos minutos** fichas sin datos de salud. Es lo que permite el doc 08 (punto 3), pero rectifica el comentario antiguo de `PersonaRepository` («nada se cachea»). Quien no lo quiera: filtro a 0 y todo vuelve a leerse en vivo (con la lentitud de antes).
- **Desfase de unos minutos** con lo que se edite en el CRM por otro lado; una baja tarda también en quitar el acceso. «Actualizar ahora» lo fuerza.
- **Un visitante cada 10 minutos** (sin cron) o la 1.ª carga tras un rato sin actividad paga la lectura de todo el grupo (~2–4 s, como antes en cada página).
- **Las búsquedas** (Dar acceso, hermanos) siguen siendo 1 consulta de ~0,5–1,5 s: hacen falta porque buscan por documento o por todos los emails.

## 8. Pruebas

- `tests/rendimiento.php` (35): la instantánea no llama al CRM al leer, da **lo mismo que el CRM en vivo** (fichas, orden, activas, tutores, secciones), va **cifrada y sin salud**, pasa de fresca a antigua (con refresco en segundo plano) y a caducada, encuentra fichas nuevas, se actualiza con lo que se guarda desde la web, se puede desactivar, con el CRM caído avisa, la comprobación de acceso de cada página ya no llama al CRM y **la sesión del CRM se renueva** sin guardarse en cada llamada.
- En las demás pruebas la instantánea está **desactivada** a propósito (así no tapa lo que comprueban); solo esta la activa.
- **608 comprobaciones en 11 ficheros**, todas OK.

## Ajustes de la v0.14.1 (revisión del código)

- **Cerrojo:** al caducar la instantánea, una sola petición lee el CRM (`Instantanea::tomar_cerrojo`); las que llegan a la vez esperan hasta 8 s su resultado y, si no aparece, leen ellas (nadie se queda sin página). Un cerrojo olvidado se da por perdido a los 60 s.
- **Índice** por nº de Scouts de España (`Instantanea::por_numeros`): `obtener()` y `obtener_varias()` ya no recorren todas las fichas en cada llamada.
- **«Actualizar»** (Usuarios y Personas) va firmado con nonce y no fuerza más de una lectura del CRM cada 30 s (`Instantanea::puede_forzar`); un enlace de otra web ya no puede provocar lecturas completas.

## Cerrojo atómico (2026-10-05; entra después de la v0.20, sin versión propia)

El cerrojo de la v0.14.1 se tomaba con `add_option()`, que **no es atómico**: mira la caché `notoptions` y después hace `INSERT … ON DUPLICATE KEY UPDATE`, así que dos peticiones que llegaban a la vez con la instantánea caducada recibían «hecho» **las dos** y las dos leían el CRM (0,5–1,5 s cada lectura, todo el grupo). Es lo mismo que se arregló en el instalador en la v0.19.0 (doc 32, «Arreglo del instalador»).

- `Instantanea::tomar_cerrojo()` usa ahora el **mismo patrón** que `Installer::tomar_cerrojo()`: `INSERT IGNORE` en `wp_options` (atómico por la clave única de `option_name`; `autoload` `off`). Si ya existe, se lee su hora **sin caché** y, si tiene más de **60 s** (como antes), se recupera con un `UPDATE … WHERE option_name = … AND option_value = <lo leído>` (comparar y cambiar: si otra petición lo recuperó justo antes, este intento no lo toma). Después se borran de la caché la opción y `notoptions`, para que `get_option()` vea el valor real.
- Diferencia con el instalador: el `UPDATE` compara con el **valor leído tal cual** (no con su conversión a número), así un valor raro también se puede recuperar.
- **No cambia el comportamiento:** `soltar_cerrojo()` sigue siendo `delete_option()`, y en `PersonaRepository` quien no toma el cerrojo sigue esperando hasta 8 s el resultado de la otra petición y, si no llega, lee el CRM igualmente (nadie se queda sin página).
- Pruebas: `tests/rendimiento.php`, **parte G** (8 comprobaciones nuevas; 55 en total): libre se toma con la hora y `autoload` `off`; un segundo intento da `false`; también cuando la caché de esa petición dice que el cerrojo no existe (el caso en que `add_option()` daba «hecho» a las dos) y sin pisar la hora de quien lo tiene; soltarlo lo borra y se vuelve a tomar; uno de 30 s sigue valiendo; uno abandonado se recupera y vuelve a bloquear; si otra petición se cuela entre la lectura y el cambio (simulado con el filtro `query`), este intento da `false`; y `get_option()` lo ve después. **Con el código anterior fallaban las dos comprobaciones de carrera** (se lanzó la prueba antes de arreglarlo).

## Generación de la instantánea (v0.57.1, 2026-10-24; fallo visto por el usuario)

**Lo que pasaba:** «las altas y bajas nuevas no se ven inmediatamente en la web; en el CRM están bien». Cada alta, baja, membresía nueva o cambio de sección desde la web (`Crm\Altas`) borra la instantánea (`Instantanea::olvidar()`), pero **cualquier página** (también la del propio alta: se comprueba en cada petición quién entra) que encontraba la instantánea con más de 5 minutos pedía su **refresco en segundo plano** (WP-Cron). Ese refresco lee el grupo entero del CRM durante varios segundos, sin cerrojo; si el alta terminaba mientras tanto, el refresco acababa después y **volvía a guardar la foto de antes del alta como «fresca»**: la web no la enseñaba hasta pasados otros 5–10 minutos. Pasaba igual si otra página ya había vuelto a leer el CRM bien (el refresco viejo la pisaba).

**Arreglo:**
- `Instantanea::olvidar()` sube un contador por grupo (`asde_ap_snapgen_<grupo>` en `wp_options`, `autoload` off, con un solo `INSERT … ON DUPLICATE KEY UPDATE option_value = option_value + 1`, atómico) antes de borrar el transient. `Instantanea::generacion()` lo lee sin caché.
- `PersonaRepository::refrescar_instantanea()` anota la generación y la hora **antes** de leer el CRM, y `Instantanea::guardar( …, $t, $g )` no guarda nada (devuelve `false`) si la generación ha cambiado entretanto. La hora guardada es la de antes de leer, no la de después.
- La instantánea lleva su generación (`g`): `parchear()` (lo que se guarda desde la ficha) tampoco resucita una instantánea que otra petición ya olvidó.
- `guardar()` sin generación guarda como antes. `uninstall.php` borra el contador.

**Lo que no cambia:** lo que se cambie **directamente en el CRM** se sigue viendo en la web al refrescar la instantánea (de 5 a 10 minutos) o al momento con «Actualizar» en Personas o Usuarios (P-205).

Pruebas: `tests/rendimiento.php`, **parte H** (8 comprobaciones nuevas; 63 en total): `olvidar()` sube la generación; una lectura con un alta a medias devuelve lo suyo pero no lo guarda; la página siguiente ya enseña el alta y esa lectura sí se guarda; el refresco en segundo plano tampoco guarda encima; la hora es la de antes de leer; `parchear()` sobre una instantánea olvidada no la resucita; `guardar()` sin generación sigue guardando.
