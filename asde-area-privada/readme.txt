=== ASDE Scouts de España - Gestor CRM para Grupos Scouts ===
Tags: scouts, crm, members, treasury, private area
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.25.0
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

= 0.25.0 =
* Treasury: the family record updates in place (registering a payment, also with a bank income, an adjustment or deleting one) without reloading the whole page. The bank list keeps its filters after conciliating or undoing.
* Treasury: "Delete…" a payment or an adjustment with a reason; it stops counting and goes to the family's history (who registered and deleted it, when and why). A payment that came from a bank income leaves that income pending again.
* Reports: new "Fee and payments" columns (family, fee status, amount due, paid, pending, overdue, payments made) and a filter by what has been paid, for whoever can access Treasury. New predefined report "Pagos de la cuota" so each scouter sees who in their section has paid.

= 0.24.0 =
* wp-admin → Scout Groups → "Repeated documents": for a group, the CRM records that share the same ID document (written in any form), to merge them in the CRM. Read-only; the document is shown masked.
* CRM registrations tested end to end in a browser against the simulated SinergiaCRM API (development only).

= 0.23.1 =
* Register a member: under 18, guardian 1 (name, surnames and email) is required.
* A leaving done from the web is emailed to Treasury and to the family (or to the person if adult); with the reason "Decease" nothing is sent to the family. The emails appear in Users → Emails.
* The ID document check now only exists as the first step of "Register a member": the separate "Is this person already in the CRM?" button in People is gone.

= 0.23.0 =
* CRM registrations, screens (off by default; enabled per group in wp-admin → Modules, "CRM registrations" column): People → "Register a member" in steps (ID document → details and membership → review → done, with the member number returned by the CRM and links to the record and to "Give access"). The document is checked first: already in the group, the registration stops and leads to that record (a new membership for someone who returns); in another group or organisation, it must be confirmed. What is typed travels in the form and is never stored in WordPress.
* Record → "Membership in the CRM" (Secretary and Coordination): new membership, leaving (date and reason, with what changes: the person's account, the family accounts and the family fee), section change and adding the ID document to someone who has none (checked first). Dates cannot be in the future, nor before the current membership started.
* Treasury → Payment control lists the recent leavings done from the web of people in a family (last 30 days): they no longer count in their family fee.

= 0.22.0 =
* CRM registrations, internal part (no screens yet; off by default, enabled per group): create a new person with their first membership (name, surnames, birth date, gender, and the ID document from age 14), a new membership for someone who returns, leaving (end date and reason; nothing is deleted), section change and adding an ID document to a minor. Checks before writing (already in the group: no second record; in another group: must be confirmed), a lock so two clicks never create two records, and retries that continue where a failed registration stopped. New table for these operations (no personal data).
* Development: a simulated SinergiaCRM API server for testing registrations without the real CRM (not included in the package).

= 0.21.0 =
* People: new "Is this person already in the CRM?" check (Secretary and Coordination), before registering someone. With an ID document (DNI, NIE or other) it says whether the person already has a record in the group (with or without an active membership) and whether they are already registered in another group or organisation of Scouts de España (a count only: nothing of that record is read). The document is validated (DNI/NIE letter) and never stored.

= 0.20.1 =
* Payment control: the bank income for "Register payment" is chosen in a pop-up window with a search box (tutor's or member's name, concept, amount or date) and one-click searches with the family's names; incomes that carry a tutor's or member's name are suggested for the family. Without JavaScript the window is a page and works the same.
* The one-line forms of the bank and inventory types no longer stack their fields with the Enfold theme.

= 0.20.0 =
* Bank: the movements list updates without reloading the page (pages, sorting, filters, reconciling); expense and income types with their own screen; "reviewed" movements get a type and a note in a pop-up window; multiple selection to mark several as reviewed.
* Payment control: the "fee of the round" box can be collapsed; "Register payment" can use a pending bank income, which is reconciled with the family.
* New wp-admin screen "Modules": turn each module on or off per group.

= 0.19.1 =
* Inventory: import materials from a CSV file (button and pop-up window, downloadable example, file check first, then the valid rows are imported and a report lists the rows that failed, which can be downloaded to fix them). The person who prepares a purchase list is shown by name.

= 0.19.0 =
* New "Inventory" module: the group's material and equipment (quantity, description, price, type, purchase year, serial number), material types managed in a pop-up window, and purchase lists that Treasury must approve (by email notice) before they can be printed as PDF.

= 0.18.2 =
* The readme is now in English and "Tested up to" is updated; code comments explain the use of core and cache-plugin hooks.

= 0.18.1 =
* Review with the WordPress plugin checker: translated texts escaped on output, translator comments, tables with `%i`, sanitized input and unified line endings.

= 0.18.0 =
* The mobile app became a separate plugin.

= 0.17.0 =
* First version with the mobile app (PWA).

== Upgrade Notice ==

= 0.25.0 =
Treasury without full reloads, payment deletion with history, payments report per member. Adds one column to the payments table and one predefined report.

= 0.24.0 =
New read-only report of repeated ID documents in wp-admin. No database changes.

= 0.23.1 =
Guardian required for minors, leaving emails to Treasury and the family, document check only inside the registration. No database changes.

= 0.23.0 =
Screens for CRM registrations, leavings and section changes. Off until enabled per group in wp-admin → Modules. No database changes.

= 0.22.0 =
CRM registrations (internal, off by default). Adds one table. No visible changes yet.

= 0.21.0 =
Adds the "already in the CRM?" check in People. Read-only: nothing is written to the CRM. No database changes.

= 0.20.1 =
Search the bank income in a window when registering a payment. No database changes.

= 0.20.0 =
Treasury and bank improvements and modules per group. Adds a table and two columns to the bank movements; all modules stay on.

= 0.19.1 =
Adds CSV import to the Inventory module. No database changes.

= 0.19.0 =
New Inventory module for scouters, Secretary, Coordination and Treasury. Three new tables are created on update.

= 0.18.2 =
No change in use: readme and code comments only.
