# 57 · Presupuestos del grupo y de las secciones (plan y conocimiento para implementar)

> **Estado:** plan escrito el 2026-10-21. **Fase 1 hecha en la v0.54.0 (2026-10-23)**: `Reparto`, `Calculadora::coste_persona`, config `presupuesto`, pestaña y prueba `presupuestos-reparto` (el módulo `presupuestos` depende de Tesorería; la capability `asde_presupuestos_seccion` llega con la fase 2). Renumerado: era «doc 50» en otra rama. Parte de la v0.43.0. Las decisiones que el usuario ya dio están en el apartado 1; lo que se supone sin preguntar, en el apartado 9 (`PR-n`, a confirmar; al entrar en `09-preguntas-abiertas.md` tomarán número `P-…`: no se numeran aquí porque otras ramas van reservando números).
>
> Cuándo leerlo: antes de tocar nada de Tesorería para este cambio. Dónde encaja: pestaña nueva **«Presupuestos»** dentro de Tesorería (doc 14), junto a *Coste federativo* (doc 14/20) y con un enlace futuro a Eventos (doc 43) y Banco (doc 16).

## 1. Qué pide el usuario (literal, ordenado)

1. Nueva pestaña **Presupuestos** en Tesorería, donde se configura el presupuesto **del grupo entero** y el **de cada sección**.
2. **Cuánto dinero hay en total** = cuota anual del grupo − **coste federativo** (ese dinero «ya está comprometido»).
3. Del restante, un **porcentaje configurable va a las arcas del grupo** (actividades grupales, lo que haga falta) y **el resto va al presupuesto de la sección a la que pertenece el asociado**.
4. El **grupo** (equipo de coordinación, Tesorería, Secretaría, Coordinación) **cambia** cuánto va a la sección y cuánto al grupo.
5. **Cada sección prepara su presupuesto** con partidas propias. Una partida de gasto (campamentos, excursiones…) **se multiplica por X; el sistema pregunta cuántos** se harán.
6. Hay una partida marcada como **ingreso**: el **objetivo de dinero a recaudar** por cada sección con su proyecto:

| Sección | Proyecto (nombre por defecto de la partida de ingreso) |
|---|---|
| Castores | Dique |
| Lobatos | Cacería |
| Scouts (Sección Scout) | Aventura |
| Escultas | Empresa |
| Rovers (Clan) | Descubierta e Intervención |

## 2. Qué hay ya en el código (y se reutiliza)

| Pieza | Dónde | Para qué sirve aquí |
|---|---|---|
| `Tesoreria\Config` | `src/Tesoreria/Config.php` | Configuración **por grupo y por ronda solar** (sept→ago) en la opción `asde_ap_tesoreria`. `de()` mezcla lo guardado con los valores por defecto; `guardar()` admite guardar solo una parte. **Aquí cuelga la config de presupuestos** (`presupuesto`). |
| `Config::cuota()` / `Calculadora::familia()` | `src/Tesoreria/` | Cuota de una familia = inscripción×hijos + seguro×hijos + anualidad (con tramos por hermanos, tope en el último). Devuelve `pagan` (miembros que pagan), `cuota`, `ajustes`, `a_pagar`, `pagado`. **Es la «cuota anual de grupo».** |
| `Calculadora::coste_federativo()` | idem | Coste al organismo federado: cuota asociativa + seguro (tabla de primas por categoría o promedio) + «otros conceptos» (por asociado, educando, adulto o fijo por grupo). **Es el coste federativo.** Hoy devuelve solo el total agregado, no por persona. |
| `TesoreriaView::estados()` | `Frontend/Views/TesoreriaView.php` | Estado de todas las familias con **una** lectura del CRM (`PersonaRepository::todas(true)`, servida por `Instantanea`). `FederacionView` ya lo usa para comparar coste e ingresos esperados. |
| `TesoreriaBaseView::subnav()` | `Frontend/Views/TesoreriaBaseView.php` | Las pestañas de Tesorería. Se añade `presupuestos`. |
| `Domain\Secciones` | `src/Domain/Secciones.php` | Educativas (`castores…rovers`) y adultas. Nombre/color por sección. |
| `Auth\Ambito::secciones_scouter()` / `vision_global()` | `src/Auth/Ambito.php` | Un scouter ve «las suyas»; Secretaría/Coordinación/admin ven todo. Mismo patrón que `Eventos\Servicio::secciones_para()`. |
| Eventos (`PartidasTable`, `Presupuesto`, doc 43) | `src/Eventos/` | Ya existe un presupuesto **por evento** (gasto/ingreso, reparto, validación de Tesorería). **No se toca**; el presupuesto de sección es de otro nivel (ronda entera). Enlace opcional en la fase 3. |
| `Modulos` | `src/Domain/Modulos.php` | Interruptores por grupo (Banco y SEPA cuelgan de Tesorería). |

No existe nada llamado «presupuesto» fuera de Eventos; esto es módulo nuevo (skill `modulo-nuevo`).

## 3. El cálculo: de la cuota al dinero de cada sección

### 3.1 Fórmula por asociado que paga (educando)

```
cuota_i      = inscripción + seguro + anualidad_familia / nº_hijos_que_pagan     (de la familia de i)
coste_fed_i  = cuota asociativa + prima de seguro de su categoría + otros conceptos «por asociado/educando»
neto_i       = cuota_i − coste_fed_i                          (puede salir negativo: ver 3.4)
a_grupo_i    = neto_i × p_grupo                               (p_grupo = % de la sección de i, apartado 4)
a_seccion_i  = neto_i − a_grupo_i
```

- **Inscripción y seguro** son por hijo, así que son exactos por persona. **La anualidad** tiene descuento por hermanos y es de la familia: se **reparte a partes iguales** entre los hijos que pagan (supuesto PR-2).
- El presupuesto de una sección = Σ `a_seccion_i` de sus educandos. El del grupo = Σ `a_grupo_i` − costes sin dueño (3.2).

### 3.2 Costes que no son de una sección

El coste federativo **de los adultos** (scouters, voluntariado: pagan cuota asociativa y seguro pero no cuota al grupo) y los conceptos **«fijo por grupo»** no pertenecen a ningún educando. Se **restan de la bolsa del grupo** (están «comprometidos») antes de repartir nada a las secciones (supuesto PR-3). Alternativa a confirmar: prorratearlos entre las secciones por nº de educandos.

### 3.3 Qué «cuota» se usa: previsto o cobrado

- **Prevista** (por defecto): `a_pagar` de cada familia (cuota + ajustes) aunque aún no esté pagada. Sirve para **planificar** al inicio de la ronda.
- **Cobrada**: lo realmente ingresado (`pagado`), proporcional entre los hijos de la familia. Se muestra al lado como informativo («previsto / cobrado»), no condiciona los presupuestos (supuesto PR-1).

### 3.4 Casos límite

| Caso | Tratamiento |
|---|---|
| `neto_i` negativo (la cuota no cubre el coste federativo, p. ej. con becas) | Se reparte igual (aporta negativo) para que el total cuadre; la pestaña avisa «hay N educandos con cuota inferior al coste federativo». |
| Familia **sin** socios activos que pagan, o socio activo sin familia | Sin cuota: su coste federativo (si es educando) resta del grupo, no de su sección. |
| Persona con **varias secciones** | Cuenta en la primera sección educativa (por edad) de `Persona->secciones` que paga; una sola vez. |
| Cambio de sección durante la ronda | Se usa la sección **actual** al calcular (el cálculo se hace siempre en vivo, no se guarda). |
| Membresía | Activa **por fechas** (`tiene_membresia_activa()`, `Altas::activa()`), nunca por `estado_c`. |
| Seguro: tabla o promedio | Se usa el **mismo criterio que muestra «Coste federativo»** (tabla por defecto); ver PR-4. |
| Redondeo | Cálculo con céntimos; cada importe `round(…, 2)`; la diferencia de redondeo va al grupo para que Σ secciones + grupo = neto total exacto. |

### 3.5 Ejemplo (cifras inventadas)

Familia con dos Lobatos: inscripción 20, seguro 10, anualidad 2 hijos = 198 → cuota = 40 + 20 + 198 = **258**, **129** por hijo. Coste federativo por educando (cuota asociativa + prima), p. ej. **45** → neto **84** por hijo. Con 40 % al grupo: **33,60** al grupo y **50,40** a *Lobatos*, por cada hijo.

## 4. Porcentaje grupo / sección

- **Un porcentaje por defecto de la ronda** (`p_grupo`, p. ej. 40 %; valor inicial a decidir, PR-5) y **uno opcional por sección** que lo sustituye (el grupo puede dar más a Castores que a Rovers, por ejemplo).
- Siempre 0–100; el de la sección es `100 − p_grupo`. El formulario muestra los dos números a la vez y el reparto en euros resultante en vivo.
- Lo cambia quien tiene `asde_tesoreria_config` (Secretaría, Coordinación, Tesorería: coincide exactamente con «equipo de coordinación, tesorero, secretario, coordinador»). **Cada cambio queda en el registro** (`Logger::log`, nombres de campo y los dos porcentajes; no son datos personales).
- Cuando una sección ya tiene su presupuesto **aprobado**, cambiar el porcentaje avisa de que cambia su dinero disponible (no lo bloquea; PR-7).

## 5. Presupuesto de una sección (y del grupo)

El grupo tiene el mismo tipo de presupuesto (`seccion = 'grupo'`) para sus actividades grupales; las secciones, el suyo.

### 5.1 Partidas

Una partida es: `tipo` (gasto / ingreso), `categoría`, `concepto`, **`veces`**, **`importe por vez`**, nota. Total de la partida = `veces × importe`.

- **Gastos.** Se elige una **categoría** de un catálogo configurable por grupo. De partida: *Campamento de verano, Acampada, Excursión, Salida de día, Material, Formación, Actividad especial, Otros*. **Al elegir la categoría, el sistema pregunta «¿cuántos?»** (campo `veces`, entero ≥ 1, 1 por defecto) y el importe de **cada uno**; ejemplo: Excursiones, 6 × 35 € = 210 €.
- **Ingreso.** La partida de **objetivo de recaudación** del proyecto de la sección (apartado 1). Se crea sola, con el nombre por defecto de la tabla (editable) e importe objetivo 0 €, al abrir el presupuesto de la sección. Admite más ingresos (subvención, venta de productos…) con `veces` × importe.
- Las categorías de gasto marcan si **suelen repetirse** (para sugerir `veces` en lugar de 1) pero eso es solo una pista.

### 5.2 Resultado de la sección

```
disponible   = Σ a_seccion_i                        (de la cuota, apartado 3)
ingresos     = Σ partidas de ingreso               (objetivo de recaudación + otros)
gastos       = Σ partidas de gasto
saldo        = disponible + ingresos − gastos
```

Se muestra el saldo con semáforo (≥ 0 correcto; < 0 «el presupuesto no cuadra»). **La recaudación es un objetivo, no dinero cobrado**: también se enseña `disponible − gastos` («sin contar la recaudación») para que se vea cuánto depende del proyecto (PR-8).

### 5.3 Estados

`borrador` → `enviado` (la sección lo da por listo) → `aprobado` (Tesorería/Coordinación). Aprobado queda **bloqueado** para scouters (solo `asde_tesoreria_config` lo reabre, con motivo en el registro). Copia hecha de la ronda anterior (apartado 7).

### 5.4 Presupuesto del grupo

Misma pantalla con la «sección» **Grupo**: disponible = Σ `a_grupo_i` − costes sin dueño (3.2). Lo edita solo `asde_tesoreria_config`.

## 6. Pantallas (pestaña «Presupuestos»)

Mejora progresiva: **funciona sin JavaScript** (formularios con recarga); el JS añade/quita filas y recalcula totales en memoria (al usuario no le gusta recargar la pantalla entera).

1. **Resumen de la ronda** (vista por defecto, equipo de grupo): barra de cuota total → coste federativo → neto → grupo / secciones; tabla por sección: educandos, cuota, coste fed., neto, % a la sección, **disponible**, gastos presupuestados, objetivo de recaudación, saldo y estado. Al lado, el porcentaje por defecto y los de cada sección (editable con `asde_tesoreria_config`).
2. **Presupuesto de una sección** (selector de sección; un scouter solo ve las suyas): disponible arriba, listas **Gastos** e **Ingresos**, formulario «Añadir partida» (categoría → «¿cuántos?» → importe por vez), totales, saldo, botones *Enviar* / *Aprobar* / *Reabrir*.
3. **Presupuesto del grupo**: igual que el 2.
4. **Ajustes** (solo `asde_tesoreria_config`): % por defecto y por sección, nombre del proyecto de cada sección, catálogo de categorías.
5. Móvil primero (390 px): tablas como tarjetas con `data-label`, sin desbordes; descarga CSV opcional con `data-asde-sin-carga`.

Los selectores de ronda de la subnav ya existen: la pestaña los hereda (todo es por ronda).

## 7. Datos y arquitectura

**Config (opción `asde_ap_tesoreria`, clave nueva por ronda)**

```
presupuesto => array(
  'pct_grupo'   => 40.0,                       // % por defecto a las arcas del grupo
  'pct_seccion' => array( 'castores' => 30.0 ),// excepciones por sección (% al grupo)
  'proyectos'   => array( 'castores' => 'Dique', 'lobatos' => 'Cacería', 'scouts' => 'Aventura',
                          'escultas' => 'Empresa', 'rovers' => 'Descubierta e Intervención' ),
  'categorias'  => array( array( 'nombre' => 'Excursión', 'repite' => true ), … ),
)
```

`Config::defaults_ronda()` añade los valores de siempre; `de()` los mezcla; `guardar()` admite `presupuesto` como parte nueva y la sanea (porcentajes 0–100, nombres con `sanitize_text_field`). Una ronda sin esta clave se comporta **como hoy** (regla 5).

**Tabla `{prefix}asde_presupuesto_partidas`** (`Presupuestos\PartidasTable`, `dbDelta`, `%i` en todas las consultas, cabecera `phpcs:disable DirectDatabaseQuery`): `id`, `grupo`, `ronda`, `seccion` (`castores…rovers` o `grupo`), `tipo` (`gasto`/`ingreso`), `categoria`, `concepto`, `veces` (int), `importe` (decimal 10,2), `nota`, `orden`, `creado`, `creado_por`. Índice `(grupo, ronda, seccion)`.

**Tabla `{prefix}asde_presupuesto_estados`** (o campos en una fila por `grupo+ronda+seccion`): `estado`, `enviado_en`, `aprobado_en`, `aprobado_por`.

**Clases nuevas** (`src/Presupuestos/`, autoload por namespace):

| Clase | Responsabilidad |
|---|---|
| `Reparto` | **Cálculo puro** (sin efectos): recibe personas activas, estados de familias y `cfg`; devuelve por sección `educandos`, `cuota`, `coste_fed`, `neto`, `a_grupo`, `a_seccion`, más el bloque `grupo` (costes sin dueño). Es lo que se prueba a fondo. |
| `PartidasTable` | Altas/bajas/consultas de partidas. |
| `Servicio` | Reglas de negocio: quién puede qué, estados, copiar de la ronda anterior, totales y saldo, auditoría. |
| `Categorias` | Catálogo de categorías y proyectos por defecto (con `asde_ta()` y `Textos::extras()`). |

**Cambio en código existente (mínimo y con prueba):** `Calculadora` gana `coste_federativo_persona( Persona, cfg, ref )` y la parte «fijo por grupo» queda expuesta aparte; **la suma por persona + conceptos de grupo debe dar exactamente `total_tabla`/`total_promedio`** (prueba de equivalencia). `coste_federativo()` no cambia su resultado.

**Vista y rutas:** `Frontend\Views\PresupuestosView extends TesoreriaBaseView` (`render` + `handle_post`), `Router::VIEWS['presupuestos']`, `Pages::DEFINICIONES['presupuestos'] = ('presupuestos', 'Presupuestos', '[asde_presupuestos]', 'tesoreria')`, ítem en `subnav()`, plantillas `templates/tesoreria/presupuestos*.php` (copiar el `phpcs:disable` de plantilla), `assets/js/presupuestos.js` (filas en memoria; textos en `TextosJs::LISTA`), bloque CSS comentado al final de `area-privada.css`.

**Rendimiento (doc 26):** el resumen necesita una sola lectura del CRM (`todas(true)` + `estados()`, como `FederacionView`); **nunca una consulta por sección ni por persona**. Los importes se calculan en cada vista; **no se guardan** (solo partidas, estados y porcentajes). Sin datos personales en WordPress: las tablas solo llevan importes por sección y ronda.

## 8. Permisos, módulo, seguridad

- **Ver el resumen de grupo y editar porcentajes / presupuesto del grupo / aprobar / reabrir:** `asde_tesoreria_config` (Secretaría, Coordinación, Tesorería). Ya existe, no hace falta capability nueva para esto.
- **Preparar el presupuesto de una sección:** capability nueva **`asde_presupuestos_seccion`** (Scouter, Secretaría, Coordinación, Tesorería), **limitada a las secciones del ámbito** (`Ambito::secciones_scouter()`; los de visión global, todas), como `Eventos\Servicio::secciones_para()`. Un scouter ve solo sus secciones y el % que les toca, no el desglose económico del grupo (PR-6).
- Documentar en doc 02 y regenerar la matriz del doc 40.
- **Módulo** `presupuestos` en `Domain\Modulos` (depende de `tesoreria`, quita `asde_presupuestos_seccion`; encendido por defecto: solo añade una pestaña, no cambia nada existente).
- Cada acción POST: `current_user_can`, nonce, salida escapada, `wp_unslash` + saneo de `$_POST`, SQL con `prepare()` y `%i`, filtro por **grupo** en toda consulta (regla 3: un grupo no ve a otro). Auditoría con `Logger::log` (campos, no valores personales). Avisos con `Notices`.
- Idiomas: todo texto en `asde_t()`/`asde_h()`…; catálogos `ca`/`eu`/`gl` (skill `traducir-textos`); las constantes (categorías, proyectos) con `asde_ta()` + `Textos::extras()`. Lo que se **guarda** (conceptos) va en castellano sin traducir.

## 9. Supuestos a confirmar (el usuario no los ha dicho)

| Id | Supuesto | Alternativa |
|---|---|---|
| PR-1 | Los presupuestos se calculan sobre la cuota **prevista** (cuota + ajustes de cada familia); se enseña el **cobrado** al lado. | Calcular sobre lo cobrado. |
| PR-2 | La anualidad con descuento por hermanos se **reparte a partes iguales** entre los hijos que pagan; inscripción y seguro, exactos por hijo. | Asignar toda la anualidad al hijo mayor, o proporcional a la edad. |
| PR-3 | El coste federativo de **adultos** y los conceptos «fijo por grupo» se **restan de la bolsa del grupo** antes de repartir. | Prorratearlo entre secciones por nº de educandos. |
| PR-4 | Se usa el seguro **por tabla de primas** (como «Coste federativo»). | Usar el promedio, o elegirlo en ajustes. |
| PR-5 | Porcentaje inicial al grupo: **40 %** (50/50 o lo que decida el grupo; es solo el valor de partida). Editable por sección. | Otro valor inicial. |
| PR-6 | Un scouter ve y edita **solo sus secciones**; el resumen económico del grupo es de Secretaría/Coordinación/Tesorería. | Que todos los scouters vean el resumen. |
| PR-7 | Aprobado = bloqueado para scouters; cambiar el % **avisa** pero no bloquea. | Congelar el % de una sección aprobada. |
| PR-8 | La recaudación (Dique, Cacería…) es un **objetivo**: cuenta en el saldo, pero se enseña también el saldo **sin** ella. | Contarla solo cuando esté cobrada (requeriría ingresos reales). |
| PR-9 | El presupuesto es **por ronda** (sept→ago), con botón «Copiar de la ronda anterior» (partidas y %; nunca estados). | Presupuesto continuo, sin ronda. |
| PR-10 | La pestaña es **independiente de Eventos** al principio; el gasto real y la relación con los eventos van en la fase 3. | Enlazar ya los presupuestos de eventos validados. |
| PR-11 | Pueden recibir cuota solo los **educandos** (`secciones_pagan` de la ronda); adultos no aportan. | Permitir otra configuración. |

Preguntas abiertas para el usuario con más impacto: **PR-2, PR-3, PR-5 y PR-8**.

## 10. Plan de implementación por fases

Rama nueva desde `origin/main`; versión **0.44.0** (subir `ASDE_AP_VERSION`, `Version:`, `readme.txt` con Stable tag y Changelog).

**Fase 1 · Motor y ajustes** (sin partidas)
1. `Calculadora::coste_federativo_persona` + prueba de equivalencia con el total.
2. `Presupuestos\Reparto` + config `presupuesto` en `Config` (defaults, `de`, `guardar`, saneo).
3. Pestaña con el **resumen** (solo lectura salvo los porcentajes) y la tabla por sección. Permisos, módulo, página, subnav, CSS, textos.
4. `tests/presupuestos-reparto.php`.

**Fase 2 · Presupuesto de sección y de grupo**
1. Tablas, `PartidasTable`, `Servicio`, `Categorias`.
2. Pantalla de sección (gastos con «¿cuántos?», ingresos con proyecto, saldo), estados y aprobación, JS de filas, CSV.
3. Copiar de la ronda anterior.
4. `tests/presupuestos.php`.

**Fase 3 · Cierre del ciclo** (a decidir con el usuario)
- Gasto real: imputar gastos de **Banco** (tipos de gasto, doc 16) y de **Eventos** (cuentas, doc 43) a la partida; «presupuestado / gastado / queda».
- Que un evento de campamento/acampada de la sección tome su coste de la partida correspondiente.
- Manual, ayuda (doc 35) y capturas.

**Pruebas obligatorias** (CRM simulado, todo se restaura en `finally`; aserciones que puedan fallar):

- *Reparto*: ejemplo del 3.5; familia con 1, 2 y 3+ hijos; tope de tramos; adulto sin cuota; neto negativo; socio sin familia; sin membresía activa (por fechas, no por `estado_c`); varias secciones; cambio de sección; Σ secciones + grupo = neto total al céntimo; % por defecto y por sección; 0 % y 100 %; seguro tabla y promedio; equivalencia con `Calculadora::coste_federativo`.
- *Config*: ronda sin la clave = comportamiento actual; saneo de porcentajes (negativos, > 100, comas), nombres de proyecto; no pisa otros apartados de la config.
- *Partidas*: `veces × importe`, `veces < 1`, importes negativos o con coma, categoría desconocida, proyecto creado una sola vez, borrado y orden.
- *Permisos*: scouter solo en sus secciones (otra sección = rechazo), no ve el resumen económico; Secretaría/Coordinación/Tesorería sí; familias y participantes no entran; módulo apagado = sin pestaña ni capability; **un grupo no ve las partidas de otro**; nonces ausentes o malos; aprobado bloqueado y reabrir con motivo.
- *Estilo*: `tests/estilo-wordpress.php` (`%i`, escapes, CRLF, plantillas), `tests/idiomas-catalogos.php`.
- Revisión visual a 1280 y 390 px con datos inventados (skill `probar-en-la-nube`).

**Entrega** (skill `entregar-version`): docs 00 (estado + fila), 02 (capability), 09 (los `PR-n` con su `P-…`), 10 (recuento de pruebas), 14 (pestaña), 40 (regenerar mapa), 35/21 (ayuda y manual si procede); zip; resumen al usuario con lo que debe decidir.

## 11. Lista de ficheros a tocar / crear (referencia rápida)

**Crear:** `src/Presupuestos/{Reparto,PartidasTable,Servicio,Categorias}.php`, `src/Frontend/Views/PresupuestosView.php`, `templates/tesoreria/presupuestos*.php`, `assets/js/presupuestos.js`, `tests/presupuestos*.php`, `docs/57-presupuestos.md` (este).

**Modificar:** `src/Tesoreria/Config.php`, `src/Tesoreria/Calculadora.php`, `src/Frontend/Views/TesoreriaBaseView.php` (subnav), `src/Frontend/Router.php` (VIEWS), `src/Frontend/Pages.php` (DEFINICIONES), `src/Install/Roles.php` (capability), `src/Install/Installer.php` (tabla + página, patrón de las otras tablas), `src/Domain/Modulos.php`, `src/Plugin.php` (si registra tablas/versión de BD), `uninstall.php` (tablas, variables `$asde_`), `src/I18n/Textos.php` + `TextosJs`, `assets/css/area-privada.css` (bloque al final), `languages/{es,ca,eu,gl}.php`, `asde-area-privada.php`, `readme.txt`, docs 00/02/09/10/14/40.

> Antes de empezar la fase 1, comprobar a mano los nombres exactos de `Installer`, `Plugin` y del fichero de textos (aquí citados de memoria del mapa del doc 40) y leer `docs/14-tesoreria.md` y `docs/20-organizacion-federada.md` para no contradecir las decisiones de cuotas y coste federativo.
