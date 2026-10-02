# 28 · Uniformes para scouters, menús por rol, independencia del tema, colores e idiomas (v0.15)

Petición del usuario (2026-10-02). Cinco cambios; cada uno se cierra con sus pruebas y va en **su propio commit**.

| # | Cambio | Estado |
|---|---|---|
| 3 | Todo scouter pide su uniforme, no solo lo gestiona | ✅ |
| 2 | Menús distintos para scouters (gestión) y familias | ⏳ |
| 4 | El plugin no depende del tema: se sugiere uno, no es obligatorio | ⏳ |
| 5 | Pantalla de gestión de colores | ⏳ |
| 1 | Selector de idioma (castellano, catalán, euskera, gallego) y gestión de etiquetas | ⏳ |

---

## 3 · Los scouters también piden su uniforme

**Qué pasaba.** El permiso ya lo tenían (`asde_uniformes_pedir`) y `Servicio::para_quien()` incluye la ficha propia, pero en el menú principal el scouter solo veía «Uniformes» (la gestión): el formulario de pedido quedaba dentro de una subpestaña («Mis pedidos»), poco visible, y las tareas pendientes de entrega y de confirmar se mezclaban en un solo contador.

**Qué hay ahora.** Quien puede pedir y gestionar tiene **dos** entradas: «Mi uniforme» (pedir, seguir su pedido y confirmar la recepción; el contador son los pedidos por confirmar) y «Gestión de uniformes» (el contador son los pagos, inclusiones en el pedido y entregas pendientes). Quien solo pide (familias, participantes) sigue viendo «Uniforme».

**Decisiones / supuestos.**
- Un scouter pide **solo para sí mismo**; no para otros de su sección (los gestiona, no los pide). Lo comprueba una prueba.
- No cambian las capabilities ni los permisos.

Pruebas: `tests/uniformes.php` (pestañas del scouter, el formulario de pedido que se ve y el pedido de un scouter que no es de Tesorería).
