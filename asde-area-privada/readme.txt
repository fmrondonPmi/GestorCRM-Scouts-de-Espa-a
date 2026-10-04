=== ASDE Scouts de España - Gestor CRM para Grupos Scouts ===
Tags: scouts, crm, members, treasury, private area
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.18.2
License: MIT
License URI: https://opensource.org/licenses/MIT

Private area and management for Scout Groups (members, users, reports, treasury and uniforms) with data read from SinergiaCRM.

== Description ==

Gives each Scout Group a WordPress **private area** with data read from **SinergiaCRM** (the federal CRM of ASDE Scouts de España). Each group has its own CRM access and **one group never sees another**.

* **Members login** with a username (member number or email), password and two-step verification (with Wordfence), plus access requests that check the person's data.
* **Member records** (read, and edit when the CRM allows it), with changes proposed by families that need approval.
* **Users and member numbers**, reports with CSV download and per-section scope.
* **Treasury:** families, fees, installments, federation costs, bank import (Norma 43 or CSV) and SEPA direct debit (`pain.008` file).
* **Uniforms:** orders, payment, delivery and a summary for the supplier. "My payments" for families.
* Texts in **Spanish, Catalan, Basque and Galician**, with labels that can be changed without touching code, and group colors.
* It does not depend on any theme (Enfold is suggested).

The **mobile app** (installing the private area on Android and iPhone, push notifications) is a separate plugin: "ASDE Scouts de España - App móvil del Gestor CRM".

= Privacy =

* Personal data (names, phone numbers, health) **is read from the CRM at the time and is not stored in WordPress**; health data is never stored.
* WordPress stores, **encrypted** and for a short time: each account's email, access requests (30 days) and SEPA direct debit authorizations (while valid and for 14 months after).
* Every action is recorded in an audit log with field names, never values.

== Installation ==

1. Upload the plugin in *Plugins → Add New → Upload Plugin* and activate it.
2. Go to *Grupos Scouts → Instalación y entorno* (Installation and environment) and press the button that creates the pages, roles and tables.
3. Choose the federated organization in *Grupos Scouts → Organización federada* and add the group, with its CRM user, in *Grupos Scouts → Grupos y conexión CRM*.

Requirements: WordPress 6.4 or later, PHP 8.0 or later with the sodium extension, and HTTPS recommended.

== Frequently Asked Questions ==

= Does it store members' data in WordPress? =

No. Data is read from the CRM on each request (with a short, encrypted copy of the records to make it faster). What it does store is described under "Privacy".

= Does it write to the CRM? =

Only when a person with permission asks for it (editing a record, fixing a member number, approving a change or a request); everything is logged.

= Where is the mobile app? =

In the separate plugin "ASDE Scouts de España - App móvil del Gestor CRM".

== Changelog ==

= 0.18.2 =
* The readme is now in English and "Tested up to" is updated; code comments explain the use of core and cache-plugin hooks.

= 0.18.1 =
* Review with the WordPress plugin checker: translated texts escaped on output, translator comments, tables with `%i`, sanitized input and unified line endings.

= 0.18.0 =
* The mobile app became a separate plugin.

= 0.17.0 =
* First version with the mobile app (PWA).

== Upgrade Notice ==

= 0.18.2 =
No change in use: readme and code comments only.
