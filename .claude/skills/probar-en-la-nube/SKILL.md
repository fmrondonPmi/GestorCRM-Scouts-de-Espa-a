---
name: probar-en-la-nube
description: Monta un WordPress de pruebas con SQLite en una sesión de Claude Code en la nube (sin Local ni Windows), lanza allí las pruebas de asde-area-privada (tests/*.php con el CRM simulado) y fotografía las pantallas con Chrome a 1280 y 390 px con datos inventados. Úsala siempre que haya que probar o mirar cambios del plugin y no se esté en el Windows del usuario.
---

# Probar el plugin en una sesión en la nube

En la nube no hay Local, ni `herramientas-desarrollo/`, ni MySQL. Esta skill monta lo necesario en la carpeta de trabajo de la sesión (el scratchpad), **nunca en el repo**. Todo es de usar y tirar: datos inventados, claves fijas, sin el CRM real.

## 1. Montar (una vez por sesión, ~1 min)

```bash
export NUBE=<scratchpad de la sesión>/nube
.claude/skills/probar-en-la-nube/montar.sh
```

Descarga WordPress (es_ES), el plugin «SQLite Database Integration», el tema Twenty Twenty-One y WP-CLI; instala, crea el menú «primary», copia y activa el plugin y ejecuta `preparar.php` (grupo 684 «Grupo Scout Ejemplo» con un grupo de seguridad inventado, URL del CRM simulado, organización federada e «Instalación y entorno»). WP-CLI queda en `$NUBE/bin/wp` (ya lleva `--allow-root` y `--path`).

## 2. Pruebas

```bash
NUBE=… .claude/skills/probar-en-la-nube/probar.sh            # todas
NUBE=… .claude/skills/probar-en-la-nube/probar.sh eventos    # una o varias
```

Copia el plugin del repo, borra los transients (la instantánea que deje la sesión web cambiaría las lecturas) y lanza cada `tests/<nombre>.php`, con un resumen por fichero y «TODO OK» o la lista de los que fallan.

- **Lo que no se puede probar aquí:** lo que lee el CRM real. `alta-reglas.php` (parte 2), `grupos-emails-banco.php` (la verificación del grupo da «CONNECT tunnel failed, response 502») y `fichas-escritura.php` (0 comprobaciones). Antes de cambiar nada, lanzar la suite para tener la **línea base** y comparar con ella; al entregar, decir que esas tres hay que pasarlas en el Local.
- Una prueba que cuente cosas del sitio (pendientes, filas) tiene que compararlas **con lo que había antes**: el WordPress de pruebas puede tener datos de demostración, y el Local del usuario, datos reales.
- Recuento de referencia: v0.19.0, 809 OK en 20 ficheros; v0.19.1, 900 OK en 21.
- Una prueba que pinte una pantalla con **todos** los eventos (o pedidos…) del sitio tiene que mirar solo lo suyo (p. ej. la tarjeta de su evento): los datos de demostración de las capturas también están en la base de datos.

## 3. Mirar las pantallas (escritorio y 390 px)

1. CRM de demostración **solo para las peticiones web**: `mkdir -p $NUBE/wp/wp-content/mu-plugins && cp .claude/skills/probar-en-la-nube/crm-demo.php $NUBE/wp/wp-content/mu-plugins/` (fichas inventadas 990041–990049; ampliarlas en el propio fichero si hace falta).
2. Datos de la pantalla que se mira (eventos, pedidos…): un `wp eval-file` propio en el scratchpad que los cree con las clases del plugin.
3. Cuentas y cookies: `$NUBE/bin/wp eval-file .claude/skills/probar-en-la-nube/sesion.php > $NUBE/sesion.json` (perfiles `scouter`, `tesoreria`, `coordinacion`, `familia`).
4. Servidor: `(NUBE=$NUBE nohup php -S localhost:8099 -t $NUBE/wp .claude/skills/probar-en-la-nube/router.php > $NUBE/servidor.log 2>&1 &)`.
5. Lista de páginas en `$NUBE/paginas.json` (`[["scouter","agenda","http://localhost:8099/area-privada/eventos/"], …]`) y capturas:
   `NODE_PATH=$(npm root -g) node .claude/skills/probar-en-la-nube/capturas.js $NUBE/sesion.json $NUBE/paginas.json $NUBE/capturas`
6. Mirar las PNG (herramienta *Read*) y corregir hasta que **ninguna pantalla se salga de ancho**. El tema es Twenty Twenty-One, no Enfold: los botones se ven distintos (no es un fallo del plugin).

## Trampas

- El mu-plugin de demostración **no** debe activarse con WP-CLI (contestaría a las pruebas que leen el CRM real): `crm-demo.php` ya lo evita. Si se han hecho capturas, `probar.sh` borra los transients antes de las pruebas.
- Como root, WP-CLI pide `--allow-root` (ya va en `$NUBE/bin/wp`).
- Chromium está en `/opt/pw-browsers` y Playwright, global (`npm root -g`): no ejecutar `playwright install`.
- El correo no sale (las pruebas lo cortan con `pre_wp_mail`); no hay Mailpit.
- `router.php` corre en el ámbito global, como `index.php`: sus variables llevan prefijo (`$asde_nube_…`). Una `$wp` pisa el objeto de WordPress y todas las páginas dan «error crítico».
- Para parar el servidor, no uses `pkill -f "php -S …"`: el patrón también coincide con la propia orden de la shell y la mata. Mejor `for p in $(pgrep -f '^php -S localhost:8099'); do kill $p; done`.
- Al acabar no hace falta limpiar: la carpeta es de la sesión. Nada de esto va al repo ni al zip.
