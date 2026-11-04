# 64 · Grids siempre paginados (v0.61, petición del usuario 2026-11-04)

> «En recetas el grid de Lista ingredientes debe ir paginado y lo mismo va para todos los grids. Siempre deben ir paginados y por defecto visualmente se deben visualizar al menos 5 o 10 registros dependiendo de cuánto espacio hay en la pantalla.»

Skill de usuario con la receta paso a paso: `~/.claude/skills/grids-area-privada/`.

## La regla

1. **Toda tabla de datos del área privada va paginada.** Por defecto **10 filas**; **5** en pantallas estrechas (≤ 600 px) si la persona no ha elegido otro tamaño. El selector «Mostrar N por página» (5, 10, 15 … 50) es el de siempre y el tamaño elegido se recuerda en el perfil (`asde_por_pagina`), el mismo en todas las pantallas.
2. **Dos mecanismos, mismo aspecto y mismos textos** (`nav.asde-paginacion`, «Showing %1$s–%2$s of %3$s», «per page»):
   - **Servidor** (`Frontend\Tabla` + `templates/comun/paginacion.php`): cuando la lista puede ser muy larga o lleva orden/filtros en la URL (Personas, Banco, Historial de accesos, Registro de emails…). La tabla lleva `data-asde-paginada="servidor"`.
   - **Navegador** (`assets/js/grids.js`, nuevo en la v0.61): cualquier otra tabla con `<table data-asde-paginar>`. Pagina las filas que ya están en el HTML, vuelve a paginar sola si la lista se recarga por AJAX (`parcial.js`) o se añaden filas, y recuerda el tamaño por el mismo aviso AJAX (`asde_ap_por_pagina`). Una fila con una sola celda de varias columnas es el detalle de la anterior y viaja con ella; una fila con `hidden` no cuenta. Sin JavaScript se ven todas las filas (mejora progresiva).
   - El listado de Usuarios (`data-asde-tabla`, `usuarios.js`) ya paginaba en memoria.
3. **Excepciones** (`data-asde-sin-paginar="motivo"`, motivo obligatorio): tablas de datos fijos o de pocas filas conocidas (resúmenes por secciones, matriz de permisos, comparación campo a campo), **editores de filas** donde se añaden filas en pantalla (líneas de una receta, alta de inventario…) y la **versión para imprimir**. Son 29; el test fija el tope y obliga a revisar con el usuario cualquier otra.
4. Los emails (`templates/email/`) y wp-admin (`src/Admin/`) quedan fuera: no son pantallas del área privada.

## Cómo se comprueba

`tests/grids-paginados.php` (solo lee ficheros): cada `<table>` de `templates/` lleva exactamente una marca; las de servidor llaman a `paginacion()`; las excepciones llevan motivo y no pasan de 29; existe `grids.js`, el Router lo encola, `Tabla` ofrece 5 y 10 con 10 por defecto; ninguna clase de `src/` (salvo wp-admin) imprime una `<table>`.

## Inventario (v0.61)

> **Excepciones pendientes de confirmar por el usuario** (se marcaron con su motivo para que el test pase; cambiar una a paginada es quitar `data-asde-sin-paginar` y poner `data-asde-paginar`).

| Plantilla | Nº de tabla | ¿Paginada? | Filas por defecto | Nota / motivo de la excepción |
|---|---|---|---|---|
| `accesos/historial.php` | 1 | Sí (servidor, `Tabla`) | 10 (elegible) | orden y filtros en la URL |
| `accesos/matriz.php` | 1 | **Excepción** | todas | matriz fija de roles y permisos |
| `accesos/persona.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `accesos/personas.php` | 1 | Sí (servidor, `Tabla`) | 10 (elegible) | orden y filtros en la URL |
| `alta/buscar.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `comun/conceptos.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `eventos/agenda.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `eventos/config.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `eventos/config.php` | 2 | **Excepción** | todas | editor de la plantilla del evento |
| `eventos/cuentas.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `eventos/cuentas.php` | 2 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `eventos/cuentas.php` | 3 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `eventos/equipo.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `eventos/formulario.php` | 1 | **Excepción** | todas | editor de plazas por seccion (pocas filas fijas) |
| `eventos/inscripciones.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `eventos/material.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `eventos/menu.php` | 1 | **Excepción** | todas | rejilla fija de dias y comidas |
| `eventos/menu.php` | 2 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `eventos/presupuesto.php` | 1 | **Excepción** | todas | editor de partidas del presupuesto |
| `eventos/presupuesto.php` | 2 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `fichas/cambios.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `fichas/cambios.php` | 2 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `fichas/cambios.php` | 3 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `fichas/cambios.php` | 4 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `fichas/ficha.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `grupo/datos.php` | 1 | **Excepción** | todas | tabla de datos fijos / resumen sin bucle |
| `informes/imprimir.php` | 1 | **Excepción** | todas | version para imprimir |
| `informes/lista.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `informes/resultado.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `inventario/compra.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `inventario/compras.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `inventario/importar.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `inventario/lista.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `inventario/nueva.php` | 1 | **Excepción** | todas | editor de alta (se anaden filas en pantalla) |
| `inventario/nueva.php` | 2 | **Excepción** | todas | editor de alta (se anaden filas en pantalla) |
| `inventario/tipos.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `lista-espera/listado.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `lista-espera/listado.php` | 2 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `menus/ingredientes.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `menus/receta.php` | 1 | **Excepción** | todas | editor de lineas de la receta (se anaden filas en pantalla) |
| `menus/receta.php` | 2 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `menus/recetas.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `menus/recetas.php` | 2 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `pagos/mis-pagos.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `pagos/mis-pagos.php` | 2 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `personas/listado.php` | 1 | Sí (servidor, `Tabla`) | 10 (elegible) | orden y filtros en la URL |
| `reintegros/index.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `solicitudes/detalle.php` | 1 | **Excepción** | todas | comparacion campo a campo de una ficha |
| `solicitudes/listado.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tesoreria/banco-asociar.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tesoreria/banco-previa.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tesoreria/banco-previa.php` | 2 | Sí (servidor, `Tabla`) | 10 (elegible) | orden y filtros en la URL |
| `tesoreria/banco-revisar.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tesoreria/banco-tipos.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tesoreria/banco.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tesoreria/banco.php` | 2 | Sí (servidor, `Tabla`) | 10 (elegible) | orden y filtros en la URL |
| `tesoreria/caja-banco.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tesoreria/caja.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tesoreria/control.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tesoreria/cuotas.php` | 1 | **Excepción** | todas | resumen por secciones o categorias (pocas filas fijas) |
| `tesoreria/familia-banco.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tesoreria/familia-editar.php` | 1 | **Excepción** | todas | editor de los datos de una familia |
| `tesoreria/familia.php` | 1 | **Excepción** | todas | tabla de datos fijos / resumen sin bucle |
| `tesoreria/familia.php` | 2 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tesoreria/familia.php` | 3 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tesoreria/familias.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tesoreria/federacion.php` | 1 | **Excepción** | todas | resumen por secciones o categorias (pocas filas fijas) |
| `tesoreria/federacion.php` | 2 | **Excepción** | todas | resumen por secciones o categorias (pocas filas fijas) |
| `tesoreria/federacion.php` | 3 | **Excepción** | todas | tabla de datos fijos / resumen sin bucle |
| `tesoreria/libro-mayor.php` | 1 | **Excepción** | todas | resumen por secciones o categorias (pocas filas fijas) |
| `tesoreria/libro-mayor.php` | 2 | **Excepción** | todas | resumen por secciones o categorias (pocas filas fijas) |
| `tesoreria/libro-mayor.php` | 3 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tesoreria/presupuesto-seccion.php` | 1 | **Excepción** | todas | tabla de datos fijos / resumen sin bucle |
| `tesoreria/presupuesto-seccion.php` | 2 | **Excepción** | todas | resumen por secciones o categorias (pocas filas fijas) |
| `tesoreria/presupuesto-seccion.php` | 3 | **Excepción** | todas | editor de importes por partida |
| `tesoreria/presupuesto-seccion.php` | 4 | **Excepción** | todas | tabla de datos fijos / resumen sin bucle |
| `tesoreria/presupuesto-seccion.php` | 5 | **Excepción** | todas | resumen por secciones o categorias (pocas filas fijas) |
| `tesoreria/presupuestos.php` | 1 | **Excepción** | todas | tabla de datos fijos / resumen sin bucle |
| `tesoreria/presupuestos.php` | 2 | **Excepción** | todas | resumen por secciones o categorias (pocas filas fijas) |
| `tesoreria/presupuestos.php` | 3 | **Excepción** | todas | tabla de datos fijos / resumen sin bucle |
| `tesoreria/presupuestos.php` | 4 | **Excepción** | todas | resumen por secciones o categorias (pocas filas fijas) |
| `tesoreria/sepa-remesa.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tesoreria/sepa.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tesoreria/sepa.php` | 2 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tesoreria/sepa.php` | 3 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tienda/banco-sugerencias.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tienda/gestion.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tienda/informe.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tienda/informe.php` | 2 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tienda/informe.php` | 3 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tienda/producto.php` | 1 | **Excepción** | todas | editor de variantes del producto |
| `tienda/productos-importar.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tienda/productos.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tienda/proveedor.php` | 1 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `tienda/proveedor.php` | 2 | Sí (navegador, grids.js) | 10 (5 en móvil) |  |
| `usuarios/emails.php` | 1 | Sí (servidor, `Tabla`) | 10 (elegible) | orden y filtros en la URL |
| `usuarios/listado.php` | 1 | Sí (navegador, `usuarios.js`) | 10 (elegible) | filtros y orden en memoria |

## Cómo se comprobó en el navegador

WordPress aparte (`wp-aparte/montar.sh`, datos inventados): «Lista de ingredientes» con 50 ingredientes → 10 filas en escritorio, 5 en estrecho, páginas, cambio de tamaño que se recuerda, sin desbordes a 390 px (tarjetas) ni en escritorio.
