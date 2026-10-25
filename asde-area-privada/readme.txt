=== ASDE Scouts de España - Gestor CRM para Grupos Scouts ===
Tags: scouts, crm, members, treasury, private area
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.59.0
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

= 0.59.0 =
* New module "Cash box and ledger" (off by default, under Treasury): the group cash box and one per section. Cash collected or paid in the other modules (fees, events, shop, refunds, advances) appears in the group cash box automatically, read live. Opening balance, manual entries, transfers between boxes and with the bank (linked to their bank movement), and cash counts with the difference recorded as an adjustment.
* Ledger: the bank and every cash box with opening and closing balance, running balance and a summary by category, by round or calendar year, with Excel downloads.

= 0.58.1 =
* Treasury: families with no member with an active membership are also hidden from the payments control screen (list, summary and totals), not only from its Excel. They stay in "Families".
* Bank Excel: the "Family" column now shows the family number and the names of its members.

= 0.58.0 =
* Treasury: the payments control Excel no longer lists families with no member with an active membership. The "All reports" shortcut is removed from the treasury bar and a new "Bank report" shortcut downloads every bank movement in Excel.
* Bank: "Download Excel" exports the movements of the current filter (date, value date, payer, concept, references, amount, status, type, family, event, note and linked movement, with totals). Two bank movements can now be linked to each other (e.g. an expense and the income that refunds it); linking does not change their status or type.
* Reports: section scouters can now select every column for the members of their sections, including the ID document.
* Ledger and cash box: plan only (doc 61), nothing changes yet.

= 0.57.1 =
* Fix: new members and leavers added from the web were sometimes not shown for several minutes. A background re-read of the CRM that had started before the change could save the old list of members over it; now every change bumps a counter and an older read is discarded.
* Fix (budgets): the federation cost is paid with the registration and insurance fees charged per child (e.g. 30 of a 140 fee), and only the rest (the yearly fee, 110) is split between the group and the section. A grant that leaves the fee below the registration and insurance no longer gives the section a negative amount.

= 0.57.0 =
* Full code review (doc 59). Money and numbering are now safe under concurrent requests: bank reconciliation, payment of shop orders with a bank income, SEPA batch payments, event places and family numbers use atomic updates or database locks. Fixes: CRM lists are never silently truncated, SEPA payments of events are always recorded, the last fee instalment closes the total, account actions never touch users of another group, and the daily task no longer stops at the first error.
* Waiting list: new "Joins as a member" button that opens the new member form filled in with the stored data (checking the ID document if there is one); when saved, the entry is marked as a member and all its encrypted data is deleted.
* Login: every user, whatever their role, needs an active membership in the CRM (family accounts: at least one of their children). If the CRM cannot be reached, access is denied with a notice.
* Access requests pending for more than 90 days are rejected automatically with the usual rejection email. Receipts are deleted when a refund or an expense is rejected, cancelled or withdrawn, if no other row uses them. "Quartermaster" added to the scouter sign-up label and the requests help.
* Email log: the preview of waiting-list emails and of group sign-up emails now shows the email that was sent, not the access invitation.
* Test suite reviewed (doc 60): new tests and every test restores the site.

= 0.56.0 =
* New "Group data" page (name, tax name, tax ID, address, postcode, town, province, contact email) in one place: everybody can see it, the Coordination team edits it (new permission, listed in Access management). The local member number is now province (2 digits, keeping the leading 0) + group number + 4-digit counter, so the "organisation code" setting is gone; numbers without the leading 0 stay valid. The SEPA creditor address now comes from the group data. No database changes.

= 0.55.0 =
* Budgets, phases 2 and 3: each section (and the group) prepares its budget with expense items (category x how many x amount each) and income items (the fundraising project target of each section and others), with balance, draft / sent / approved status (Treasury approves and reopens with a reason), copy from the previous round and real spending recorded against each item (budgeted / spent / left). New permission "Prepare the budget of their sections" (Scouters, Secretary, Coordination, Treasury; listed in Access management). Three new database tables.

= 0.54.0 =
* New "Budgets" tab in Treasury (phase 1) for Coordination, Secretary and Treasury: from the yearly fees, minus the federation cost, a configurable percentage goes to the group and the rest to the section of each member; per-section percentages and the fundraising project name of each section. Calculated live, nothing new is stored in the database. Can be switched off per group (Modules).

= 0.53.0 =
* New page "Access management" for Coordination, Secretary and Treasury: a role x permission matrix grouped by module, extra permissions for single people (with or without an expiry date, expiring on their own) and a filterable history. Every action needs the "view" permission of its module, nobody can grant permissions to themselves and sensitive permissions require a reason. Three new database tables.

= 0.52.2 =
* Waiting list: the public form requires a declaration that the data is true and that the group's conditions are accepted; the text can be edited per group. Manuals and help updated.

= 0.52.1 =
* Shop: quantity and size now sit on one line also with the Enfold theme. Office home: the cards no longer mention actions the office cannot do.

= 0.52.0 =
* Bank: when marking a movement as reviewed it can be tied to an event and to a family (shown in the bank list, the family history and the event accounts). Adds a column to the bank table.
* Events: publishing asks in a window whom to notify by email (families, scouters or everyone); if nothing is ticked, no email is sent.

= 0.51.0 =
* Shop: products now take their stock from the general inventory. A row of the inventory marked "Se vende en la tienda" is a shop variant; selling it more than is in stock is allowed and the shortfall goes to the supplier order. Existing shop stock is moved to the inventory once. Adds two columns to the inventory table and one to shop variants.

= 0.50.0 =
* Office web: new "Oficina" manual and help, read-only screens without action buttons, and every record viewed or file downloaded by the office is audited.
* Office web: groups can sign up by invitation (one-use link valid 14 days, credential checked on the same screen and stored encrypted); two new tables.
* Office web: the federal office can store its CRM credential (encrypted, with a connection test) and there is a new read-only "Oficina" role that can pick any group and sign in without a member record. Nothing changes for group sites.
* New setting "Installation type" (Organisation settings): the plugin can be installed on a group's WordPress (default, nothing changes) or on the federal office's WordPress. It does not change any screen yet: it is the first step towards the office read-only view and the group invitation flow. It can be fixed with the ASDE_AP_MODO constant in wp-config.php.

= 0.49.0 =
* Shop: each group can add a link to its public shop in the website's main menu (text set in the shop settings; no text, no link). Manuals and help updated for the new shop flow.

= 0.48.1 =
* Screens: filters and action rows (search, status, dates, "mark as paid", adjustments, contact log, material reservation, new refund…) now go on a single line where they fit, with the fewest fields possible (drop-downs instead of radio buttons); on narrow screens they wrap without overflowing.

= 0.48.0 =
* Shop: new "Pedido de camisas" tab (orders to the supplier). Coordination groups what customers have already paid and the shop does not have in stock, creates the order to the supplier (downloadable as Excel) and marks it as received when it arrives; anything that arrives beyond what was ordered is added to the shop stock. Customer orders that go in a supplier order cannot be delivered until it arrives. The old "next supplier order" box in the orders screen is replaced by a link to the new tab.
* Screens: filters and action rows now go on a single line where they fit (new design rule); the new tab follows it.

= 0.47.0 =
* New module "Reintegros" (off by default; turn it on in Groups > Modules): scouters and Coordination can ask to be refunded for an expense they paid, attaching a photo or PDF of the receipt (stored encrypted), optionally linking it to an event, and choosing cash or bank transfer. For a transfer the IBAN is typed by the requester, stored encrypted and deleted when the refund ends, and an email with all the details goes to Treasury. Treasury approves (or rejects with a reason); Treasury or Coordination mark it as paid, which also adds the expense to the event's accounts when it has one.

= 0.46.0 =
* Shop: new payment option "Datáfono / TPV" (card payment in person with the group's terminal). It is off by default (Shop settings); members and guests can choose it, and Treasury marks the order as paid. If the bank module is on, the bank deposit that matches what was collected with the terminal (minus the bank's fee) is suggested to Treasury, who confirms it; nothing is reconciled automatically.

= 0.45.0 =
* Shop: for products with several sizes you now type the quantity and pick the size from a drop-down (with an "Add another size" button for more rows), instead of one quantity per size. It works in the members' shop and in the public shop, and without JavaScript every row is shown.

= 0.44.0 =
* Shop: the uniform is now just another sale. The shirt and the badges are ordinary products with the shop's own stock; the special uniform form, the "first shirt free for scouters" rule, the 25 EUR shirt price, the scouter ordering for their section and serving shirts from the general Inventory are gone. Orders placed before keep showing as they were. Prices are set in each product (price and member price).
= 0.43.0 =
* New role "Intendente" (the person in charge of the group's material, matching the CRM's "Responsable de Material" position). It is granted like Secretary or Treasurer: together with the Scouter role or as a volunteer without a section. It sees every event of the group, but only the Summary and Material tabs, and manages the material (reservations, departure, return and deduction from the inventory); it sees nothing about menus, accounts, registrations or health.
* Events, menus: a reminder email about allergens still unresolved in the menu. From the date of the "Review allergies" task of the calendar until the event starts, the daily task checks the menu against the attendees and, if some dish carries an allergen of someone going and nobody has confirmed the alternative, emails the event team (not the support team, who get the kitchen sheet). Names are included only for those who can already see the whole group's health records; the others get the dish, the allergen and how many people. Nothing about health is stored: the email log keeps only that it was sent, and a salted fingerprint prevents repeating the same warning every day.

= Older versions =
* The changelog of versions 0.42.0 and earlier is in changelog.txt, in the plugin folder.

== Upgrade Notice ==

= 0.59.0 =
New "Cash box and ledger" module, off by default (turn it on per group in Modules). Database change: new table asde_caja, created automatically on update.

= 0.58.1 =
Payments control without families with no active members; bank Excel with family member names. No database changes.

= 0.58.0 =
Bank report in Excel, linked bank movements and report changes. Database change: the bank movements table gets a new column (linked movement), added automatically on update.

= 0.57.1 =
Fixes: new members and leavers are shown at once, and budgets split only the yearly fee (registration and insurance pay the federation cost). No database changes.

= 0.57.0 =
Code review fixes, "Joins as a member" in the waiting list and login only with an active membership. Database change: the waiting-list table gets two new columns (member number and date it joined), added automatically on update.

= 0.56.0 =
Adds the "Group data" page and a new permission. The member number format now uses the group province (set it in Group data; Scouts de Balears keeps working with 07). No database changes.

= 0.55.0 =
Adds the budgets of each section and of the group (items, approval, real spending) and a new permission. Three new database tables (items, status, real spending).

= 0.54.0 =
Adds the "Budgets" tab to Treasury (fee split between the group and the sections). No database changes.

= 0.53.0 =
Adds the "Access management" page (Coordination, Secretary, Treasury) and three database tables (role permission changes, per-person permissions, access history). Nothing changes until someone uses it.

= 0.52.2 =
New mandatory declaration checkbox in the waiting list form. No database changes.

= 0.52.1 =
Layout fixes. No database changes.

= 0.52.0 =
Publishing an event no longer emails anyone unless you choose whom to notify. Adds a database column.

= 0.51.0 =
The shop's stock now lives in the general inventory (existing stock is moved automatically). Adds database columns.

= 0.50.0 =
Adds two tables (office sites only). Office credential and role (office sites only). New "Installation type" setting (group or office). Nothing changes for existing sites. No database changes.

= 0.49.0 =
Optional link to the public shop in the main menu. No database changes.

= 0.48.1 =
Layout of filters and action rows. No database changes.

= 0.48.0 =
New "Pedido de camisas" tab in the shop. Adds a table and a column (added automatically).

= 0.47.0 =
New "Reintegros" module (off by default). Adds a table; the new capabilities are added to the roles automatically.

= 0.46.0 =
Card terminal payment option in the shop. Adds a column to the shop orders table (added automatically).

= 0.45.0 =
Quantity and size drop-down in the shop. No database changes.

= 0.44.0 =
The shop no longer has its own uniform flow: shirt and badges are normal products. No database changes; old orders are kept.
= 0.43.0 =
New Intendente role and the unresolved-allergens reminder. No database changes; the role is added automatically.

= 0.42.0 =
Event material. Adds one table (created automatically); nothing else changes.

= 0.41.0 =
Recipe book for event menus. Adds five tables and one permission (created automatically); nothing else changes.

= 0.40.0 =
Event task calendar. Adds one table (created automatically); nothing else changes.

= 0.39.0 =
Uniforms becomes the Shop. The orders table is renamed from asde_uniformes to asde_tienda_pedidos, two tables for products and variants are added and the settings move automatically; nothing is lost. A column is added to the orders table for the payment reminder (created automatically).

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
