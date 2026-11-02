# 63 · Historial de emails y reenvío de avisos de eventos (v0.60)

Petición del 2026-11-02, rama `Correos-Historico-Reenvio` (sale de `Traducciones-WpOrg`, doc 62).

## 1. El aviso de publicación no llegó al equipo de scouters

**Qué pasó.** En producción se publicó un evento y se marcó avisar a los scouters; salió el email a las familias y no al equipo.

**Causa.** En `Eventos\Avisos::masivo_en_grupo()` el destino «scouters» se calculaba a partir de las **personas convocadas** (socias activas de una sección convocada y con la edad del evento) y, de entre ellas, las adultas voluntarias. Las secciones convocadas de un evento son las educativas (Lobatos, Scouts…): el **equipo del evento** (director/a, monitores, prácticas, apoyo, tabla `asde_evento_inscripciones` con `papel` de equipo) no es «convocado» y, además, los límites de edad del evento lo dejan fuera. Resultado: «Scouters» solo escribía a voluntariado cuya sección estuviera convocada, es decir, casi nunca al equipo. La opción «Todos» tenía el mismo fallo.

**Arreglo.** `Avisos::destinatarios_publicado()`: «familias» = convocadas no adultas voluntarias; «scouters» = adultas voluntarias convocadas **más el equipo del evento** (leído del CRM). Lo usan el aviso al publicar y el reenvío.

Nota: el aviso del grupo se manda **una sola vez** (`avisado_en`). Si al publicar se marcó solo «Familias», los scouters no podían recibirlo después: de ahí el reenvío.

## 2. Reenviar avisos / invitaciones

En el resumen de un evento **publicado** (y no cerrado), quien lo gestiona (`Servicio::puede_reenviar`) tiene el botón «Reenviar avisos / invitaciones» (ventana como la de publicar; sin JavaScript, el formulario va en `<noscript>`). Se elige:

- **Familias** (con la casilla «solo las que aún no se han inscrito», marcada por defecto);
- **Scouters (equipo del evento)**;
- **Una persona** (lista con el equipo y las personas inscritas).

Sale el mismo email que al publicar (asunto «Nuevo evento…»). A una persona, en el momento (`Avisos::reenviar()`); a un grupo, en segundo plano con WP-Cron (`asde_ap_eventos_reenvio`). Hace falta que el aviso «evento publicado» esté activo en la configuración de eventos. Cada email queda en el registro de emails y cada reenvío en el registro de actividad (`evento_aviso_reenviado`). Una persona solo se acepta si está en el equipo, inscrita o convocada.

## 3. Historial de emails

Ya existía el **Registro de emails** (hoja de «Usuarios», capability `asde_manage_users`) y **todos** los módulos pasan por `RegistroEmails::enviar()/registrar()` (invitaciones, SEPA, tienda, eventos, hoja de cocina, alérgenos, lista de espera, reintegros, material, altas de grupo…). Se ha convertido en el historial completo:

- Filtros en una línea: tipo (módulo), estado, **desde / hasta** (día completo, hora del sitio), texto (asunto, usuario o dirección) y «quitar filtros».
- Nueva columna **Asunto** (ordenable); hasta 5.000 filas por consulta.
- No es un módulo: no depende de ningún interruptor de Módulos. Está en **Usuarios → Registro de emails** (la hoja que ya existía; no se añade entrada de menú aparte para no repetir los enlaces de las hojas).

El destino sigue guardado enmascarado y sin datos personales (regla del registro).

## 4. Pruebas

- `tests/eventos-inscripciones.php`: equipo como destinatario, reenvío a persona / familias / equipo, «solo sin inscribir», permisos y pantalla.
- `tests/grupos-emails-banco.php`: filtros del historial (tipo, estado, texto, fechas).
- Textos nuevos traducidos en los cinco `.po` (los dos plurales que quedaban en castellano en wp-admin pasan a inglés).

## 5. Pendiente / ideas

- Historial: exportar a Excel, ver quién disparó cada email (`autor_id` ya se guarda), reenviar desde el propio historial.
- El reenvío manda siempre el aviso de publicación; no hay reenvío de recordatorios ni de confirmaciones.
