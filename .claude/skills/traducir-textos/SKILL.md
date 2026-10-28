---
name: traducir-textos
description: Pasa los textos nuevos de una versión de asde-area-privada a las traducciones (traducciones/*.po: es_ES, gl_ES, eu, ca y ca_valencia). Úsala siempre que se añadan o cambien textos visibles (__(), esc_html__(), _n(), asde_guardado(), TextosJs) y antes de pasar tests/idiomas-catalogos.php.
---

# Traducir los textos nuevos (doc 62)

Los textos del código están **en inglés** con gettext estándar (`__()`, `_n()`…) y el dominio `asde-scouts-de-espana-gestor-crm-para-grupos-scouts`. Las traducciones viven fuera del plugin, en `traducciones/` (el zip no las lleva): `*.pot` (generado), un `.po` por idioma y `es-en.json` (el mapa castellano → inglés de la migración, ya no se actualiza). Los idiomas definitivos los llevan los traductores en **translate.wordpress.org**; los `.po` del repo sirven de semilla (se importan allí) y para las pruebas.

## 1. Escribir el texto

- Frase completa en inglés dentro de `__()`; con partes que cambian, `sprintf` y marcadores numerados si hay dos o más (`%1$s`, `%2$d`), con `/* translators: 1: name, 2: date */` justo antes.
- Plurales con `_n( 'singular', 'plural', $n, DOM )`.
- Un texto que se **guarda** en castellano y se muestra después (plantillas, semillas…) va en `Idiomas\Guardados::mapa()` con su `__()` en inglés, y se muestra con `asde_guardado()`.
- JS: `asdeT( 'English text' )` y el texto en `TextosJs::diccionario()`.

## 2. Regenerar

```bash
php herramientas/generar-po.php --mo      # PHP de Local en el PATH; escribe traducciones/*.pot, *.po y mo/
```

Extrae los textos nuevos al `.pot` y los añade a cada `.po` **sin tocar lo ya traducido**. Los textos sin traducción salen con `msgstr ""`.

## 3. Traducir lo que falte

Abrir cada `traducciones/asde-…-<idioma>.po`, buscar `msgstr ""` y rellenar (es_ES, gl_ES, eu, ca; ca_valencia parte del catalán y se ajusta al valenciano). Reglas:
- **Mismos marcadores** (`%s`, `%d`, `%1$s`, `%%`) y **mismas etiquetas HTML** que el original.
- Frase completa y natural; no traducir nombres propios ni siglas (CRM, SEPA, IBAN, DNI, ASDE).
- Botones en **imperativo** en catalán (`Desa`, `Cancel·la`), infinitivo en gallego y euskera (`Gardar`, `Gorde`).
- Vocabulario ya usado: buscar en el `.po` antes de inventar (`grep -n 'msgid "Treasury"' …`). Secciones: Castores, Lobatos, Scouts, Escultas, Rovers (Castors, Llobatons, Scouts, Escoltes, Rovers; Kastorrak, Otsokumeak, Eskautak, Eskultak, Roverrak).

Vuelve a lanzar el paso 2 para compilar los `.mo`.

## 4. Comprobar

`php -l` de lo tocado; `tests/idiomas-catalogos.php` (el `.pot` recoge el código; marcadores y etiquetas iguales en los cinco `.po`), `tests/idiomas.php` y `tests/estilo-wordpress.php` (`probar-todo.sh idiomas idiomas-catalogos estilo-wordpress`).

Las traducciones son una primera versión sin revisión nativa: decirlo en el resumen si los textos nuevos son muy visibles (menú, inicio, emails).
