# PWA del área privada · punto de entrada

> **Leer este fichero primero y después `ESTADO.md`.** Con eso basta para retomar el trabajo sin volver a explorar el código. `ARQUITECTURA.md` solo si se toca el diseño. Para **publicar en producción**: `MANUAL-PUBLICACION.md`.

## Qué es
Convertir el área privada (plugin `asde-area-privada`) en una **PWA**: la misma web, instalable en Android e iPhone con icono propio y pantalla completa, y con una pantalla «sin conexión». **No es una app nativa ni se publica en las tiendas** (eso sería el paso B, Capacitor, sobre este mismo trabajo). Decisión del usuario el 2026-09-30.

## Reglas de trabajo (del usuario, 2026-09-30)
1. **Una sección cada vez.** No se empieza la siguiente hasta cerrar la anterior (código + pruebas + docs).
2. **Sin paralelizar de más:** lo normal es trabajar directamente, sin agentes. Si hace falta, **2 como máximo, 3 en un caso excepcional**; el objetivo es **reducir** el consumo de tokens, no aumentarlo.
3. **Documentar al cerrar cada sección** en esta carpeta (`ESTADO.md`, y `ARQUITECTURA.md` si cambia el diseño), para no tener que releer el código.
4. Las normas generales del proyecto (`CLAUDE.md` de la raíz del repo) siguen igual: pruebas «TODO OK», docs del proyecto al día, zip nuevo sin `tests/`, y **el usuario decide qué sube a producción** (Claude no publica).

## Ficheros de esta carpeta
| Fichero | Para qué |
|---|---|
| [ESTADO.md](ESTADO.md) | Qué secciones están hechas, cuál toca y los ficheros tocados. **Se actualiza al cerrar cada sección.** |
| [ARQUITECTURA.md](ARQUITECTURA.md) | Decisiones de diseño, rutas virtuales, alcance (*scope*), qué se cachea y qué **nunca**, trampas conocidas |
| [MANUAL-PUBLICACION.md](MANUAL-PUBLICACION.md) | **Cómo publicar la app en producción:** requisitos, subida, caché de LiteSpeed/CDN, comprobaciones con `curl`, prueba en móviles, apagado y vuelta atrás, texto para familias |

## Dónde está todo (desde el 2026-10-02, en el repo `C:\Proyectos\GestorCRM`)
- Código: `asde-area-privada/src/Pwa/` (clases), `asde-area-privada/assets/pwa/` (iconos, `sw.js`, `sw-apagado.js`, `registro.js`), `asde-area-privada/templates/pwa/sin-conexion.php`.
- Pruebas: `asde-area-privada/tests/pwa.php` (108 comprobaciones). **No** entra en `probar-todo.sh` sin argumentos (se salta a propósito): se lanza con `herramientas-desarrollo/probar-todo.sh pwa`.
- Entorno: WordPress en Local `http://localhost:10011`; `herramientas-desarrollo/wp-local.sh` (carpeta antigua del proyecto, fuera del repo).
- La PWA forma parte del plugin desde la **v0.17.0**; los zips anteriores (≤ 0.16.0) se construyeron sin ella.
