# 01 · Contexto y alcance

## Organización

- **ASDE Scouts de España**: federación estatal.
- **ASDE Scouts de Balears**: organización federada. Es la propietaria del site `https://baleares.scout.es/`.
- **Grupos Scouts**: hoy hay uno, el **Grupo Scout Myotragus 684**. Pueden llegar más en el futuro.
- Cada grupo se organiza en **secciones educativas** por edades (ver [05-secciones-educativas.md](05-secciones-educativas.md)).

> **Requisito de diseño:** aunque hoy solo exista un grupo, el modelo de datos y los permisos deben contemplar **N grupos** desde el principio. Todo ámbito de acceso es *grupo + sección*, nunca solo *sección*.

## Qué se va a construir

Dentro del WordPress existente, una sección **"Grupo Scout"** con dos partes:

| Parte | Tipo | Estado |
|---|---|---|
| Información genérica del grupo | Pública | Ya existe: `/nuestro-grupo-scout/` |
| Área privada | Con login y roles | **A construir** |

### Módulos del área privada

| # | Módulo | Estado de la definición |
|---|---|---|
| 0 | Gestión de usuarios (roles y permisos) | Definido a alto nivel → [02-roles-y-permisos.md](02-roles-y-permisos.md) |
| 1 | Gestión de familias | **Pendiente** (el mensaje del usuario se cortó en "1: Gestion de familias, est…") |
| — | Sincronización con CRM (SinergiaCRM) | Solo lectura en v1 → [04-integracion-sinergiacrm.md](04-integracion-sinergiacrm.md) |
| — | Notificación de cambios pendientes | Se definirá más adelante → [07-flujo-de-cambios.md](07-flujo-de-cambios.md) |

## Restricciones confirmadas por el usuario

1. **La fuente de verdad es SinergiaCRM.** Participantes, scouters y membresías (a qué sección pertenece cada persona) se leen del CRM que ya existe.
2. **v1 = solo lectura contra el CRM.** La escritura vía API ("inyección de datos") queda para una versión posterior.
3. **Todo va dentro del site** y **mantiene los estilos del tema** (ver [06-tema-y-estilos.md](06-tema-y-estilos.md)).
4. El site se va a montar en local (copia del WordPress de producción) para desarrollar.

## Consecuencia importante de "v1 solo lectura"

Los roles incluyen acciones de **modificación**: el scouter edita datos de contacto, secretaría lo edita todo, los tutores proponen cambios, etc. Como en v1 no se escribe en el CRM, **toda modificación se guarda en WordPress como "cambio pendiente"**, y:

- se revisa y aprueba en el área privada (flujo de [07-flujo-de-cambios.md](07-flujo-de-cambios.md)),
- una vez aprobado, **alguien lo pasa a mano al CRM** en v1; en v2 lo enviará la API automáticamente,
- mientras tanto, la ficha muestra el dato del CRM junto al valor propuesto o aprobado pendiente de sincronizar.

Así, el paso a v2 solo cambia el último eslabón (manual → API) y no hay que rediseñar nada.
