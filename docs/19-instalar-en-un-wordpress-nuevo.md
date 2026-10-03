# 19 · Instalar en un WordPress nuevo: «Instalación y entorno» (v0.8.0; revisado en la v0.9)

## Petición del usuario (2026-09-27)

> Un botón en el plugin para **generar automáticamente todo el entorno en un WordPress nuevo**.

## Dónde está

- **wp-admin → Grupos Scouts → Instalación y entorno**. También hay un botón en *Grupos y conexión CRM*.
- En un WordPress sin ningún grupo, el **Escritorio** y **Plugins** muestran un aviso con el enlace: «falta preparar el entorno y dar de alta el grupo».
- Solo para administradores (`manage_options`).

## Pasos en un WordPress nuevo

1. **Instala el tema** (Enfold, recomendado: el área está diseñada para él) y ponlo en español (*Ajustes → Generales*).
2. **Instala el plugin:** *Plugins → Añadir nuevo → Subir plugin* con `asde-area-privada-0.9.0.zip` (en la carpeta del proyecto; no incluye las pruebas). Actívalo.
3. **Instalación y entorno:** revisa los ajustes que propone (vienen marcados) y pulsa **Generar / reparar el entorno**.
4. **Organización federada** (v0.9, *Grupos Scouts → Organización federada*, doc 20): elige tu organización, su código para el nº de socio local y el coste federativo por defecto.
5. **Antes de dar de alta los grupos,** añade a `wp-config.php` una **clave de cifrado propia**. La página propone una generada al azar en el momento, que no se guarda en ningún sitio.
6. Sigue la lista **«Queda en tus manos»**:
   - la dirección del CRM y el grupo (nº, nombre, usuario y contraseña del CRM, y «Probar conexión»);
   - Wordfence;
   - un plugin SMTP y el **email de prueba**;
   - el **diagnóstico de tareas en segundo plano** (doc 18).
7. En el área privada, da acceso a Secretaría y Coordinación; ellos invitan al resto.

## Qué hace el botón

**Siempre** (solo afecta al plugin; nunca borra nada):

| Pieza | Qué hace |
|---|---|
| Tablas | Crea las 14 tablas si faltan (`dbDelta`, no toca datos). `asde_solicitudes` es de la v0.10 (doc 22), `asde_uniformes`, de la v0.11 (doc 24) y `asde_sepa_mandatos` y `asde_sepa_remesas`, de la v0.12 (doc 25) |
| Roles | Crea o sincroniza los 6 roles; los administradores reciben todos los permisos del área |
| Páginas (17) | Crea las que faltan y **recupera** las que están en la papelera: la misma página, con su dirección, no una copia. Publica las que estaban sin publicar y **vuelve a poner el shortcode** si alguien lo quitó, conservando el texto que hubiera. Sin barra de título ni migas en Enfold |
| «Acceso socios» | Lo añade al menú principal; también si lo habían borrado a mano |
| Tarea diaria | La programa si no lo está |
| Wordfence | Si está activo, aplica la verificación en dos pasos de los roles del área |

**Ajustes del sitio**: solo se ofrecen si siguen como en un WordPress recién instalado, y solo se aplican los marcados.

| Ajuste | Cuándo se ofrece | Qué hace |
|---|---|---|
| Enlaces permanentes | Están los de por defecto (`?page_id=`) | «Nombre de la entrada» (`/%postname%/`) |
| Registro público | Está abierto | Lo cierra (el alta es solo por invitación) |
| Menú principal | Tema clásico sin ningún menú asignado | Crea «Menú principal» (Inicio + Acceso socios) en la ubicación principal (`avia` en Enfold) |
| «noabort» | Servidor LiteSpeed sin la regla y con el `.htaccess` escribible | La pone al principio del `.htaccess`: copia del original en `.htaccess.asde-copia-FECHA`; si la web responde con error, se deshace sola (doc 18) |

Aunque un ajuste llegue marcado, si ya no hacía falta **no se aplica**.

**La hora no la toca el plugin** (desde la v0.9, decisión del usuario): la zona horaria es un ajuste de WordPress (*Ajustes → Generales*). La v0.8 la comprobaba y la ofrecía; se quitó.

**Se comprueba y se explica, pero no se hace solo:**

- requisitos: PHP 8.0 con sodium y WordPress 6.4;
- organización federada (v0.9): si no está elegida, es el primer paso (doc 20);
- HTTPS e idioma;
- email (detecta los plugins SMTP habituales o `phpmailer_init`; en Local, Mailpit);
- clave de cifrado;
- dirección del CRM y grupos: hacen falta sus credenciales;
- tareas en segundo plano (el último diagnóstico);
- tema.


**Temas de bloques:** su navegación se edita en el Editor del sitio, así que «Acceso socios» hay que añadirlo a mano (la página lo indica con la dirección). Las páginas del área **no salen** en la «Lista de páginas» del tema (`get_pages()`, doc 15).

**Auditoría:** cada generación queda registrada (`entorno_generado`) con el número de cambios, los errores y los ajustes aplicados.

## Piezas

| Clase | Qué hace |
|---|---|
| `Install\Entorno` | `comprobar()`: 19 piezas con estado (la v0.12 añade «Email de contacto del grupo»). `opciones()`: los ajustes que tiene sentido ofrecer. `generar()`: crea o repara y devuelve lo hecho, los avisos y los errores. `poner_noabort()` |
| `Admin\EntornoPage` | La página: estado, ajustes, botón, informe, siguientes pasos, email de prueba y aviso en Escritorio y Plugins |
| `Frontend\Pages::reparar()` / `revisar()` | Reparación y estado de las 17 páginas. `install()` (activación y actualización) también recupera de la papelera en vez de duplicar |
| `Frontend\Visibilidad` | `ubicacion_principal()`, `menu_principal()`, `menu_visible()`, `asegurar_menu( $forzar )` y el filtro `get_pages` |

## Probado (2026-09-27)

**En un WordPress nuevo de verdad.** Se montó con los ficheros de WordPress de Local (sin descargar nada), una base de datos vacía en el MySQL de Local y `wp server`; al acabar se borró todo.

- **Enfold recién activado, sin menús.**
  - Tras activar el plugin: 17 páginas en `/area-privada/`, pero sin menú ni «Acceso socios».
  - La página propuso enlaces permanentes, zona horaria (v0.8; desde la v0.9 ya no) y menú principal. Tras pulsar el botón: enlaces `/%postname%/`, zona Europe/Madrid y «Menú principal» (Inicio + Acceso socios) en la ubicación `avia`.
  - En la web, sin sesión: el menú muestra «Inicio» y «Acceso socios» y ninguna otra página del área. Las privadas redirigen a *Acceso socios*, que sin grupos dice que el acceso no está disponible.
- **Reparación:** «Banco» en la papelera, «Ficha» sin su shortcode y «Acceso socios» borrado del menú.
  - Resultado: recupera la misma página con su dirección, vuelve a poner el shortcode conservando el texto y vuelve a añadir el elemento del menú.
  - La segunda vez: «ya estaba en orden».
  - La primera versión duplicaba la página de la papelera; corregido.
- **`.htaccess`:** sin fichero previo lo crea con la regla. Otra vez, no lo duplica. Con uno previo, pone la regla al principio, conserva el bloque de WordPress y guarda una copia idéntica del original.
- **Tema de bloques mínimo:**
  - «Acceso socios» queda como paso manual y no propone crear menú;
  - la «Lista de páginas» de la cabecera solo muestra la página pública: sin el filtro salían las 18.
- **Aviso** en el Escritorio del sitio nuevo.

**En el Local ya configurado:** `tests/entorno.php`, **19 comprobaciones** (21 en la v0.8), todas OK:

- estado de las 19 piezas, incluida la organización federada y el email de contacto de los grupos;
- «no cambia nada si todo está bien»;
- reparar páginas;
- ajustes solo si se marcan y si hacían falta;
- la zona horaria no la toca aunque llegue marcada (v0.9);
- limpieza.

La prueba deja el sitio como estaba y no toca el menú ni el `.htaccess`.

## Límites

- No instala Wordfence ni un plugin SMTP, ni configura el correo.
- No da de alta los grupos: hacen falta el usuario y la contraseña del CRM.
- No importa diseño ni contenido de la web. El área privada usa los estilos del tema; con Enfold se ve como en la web actual.
- Hasta la v0.8 estaba hecho solo para Scouts de Balears. **Desde la v0.9 sirve para cualquier organización federada:** el nombre de los textos, el código del nº de socio local y el coste federativo se configuran en *Organización federada* (doc 20).

## Pieza «App móvil (PWA)» (v0.17.3)

Con el plugin «App móvil» activo (`asde-area-privada-app`, aparte desde la 0.18; repo GestorCRM-App), «Instalación y entorno» comprueba también la app móvil: apagada (info), sin HTTPS salvo en local (falla, porque sin HTTPS no se puede instalar), enlaces permanentes simples (aviso: la app abarcaría todo el sitio) y sin «Icono del sitio» (info: lleva el icono genérico, con enlace para ponerlo). No cambia nada: la app se enciende, se apaga y se comprueba en *Grupos Scouts → App móvil*.
