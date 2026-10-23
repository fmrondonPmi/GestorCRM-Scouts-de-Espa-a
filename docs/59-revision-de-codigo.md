# 59 · Revisión de código de todo el plugin (2026-10-23)

Revisión módulo a módulo de `asde-area-privada/` (src, plantillas, JS, CSS y `uninstall.php`) sobre la rama `Revision-Codigo` (sale de `Correcciones-Numeracion`, que ya trae corregidos los bloqueos `GET_LOCK`, la numeración de familias por grupo y el `insert_id` obsoleto).

**Este fichero es la lista de trabajo del agente de correcciones.** Cada corrección lleva un identificador (`RC-<módulo>-<n>`), su prioridad, el fichero y la línea, qué falla, cómo se nota y qué hacer. Al corregir una, cambiar su casilla a `[x]` y anotar debajo el commit o el motivo si se descarta.

## Cómo corregir (reglas del proyecto)

- Leer antes `CLAUDE.md` del repo (reglas que no se discuten, convenciones de interfaz, trampas del entorno).
- Una prueba por cambio en `asde-area-privada/tests/` con el CRM simulado; cada prueba restaura en un `finally` lo que toca. Lanzar con `herramientas-desarrollo/probar-todo.sh <prueba>` (carpeta antigua, entre comillas: `C:\Claude\Scouts De Balears  - Area Privada\herramientas-desarrollo`). **No cortar nunca una prueba a medias.**
- Textos visibles nuevos: `asde_t()` y sus traducciones (ca/eu/gl) con la skill de usuario `version-area-privada`; mejor reutilizar textos que ya existen.
- Ficheros con CRLF: no mezclar finales de línea. Revisar que no se pierden las `\` de los espacios de nombres.
- `php -l` sobre lo tocado y `tests/estilo-wordpress.php` al final.
- Lo que el doc 41 dice que **no** es un fallo no se «arregla».

## Prioridades

- **P1** — fallo que da un resultado incorrecto, pierde o mezcla datos, o abre un hueco de seguridad.
- **P2** — fallo en un caso concreto o poco frecuente; incoherencia visible.
- **P3** — limpieza: duplicación, código muerto, trabajo de más (sin cambiar comportamiento).

## Pendiente de revisiones anteriores (ya conocidas)

- [ ] **RC-PRE-1 · P3** · `src/Domain/DatosGrupo.php:53` y `src/Domain/Grupos.php` (`direccion`) — la dirección en una línea se construye dos veces; `Grupos::direccion` no usa el respaldo de la dirección SEPA. Hacer que una delegue en la otra (respetando lo que espera `tests/datos-grupo.php`).
- [ ] **RC-PRE-2 · P3** · `src/Domain/Organizacion.php:201` — `codigo()` @deprecated sin usos fuera de las pruebas: quitarlo y que `tests/organizacion.php` use `codigo_antiguo()`.
- [ ] **RC-PRE-3 · P3** · `src/Domain/NumeroSocio.php` (`otras_formas`) — recorre todos los grupos y compila regex en cada llamada; memorizar en una estática los pares prefijo/antiguo por grupo (limpiarla en `Grupos::olvidar()`).
- [ ] **RC-PRE-4 · P3** · `src/Frontend/Views/EventosView.php` y `ReintegrosView.php` — `justificante_subido()` y `ver_justificante()` duplicados: moverlos a `Justificantes` (p. ej. `Justificantes::subido( $campo )` y `Justificantes::enviar( $contenido, $tipo, $nombre )`).
- [ ] **RC-PRE-5 · P3** · `src/Presupuestos/Servicio.php` — la validación de importes se repite en `anadir_partida`, `fijar_proyecto` y `anadir_gasto_real`: un `importe_valido( $v, bool $cero_vale )`.
- [ ] **RC-PRE-6 · P3** · `src/Presupuestos/PartidasTable.php:66` — `anadir()` lee todas las partidas solo para calcular `orden`: usar `SELECT COALESCE(MAX(orden),0)+1`.
- [ ] **RC-PRE-7 · P3** · `src/Presupuestos/Reparto.php:66` — `Calculadora::coste_persona` se calcula dos veces por persona: guardarlo por nº de socio en el primer recorrido.

No se corrige: `MigracionUsuario` carga todos los usuarios (se usa una vez por migración, aceptable) ni el respaldo de provincia común (comportamiento de siempre, decisión de la v0.56).

---

## Requiere decisión del usuario (no corregir sin preguntar)

- [ ] **RC-DEC-1 · P2** · `src/Accesos/Concesiones.php:230` (`conceder`) — quien tiene `asde_accesos` puede conceder a otra persona permisos que **él mismo no tiene**. Dos gestores pueden darse el uno al otro lo que ninguno tenía (la regla «nunca a sí mismo» se salta con dos personas). Propuesta: exigir `user_can( $actor, $permiso )` salvo a los administradores. Cambia el comportamiento del doc 56: preguntar antes.
- [ ] **RC-DEC-2 · P2** · `src/Usuarios/Solicitudes.php:176` (`purgar`) — lo escrito en una solicitud de acceso (nombre, email, documento, fecha) se borra 30 días después de **resolverla**, pero una solicitud que nadie revisa se queda **pendiente para siempre** con esos datos (cifrados). La regla 1 de `CLAUDE.md` pide «solo cifrado y por poco tiempo». Propuesta: rechazar automáticamente (sin email, o con el de rechazo) las pendientes de más de N días (¿90?) y dejar que `purgar()` las vacíe a los 30. Hace falta decidir N y si se avisa.
- [ ] **RC-DEC-3 · P3** · `src/Eventos/Justificantes.php` — los justificantes (tiques y facturas, cifrados en `uploads/asde-privado/<grupo>/`) **no se borran nunca**, tampoco los de reintegros rechazados o cancelados ni los de gastos rechazados o retirados. Pueden llevar datos personales. Propuesta: `Justificantes::borrar( $fichero )` al rechazar o cancelar un reintegro y al rechazar o retirar un gasto, **solo si ninguna otra fila lo usa** (`Reintegros\Servicio::gasto_del_evento()` copia el mismo fichero al gasto del evento). Decidir si se hace y si se borra también al cerrar las cuentas del evento.

## Auth, Audit, Integraciones

Sin correcciones: límites de intentos, solo lectura, ámbito y Wordfence revisados.

## Accesos

- [ ] **RC-ACC-1 · P3** · `src/Accesos/PermisosRol.php:427` (`cambios()`) — se consulta la tabla en cada `Concesiones::del_rol()`, es decir, por cada persona del listado de «Accesos por persona» y en cada `mapa()`. Memorizarla en una estática por petición y vaciarla en `guardar()`, `restaurar()` y `Concesiones::olvidar()`.

## Domain

- [ ] **RC-DOM-1 · P3** · `src/Domain/Modulos.php:155` (`quitar_caps`) — `( ! self::apagados() && ! self::POR_DEFECTO_APAGADOS )` nunca es verdad (la constante no está vacía): condición muerta. Quitarla y dejar el comentario.
- [ ] **RC-DOM-2 · P3** · Comentarios desfasados desde la v0.56 (el nº local ya no lleva «código de la organización», sino la provincia con su 0): docblock de `src/Domain/Organizacion.php:6-19`, de `Grupos::de_usuario` (`src/Domain/Grupos.php:66-70`), de `src/Domain/Identidad.php:15-17` y el de `Identificacion::normalizar` (`src/Auth/Identificacion.php:41-44`). Solo texto.

## Crm

- [ ] **RC-CRM-1 · P1** · `src/Crm/SinergiaV41Client.php:140` (`list_all`, `hard_limit` 5000) — corta en silencio. `PersonaRepository::refrescar_instantanea()` y `todas()` piden **todas** las fichas visibles sin condición de grupo y filtran después (`filtrar_grupo`). Con una credencial que ve muchos grupos (modo Oficina, o un usuario del CRM con `filtrar = true`) y más de 5000 fichas en total, las del grupo que caen después del corte desaparecen sin aviso: personas que «no están», ámbitos sin ficha, informes incompletos. Lo mismo en `Duplicados::repetidos()`. Corrección mínima: si se llega al límite con más registros pendientes (`total` > recogidos), lanzar `CrmException` con un mensaje claro en vez de devolver una lista parcial (y registrarlo con `Logger`). (No se puede restringir la consulta al grupo de seguridad: el CRM rechaza las subconsultas a `securitygroups_records`, doc 34.) Prueba: CRM simulado con un `total` mayor que el límite (bajar el límite con un filtro o parámetro para la prueba).
- [ ] **RC-CRM-2 · P3** · `src/Crm/Instantanea.php:237` (`refrescar_en_segundo_plano`) — usa `Grupo::forzar( $datos )` y en el `finally` `Grupo::forzar( null )`, que no restaura el grupo que hubiera forzado antes (otras tareas del mismo WP-Cron). Usar `Grupo::con( $datos, … )`, como `TareaNumeracion`.
- [ ] **RC-CRM-3 · P3** · `src/Crm/PersonaRepository.php:240` (`obtener_varias`) — ordena (`ordenar()`, con `remove_accents` por persona) una lista que justo después se reindexa en el orden pedido: el orden se pierde. Quitar la llamada a `ordenar()` ahí.

## Fichas

- [ ] **RC-FIC-1 · P3** · `src/Fichas/FichaService.php:172` — `sprintf( 'máximo %d caracteres.', $len )` es un texto visible sin `asde_t()` (regla de idiomas de `CLAUDE.md`: «todo texto que vea una persona en el área se escribe en castellano dentro de `asde_t()`…»). Pasarlo a `asde_t()` y añadir sus traducciones.

## Socios

- [ ] **RC-SOC-1 · P3** · `src/Socios/Numeros.php:124` — el aviso `⚠ nº territorial: ` va sin `asde_t()` (misma regla de idiomas). Usar `sprintf( asde_t( 'nº territorial: %s' ), … )` o similar y traducirlo.

## Usuarios

- [ ] **RC-USU-1 · P2** · `src/Socios/Eliminadas.php:72-84` (`eliminar`) — busca la cuenta con `Usuarios::por_socio()` y las familiares con `Usuarios::tutores_de()` **sin mirar el grupo** de la cuenta. Si esa ficha está también en otro grupo de la instalación (doc 34: una persona puede estar en varios grupos) o la cuenta pertenece a otro grupo, «Eliminar de la web» en un grupo desactiva la cuenta y quita representados de la familia del otro. Filtrar por `Grupos::de_usuario( $u )['id'] === Grupo::id()` antes de desactivar o quitar. Lo mismo, como defensa, en `AltaService::despues_de_alta()` (`src/Usuarios/AltaService.php:426`), que reescribe `META_GRUPO` de una cuenta ya existente con el grupo actual: si la cuenta era de otro grupo, se la lleva sin avisar; ahí, devolver error si la cuenta vinculada es de otro grupo.
- [ ] **RC-USU-2 · P3** · `src/Usuarios/Contacto.php:68` (`lista`) — el conector `'y'`/`'o'` va en castellano también en catalán, euskera y gallego («família de Joan y Maria»). Traducir el conector con `asde_t( 'y' )` / `asde_t( 'o' )` dentro de `lista()` (y que los que llaman pasen un indicador en vez del texto).
- [ ] **RC-USU-3 · P3** · `src/Usuarios/AltaScouterService.php:58` — el error dice «Secretaría, Coordinación o Tesorería», pero `ROLES_RESPONSABILIDAD` incluye también Intendencia (v0.4x). Corregir el texto (y su traducción).

## Tesorería y Banco

- [ ] **RC-TES-1 · P1** · `src/Tesoreria/Banco/Conciliacion.php:150` (`conciliar`) — comprueba que el movimiento está `pendiente`, registra el pago y **después** lo marca conciliado, sin bloqueo ni comprobación atómica. Dos personas (o un doble clic) que concilian el mismo movimiento a la vez registran **dos pagos** de la familia por un solo ingreso del banco. Corrección: marcar primero con una actualización condicionada (`UPDATE … SET estado = 'conciliado' WHERE id = %d AND grupo = %s AND estado = 'pendiente'`; si no cambia ninguna fila, error «ya está revisado») y solo entonces registrar el pago y guardar su `pago_id` (si el registro falla, devolver el movimiento a pendiente). Mismo patrón en `ignorar()` y `deshacer()`. Prueba: llamar dos veces seguidas a `conciliar()` con el mismo movimiento y comprobar un solo pago.
- [ ] **RC-TES-2 · P2** · `src/Tesoreria/Calculadora.php:65-78` — cada plazo se redondea por separado (`round( $a_pagar * % / 100, 2 )`) y el acumulado puede no llegar o pasarse de `a_pagar` (p. ej. 50 € en tres plazos de 33,33 %: 16,67 × 3 = 50,01). Una familia que lo ha pagado todo ve su último plazo «sin cubrir». Hacer que el último plazo sea `a_pagar − acumulado anterior` cuando la suma de porcentajes es 100.
- [ ] **RC-TES-3 · P3** · `src/Tesoreria/FamiliasTable.php:98` (`todas`) — lee **todos** los miembros de todas las familias de todos los grupos y filtra en PHP. Usar el `INNER JOIN … WHERE f.grupo = %s` de `mapa_socios()`.
- [ ] **RC-TES-4 · P3** · `src/Tesoreria/Config.php:30-78` (`defaults_ronda`, `presupuesto_defecto`) — los valores por defecto se construyen ya traducidos (`asde_t( 'Cuota anual' )`, proyectos, categorías) y, al guardar la configuración, se guardan en el idioma de quien la guarda. `CLAUDE.md`: «Lo que se guarda (motivos, conceptos, referencias) va en castellano, sin traducir» y «en las constantes, el valor se traduce al mostrarlo con `asde_ta()`». Guardar en castellano y traducir al mostrar (y que la validación de categorías de `Presupuestos\Servicio::anadir_partida` compare con el valor en castellano).
- [ ] **RC-TES-5 · P3** · `src/Tesoreria/Banco/Conciliacion.php:25` (`referencia`) — el patrón exige exactamente 3 cifras de familia (`F684123`); desde la familia 1000 (`F6841000`) la referencia del concepto ya no se reconoce. Aceptar `\d{3,}`.

## Sepa

- [ ] **RC-SEPA-1 · P1** · `src/Sepa/Remesas.php:609` (`registrar_cobros`) — lee las líneas pendientes, registra un pago por cada cobrada y guarda; sin bloqueo. Dos envíos a la vez (doble clic, dos tesoreros) registran **cada cobro dos veces**. Tomar `Domain\Bloqueo::tomar( 'asde_sepa_cobros_' . $id )`, releer la remesa dentro del bloqueo y soltarlo en un `finally` (como en `crear()`). Prueba: dos llamadas seguidas con las mismas familias → un solo pago por familia.
- [ ] **RC-SEPA-2 · P3** · `src/Sepa/Remesas.php:529` (`adeudos`) — `' adeudo'` / `' adeudos'` en castellano sin traducir (regla de idiomas). Usar `sprintf( asde_n( '%d adeudo', '%d adeudos', $n ), $n )` (o el equivalente del plugin) y añadir el texto a las traducciones.
- [ ] **RC-TES-6 · P1** · `src/Tienda/ConciliacionBanco.php:88` (`confirmar`) y `:190` (`confirmar_datafono`) — el mismo problema que RC-TES-1: comprueban que el ingreso está `pendiente`, marcan el pedido pagado y después lo revisan, sin condición atómica. Dos confirmaciones a la vez pagan dos pedidos con el mismo ingreso. Resolverlo con el mismo método atómico de `BancoTable` que se cree para RC-TES-1 (p. ej. `BancoTable::tomar( $id, $estado_esperado, $datos ): bool`, una sola sentencia `UPDATE … WHERE estado = %s`).

## Presupuestos y Reintegros

Solo lo de RC-PRE-5 a RC-PRE-7 (arriba). Las transiciones de los reintegros ya son atómicas (`ReintegrosTable::cambiar`).

## Eventos

- [ ] **RC-EVE-1 · P1** · `src/Eventos/Inscripciones.php:687` (`alta`) y `:466` (`revisar`) — se cuenta si hay plaza (`hay_plaza`) y después se inserta, sin bloqueo. Al abrir la inscripción de un evento con plazas muchas familias entran a la vez: dos pueden llevarse la última plaza y el evento queda con más inscritos que plazas (y sin lista de espera para el que sobra). `revisar()`, que se llama al mirar el evento, puede ofrecer la misma plaza libre dos veces y mandar dos avisos. Envolver el cálculo de plaza + la escritura de `alta()` y de `revisar()` en `Domain\Bloqueo::tomar( 'asde_evento_' . $e['id'] )` (error claro si no se consigue; `soltar` en `finally`). Prueba: simular dos altas seguidas con una sola plaza (la segunda tiene que quedar en lista de espera).
- [ ] **RC-EVE-2 · P2** · `src/Eventos/Cobros.php:109` (`pagar_familia`, usado por `Sepa\Remesas::registrar_cobros` para las remesas de eventos) — si entre la remesa y el registro del cobro la familia ha pagado algo a mano (o le han hecho una rebaja), el importe cobrado por el banco es mayor que lo pendiente y se rechaza («Es más de lo que queda por pagar»), o «Esa familia no tiene nada pendiente». El banco **ya cobró** ese dinero y la web no lo apunta en ningún sitio. Con `$sepa = true`, registrar siempre el importe cobrado: repartir lo pendiente y dejar lo que sobre en la última inscripción (quedará como saldo a devolver) y avisar en el resultado (`errores` → `avisos`) para que Tesorería decida la devolución.
- [ ] **RC-EVE-3 · P3** · `src/Eventos/Cuentas.php` (`retirar`, `anulacion( asde_t( 'Retirado' ) )`) — el motivo que se **guarda** va traducido al idioma de quien actúa. `CLAUDE.md`: «Lo que se guarda (motivos, conceptos, referencias) va en castellano, sin traducir». Guardar `'Retirado'` y traducirlo al mostrar.
- [ ] **RC-EVE-4 · P3** · `src/Eventos/Inscripciones.php:490-505` (`revisar`) — vuelve a leer `InscripcionesTable::participantes()` por cada persona de la lista de espera (una consulta por candidato). Leer una vez y actualizar el array en memoria al ofrecer cada plaza.

## Lista de espera, Menús, Inventario, Informes, Idiomas

Sin correcciones. Revisado: caducidad y avisos de la lista de espera, datos para el alta en el CRM, escalado de recetas, listas de compra (transiciones atómicas, buen modelo para RC-TES-1), exportaciones CSV (todas pasan por `Generador::celda_segura`) y Excel.

## Install y mantenimiento

- [ ] **RC-INS-1 · P2** · `src/Install/Mantenimiento.php:60-92` (`ejecutar`) — cada grupo solo captura `CrmException`. Cualquier otro error (un `TypeError`, un fallo de base de datos convertido en excepción…) en un grupo corta la tarea diaria entera: los grupos siguientes no se procesan y **no se ejecutan las purgas** de después del bucle (`Solicitudes::purgar`, permisos caducados, compras sin sesión, mandatos y remesas), que son las que cumplen los plazos de conservación de datos. Capturar `\Throwable` por grupo (registrarlo con `Logger::log( 'mantenimiento_error', … )`), y dejar `Grupo::forzar( null )` y las purgas en un `finally` del bucle.

## Frontend, plantillas y JavaScript

Sin correcciones de seguridad: todos los `handle_post()` comprueban nonce y permiso (los públicos, además, clave del enlace, campo trampa y límite por IP); el Router bloquea a las cuentas de solo lectura; los avisos pasan por `wp_kses_post`; las descargas llevan su nonce. Solo lo de idiomas ya anotado (RC-FIC-1, RC-SOC-1, RC-USU-2, RC-SEPA-2).

---

## Resumen

| Prioridad | Correcciones |
|---|---|
| **P1** | RC-CRM-1, RC-TES-1, RC-TES-6, RC-SEPA-1, RC-EVE-1 |
| **P2** | RC-USU-1, RC-TES-2, RC-EVE-2, RC-INS-1 |
| **P3** | RC-PRE-1…7, RC-ACC-1, RC-DOM-1, RC-DOM-2, RC-CRM-2, RC-CRM-3, RC-FIC-1, RC-SOC-1, RC-USU-2, RC-USU-3, RC-TES-3, RC-TES-4, RC-TES-5, RC-SEPA-2, RC-EVE-3, RC-EVE-4 |
| **Decisión** | RC-DEC-1, RC-DEC-2, RC-DEC-3 (no tocar sin respuesta del usuario) |

**Orden recomendado:** primero las P1 (cada una con su prueba), después las P2, después las P3 agrupadas por módulo. Al final: `estilo-wordpress`, traducciones (`idiomas-catalogos`) y la batería completa con `probar-todo.sh` (debe seguir fallando solo `tienda-proveedor`, que ya fallaba antes de esta revisión: «la pestaña lo enseña recibido, sin botón»).

**Nota sobre la primera revisión:** el arreglo de la numeración de familias (commit `6b0e98b`) se pensó para grupos como 684 y 6841, pero los números de grupo son siempre de 3 cifras (`Grupos::normalizar_numero`), así que ese choque no podía darse con datos reales; el cambio (filtrar por `grupo`) se queda porque es correcto y más claro.
