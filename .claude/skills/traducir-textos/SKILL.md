---
name: traducir-textos
description: Completa los catálogos de idiomas de asde-area-privada (languages/ca.php, eu.php y gl.php) con los textos nuevos de una versión. Úsala siempre que se añadan o cambien textos visibles (asde_t, asde_e, asde_a, asde_n, asde_ta, TextosJs) y antes de pasar tests/idiomas-catalogos.php.
---

# Traducir los textos nuevos (catalán, euskera y gallego)

El castellano es la clave (doc 28). `languages/es.php` se **regenera** (no se edita a mano) y los otros tres catálogos llevan la traducción de cada texto. Esta skill busca lo que falta, lo deja numerado, y fusiona las traducciones comprobando huecos y etiquetas. Los scripts no necesitan WordPress.

## 1. Regenerar es.php

Con WP-CLI en un WordPress con el plugin activo (Local o el de la skill `probar-en-la-nube`):

```bash
wp eval-file <repo>/herramientas/generar-es.php <ruta-al-plugin>     # en la nube: $NUBE/bin/wp …
```

Hay que lanzarlo **sobre el plugin del repo** (la ruta del segundo argumento), no sobre la copia desplegada, para que el fichero que cambie sea el del repo.

## 2. Qué falta

```bash
php .claude/skills/traducir-textos/faltan.php asde-area-privada <carpeta de trabajo>
```

Dice cuántos faltan y cuáles **sobran** en cada catálogo, y escribe en la carpeta de trabajo (el scratchpad, nunca el repo) `faltan.json` y `faltan.txt` (`N|texto`, una línea por texto, en el orden de `es.php`).

- Lo que sobra es un texto que ya no está en el código (se cambió una frase): quitarlo a mano del catálogo o, si es la misma frase retocada, reutilizar su traducción.

## 3. Traducir

Escribir `trad-ca.txt`, `trad-eu.txt` y `trad-gl.txt` en la carpeta de trabajo con **las mismas líneas y números** que `faltan.txt`: `N|traducción`. Con muchos textos, mejor por tandas de ~100 líneas (con la herramienta *Write*, no con heredocs: las `\` y los `$` de los huecos).

Reglas:
- **Mismos huecos** (`%s`, `%d`, `%1$s`, `%%`) y **mismas etiquetas HTML** que el original. En euskera se puede cambiar el orden con huecos numerados (`%2$s … %1$s`) solo si el original ya los numera.
- Frase completa y natural en cada idioma; no traducir nombres propios, siglas (CRM, SEPA, IBAN, DNI, ASDE) ni datos.
- Mismo registro que el resto del catálogo: botones en **imperativo** en catalán (`Desa`, `Cancel·la`, `Treu`, `Esborra`, `Surt`), infinitivo en gallego y euskera (`Gardar`, `Gorde`).
- Vocabulario ya usado (mantenerlo; buscar en el catálogo antes de inventar uno):

  | Castellano | ca | eu | gl |
  |---|---|---|---|
  | Tesorería | Tresoreria | Diruzaintza | Tesouraría |
  | Coordinación | Coordinació | Koordinazioa | Coordinación |
  | Sección / secciones | Secció / seccions | Atala / atalak | Sección / seccións |
  | Socio/a | Soci/sòcia | Bazkidea | Socio/a |
  | Evento | Esdeveniment | Ekitaldia | Evento |
  | Presupuesto | Pressupost | Aurrekontua | Orzamento |
  | Pedido | Comanda | Eskaera | Pedido |
  | Scouters | Scouters | Scouterrak | Scouters |
  | Castores, Lobatos, Scouts, Escultas, Rovers | Castors, Llobatons, Scouts, Escoltes, Rovers | Kastorrak, Otsokumeak, Eskautak, Eskultak, Roverrak | Castores, Lobatos, Scouts, Escultas, Rovers |

  Para otra palabra: `grep -n "'Palabra'" asde-area-privada/languages/ca.php`.

## 4. Fusionar

```bash
php .claude/skills/traducir-textos/fusionar.php asde-area-privada <carpeta de trabajo> "<Grupo>"
```

`<Grupo>` es el nombre del bloque (el módulo o la versión, p. ej. «Eventos»). Comprueba las tres listas **antes de escribir nada** (número de líneas, numeración, huecos, etiquetas, vacías); si algo falla, no toca ningún catálogo y dice qué línea corregir. Si todo va bien, añade al final de cada catálogo un bloque `// <Grupo>` con los textos que no estaban y comprueba que el fichero sigue siendo PHP válido (si no, lo restaura).

## 5. Comprobar

- `php -l` de los cuatro catálogos.
- `tests/idiomas-catalogos.php` (en el Local con `probar-todo.sh idiomas-catalogos`; en la nube, `probar.sh idiomas-catalogos`): `es.php` al día, 100 % traducido, mismos huecos y etiquetas **permitidas** (el script no comprueba cuáles se permiten: lo hace la prueba con `wp_kses`).
- Las traducciones son una primera versión sin revisión nativa (doc 28): decirlo en el resumen si los textos nuevos son muy visibles (menú, inicio, emails).
