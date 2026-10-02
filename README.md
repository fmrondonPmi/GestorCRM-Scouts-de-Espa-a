# Gestor CRM para Grupos Scouts · ASDE Scouts de España

Plugin de WordPress que da a los Grupos Scouts un **área privada** (fichas, usuarios, informes, tesorería, uniformes, domiciliaciones SEPA…) con datos leídos de **SinergiaCRM**. Pensado para el tema Enfold. Todo en español.

- **Versión actual:** 0.14.1
- **Requisitos:** WordPress 6.4 o superior y PHP 8.0 o superior (con libsodium).
- **Licencia:** GPL-2.0-or-later (la declarada en la cabecera del plugin).

## Qué hay en este repositorio

| Carpeta | Contenido |
| --- | --- |
| `asde-area-privada/` | El plugin: `src/` (código, con *autoload* por espacio de nombres `AsdeBalears\AreaPrivada`), `templates/`, `assets/` (CSS y JS), `herramientas/` y `tests/`. |
| `manual/` | Manual de uso en HTML (`index.html` + `capturas/`). Todas las capturas son de un grupo inventado («Grupo Scout Ejemplo, 999») con datos de mentira. |

## Instalación

1. Comprime la carpeta `asde-area-privada/` en un `.zip` **sin la carpeta `tests/`**, con la propia carpeta como raíz del zip.
2. En WordPress: *Plugins → Añadir nuevo → Subir plugin*.
3. Configura la conexión con el CRM y los grupos en el menú *Grupos Scouts → Grupos y conexión CRM* (la URL y las credenciales de cada grupo **no** están en el código).

## Pruebas

Las pruebas están en `asde-area-privada/tests/*.php` y se lanzan con WP-CLI dentro de un WordPress de desarrollo (`wp eval-file wp-content/plugins/asde-area-privada/tests/<fichero>.php`). Usan un **CRM simulado** (`tests/lib/CrmSimulado.php`): no tocan ningún CRM real y cada prueba deja el sitio como estaba. Todas deben terminar con «TODO OK».

## Datos personales

El plugin no guarda en WordPress datos personales en claro (la salud y las dietas no se guardan nunca; lo demás, solo cifrado y por poco tiempo). Este repositorio no contiene datos reales: ni en el código, ni en las pruebas, ni en las capturas del manual.
