# Event Plus – WordPress rebuild

Rebuild of https://epevents.com.my/ as **one custom WordPress theme** (`epevents`), built on **ACF Pro**, plus four
in-house, reusable plugins (**Tamago**) for email sending, visitor reports, import / export and roles & permissions.
There is no third-party plugin apart from ACF Pro.
Git tracks the theme, the four Tamago plugins, `docs/` and this README; WordPress core, ACF Pro, `wp-config.php` and uploads are not.

| Document | For |
|---|---|
| This README | the Event Plus theme: features, folders, ACF, deployment |
| [docs/event-plus-settings.md](docs/event-plus-settings.md) | setting up the Tamago plugins like Event Plus (this site or another project), presets in `docs/presets/` |
| [docs/tamago-conventions.md](docs/tamago-conventions.md) | rules shared by the four plugins (prefixes, folders, permissions, menus, settings JSON, security) |
| `wp-content/plugins/tamago-*/README.md` | each plugin's developer guide: screens, settings keys, hooks, WP-CLI, data stored |

## Requirements

| Requirement | Version | Notes |
|---|---|---|
| WordPress | 6.4 or later | |
| PHP | 8.0 or later (local: 8.3) | |
| MySQL / MariaDB | any version WordPress supports | Needs `GET_LOCK` (standard), used against overbooking |
| **Advanced Custom Fields PRO** | 6.x (local: 6.8.11) | **The only third-party plugin.** Installed separately in `wp-content/plugins/advanced-custom-fields-pro/`, not bundled in the theme, so it keeps receiving its own updates. Licence key: ACF → Updates (or `define( 'ACF_PRO_LICENSE', '…' );` in `wp-config.php`). It registers the post types, options pages and field groups, so without it events and microsites do not exist (the theme shows an admin warning). |
| **Tamago SMTP** (`tamago-smtp`) | 1.0.0 | Mail server, sender, test email, email log. In this repository. |
| **Tamago Reports** (`tamago-reports`) | 1.0.0 | Visitor analytics, goals (registrations), real-time. In this repository. |
| **Tamago Import & Export** (`tamago-io`) | 1.0.0 | Any content, ACF values and structure, applicants sheet. In this repository. |
| **Tamago Roles** (`tamago-roles`) | 1.0.0 | Roles, ranks, permission matrix, admin menus, content access. In this repository. |
| Theme **EP Events** (`epevents`) | 3.0.0 | Must be the active theme. Warns when a Tamago plugin is not active. |

Nothing else is needed: no form, SMTP, analytics, export, roles or maintenance plugin from outside.
Optional `wp-config.php` constants are listed under [Deploying to staging](#deploying-to-staging-cpanel).

## Folder structure – features vs presentation

```
wp-content/themes/epevents/
├── functions.php          loads core/ first, then the presentation code below
├── style.css              theme header (name, version, requirements)
│
├── core/                  ── FEATURES & DATA (what the site does) ──
│   ├── bootstrap.php      constants, loads every file below, ACF check, one-time setup
│   ├── acf-json/          ACF (JSON): Events / Microsites / Applicants post types, Settings options page,
│   │                      Event / Microsite / Settings field groups
│   ├── acf.php            ACF glue: JSON folders, "add new" permission, HTML cleaning, choices from code
│   ├── helpers.php        shared functions used by every feature
│   ├── registration/      form.php · validator.php · capacity.php
│   ├── applicants/        applicants-admin.php · features.php (alerts, waiting list, masking) ·
│   │                      applicants-io.php ("Applicants" sheet for Tamago Import & Export)
│   ├── email/             mailer.php (event emails; sending itself is Tamago SMTP)
│   ├── access/            site-access.php · admin.php (menu, overview, list links)
│   ├── tamago.php         link to the Tamago plugins: permissions, menu, registration goal, warnings
│   ├── assets/            admin CSS, registration form CSS/JS
│   ├── tests/run.php      automated checks
│   └── dev/               developer-only scripts (demo content seeder, migrate-to-tamago.php)
│
│                          ── PRESENTATION (how the site looks) ──
├── acf-json/             ACF (JSON): Branding options page and field group
├── inc/
│   ├── branding.php       Appearance → Branding (logo, colours, fonts, login, admin, emails)
│   └── template-helpers.php
├── template-parts/        site / microsite headers, cards, one file per microsite section
├── *.php                  page templates (front page, event, microsite, maintenance, 404…)
├── page-templates/        Style guide
└── assets/                theme CSS / JS
```

**Rule of thumb:** if it stores, checks, sends or protects data, it goes in `core/`. If it only changes how a page looks, it goes in the presentation part.
`core/` produces no public page layout except the registration form itself; the presentation part never writes to the database.
Code prefixes: core uses `EPC_` / `epc_`, presentation uses `ept_` / `EPT_`. They stay as they are so saved settings, options and meta keep working.

## Features and where they live

| Feature | Admin location | Code |
|---|---|---|
| Events, microsites, applicants (post types) | Event Plus menu; defined in ACF → Post Types | `core/acf-json/post_type_epc_*.json` + `core/access/admin.php` (Duplicate / Applicants / Report row links) + `core/acf.php` ("add new" permission) |
| Event / microsite / settings fields, Settings page | edit screens, Event Plus → Settings; defined in ACF → Field Groups / Options Pages | `core/acf-json/*.json` (+ `core/acf.php` for code-filled choices) |
| Registration form: render, validation, spam guards, storage | event page, Form section | `core/registration/form.php`, `validator.php`, `core/assets/js/form.js` |
| Capacity, status, waiting list | event edit screen | `core/registration/capacity.php` |
| Optional features (alerts, seat freed, calendar, masking) | Settings → Features | `core/applicants/features.php` |
| Applicants list, edit, notes & activity, bulk actions | Event Plus → Applicants | `core/applicants/applicants-admin.php`, `core/access/admin.php` (read-only view) |
| Emails (notification, confirmation, waitlist, .ics) | event edit screen, Settings → Email (recipients) | `core/email/mailer.php` |
| SMTP sending, test email, email log | SMTP (own menu) | plugin **Tamago SMTP** |
| Visitor analytics + reports, registration goal | Reports (own menu) | plugin **Tamago Reports** + `core/tamago.php` (goal, consent) |
| Import / export (JSON, ZIP, Excel, CSV, ACF) | Import & Export (own menu) | plugin **Tamago Import & Export** + `core/applicants/applicants-io.php` (Applicants sheet) |
| Roles & permissions, hidden Developers | Roles & Permissions (own menu) | plugin **Tamago Roles** + `core/tamago.php` (`epc_permissions()`) |
| Site access (maintenance page) | Settings → Site access | `core/access/site-access.php` + theme `maintenance.php` |
| Overview dashboard | Event Plus → Overview | `core/access/admin.php` |
| Shared helpers (settings, lists, field definitions, client IP) | – | `core/helpers.php` |
| Microsite sections | microsite edit screen | `template-parts/sections/*.php` (fields: ACF → Field Groups → Microsite, `core/acf-json/group_epc_microsite.json`) |
| Event registration page layout | – | `single-ep_event.php` |
| Branding / white label | Appearance → Branding | `inc/branding.php` (page and fields: `acf-json/*_ept_branding.json`) |
| Style guide | private page "Style guide" | `page-templates/style-guide.php` |

## ACF configuration (Local JSON)

Post types, options pages and field groups are all set up in **ACF Pro**, not in theme code. They are visible and editable in the ACF menu (Developers only) and stored as JSON files inside the theme, so they are versioned in git and travel with the theme.

| ACF screen | Item | Key | File |
|---|---|---|---|
| Post Types | Events (`ep_event`, URL `/event/{slug}/`) | `post_type_epc_event` | `core/acf-json/post_type_epc_event.json` |
| Post Types | Microsites (`ep_microsite`, URL `/e/{slug}/`) | `post_type_epc_microsite` | `core/acf-json/post_type_epc_microsite.json` |
| Post Types | Applicants (`ep_applicant`, admin only) | `post_type_epc_applicant` | `core/acf-json/post_type_epc_applicant.json` |
| Options Pages | Event Plus → Settings (`epc-settings`) | `ui_options_page_epc_settings` | `core/acf-json/ui_options_page_epc_settings.json` |
| Options Pages | Appearance → Branding (`ept-branding`) | `ui_options_page_ept_branding` | `acf-json/ui_options_page_ept_branding.json` |
| Field Groups | Event | `group_epc_event` | `core/acf-json/group_epc_event.json` |
| Field Groups | Microsite (sections) | `group_epc_microsite` | `core/acf-json/group_epc_microsite.json` |
| Field Groups | Event Plus Settings | `group_epc_settings` | `core/acf-json/group_epc_settings.json` |
| Field Groups | Branding | `group_ept_branding` | `acf-json/group_ept_branding.json` |

- **Editing**: change the item in ACF and save. ACF rewrites its JSON file; commit that file. Items whose key contains `_ept_` are saved to `acf-json/` (presentation), all others to `core/acf-json/` (features). Folder setup: `core/acf.php`.
- **Other sites (staging, a teammate)**: after deploying or pulling, the ACF screens show **Sync available** for changed items. Click *Sync* to load them. Until then the site already uses the JSON version.
- **Never change**: a post type key (`ep_event`, `ep_microsite`, `ep_applicant`), an options page menu slug, or the key / name of a field that holds data. Content is stored under them and code refers to them (e.g. `field_epc_html_code`, `field_epc_ev_reg_status`). Labels, icons and menu positions are safe to change.
- **Post type permissions**: the post types use their own capabilities (*Rename Capabilities*: `ep_event` / `ep_events` etc.), granted in **Roles & Permissions**. Keep those names. ACF has no setting for the "create" capability, so a filter in `core/acf.php` adds it (`create_ep_events`, `create_ep_microsites`; Applicants can never be added by hand).
- **Changing a URL slug** (Post Types → *Permalink Rewrite*): save, then Settings → Permalinks → Save. Existing links stop working.
- **Choices filled by code** (overwritten when the field loads, so edit them in code, not in ACF): form builder *Type* (`epc_field_types()`), *Saved list* (Settings → Option lists), Branding fonts (`ept_font_catalogue()`). The banner countdown default also comes from code (`core/applicants/features.php`).
- **Backups**: ACF → Tools → Export gives one JSON file with everything above; the files in git are a backup too. On a site that already has this theme, use *Sync*, not Import, or the items are created twice.
- The server needs write access to the `acf-json` folders only if someone edits ACF there; normally edit locally and deploy.

## Developer guidelines

- **Where code goes**: each folder in `core/` is one feature area named after what it does (registration, applicants, email, access). Email sending, reports, import / export and roles are **not** theme code: they are the Tamago plugins, configured on their screens (see `docs/event-plus-settings.md`). The theme only connects to them in `core/tamago.php`.
- **A new permission**: add it to `epc_permissions()` in `core/tamago.php` (it appears in Roles & Permissions at once), and check its capability with `current_user_can()`. Every file starts with a comment saying what it does and which *Screen* it serves – read that first.
- **Adding a feature**: put it in the folder it belongs to (or a new plain-named folder) as a class with a static `init()` that adds its hooks; `require_once` it and call `init()` in `core/bootstrap.php`. Class names keep the `EPC_` prefix (e.g. `EPC_Form` in `registration/form.php`). Create its fields in ACF → Field Groups (they save to `core/acf-json/`), its admin assets go in `core/assets/`.
- **Adding a microsite section**: add a layout to the *Sections* field in ACF → Field Groups → Microsite, then a template `template-parts/sections/{layout}.php` (file name = layout name).
- **Assets** in core use `EPC_URL` / `EPC_DIR`; presentation uses `get_template_directory_uri()`. Version query strings: `EPC_VERSION` (core) and `EPT_VERSION` (presentation) – bump them when CSS / JS changes.
- **New post type or options page**: create it in ACF (it saves to `core/acf-json/`). Give a presentation-only item a key containing `_ept_` so it is saved to `acf-json/`.
- **One-time setup** (default lists, Event Plus capabilities for Administrator and Developer, rewrite rules) runs on theme activation and once after every `EPC_VERSION` change (`epc_setup()` in `core/bootstrap.php`). Roles themselves live in Tamago Roles.
- **Status changes** go through `EPC_Features::set_status()` only; emails through `EPC_Mailer`.
- **Security**: check a capability *and* a nonce on every admin action; escape on output; new public REST routes must respect `EPC_Site_Access`; run the tests before every commit.
- **Child theme**: possible – the core loads from the parent theme (`get_template_directory()`), and a child theme can override templates such as `maintenance.php` or a section.
- Do not install the old **EP Events Core** plugin next to this theme. If it is still active somewhere, the theme does not load its core and shows a notice asking for the plugin to be removed.

## Local setup (XAMPP)

- URL: http://localhost/event-planner/ · admin: http://localhost/event-planner/wp-admin/
- Database: `epevents` (MariaDB, root, no password)
- Plugins: Advanced Custom Fields PRO (licence entered under ACF → Updates) and the four Tamago plugins
- Plugin test sites (clean WordPress, one per plugin): `http://localhost/tamago-lab-smtp|reports|io|roles/` (+ `tamago-lab-io2` for imports); each plugin folder is linked into them, logins in `.local-credentials.txt`. Self-tests: `php tools/wp-cli.phar --path=../tamago-lab-io eval-file wp-content/plugins/tamago-io/tests/run.php --user=1` (same pattern for each plugin; SMTP needs its test mail server `php ../tamago-lab-smtp/smtp-sink/sink.php`).
- Active theme: EP Events
- WP-CLI: `php tools/wp-cli.phar --path=. …` (local test logins are in the git-ignored `.local-credentials.txt`)
- Permalinks: `/%postname%/`; `.htaccess` uses `RewriteBase /event-planner/`.

## Content model

| Type | URL | Purpose |
|---|---|---|
| Microsite (`ep_microsite`) | `/e/{slug}/` | Shareable landing page built from sections |
| Event (`ep_event`) | `/event/{slug}/` | Official registration form + capacity |
| Applicant (`ep_applicant`) | admin only | One registration; answers stored as `_epc_data` + `f_{key}` meta |
| Post | `/{slug}/` | News / blog articles shown in microsite "News" sections |

### Microsite sections (ACF flexible content)
Introduction banner · Text / essay · Image + text · Image · Image gallery (grid / masonry / slider + lightbox) ·
Speakers (cards with bio pop-up, or list) · Programme / agenda · Cards (hotels, info boxes) · News / blog / articles ·
Logos / sponsors · Video · Contact persons (WhatsApp / phone / email) · Registration form (embed) · Custom HTML.

Every section has the same **appearance** panel: background *colour / gradient / image (+ mobile image) / video*, overlay,
text colour (auto-contrast), spacing, width, anchor ID, top-menu label and hide toggle.
Banner buttons can open an Event form, a URL (Zoom etc.), scroll to a section, or a **pop-up list of links** (breakout rooms).
Banner styles: **Centred**, **Text + key visual** (image beside the text), **Card on background** (text in a white panel over a photo).
Most sections also take a small label above the heading (e.g. "Day 1").

Demo content: `wp-content/themes/epevents/core/dev/seed-showcase.php` creates a private "All sections showcase" microsite using every layout (`/e/all-sections/`).
A private **Style guide** page (template *Style guide*) shows colours, type, buttons, badges, form fields and cards for client sign-off.

### Event registration page
Layout per event: **Details beside form** (default: status badge, date / time / venue with map link, "About this event") or **Form only**.
On phones the details sit above the form. Status badge: Registration open · *N* seats left (≤ 20) · Waiting list open · Application full · Closed · Opening soon.

### Event form builder
Field types: Salutation, Text, Paragraph, Email, Phone, NRIC/ID, Number, Date, Dropdown, Radio, Checkboxes, Country,
Consent, Hidden, Heading, Text block. Per field: width (full, 2/3, 1/2, 1/3, 1/4), required, placeholder, help, default,
"Others – please specify", **unique per event** (blocks duplicate email / NRIC), and **conditional display**
(e.g. show NRIC / MMC / State only when Country is Malaysia).

- **Saved lists** (Event Plus → Settings): reusable options, incl. `salutation` used by every Salutation field.
- **Formats** (validated in browser *and* server):
  - NRIC – Malaysian MyKad (date + place-of-birth code checked, saved as `YYMMDD-PB-####`), NRIC-or-passport, or any.
  - Phone – Malaysia (saved as `+60…`), international (country code required), or any.
  - Email – validated and lower-cased.
- After submit: thank-you message with placeholders (`{fullname}`, `{event_title}`…) or redirect; admin notification; applicant confirmation email with `{registration_details}`.
- Spam and abuse: honeypot; a **signed** "form shown at" time (at least 3 seconds to fill, cannot be faked); at most **20 registrations per visitor network per event per hour** (`define( 'EPC_RATE_LIMIT', 50 );` to change, `0` = off); at most 5 automatic confirmation emails per address per hour.
- Overbooking and double submissions prevented with a DB lock around the capacity and duplicate checks.
- Password-protected events show WordPress's password form instead of the registration form until unlocked.

### Capacity & status
Status: **Automatic** (opens/closes on dates; "Application Full" when max reached), **Force open**, **Application Full**, **Closed**.
Min / max capacity, optional waiting list, editable Full / Closed / Waitlist messages. Event list shows `count / max`, progress bar and "min not reached".
Seats are taken by Registered, Confirmed and Attended applicants; Waitlist and Cancelled do not count.

### Applicants
- Filter by event: a summary bar shows seats taken / max, progress, "minimum not reached" and a count per status. Status links show counts.
- Search inside every answer (name, email, NRIC, phone…); users who see masked data search names only. Bulk: change status, resend confirmation email. Export Excel / CSV / JSON.
- Edit screen: answers are checked and normalised like the public form (email, phone, NRIC); a warning lists anything that does not pass.
- **Notes & activity**: staff notes plus an automatic log of status changes, edits, emails and moves between events (who and when).
- **Resend confirmation email** from the row, the edit screen or in bulk (sends the waiting-list version to waiting applicants).
- Viewers get a read-only view with the same activity log.

### Optional features (Event Plus → Settings → Features, all off by default)
| Feature | What it does |
|---|---|
| Capacity alert email | Emails the event's notification recipients at a set % of capacity (default 90%) and again when full; each alert once per event. |
| When a seat frees up | *Do nothing* (default) · *Email the team* · *Give the seat to the next person on the waiting list and email them*. A seat frees up when a seated applicant is cancelled or deleted, or the maximum is raised. Respects a manual "Application full" / "Closed". |
| Calendar invite | Confirmation emails carry an `.ics` file (Outlook / Apple) and an "Add to Google Calendar" link; `{calendar_link}` placeholder. Needs the event start date. |
| Countdown on new banners | New banners start with the countdown switched on. |
| Hide personal data from Viewers | Users who cannot edit applicants see `j•••@mail.com`, `••••••-••-1234` in lists, the read-only view **and exports**. |

Waiting-list and seat-offer email texts are editable in the same tab. People on the waiting list now get a waiting-list email instead of the normal confirmation.
Emails are built in `core/email/mailer.php`; features and status changes in `core/applicants/features.php` (`EPC_Features::set_status()` is the only place a status should change).

## Email sending (SMTP) – Tamago SMTP
**SMTP** (own admin menu: Settings · Test · Logs · Tools). Settings → Email keeps only the default notification recipients.

- Server, encryption, login and password (encrypted, never shown again; dropped when the host or username changes). Provider presets.
- From name / From email, forced for every email the site sends.
- **Test**: send a test email or only check the connection; shows the SMTP conversation (passwords masked) and a plain-language hint.
- **Logs**: every email with status, recipients, content, headers, attachments, error, source and timing; resend, CSV, 30-day retention, failure alert.
- `wp-config.php` can lock any setting: `TMG_SMTP_HOST`, `TMG_SMTP_PORT`, `TMG_SMTP_ENCRYPTION`, `TMG_SMTP_USERNAME`, `TMG_SMTP_PASSWORD` …
- Details: `wp-content/plugins/tamago-smtp/README.md`; Event Plus values: `docs/event-plus-settings.md`.

## Site access (maintenance page)
Event Plus → Settings → **Site access**. Default: **Direct links only**.

| URL | Visitors see |
|---|---|
| Homepage, search, archives, other pages, 404s | Branded maintenance page (`noindex`), editable title, message, contact email, background image |
| `/event/{slug}/` forms and `/e/{slug}/` microsites | The page, when opened from its link |
| News articles | Open by direct link (switchable; microsite "News" sections link to them) |
| Privacy policy page | Always open (consent checkboxes link to it) |
| Other pages | Maintenance, unless "Pages open by direct link" is on |
| Feeds, sitemaps, public REST listing / search (events, microsites, media, users, posts, pages) | Not available, so links cannot be discovered |
| `/?p=123`-style ID links and WordPress's "did you mean" redirects (`/a` → first slug starting with a) | Maintenance page, so nobody can walk through IDs or guess slugs. Staff previews still work. |

Staff see a "Direct links only" marker in the admin bar. Switch to **Public website** for a normal homepage.
Logic: `core/access/site-access.php`; page: theme `maintenance.php` (a child theme can override it).

## Automated tests
```bash
php tools/wp-cli.phar --path=. eval-file wp-content/themes/epevents/core/tests/run.php
```
49 checks: validators (NRIC, phone, email), the registration form end to end (spam guards incl. forged timestamps, rate limit, password-protected events, malformed input, required and conditional fields, normalised storage, duplicates, emails), capacity and waiting list, the optional features, masking, Custom HTML cleaning and the private-mode REST blocks.
The test creates its own temporary event (copying the richest published form), captures emails instead of sending them, and removes everything afterwards. Exit code 1 on failure.

## Reports (visitor analytics) – Tamago Reports
**Reports** (own admin menu: Overview · Real-time · Settings). First-party beacon (cache-friendly REST endpoint) recording per page view: device, browser, OS, screen,
language, **timezone**, **country / region / city**, referrer, channel (direct / search / social / email / paid / QR / campaign),
UTM source / medium / campaign, time on page, scroll depth, button clicks and outbound links. Registrations are recorded
server-side as the **Registration** goal (`core/tamago.php`) with the visitor's campaign attribution, giving a conversion rate per event.

- Location source (Reports → Settings): Cloudflare/proxy headers (default, no external calls), ipapi.co, or ipinfo.io.
- No raw IPs stored (salted hash only); 730-day retention; staff roles excluded; optional Do-Not-Track.
- Reports: date ranges with comparison, filters, KPI tiles, chart, pages / sources / campaigns / devices / locations / events / goals, hour × weekday heatmap, real-time, per-page report (also from the Report link on events), CSV exports.
- Details: `wp-content/plugins/tamago-reports/README.md`.

## Import / Export – Tamago Import & Export
**Import & Export** (own admin menu: Export · Import · History · Settings).

- **Any content**: events (with forms), microsites, posts, pages, media, terms, users, menus, options and ACF options pages, with all custom fields and **ACF values** (repeaters, flexible content, relationships – IDs are remapped on import).
- **ACF structure**: post types, taxonomies, field groups, options pages (ACF's own format).
- Formats: JSON, ZIP (with media files), Excel, CSV. Presets, dry run, batched imports with progress, history and reports.
- **Applicants** (source added by the theme): one column per form field; import lists from anywhere (old site, Google Forms, Excel) into an event with column mapping, skipping duplicates. The Export buttons above the Applicants list still download the filtered list.
- Importing follows the importer's own rights (post-type capabilities, publish, Custom HTML, options and ACF structure only for managers).
- Details: `wp-content/plugins/tamago-io/README.md`.

## Roles & permissions – Tamago Roles
**Roles & Permissions** (own admin menu: Roles · Permissions · Users · Admin menus · Content access · Settings · Log). The Event Plus permissions are registered by `epc_permissions()` in `core/tamago.php`; the plugins add theirs (Reports, Import & Export, SMTP, Roles). Recovery: `define( 'TMG_ROLES_SAFE_MODE', true );`.

Roles, highest first: **Developer** → **Super Admin** → **Manager** → **Viewer**.
WordPress *Administrator* is treated as Developer.

- **Developer** always has everything, and is the only role that sees WordPress's own areas: Dashboard, Posts, Pages, Comments, Appearance (incl. Branding and Menus), Plugins, Tools, Settings and ACF. These are *protected capabilities* (minimum rank 4): menus are removed, screens opened by direct URL go back to Event Plus → Overview, and the matching capabilities are stripped from every other role and user.
- Everyone else works only in **Event Plus**, **Media** (if allowed), **Users** (if allowed) and their **Profile**.
- **Super Admin, Manager and Viewer** get exactly what the matrix grants, so each role only sees the sections it is allowed (menus are hidden *and* the screens are blocked). The WordPress dashboard is skipped: they land on Event Plus → Overview.
- **Roles & Permissions** (own admin menu) has the full matrix, per-user Allow/Deny overrides, admin-menu rules per role and a change log. Anyone with *Manage permissions* can only change roles and users ranked **below** their own: a Developer edits Super Admin, Manager and Viewer; a Super Admin edits Manager and Viewer, and sees their own column locked.
- Nobody can hand out a permission they do not hold themselves (those boxes are locked for them).
- **Custom HTML & embed codes**: the code is cleaned when it is saved by someone without this permission (iframes and safe HTML stay, scripts go), so it is safe whoever views the page.
- Enforcement uses real WordPress capabilities (custom capability types on each post type), not just hidden menus.

| Permission | Super Admin (default) | Manager (default) | Viewer (default) |
|---|:-:|:-:|:-:|
| Dashboard | ✔ | ✔ | ✔ |
| View events / microsites | ✔ | ✔ | ✔ |
| Create & edit events / microsites | ✔ | ✔ | – |
| Publish events / microsites | ✔ | ✔ | – |
| Open / close registration manually | ✔ | ✔ | – |
| Delete events / microsites | ✔ | – | – |
| View applicants (read-only view) | ✔ | ✔ | ✔ |
| Edit applicants & status | ✔ | ✔ | – |
| Delete applicants | ✔ | – | – |
| Export applicants | ✔ | ✔ | – |
| View reports | ✔ | ✔ | ✔ |
| Export report data | ✔ | ✔ | – |
| Export content | ✔ | ✔ | – |
| Import content | ✔ | – | – |
| Media uploads | ✔ | ✔ | – |
| Custom HTML & embed codes (scripts) | ✔ | – | – |
| Settings (lists, site access, features) · Email (SMTP) · Report settings | ✔ | – | – |
| Manage users | ✔ | – | – |
| Manage permissions | ✔ | – | – |

User management rules:
- Nobody can edit, delete or promote a user ranked above them.
- Roles can only be assigned up to your own level (a Super Admin can add another Super Admin, never a Developer).
- Developer (and Administrator) accounts are **ghosts** to everyone else: not in the user list, user counts, role filters or "Who has which role"; shown under the site name in activity logs and as author; their author pages and REST user entries return "not found".

News articles (Posts) are written by a Developer; microsite "News" sections pick them up as before.

## Branding (white label)
Appearance → **Branding** (theme `inc/branding.php`, Developer only), with a live preview:

- **Logo**: main logo, logo for dark backgrounds, favicon, header logo height, footer copyright (`{year}`, `{site}`).
- **Colours**: primary, secondary, page, cards, text, secondary text, borders, footer background and text. Button text contrast is automatic. Events and microsites can still set their own accent.
- **Fonts**: 18 curated Google Fonts, system font, or any custom font by name + stylesheet URL; text size; heading weight.
- **Shapes**: corners (square / soft / round) and buttons (pill / rounded / square).
- **Login page**: logo, background colour or image, message.
- **Admin**: replaces the WordPress logo, news, welcome panel and version with your brand; admin footer text; dashboard welcome note.
- **Emails**: wraps registration emails with the logo, primary colour and a footer (filter `epc_email_html`).

Defaults (used until something is saved): primary `#6d1a45` (plum), secondary `#e0a23a` (amber), page `#f7f5f2`, text `#1c1a1f`, Plus Jakarta Sans for headings and body, 16 px text, soft corners, pill buttons. Change them in `ept_brand_defaults()`.

## Deploying to staging (cPanel)
Git holds only the theme; ACF Pro, the database and uploads travel separately.

1. **Files**: in cPanel install WordPress (or upload the core files), then upload
   `wp-content/plugins/advanced-custom-fields-pro/`, the four `wp-content/plugins/tamago-*/` folders, `wp-content/themes/epevents/` and `wp-content/uploads/`.
   If the server already has the old `wp-content/plugins/epevents-core/` plugin, deactivate and delete it first (the theme warns while it is active).
   A server that ran theme 2.x keeps its data: activate the Tamago plugins and run `core/dev/migrate-to-tamago.php` once (see `docs/event-plus-settings.md`).
2. **Database** – export locally with the URLs already replaced (one line, run in the project folder):
   ```bash
   php tools/wp-cli.phar --path=. search-replace 'http://localhost/event-planner' 'https://STAGING-DOMAIN' --all-tables --export=epevents-staging.sql
   ```
   Import `epevents-staging.sql` in cPanel → phpMyAdmin, and put that database's name / user / password in the staging `wp-config.php`.
3. **wp-config.php** on staging:
   ```php
   define( 'WP_DEBUG', false );
   define( 'DISALLOW_FILE_EDIT', true );
   define( 'FORCE_SSL_ADMIN', true );
   define( 'ACF_PRO_LICENSE', '…' );
   // Only behind Cloudflare / a proxy, so form rate limits see the real visitor (Reports: Settings → real visitor IP header):
   // define( 'EPC_PROXY_IP_HEADER', 'HTTP_CF_CONNECTING_IP' );
   // Optional: mail server here instead of SMTP:
   // define( 'TMG_SMTP_HOST', '…' ); define( 'TMG_SMTP_USERNAME', '…' ); define( 'TMG_SMTP_PASSWORD', '…' );
   ```
   Use **fresh** salts from https://api.wordpress.org/secret-key/1.1/salt/ – then re-enter the SMTP password in SMTP (it is encrypted with the salts).
4. **Permalinks**: Settings → Permalinks → Save (writes `.htaccess` for the new folder).
5. **Accounts**: the local test accounts come along with the database. Change every password (or delete the test users) before anyone else gets the link.
6. **ACF**: Post Types, Options Pages and Field Groups – if any shows *Sync available*, click *Sync*. Then Settings → Permalinks → Save once more.
7. **Check**: SMTP → Test → *Send test email*; open an event link and register once; open the homepage signed out (maintenance page).

## Not in this phase
- SEO fields (per agreement – later phase).
- Admin screens of the current live site could not be reviewed (no session in the review browser); the field set was derived from the public pages.
