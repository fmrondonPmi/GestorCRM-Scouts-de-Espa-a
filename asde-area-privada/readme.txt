=== ASDE Scouts de España - Gestor CRM para Grupos Scouts ===
Tags: scouts, crm, members, treasury, private area
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.40.0
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

== External services ==

This plugin connects to **SinergiaCRM**, the CRM where the members' data lives (SinergiaCRM is developed by SinergiaTIC). Without it the private area has no data.

* **Which server:** only the SinergiaCRM instance of the federated organization. The administrator enters its address and the API user of each group in *Grupos Scouts → Grupos y conexión CRM*. Nothing is sent to any other service.
* **What is sent:** the group's API user and password (to open a session) and the queries each screen needs (for example a member number, a document number to check for duplicates or a search text). When a person with permission edits a record, approves a change, registers or removes a member or signs a SEPA authorization, the new values are sent to the CRM.
* **When:** when someone uses the private area or the plugin's screens in wp-admin, and in background tasks started from them (member numbering, refreshing the encrypted copy of the records).
* **Terms and privacy:** the use of the CRM is governed by the agreement between the federated organization and SinergiaTIC (https://www.sinergiatic.org/). SinergiaTIC's privacy policy: https://www.sinergiatic.org/es/politica-de-privacidad/

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

= 0.40.0 =
* Events: task calendar (plan in docs/49). A new "Calendar" tab in each event copies a template of tasks with deadlines counted from the start or end of the event (the ASDE camp programmer's 31-task "Acampada" template comes built in), shows a traffic light for each task (done, overdue, this week, later), lets any scout with access to the event tick tasks off, lets whoever manages the event assign a responsible person, set an own date, add and remove tasks, recalculates the dates if the event moves, adjusts weekend deadlines to the previous Friday (configurable) and prints. Each group can edit the template or create its own in Events settings. Overdue and this-week tasks appear in "Pending review" on the home page. A new table is created automatically.

= 0.39.0 =
* The "Uniforms" module is now "Shop" (plan in docs/48). Existing uniform orders, settings, pages and addresses keep working (old /uniforme addresses redirect; the orders table and settings are renamed automatically). Secretary joins Coordination and Treasury as shop administrator.
* Shop catalogue: products with variants (size, colour), price, optional member price, per-person limit, stock adjusted with a reason, archiving and CSV import. The section is called "Shop inventory".
* Cart and payment: members order several products at once; stock is reserved atomically and returned when the order is cancelled; pay by transfer, Bizum or, for members, in person to Treasury; the group's IBAN and Bizum number are in the settings; only Treasury marks payments; emails for order received, payment received and delivered.
* Public shop (optional, off by default): anyone with the group's link can buy products marked for everybody without logging in; their details are stored encrypted, unpaid orders are cancelled after a configurable number of days (14) and the personal data are deleted 90 days after delivery or cancellation. Members-only products never appear there.

= 0.38.0 =
* Waiting list: each group can add its own fields to the public sign-up form (label, type and optional/required), managed by Secretary and Coordination from the waiting list screen. The answers are stored encrypted with a key derived from the sign-up email, so they cannot be read once the entry is deleted.

= 0.37.1 =
* Treasury role: can open Reports and sees the payment reports for the whole group (identity and payment columns only; no contact or health data).

= 0.37.0 =
* Treasury, family file: the new "Payment history" lists everything a family has paid (round fee, events and uniforms), newest first, with who recorded each payment and when; removed items show as removed.
* Treasury, "Record payment": a payment can be assigned to an event whose registration period is open and where the family still owes something (Treasury only; cash, transfer or Bizum). It is split among the family's registrations and does not touch the round fee.
* Treasury: quick links to the payment reports ("Payment report" and "All reports") next to the Treasury tabs, for users who can see reports and payments.
* Payment control: the download is now a formatted Excel workbook (title, euro amounts, coloured status and a totals row) instead of a CSV.

= 0.36.1 =
* Events: Treasury can reopen an event's closed accounts, always with a reason (for example, an invoice that arrives late), and close them again with the report up to date. The pick-up details and notes deleted when closing do not come back.

= 0.36.0 =
* Events, new "Accounts" tab (event managers and Treasury): real expenses with their receipt (photo or PDF up to 5 MB, stored encrypted in uploads/asde-privado), proposed by the event team and validated or rejected by Treasury; reimbursements to whoever paid out of pocket; real income (grants); cash advances and their settlement (a note is required if it does not add up); emergency fund (collected, used, left); balance (planned and actual per budget line, collected, pending, refunded and result), printable and as CSV.
* Closing an event's accounts (Treasury): once the event has ended, with nothing to review, reimburse or settle. The balance is kept as the final report, the pick-up details and notes of the registrations are deleted and the event becomes read-only. It cannot be reopened.
* Refunds to families: from any registration with something paid, up to what was paid and with a reason. They show in "My events" and "My payments".
* SEPA direct debits: "Charge an event" prepares one debit per family with an authorisation for everything pending in its registrations (siblings together). When the collections are recorded, the amount is split among the family's registrations as a "SEPA direct debit" payment.

= 0.35.1 =
* Families: an empty family (no members, payments, SEPA authorisations or bank movements) has "Delete" in the list; the row says why when it cannot be deleted.
* Two-step verification is now optional for every role (it was required for Scouter, Secretaría, Coordinación and Tesorería). Applied once on update; an administrator can require it again in Wordfence.
* Login: the verification code field is collapsed; it opens, highlighted and required, when Wordfence asks for the code.
* Fix: a membership ended in the CRM showed the person as active until the CRM recalculated "Secciones actuales". Sections are now taken from the memberships that are active by their dates, read in the same request (snapshot, searches, People and reports).
* People: "Remove from the website" for someone with no active membership. Nothing changes in the CRM; they leave People and their family, and their web accounts are deactivated. They come back by themselves if they get an active membership again.

= 0.35.0 =
* New "Events" module, off by default (switch it on for each group in wp-admin → Grupos Scouts → Módulos): outings, camps and other activities of the group, with their leader team checked against the Balearic youth leisure rules (director, ratio, qualifications, certificate), a budget whose price per section is validated by Treasury, a checklist and publishing.
* Registrations: "My events" for families and members. Registering a minor signs the participation authorisation (versioned text); members aged 14 to 17 pre-register and their family signs. Places and waiting list (a freed place is offered for 3 days), invitations with "not going", withdrawals while registration is open. The event's "Registrations" tab: participants, waiting list, no answer, registering on behalf of a family and paper authorisations.
* Event payments (Treasury): cash, transfer or Bizum, one family payment split among siblings, grants and adjustments with a reason, and cancelling a movement. Event payments also show in "My payments". Emails when an event is published, a registration is confirmed, a place is offered or an event is cancelled, plus a reminder before it starts.
* Built in a separate cloud session on an older base and adapted to this version: module switch, current roles, email log, help pages and Plugin Check rules.

= 0.34.0 =
* Bank: when marking movements as reviewed, a "+" next to each type opens a small window on top to create a new income or expense type without losing what was chosen; the new type is selected in that row.
* Families: search by family number, family account, or the name or member number of any member, without reloading the page.
* Person record: "Member since" in the header, with the years, months and days since the continuous membership began (seen from People).
* Fix: a membership set to "baja" directly in the CRM without an end date is still active for the CRM (it computes it from the dates). The website showed the person as active and "Remove membership" said there was no active membership; now it finds it and sets the end date.

= 0.33.2 =
* Code review for simplicity (no change in use): all notices send email through one function, the two public group links share their secret-key code, the inventory screens use the same tested threshold rule, and unused code was removed.

= 0.33.1 =
* Security review with the WordPress plugin audit, coding standards and security guidance tools: the screens that build HTML in JavaScript now escape translated texts inside HTML attributes (custom labels can contain quotes).
* Templates: the WordPress coding standards no longer report local template variables as overridden WordPress globals.
* readme.txt: new "External services" section (SinergiaCRM). Plugin header: License URI.

= 0.33.0 =
* SEPA direct debit: the bank account (IBAN, holder, bank) is no longer stored by the plugin. When a guardian or an adult member signs the authorization, the account is written to the CRM record of every member of the family; the plugin keeps the authorization, the masked IBAN and a fingerprint to check, before each collection, that the CRM account is still the authorized one. Each family is still a single debit in the SEPA file.
* Person record: new "Bank details and SEPA direct debit" section (masked), for the family, adult members and Treasury.
* Treasury: "Move accounts to the CRM" for authorizations signed before 0.33.

= 0.32.0 =
* New "Waiting list" module (off by default; turn it on per group in Scout Groups → Modules): a group link where non-members leave the minimum data (name, surnames, birth date, ID document from age 14, contact phone and email, notes), email confirmation, a personal link to view, keep (12 more months) or delete their data, and automatic deletion after 12 months with a reminder 30 days before.
* Scouters, Secretary, Coordination and Treasury see the list with the section that matches each age and the order of arrival; they log contacts (email sent from the site or phone call) and change the status. Secretary and Coordination assign the section and register the person in the CRM through "New member", which checks the ID document against the CRM first; the waiting list entry is deleted right after.
* All waiting list data is stored encrypted.

= 0.31.0 =
* Lists: 10 rows per page by default (5 to 50, in steps of 5); filtering and paging refresh only the list in every list (payments, uniform orders, purchase lists, report sections). People list sorted by section; counters moved below the lists.
* Payments: filter on one line, "View / record payment" as a link, children per fee as a tooltip. Bank: search box first and filters on one line.
* New members get their territorial number automatically (shown only to who has it visible). A youth member can only move to the next section by age; adult volunteers to any section.
* WordPress.org review: no inline <script>, <style> or event handlers (enqueued assets and data attributes instead), sanitized $_SERVER/$_COOKIE input.

= 0.30.0 =
* Inventory: when an item is added to a shopping list, the "are you sure?" question is now asked when there are already 5 units or more ("do you really need to buy more?"), not when there are few. The yellow "few units" label stays.
* Shopping lists can include new items that are not in the inventory yet (description, quantity and optional estimated price), also in a list made only of new items ("Lista de material nuevo"). They are shown in the list, in its PDF and in the email to Treasury.
* When Treasury approves or rejects a shopping list, the person who asked for it gets an email with the result, the reason if rejected and a link to the list (if the account has an email; otherwise it is logged as "no email"). It is recorded in the email log as "Lista de compra revisada".
* "Marcar como comprada": an approved list can be marked as bought (with confirmation, only once). The bought quantities are added to each inventory item and the new items are created in the inventory. Recorded in the audit log (item ids only).
* CSV import: a row without serial number with the same description (ignoring case, accents and extra spaces) and type as an item already in the inventory, or as a previous row of the same file, adds its quantity to that item instead of creating a duplicate (uniform shirts only if model and size also match). The import report says how many rows were added this way.
* Uniforms: a scouter can order the uniform for any member with an active membership in their sections, besides themselves (families, as before: their minors). The shirt price follows the rule of the person it is for (the first one of an adult scouter or volunteer is free). With more than five people, "¿Para quién?" becomes a drop-down grouped by section. The people come from the group snapshot (one read, no CRM query per person).

= 0.29.0 =
* Fix: in People, the member-number buttons (assign, pass to the CRM, correct the format, give a new number) did not ask for confirmation since 0.18.1; they do again. Code review fixes: cancelling an order returns a shirt served from the inventory, the Excel file is built in memory (no temporary file with personal data), the change history only shows edits made by the group's own accounts, approving changes without CRM write access keeps them pending.
* Fix: downloads (reports, Treasury and uniform lists, inventory CSV files) left the page blank with themes that fade the page out on every link (Enfold's page transitions): the file was downloaded but the page stayed white. Download links no longer trigger the theme transition.
* Reports: "Descargar Excel" downloads a real .xlsx spreadsheet (bold, frozen header row with filters, column widths, numbers as numbers) instead of a CSV. "Imprimir" opens a printable version of the whole report (all columns, text wraps instead of being cut, landscape with many columns, header repeated on every page) in a pop-up window with its own "Imprimir" button.
* Every box of the private area with a title can be collapsed (▾ next to the title); boxes, record sections and remembered panels start collapsed and the browser remembers the ones each person opens. In a person's record, the name box (collapsed: only the full name and the section or role) and "Membresía en el CRM" can be collapsed too, and "Contraer todo / Expandir todo" is now at the top. In People, the summary box can be collapsed.
* People: the list filters without reloading the page and searches while typing from the third letter; "Dar acceso" is a link like "Ver ficha" (shorter rows) and "Mostrar", the search box and "Filtrar" stay on one line (the theme stretched the drop-down to full width).
* Reports: new predefined report "Fichas reducidas": the short record of each active member (name, mobile, guardian 1, image consent, allergies, food allergies and intolerances, special diet, eating disorder and illnesses), one per page when printed instead of a table. Any report can use the new "one record per person" layout, and a "Sección" selector on the result shows a single section without editing the report.
* Proposed changes: grouped by person. "Revisar" opens all the pending changes of that person in the pop-up window, each with a checkbox, "Todos" at the top of the column and a single "Aprobar los seleccionados" button (or reject them with one reason); they are saved in the CRM in a single write. "Resueltos" now also lists the changes made directly in the records by the Secretariat, Coordination or scouters in the last 30 days (which data, who and when; values are never stored), which is why it looked empty before.
* Uniforms: the shirt costs the configured price (25 €) for members; a scouter's first shirt is free (the order is marked as paid at no cost) and from the second one on it costs the price. New option in the uniform settings, on by default.
* Uniforms and inventory: the inventory has a "Camisa uniforme" type (created automatically, cannot be deleted) whose items record the model (boy or girl shirt) and the size; the CSV import accepts "Modelo (camisas)" and "Talla (camisas)" columns. Uniform management shows the shirts in stock and can serve an order's shirt from the inventory instead of ordering it from the supplier, give a shirt from the inventory to someone who did not order it (creating their order, free of charge if the amount is 0), and return a shirt to the inventory; the stock is updated each time.

= 0.28.0 =
* Help is now text only and always opens in the pop-up window: the help of each page shows the section of the user manual for that page and role without screenshots (each screenshot caption is kept as a short note), and "Ver el manual completo" opens the whole manual of the role, also text only, in the same window, with its index and links between sections. All of it ships inside the plugin (about 300 KB), so the plugin zip stays small (version 0.27.0, which bundled the illustrated manual, was discarded: its zip was over 10 MB).
* The illustrated user manual (with screenshots) is uploaded separately by hand as extra help: wp-admin → Grupos Scouts → Organización federada → "Manual con capturas (ayuda extra)" holds its address (for Scouts de Balears, https://baleares.scout.es/manual/ by default), and the help shows a "Manual con capturas" link that opens it in another tab.

= 0.26.2 =
* Code quality: fixes all 589 findings of the WordPress Plugin Check report (5 errors and 584 warnings). Database queries pass table names with the %i placeholder and no longer build SQL by concatenation: optional filters go as prepared values (bank movements, change proposals, email log, access requests, inventory). A bank movement without a value date is stored as NULL with NULLIF instead of a conditional in the query. In-memory CSV streams (php://temp) are documented as such. Templates declare why their variables are not globals (they are included inside a function), and uninstall.php prefixes its global variables. No visible changes.

= 0.26.1 =
* Security: deactivating a user (Usuarios → "Desactivar", or automatically when a family account no longer represents anyone) now also deletes what let them log in: the password (replaced by a random one nobody knows), WordPress application passwords and, with Wordfence Login Security, the two-step verification (secret and recovery codes) and passkeys. It also forgets that the account was activated, so giving access again sends a new invitation to choose a password. The activity log records what was removed.

= 0.26.0 =
* Help on every page: "Ayuda", next to the person's name, opens in the pop-up window the section of the user manual for that page and for the role of whoever is logged in (families, members aged 14-17, scouters or the coordination team), and at the end a link to their full manual. The help is generated from the manual sources (data-paginas) into the plugin's ayuda/ folder; its screenshots and links point to the published manual.
* wp-admin → Grupos Scouts → Organización federada: new "Manual de uso" field with the address of the published manual (by default, https://baleares.scout.es/manual/ for Scouts de Balears).

= 0.25.1 =
* Fix: "Dar acceso" (opened as a page or in the pop-up window) failed with an error 500 when the person's role in the CRM ("Cargo en el CRM") was not one of the known ones; it now shows the role as the CRM has it. Known roles show their name again (they were blank). The bug came from v0.15.0.

= 0.25.0 =
* Treasury: the family record updates in place (registering a payment, also with a bank income, an adjustment or deleting one) without reloading the whole page. The bank list keeps its filters after conciliating or undoing.
* Treasury: "Delete…" a payment or an adjustment with a reason; it stops counting and goes to the family's history (who registered and deleted it, when and why). A payment that came from a bank income leaves that income pending again.
* Users: "Users", "Grant access" and "Email log" are now sheets of a single "Users" section, like a spreadsheet. Switching sheets does not reload the page and each sheet keeps its filters and search; the browser's back and forward buttons work. The home page shows a single "Users" tile.
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

= 0.40.0 =
Event task calendar. Adds one table (created automatically); nothing else changes.

= 0.39.0 =
Uniforms becomes the Shop. The orders table is renamed from asde_uniformes to asde_tienda_pedidos, two tables for products and variants are added and the settings move automatically; nothing is lost.

= 0.38.0 =
Custom waiting list fields. Adds an encrypted column to the waiting list table (updated automatically).

= 0.37.1 =
Treasury accounts get payment reports for the whole group. No database changes.

= 0.37.0 =
Payment history per family, event payments in "Record payment", report links in Treasury and a formatted Excel for the payment control. No database changes.

= 0.36.1 =
Treasury can reopen an event's closed accounts. No database changes.

= 0.36.0 =
Event accounts, refunds and SEPA for events. Two new database tables (event expenses and cash advances) and new columns in events and SEPA remittances are created automatically on update.

= 0.35.1 =
Two-step verification becomes optional for every role (applied once). Fix for memberships ended in the CRM. No database changes.

= 0.35.0 =
New Events module, off by default. Four new database tables (events, registrations, budget lines and event payments) are created automatically on update.

= 0.34.0 =
New type from the bank review window, family search, "Member since" in the record and a fix for memberships ended in the CRM without a date. No database changes.

= 0.33.2 =
Internal clean-up, no change in use. No database changes.

= 0.33.1 =
Security hardening and coding-standards fixes. No database changes.

= 0.33.0 =
The SEPA bank account moves to the CRM record. No database changes; Treasury can move older authorizations with one button.

= 0.32.0 =
Waiting list module (off by default). Adds a new table for the waiting list (created automatically on update).

= 0.31.0 =
Lists refresh without reloading the page, territorial number on new members and next-section rule. No database changes.

= 0.30.0 =
Shopping lists: new items, emails on approval or rejection, "mark as bought" and CSV import that merges repeated items. Scouters order uniforms for their sections. Adds two columns to the shopping lists table (applied automatically on update).

= 0.29.0 =
Excel and printable reports, one-record-per-page reports, collapsible boxes, live search in People, grouped approval of changes and shirts from the inventory. Adds columns to the inventory and uniform tables and a predefined report (applied on update).

= 0.28.0 =
Text-only help in the pop-up window, with the full manual of each role inside the plugin; the illustrated manual is optional extra help uploaded separately. No database changes.

= 0.26.2 =
Code quality fixes from WordPress Plugin Check. No visible changes and no database changes.

= 0.26.1 =
Deactivating a user also deletes their password and their two-step verification. No database changes.

= 0.26.0 =
Contextual help on every page by role and a link to the full manual. No database changes.

= 0.25.1 =
Fixes an error 500 in "Dar acceso" for people with an unknown role in the CRM. No database changes.

= 0.25.0 =
Treasury without full reloads, payment deletion with history, payments report per member, Users section with sheets. Adds one column to the payments table and one predefined report.

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
