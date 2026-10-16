# 47 · Avisos y normativa de los eventos configurables por entidad

**Motivo (2026-10-16).** El plugin lo puede usar cualquier entidad federada de ASDE (Scouts de España), y cada una puede
avisar de forma distinta: otros documentos, otros plazos, otros ratios. Se revisó el módulo de eventos (doc 43) para ver qué
es configurable hoy.

## 1. Situación antes de este plan (v0.37)

Configuración por grupo en `Eventos\Config` (opción `asde_ap_eventos_<grupo>`, pantalla *Eventos · configuración*):

| Cosa | ¿Configurable? |
|---|---|
| Días del recordatorio (`recordatorio_dias`, 0 = no se manda) | **Sí** |
| Texto de la autorización firmada (con versión) y texto de cómo pagar | **Sí** |
| Tipos de actividad (coste, comunicación), margen del fondo, titulaciones | **Sí** |
| Qué avisos se mandan (publicado, confirmada, plaza, recordatorio, cancelado) | No: siempre los cinco |
| Aviso push para la app móvil | No: siempre se dispara |
| Días para confirmar una plaza de la lista de espera | No: constante `DIAS_OFERTA = 3` |
| Edad desde la que la persona recibe el aviso en su cuenta | No: constante `EDAD_PREINSCRIPCION = 14` (hecho en la fase 2) |
| Texto de los emails de aviso | No: plantilla `email/evento.php` + traducciones (párrafos propios en la fase 2) |
| Ratios y plazos de la normativa (`Normativa`): 30 días, 2 noches, 10 menores, 1 monitor/a por 10, certificado de 12 meses, secciones menores | No: constantes |
| Documentos exigidos (certificado de delitos sexuales, formación, autorización) | Solo el texto de la autorización |

## 2. Plan por fases

### Fase 1 · Avisos (hecha en la v0.39)
En `Config`: `avisos` (un interruptor por tipo), `push` y `dias_oferta` (1–30). Por defecto todo igual que antes, así que
ningún grupo cambia de comportamiento al actualizar. Se comprueban en `Avisos` (programar, masivo, a_inscripcion, diario,
push) e `Inscripciones::dias_oferta()`. Pantalla: tarjeta *Avisos* en la configuración. Prueba: `tests/eventos-avisos-config.php`.

### Fase 2 · Quién avisa y con qué texto (hecha en la v0.39, salvo el aviso al equipo)
- `edad_cuenta_propia` (14 por defecto, 10–18): edad desde la que la cuenta propia recibe avisos y la persona se inscribe sola.
- `textos`: un párrafo propio al principio y otro al final de cada email de aviso (hasta 600 caracteres; en el idioma que
  escriba el grupo, igual para todos). Se escapan al pintar el email.
- `recordatorio_extra`: segundo recordatorio, N días antes (menor que el primero). Sin columna nueva: se manda si el primero
  salió antes de que empezara la ventana del segundo (`recordatorio_en`).
- **Pendiente:** avisar al equipo (scouters) de las inscripciones nuevas y de la lista de espera; hace falta decidir a quién
  (director/a, responsable de sección) y con qué frecuencia (aviso a cada inscripción o resumen diario).

### Fase 3 · Normativa por entidad
Mover las constantes de `Normativa` a un bloque `normativa` de la configuración, con los valores actuales por defecto:
`dias_comunicacion`, `noches_comunicacion`, `menores_comunicacion`, `participantes_monitor`, `meses_certificado`,
`secciones_menores`. Cada federación pone los suyos (la ley y los estatutos cambian de una a otra). Hay que decidir:
¿la configuración la cambia cada grupo o solo la entidad (una opción global que los grupos heredan)? Recomendado: **plantilla
de la entidad + cambio por grupo solo si la entidad lo permite**.

### Fase 4 · Plantilla de entidad
Opción global (`asde_ap_eventos_entidad`) que da los valores por defecto a los grupos nuevos, con exportar/importar la
configuración en JSON para repartirla entre grupos.

## 3. Criterios
- Siempre con valores por defecto iguales a los actuales (sin migración ni cambio de comportamiento).
- Textos nuevos de la interfaz con traducciones a ca/eu/gl (skill `version-area-privada`).
- Cada fase con su prueba en `tests/` y su apartado en el manual de la persona responsable de eventos.

## 4. Preguntas abiertas
- **P-171.** ¿Quién configura la normativa: cada grupo o la entidad (con herencia)?
- **P-172.** (Resuelta con un supuesto: dos recordatorios como máximo.) ¿Hacen falta más?
- **P-173.** ¿Los textos de los emails deben ser editables por el grupo o solo por la entidad?
