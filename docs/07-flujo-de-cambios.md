# 07 · Flujo de cambios pendientes

> **Implementado en v0.7** (doc 17), con la escritura en el CRM ya disponible y estas simplificaciones:
>
> - **Estados:** `pendiente` → `aplicado` (aprobado y escrito en el CRM) | `error` (el CRM no lo aceptó) | `rechazado` | `cancelado`. Ya no hace falta «sincronizado a mano»: al aprobar se escribe directamente.
> - Tabla `{prefix}asde_cambios`, por grupo y nº de socio, con los valores anterior y nuevo **cifrados** (todos, no solo los de salud).
> - Solo proponen las **cuentas familiares**. Los demás roles editan directamente lo que les permite la matriz.
> - Se mantienen la detección de conflictos, la sustitución por el cambio más reciente, el motivo obligatorio al rechazar, la bandeja por ámbito, la auditoría y los ganchos de notificación.
>
> Las notificaciones las definirá el usuario más adelante. Aquí queda el modelo base para que no haya que rehacerlo.

## Estados

```
            (rol con 📝)                     (rol aprobador)
propuesto ──────────────► pendiente_aprobacion ──► aprobado ──► sincronizado
    │                           │                     │          (v1: manual
    │ (rol con ✅)              └──► rechazado        │           v2: API)
    └──────────────────────────────────────────────►──┘
                                        cancelado ◄── (quien lo propuso, mientras está pendiente)
```

- **pendiente_aprobacion**: lo ha propuesto un tutor, o un participante si P-06 así lo decide.
- **aprobado**: lo ha aprobado un scouter de la sección, secretaría o coordinación, o lo ha creado directamente un rol con ✅.
- **sincronizado**: el dato ya está en el CRM. En v1 lo marca a mano quien lo introduce en el CRM. En v2 lo marca el proceso de envío por API.
- **rechazado**: el motivo es obligatorio y se muestra a quien lo propuso.

## Tabla propia en WordPress: `{prefix}asde_cambios`

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| grupo_crm_id | varchar(36) | ámbito |
| contacto_crm_id | varchar(36) | a quién afecta el cambio |
| modulo_crm | varchar(64) | `Contacts`, módulo de salud, etc. |
| registro_crm_id | varchar(36) null | registro concreto, si no es el Contact |
| campo | varchar(128) | nombre técnico del campo en el CRM |
| valor_anterior | longtext | el que había en el CRM al proponer (para detectar conflictos) |
| valor_nuevo | longtext | **cifrado si es un dato de salud** |
| estado | enum | los de arriba |
| propuesto_por | bigint (user id) | |
| propuesto_en | datetime | |
| revisado_por / revisado_en | | |
| motivo_rechazo | text null | |
| sincronizado_por / sincronizado_en | | |

## Reglas

- **Detección de conflictos:** al aprobar o sincronizar, si el valor actual en el CRM ya no coincide con `valor_anterior`, se avisa al aprobador en lugar de sobrescribir sin más.
- **Varios cambios pendientes sobre el mismo campo:** se muestran agrupados y el más reciente sustituye a los anteriores.
- **Visualización en la ficha:** se ve el dato del CRM y, al lado, una etiqueta con el cambio en curso ("Pendiente de aprobación" / "Aprobado, pendiente de pasar al CRM").
- **Bandeja de revisión:** cada aprobador ve solo los cambios de su ámbito (sección o grupo).
- **Exportación para v1:** la bandeja de "aprobados sin sincronizar" se puede exportar a CSV para introducirlos en el CRM a mano o por importación.
- Todo cambio de estado queda en el **registro de auditoría** (ver [08](08-proteccion-de-datos-y-seguridad.md)).

## Notificaciones (a definir por el usuario)

Enganches previstos en el código (`do_action`), para conectar después email, aviso en el área privada, etc.:

- `asde_cambio_propuesto`
- `asde_cambio_aprobado`
- `asde_cambio_rechazado`
- `asde_cambio_sincronizado`
