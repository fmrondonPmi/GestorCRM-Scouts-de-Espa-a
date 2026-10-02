# PWA del área privada · punto de entrada

> **Leer este fichero primero y después `ESTADO.md`.** Con eso basta para retomar el trabajo sin volver a explorar el código. `ARQUITECTURA.md` solo si se toca el diseño.

## Qué es
Convertir el área privada (plugin `asde-area-privada`) en una **PWA**: la misma web, instalable en Android e iPhone con icono propio y pantalla completa, y con una pantalla «sin conexión». **No es una app nativa ni se publica en las tiendas** (eso sería el paso B, Capacitor, sobre este mismo trabajo). Decisión del usuario el 2026-09-30.

## Reglas de trabajo (del usuario, 2026-09-30)
1. **Una sección cada vez.** No se empieza la siguiente hasta cerrar la anterior (código + pruebas + docs).
2. **Sin paralelizar de más:** lo normal es trabajar directamente, sin agentes. Si hace falta, **2 como máximo, 3 en un caso excepcional**; el objetivo es **reducir** el consumo de tokens, no aumentarlo.
3. **Documentar al cerrar cada sección** en esta carpeta (`ESTADO.md`, y `ARQUITECTURA.md` si cambia el diseño), para no tener que releer el código.
4. Las normas generales del proyecto siguen igual: pruebas `wp eval-file` («TODO OK»), docs del proyecto al día, zip nuevo sin `tests/`, y el usuario decide qué sube a producción. Memoria: `proyecto-scouts-area-privada`.

## Ficheros de esta carpeta
| Fichero | Para qué |
|---|---|
| [ESTADO.md](ESTADO.md) | Qué secciones están hechas, cuál toca y los ficheros tocados. **Se actualiza al cerrar cada sección.** |
| [ARQUITECTURA.md](ARQUITECTURA.md) | Decisiones de diseño, rutas virtuales, alcance (*scope*), qué se cachea y qué **nunca**, trampas conocidas |

## Dónde está todo
- Código de la PWA: `asde-area-privada/src/Pwa/` (clases), `asde-area-privada/assets/pwa/` (iconos, JS y página sin conexión).
- Pruebas: `asde-area-privada/tests/pwa.php` (se lanza como las demás, doc 10).
- Entorno: WordPress en Local `http://localhost:10011`; script WP-CLI en el doc 10 (hay una copia lista en el directorio temporal de la sesión, pero se puede recrear desde el doc).
