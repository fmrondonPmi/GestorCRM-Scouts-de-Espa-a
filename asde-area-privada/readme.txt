=== ASDE Scouts de España - Gestor CRM para Grupos Scouts ===
Tags: scouts, crm, socios, tesorería, área privada
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.18.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Área privada y gestión de los Grupos Scouts (socios, usuarios, informes, tesorería y uniformes) con datos leídos de SinergiaCRM.

== Description ==

Da a cada Grupo Scout un **área privada** en WordPress con los datos leídos de **SinergiaCRM** (el CRM federal de ASDE Scouts de España). Cada grupo tiene su propio acceso al CRM y **un grupo no ve a otro**.

* **Acceso socios** con usuario (nº de socio o email), contraseña y verificación en dos pasos (con Wordfence) y solicitudes de acceso con comprobación de los datos.
* **Fichas** de los socios (lectura y edición cuando el CRM lo permite), cambios propuestos por las familias con aprobación.
* **Usuarios y números de socio**, informes con descarga CSV y ámbito por sección.
* **Tesorería:** familias, cuotas, plazos, coste federativo, importación del banco (Norma 43 o CSV) y domiciliación SEPA (fichero `pain.008`).
* **Uniformes:** pedidos, pago, entrega y resumen para el proveedor. «Mis pagos» para las familias.
* Textos en **castellano, catalán, euskera y gallego**, con etiquetas que se pueden cambiar sin tocar código, y colores del grupo.
* No depende de ningún tema (se sugiere Enfold).

La **app móvil** (instalar el área privada en Android e iPhone, avisos push) es otro plugin: «ASDE Scouts de España - App móvil del Gestor CRM».

= Privacidad =

* Los datos personales (nombres, teléfonos, salud) **se leen del CRM en el momento y no se guardan en WordPress**; la salud nunca se guarda.
* WordPress guarda, **cifrado** y por poco tiempo: el email de cada cuenta, las solicitudes de acceso (30 días) y las autorizaciones de domiciliación SEPA (mientras estén vigentes y 14 meses después).
* Toda acción queda en un registro de auditoría con nombres de campos, nunca con valores.

== Installation ==

1. Sube el plugin en *Plugins → Añadir nuevo → Subir plugin* y actívalo.
2. Entra en *Grupos Scouts → Instalación y entorno* y pulsa el botón que crea las páginas, los roles y las tablas.
3. Elige la organización federada en *Grupos Scouts → Organización federada* y da de alta el grupo, con su usuario del CRM, en *Grupos Scouts → Grupos y conexión CRM*.

Requisitos: WordPress 6.4 o posterior, PHP 8.0 o posterior con la extensión sodium y HTTPS recomendado.

== Frequently Asked Questions ==

= ¿Guarda datos de los socios en WordPress? =

No: se leen del CRM en cada consulta (con una copia cifrada y corta de las fichas para ir más rápido). Lo que se guarda propio está descrito en «Privacidad».

= ¿Escribe en el CRM? =

Solo cuando una persona con permiso lo pide (editar una ficha, corregir un nº de socio, aprobar un cambio o una solicitud); todo queda en la auditoría.

= ¿Dónde está la app móvil? =

En el plugin aparte «ASDE Scouts de España - App móvil del Gestor CRM».

== Changelog ==

= 0.18.1 =
* Revisión con el revisor de plugins de WordPress: textos traducidos escapados al imprimir, comentarios para traductores, tablas con `%i`, entradas saneadas y finales de línea unificados.

= 0.18.0 =
* La app móvil pasa a ser un plugin aparte.

= 0.17.0 =
* Primera versión con la app móvil (PWA).

== Upgrade Notice ==

= 0.18.1 =
Sin cambios de uso: solo revisión de seguridad y estilo del código.
