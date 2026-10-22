# 50 · Modo Oficina y alta de grupos por invitación (estado en la v0.51)

Un mismo plugin se instala en dos modos: **de Grupo** (lo de siempre) y **de Oficina** (la federación consulta los grupos y los da de alta). Se elige en wp-admin → Grupos Scouts → Organización federada → *Tipo de instalación*, o con la constante `ASDE_AP_MODO` en wp-config.php (manda sobre la opción). Cambiarlo no borra datos y, sin guardar, es de Grupo.

## Credencial y rol «Oficina»

- `Domain\Oficina` (opción `asde_ap_oficina`, sin autoload): usuario y contraseña del CRM de la oficina; la contraseña, cifrada con `Crm\Crypto`, nunca sale en HTML, auditoría ni logs. «Probar la conexión» cuenta los Grupos Scouts que ve. La web **no puede comprobar** que la credencial sea de solo lectura en el CRM: lo afirma quien la configura.
- Rol `asde_oficina`: `asde_multigrupo`, `asde_solo_lectura`, `asde_manage_users`, `asde_reports`, `asde_view_health`. Ninguna capability de edición, tesorería, tienda, inventario, eventos, lista de espera ni reintegros. Lo crea el administrador de WordPress (Coordinación de un grupo no lo puede asignar); **2FA de Wordfence obligatoria**.
- `ClientFactory::reader()` usa la credencial de la oficina solo en una web de oficina, con la capability multigrupo y con el grupo elegido verificado (`crm_id`); si no, no lee nada.

## Lo que ve y no puede hacer

- Entra **sin ficha** del CRM y solo abre Inicio, Personas, ficha, Informes, Usuarios y Seguridad (`Oficina::PAGINAS`).
- **Solo lectura:** el Router rechaza todo envío de formulario (salvo elegir grupo), `Crm\Escritura` se niega a escribir, `SoloLectura::limpiar()` quita de cada pantalla formularios y enlaces de alta, acceso y membresía, y `PermisosFicha::accion()` es siempre «ver».
- **Selector de grupo** en el menú (`Oficina::cambiar_grupo`): solo grupos dados de alta; queda en la auditoría.
- Informes: niveles `todos`, `global` y `salud`; nunca pagos ni IBAN. Cada ficha consultada y cada descarga quedan **auditadas**.

## Alta de un grupo por invitación

1. wp-admin → Grupos Scouts → **Invitar a un grupo**: enlace de **un solo uso, 14 días**, que se muestra una vez (en la base solo el HMAC) y se puede enviar por email. Tablas `asde_grupo_invitaciones` y `asde_grupo_solicitudes`.
2. Página pública `alta_grupo`: cualquier token que no valga (falso, usado, anulado, caducado, web que no es de oficina) enseña **la misma página neutra**; límite de intentos, campo trampa, sello de tiempo, HTTPS en producción y `noindex`. El grupo escribe nº, nombre, sede, email, CIF y denominación fiscal (opcionales), usuario y contraseña del CRM y los datos de la primera persona (nombre, DNI, rol Secretaría o Coordinación).
3. **Comprobar acceso** en la misma pantalla (`VerificacionGrupo`): entra en el CRM y busca el Grupo Scout con ese nº. La credencial queda cifrada 30 minutos ligada a la invitación y **no vuelve nunca al navegador**. Una comprobación fallida no gasta la invitación. **Enviar** gasta la invitación de forma atómica y crea la solicitud (usuario, contraseña, DNI y nombre, cifrados).
4. wp-admin → **Altas de grupos**: ve lo escrito (nunca la credencial ni el DNI). **Aprobar** crea el grupo con la credencial ya cifrada y la vuelve a comprobar (`Grupos::alta_desde_solicitud`); **rechazar** borra todo lo secreto y avisa sin motivo.
5. **Primer usuario:** al aprobar se busca en el CRM del grupo la ficha por el DNI y se le da el rol elegido; la invitación sale al **email del grupo**. Sin ficha, el grupo queda aprobado sin usuario y la pantalla permite reintentar.
6. Sede, CIF y denominación fiscal se usan como cabecera de la lista de compra en PDF y como acreedor y calle por defecto de SEPA.

## Pendiente

- Enlace de tipo «actualizar» para que un grupo **ya existente** cambie su credencial o complete sede, CIF y denominación fiscal (P-191); hasta entonces el administrador la edita en Grupos Scouts.
- Pedir a ASDE el usuario del CRM de solo lectura de la oficina y probar con el CRM real (lo lanza el usuario).
- Capturas del manual «Oficina».

Pruebas: `oficina`, `solo-lectura`, `oficina-pantallas`, `modo-instalacion`, `alta-grupo-invitacion`, `alta-grupo-aprobacion`, `alta-grupo-primer-usuario`.
