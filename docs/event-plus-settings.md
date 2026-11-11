# Setting up the Tamago plugins to work like Event Plus

This guide is for any WordPress site that should behave like Event Plus (https://epevents.com.my rebuild):
the same email sending, visitor reports, import / export and roles & permissions.
It has two parts:

- **A. Event Plus itself** – the theme already talks to the plugins; one script moves an existing site over.
- **B. Another project** – the settings to choose screen by screen, or the ready-made preset files to import.

The presets are in [`docs/presets/`](presets/). They were exported from the local Event Plus site and never contain passwords or tokens.

| Plugin | Preset file | Import it in | or with WP-CLI |
|---|---|---|---|
| Tamago SMTP | `event-plus-smtp.json` | SMTP → Tools → Import settings | `wp tamago-smtp settings import docs/presets/event-plus-smtp.json` |
| Tamago Reports | `event-plus-reports.json` | Reports → Settings → Import settings | `wp tamago-reports settings import docs/presets/event-plus-reports.json` |
| Tamago Import & Export | `event-plus-io.json` | Import & Export → Settings → Import settings | `wp tamago-io settings import docs/presets/event-plus-io.json` |
| Tamago Roles | `event-plus-roles.json` | Roles & Permissions → Settings → Import (shows the changes first) | `wp tamago-roles settings import docs/presets/event-plus-roles.json --yes` |

Import **Roles last**: its preset refers to permissions the other plugins and the theme register.

---

## A. Event Plus (this repository)

The theme (`core/tamago.php`) already:

- lists the Event Plus permissions in Tamago Roles (Events, Microsites, Applicants, Content, Settings, Users);
- leaves each plugin on its own admin menu (Reports, Import & Export, SMTP, Roles & Permissions), beside Event Plus;
- records every registration in Tamago Reports as the goal **Registration** (conversion), with the visitor's campaign source;
- gives Tamago Reports consent at once, so visitors keep one ID in the browser exactly as before (returning visitors, registrations linked to their visit);
- adds the source **Applicants (one column per form field)** to Tamago Import & Export, and keeps the Export buttons above the Applicants list;
- shows a warning to Developers when one of the four plugins is not active.

### Moving an existing Event Plus site (staging / live) – once

1. Back up the database.
2. Upload `wp-content/plugins/tamago-smtp`, `tamago-reports`, `tamago-io`, `tamago-roles` and the new theme.
3. Run (or ask the host to run) in the site folder:
   ```bash
   wp plugin activate tamago-smtp tamago-reports tamago-io tamago-roles
   ```
   ```bash
   wp eval-file wp-content/themes/epevents/core/dev/migrate-to-tamago.php
   ```
   The script moves, without losing anything:
   - **Email**: server, port, encryption, login, From name / address (the password is decrypted with the old key and encrypted again by Tamago SMTP). "Force" is switched on for From name and address, as the theme always did.
   - **Reports**: tracking on/off, staff exclusion, Do Not Track, location source and token, retention, and **every saved visit** (the old `wp_epc_visits` table is copied, registrations become the *Registration* goal, then the old table is dropped).
   - **Roles**: ranks, the permission matrix **as it is now on that site** (not the defaults), per-user Allow/Deny overrides, the developer-only WordPress areas, hidden menus and admin-bar items, the landing page after login and the hidden Developer names.
   - It runs each part once; running it again does nothing.
4. ACF → Field Groups → *Event Plus Settings* → **Sync** (the Email tab lost its server fields, the Tracking tab moved to Reports).
5. Check: **SMTP** → **Test** → send a test email; register once on an event; **Reports** shows the visit and one *Registration* conversion.

Without WP-CLI (cPanel only): activate the four plugins on the Plugins screen, then import the four presets as in part B, re-enter the SMTP password and set the roles' permissions in the matrix. Saved visits from before the move are then not copied.

### What changed for staff

| Before | Now |
|---|---|
| Event Plus → Settings → Email (server, From) | **SMTP** (Settings · Test · Logs · Tools). Settings → Email keeps only the default notification recipients. |
| Settings → Tracking | **Reports → Settings** |
| Event Plus → Reports | **Reports** (more tables, real-time, comparison with the previous period) |
| Event Plus → Import / Export | **Import & Export** (any content incl. ACF; applicants are the source *Applicants*) |
| Event Plus → Permissions | **Roles & Permissions** |
| Opening a blocked WordPress screen showed "only available to Developers" | It now goes to Event Plus → Overview with a notice |
| Developer shown as "System" in an applicant's history | Shown as the site name, as on the front end |

New for staff: an **email log** (every email with status, recipient, full content and error), **resend**, failure alerts, a **real-time** panel, report CSVs per table, views per event in the event list, and export presets.

---

## B. Another project: settings screen by screen

Install the four plugins from `wp-content/plugins/tamago-*` (no other plugin is needed; ACF is optional and used when present).
By default each plugin has its own top-level admin menu: **Reports** (below Dashboard), **Roles & Permissions** (below Users), **Import & Export** (below Tools) and **SMTP** (below Settings). Positions can be changed with `add_filter( 'tamago_menu_position', fn( $pos, $plugin ) => …, 10, 2 );`.
To group them under a menu of your own instead, add to the theme: `add_filter( 'tamago_menu_parent', fn() => 'your-menu-slug' );` (Event Plus does not do this).

### 1. Tamago SMTP – how Event Plus sends email

| Tab | Setting | Event Plus value | Why |
|---|---|---|---|
| Settings | Send email through SMTP | **On** once the mail account exists | PHP mail on shared hosting lands in spam |
| | Provider | your mail host (Other SMTP / Microsoft 365 / Google Workspace …) | Fills host, port and encryption |
| | Encryption · Port | **TLS · 587** (SSL · 465 if the host asks) | |
| | Authentication · Username · Password | On · the mailbox · its password | Password is encrypted and never shown again |
| | From email | an address **on the site's own domain**, e.g. `events@…` | Other domains are rejected or marked as spam |
| | From name | the brand name (e.g. *Event Plus*) | |
| | Force From email / Force From name | **On / On** | Every email (WordPress, forms) uses the same sender |
| | Return-Path = From · Reply-To · Always BCC | Off · empty · empty | |
| | Log emails · Keep bodies | **On · On** | Staff can check what an applicant received |
| | Keep transcript for successful emails | Off | Failures always keep it |
| | Keep logs for | **30 days** | Personal data: keep it short |
| | Failure alert | On, to the developer's address (optional) | At most one per hour |
| Test | Send a test email | after saving | Shows the SMTP conversation and a hint when it fails |

Server details may instead go in `wp-config.php` (then the fields are locked on screen):
```php
define( 'TMG_SMTP_HOST', 'mail.example.com' );
define( 'TMG_SMTP_PORT', 587 );
define( 'TMG_SMTP_ENCRYPTION', 'tls' );
define( 'TMG_SMTP_USERNAME', 'events@example.com' );
define( 'TMG_SMTP_PASSWORD', '…' );
```

### 2. Tamago Reports – what Event Plus tracks

| Section | Setting | Event Plus value |
|---|---|---|
| Tracking | Tracking on | On |
| What is tracked | Content types | Everything except Attachments and 404 pages (default). Event Plus mainly reads **Events** and **Microsites** |
| | Include / exclude IDs and paths | none |
| | Query strings | Strip all (default), keeping UTM tags |
| Visitors | Exclude roles | Every role that can edit content (Administrator, Developer, Super Admin, Manager, Viewer, Editor, Author, Contributor), so staff visits do not count |
| | Exclude IPs | the office IP, if fixed |
| | Bots · Do Not Track · Global Privacy Control | Excluded · Off · On |
| Consent | Mode | **Wait for consent** + the theme calls `tamagoReports.consent(true)` (Event Plus does this in `core/tamago.php`). For a site with a cookie banner, call it when the visitor accepts, or set the banner's cookie name/value instead |
| Events | Outbound links, downloads, mailto / tel, `data-tmg-event` elements, scroll, engaged time | On (default). Form submissions: off (registrations are recorded on the server instead) |
| Goals | **Registration** – type *Server-side event*, match `registration` | Added by the Event Plus theme in code; add it here on another site |
| Location | Source | **CDN headers** (Cloudflare "visitor location headers") – no outside requests. ipapi.co / ipinfo.io only if the client agrees |
| Data | Keep raw data for | **730 days** |
| | Rate limit | 300 per 10 minutes per visitor (default) |
| | Real visitor IP header | `HTTP_CF_CONNECTING_IP` behind Cloudflare, otherwise empty |

Recording a conversion from your own code (what Event Plus does after a registration):
```php
tmg_reports_track( 'registration', array(
	'post_id' => $event_id,
	'label'   => 'Registration #' . $entry_id,
	'source'  => array( 'utm_source' => '…', 'utm_campaign' => '…' ), // optional; otherwise taken from the visit
) );
```
Reading numbers for your own dashboard: `tmg_reports_query( array( 'range' => '7d', 'metrics' => array( 'views', 'visitors', 'conversions' ) ) )`.

### 3. Tamago Import & Export – how Event Plus moves data

| Setting | Event Plus value |
|---|---|
| Largest upload | 64 MB |
| Batch size · seconds per step | 25 items · 15 s |
| Download media by URL | On (max 32 MB per file) |
| Keep history for | 90 days |
| Skipped meta keys · protected options | defaults |

Typical jobs, saved as **export presets** (Export tab → Save as preset):

| Job | Choices |
|---|---|
| Move events and microsites to another site | Content: *Events*, *Microsites* (+ Pages, Posts) · ACF values on · featured images and media · format **ZIP** (includes the images) |
| Settings and option lists | Options: prefix `options_epc_` · ACF options page *Event Plus Settings* |
| ACF structure | ACF: post types, field groups, options pages (also importable with ACF's own tool) |
| Applicants of one event | Source **Applicants (one column per form field)** · event · status · Excel |
| Applicants from Google Forms / old site | Import → upload CSV/XLSX → source *Applicants* → choose the event → map columns → dry run → import |

To offer a site-specific sheet like the Applicants one, register a source (`tmg_io_sources`, see `wp-content/plugins/tamago-io/README.md`; the Event Plus example is `core/applicants/applicants-io.php`).

### 4. Tamago Roles – Event Plus's roles and permissions

The preset `event-plus-roles.json` does all of this. Done by hand, in **Roles & Permissions**:

1. **Your permissions**: the theme (or a small plugin) registers its own through `tamago_permissions` – see `core/tamago.php` → `epc_permissions()` for the Event Plus list.
2. **Roles tab**
   | Role | Key | Rank | Starts from |
   |---|---|---|---|
   | Administrator | `administrator` | 4 | – |
   | Developer | `ep_developer` | 4 | Clone of Administrator |
   | Super Admin | `ep_super_admin` | 3 | Nothing (log-in only) |
   | Manager | `ep_manager` | 2 | Nothing (log-in only) |
   | Viewer | `ep_viewer` | 1 | Nothing (log-in only) |
   | Editor, Author, Contributor, Subscriber | – | 0 | unchanged (only top rank may assign them) |
3. **Permissions tab** (matrix defaults; tick in your project's own groups the same way)
   | Permission | Super Admin | Manager | Viewer |
   |---|:-:|:-:|:-:|
   | Access dashboard | ✔ | ✔ | ✔ |
   | View events / microsites | ✔ | ✔ | ✔ |
   | Create & edit, publish events / microsites | ✔ | ✔ | – |
   | Open / close registration | ✔ | ✔ | – |
   | Delete events / microsites | ✔ | – | – |
   | View applicants | ✔ | ✔ | ✔ |
   | Edit applicants & status · Export applicants | ✔ | ✔ | – |
   | Delete applicants | ✔ | – | – |
   | Reports: view | ✔ | ✔ | ✔ |
   | Reports: export | ✔ | ✔ | – |
   | Reports: manage settings | ✔ | – | – |
   | Import & Export: export | ✔ | ✔ | – |
   | Import & Export: import | ✔ | – | – |
   | Import & Export: manage (ACF structure, options) | – | – | – |
   | Email: settings · logs | ✔ | – | – |
   | Upload media | ✔ | ✔ | – |
   | Custom HTML & embed codes | ✔ | – | – |
   | Event Plus settings | ✔ | – | – |
   | Manage users | ✔ | – | – |
   | Manage permissions · manage users' overrides | ✔ | – | – |

   Administrator and Developer get every permission.
   **Protected capabilities**: *Add preset: Technical WordPress areas*, minimum rank **4** – posts, pages, comments, appearance, plugins, tools, settings, updates and ACF stay with Developers whatever else is ticked.
4. **Settings tab**: hide users ranked above the viewer **on**, only those at rank ≥ **4**; peers may manage each other **on**; unranked roles assignable by lower ranks **off**; apply rules to administrators **off**.
   **Public name for staff**: roles *Developer* and *Administrator*, public name empty (= site name), real names visible from rank **4**, REST single user returns "not found" **on**.
5. **Admin menus tab** – for Super Admin, Manager and Viewer: untick *Dashboard, Posts, Pages, Comments, Appearance, Plugins, Tools, Settings, ACF*. Admin bar: hide *comments, customize, updates, themes, widgets, dashboard, site editor, menus, new post, new page, edit*. After login go to `admin.php?page=epc` (your own landing page on another project).
6. Leave Developer without rules. Use **Users tab** for per-person Allow/Deny.

Recovery: `define( 'TMG_ROLES_SAFE_MODE', true );` in `wp-config.php` switches every restriction off until removed.

---

## Checklist for a new project

1. Install WordPress, ACF Pro (if the project uses ACF) and the four Tamago plugins.
2. Register the project's permissions with `tamago_permissions` and, if wanted, `tamago_menu_parent`.
3. Import the four presets (Roles last) or follow the tables above.
4. SMTP: enter the server and password, send a test email.
5. Reports: add the project's goals; call `tmg_reports_track()` where a conversion happens.
6. Roles: adjust the matrix, create the staff accounts, log in as each role once to check the menus.
7. Import & Export: save the export presets the client will use; run one export → import on staging.
