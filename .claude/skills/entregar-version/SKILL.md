---
name: entregar-version
description: Cierre de una versión del plugin asde-area-privada (subir el número de versión, comprobar, actualizar los docs 00/09/10 y el del módulo, generar el zip, commit en español y resumen al usuario). Úsala cada vez que se termina un cambio que se va a entregar como versión nueva (0.X.Y), aunque sea un arreglo pequeño.
---

# Entregar una versión

Orden fijo; no saltar pasos. Todo en español.

## 1. Número de versión

- [ ] `asde-area-privada/asde-area-privada.php`: la cabecera `Version:` **y** `define( 'ASDE_AP_VERSION', '…' )`, con el mismo número. Al cambiar, `Installer::maybe_upgrade` reinstala roles, tablas y páginas en cada sitio.
- [ ] Nueva funcionalidad → sube el segundo número (0.19.0); arreglo o paso intermedio → el tercero (0.19.1).

## 2. Comprobar

- [ ] `php -l` de todo el plugin: `find asde-area-privada -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v 'No syntax errors'` (no debe salir nada).
- [ ] Revisar que no se hayan comido las `\` de `AsdeBalears\AreaPrivada\…` en lo editado con scripts.
- [ ] Nada de datos reales, credenciales ni zips en el diff (`git diff --stat`, `git status`). Emails de ejemplo con `@example.invalid`.
- [ ] Pruebas: en el entorno del usuario, `herramientas-desarrollo/probar-todo.sh` hasta **«TODO OK»** (nunca cortarlo a medias). Anotar el recuento (comprobaciones y ficheros). En una sesión en la nube sin Local, **decir que no se han podido lanzar**; no inventar el recuento.
- [ ] Pantallas nuevas o cambiadas, vistas con datos inventados en escritorio y a 390 px (`herramientas-desarrollo/vistas-movil.php`).

## 3. Documentación

- [ ] `docs/00-INDICE.md`: bullet nuevo **arriba** del bloque «Estado (fecha) · plugin vX.Y.Z» (actualizar la fecha y la versión de ese título), con petición del usuario y fecha, qué cambia para quien lo usa, pruebas (nuevas y total) y pendiente; fila nueva en la tabla si hay doc nuevo.
- [ ] Doc del módulo: lo nuevo, en su sitio (no solo un añadido al final si cambia lo que había).
- [ ] `docs/10-entorno-local.md`: recuento y lista de pruebas.
- [ ] `docs/09-preguntas-abiertas.md`: decisiones del usuario a «Resueltas» (✅ con fecha) y preguntas nuevas con el siguiente número libre.
- [ ] Manuales de uso si cambia lo que ve alguien (`manual/fuentes/`, `php herramientas/generar-manuales.php`).

## 4. Zip (en el Windows del usuario)

- [ ] `& 'C:\Claude\Scouts De Balears  - Area Privada\herramientas-desarrollo\construir-zip.ps1'` desde la herramienta PowerShell (sin `-Version` toma la de la cabecera). Crea `asde-area-privada-<versión>.zip` **sin `tests/`**; **no sobrescribe** uno existente salvo con `-Sobrescribir` (no usarlo sin motivo: se conservan los zips anteriores).
- [ ] Los zips **no** van al repo (`.gitignore`). En la nube no se puede generar: decirlo en el resumen.

## 5. Git

- [ ] Commit en español, una línea de título con la versión («v0.19.0: …») y, si hace falta, cuerpo con el porqué.
- [ ] **`git push` solo si el usuario lo pide** (`CLAUDE.md`). En una sesión en la nube el contenedor se borra: preguntar al usuario si quiere que se suba antes de terminar.
- [ ] **Claude no publica en producción**: ni el zip ni el manual.

## 6. Resumen al usuario

En español y corto:

- qué cambia para cada perfil (familias, scouters, Tesorería, Coordinación…);
- pruebas (nuevas y total, o que no se han podido lanzar);
- **lo que tiene que decidir** (preguntas P-NN) y **los supuestos** hechos sin preguntar;
- **lo que tiene que hacer en producción** (subir el zip por *Plugins → Subir plugin*; si hay app, primero este plugin y después la app; ajustes nuevos que nacen apagados).
