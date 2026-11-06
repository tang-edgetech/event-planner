# Event Plus – WordPress rebuild

Rebuild of https://epevents.com.my/ as a custom WordPress theme + functionality plugin, built on **ACF Pro** (no form plugins).

| Part | Path | Responsibility |
|---|---|---|
| Plugin **EP Events Core** | `wp-content/plugins/epevents-core/` | Post types, ACF field groups, form builder & submissions, capacity, applicants, analytics, import/export, roles & permissions |
| Theme **EP Events** (`epevents`) | `wp-content/themes/epevents/` | Front-end presentation: microsite sections, event registration page, news |

Data lives in the plugin, so the theme can be renamed or replaced later without losing anything.
Only these two folders (plus this README) are tracked in git; WordPress core, `wp-config.php` and uploads are not.

## Local setup (XAMPP)

- URL: http://localhost/event-planner/ · admin: http://localhost/event-planner/wp-admin/
- Database: `epevents` (MariaDB, root, no password)
- Plugins: Advanced Custom Fields PRO 6.8.10 (from the supplied zip) + EP Events Core
- ACF Pro licence: add `define( 'ACF_PRO_LICENSE', 'your-key' );` to `wp-config.php` (or enter it under ACF → Updates) to enable updates.
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

Demo content: `wp-content/plugins/epevents-core/dev/seed-showcase.php` creates a private "All sections showcase" microsite using every layout (`/e/all-sections/`).
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
- Spam: honeypot + minimum fill time. Overbooking prevented with a DB lock around the capacity check.

### Capacity & status
Status: **Automatic** (opens/closes on dates; "Application Full" when max reached), **Force open**, **Application Full**, **Closed**.
Min / max capacity, optional waiting list, editable Full / Closed / Waitlist messages. Event list shows `count / max`, progress bar and "min not reached".
Seats are taken by Registered, Confirmed and Attended applicants; Waitlist and Cancelled do not count.

### Applicants
- Filter by event: a summary bar shows seats taken / max, progress, "minimum not reached" and a count per status. Status links show counts.
- Search inside every answer (name, email, NRIC, phone…). Bulk: change status, resend confirmation email. Export Excel / CSV / JSON.
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
| Hide personal data from Viewers | Users who cannot edit applicants see `j•••@mail.com`, `••••••-••-1234` in lists and the read-only view. |

Waiting-list and seat-offer email texts are editable in the same tab. People on the waiting list now get a waiting-list email instead of the normal confirmation.
Emails are built in `includes/class-mailer.php`; features and status changes in `includes/class-features.php` (`EPC_Features::set_status()` is the only place a status should change).

## Site access (maintenance page)
Event Plus → Settings → **Site access**. Default: **Direct links only**.

| URL | Visitors see |
|---|---|
| Homepage, search, archives, other pages, 404s | Branded maintenance page (`noindex`), editable title, message, contact email, background image |
| `/event/{slug}/` forms and `/e/{slug}/` microsites | The page, when opened from its link |
| News articles | Open by direct link (switchable; microsite "News" sections link to them) |
| Privacy policy page | Always open (consent checkboxes link to it) |
| Other pages | Maintenance, unless "Pages open by direct link" is on |
| Feeds, sitemaps, public REST listing / search of events and microsites | Not available, so links cannot be discovered |

Staff see a "Direct links only" marker in the admin bar. Switch to **Public website** for a normal homepage.
Logic: `includes/class-site-access.php`; page: theme `maintenance.php` (fallback `templates/maintenance.php` in the plugin).

## Automated tests
```bash
php tools/wp-cli.phar --path=. eval-file wp-content/plugins/epevents-core/tests/run.php
```
38 checks: validators (NRIC, phone, email), the registration form end to end (spam guards, required and conditional fields, normalised storage, duplicates, emails), capacity and waiting list, the optional features, and masking.
The test creates its own temporary event (copying the richest published form), captures emails instead of sending them, and removes everything afterwards. Exit code 1 on failure.

## Reports (visitor analytics)
First-party, cookie-less beacon (cache-friendly REST endpoint) recording per page view: device, browser, OS, screen,
language, **timezone**, **country / region / city**, referrer, channel (direct / search / social / email / paid / QR / campaign),
UTM source / medium / campaign, time on page, scroll depth, button clicks and outbound links. Registrations are recorded
server-side with the visitor's original campaign attribution, giving a conversion rate per microsite + linked event.

- Location source (Settings → Tracking): Cloudflare/proxy headers (default, no external calls), ipapi.co, or ipinfo.io.
- No raw IPs stored (salted monthly hash only); configurable retention; editors excluded by default; optional Do-Not-Track.
- Event Plus → Reports: date range, per page, charts, breakdown tables, raw CSV export.

## Import / Export
- **JSON package** – events (with forms), microsites, posts/pages, settings/lists and optionally applicants, with image URLs.
  Importing on any WordPress site with this plugin recreates everything, downloads images, and remaps IDs (event links, images, categories). Existing items: skip / update / import as copy.
- **Excel (.xlsx) / CSV** – applicants (one column per form field), events/microsites (with a `data_json` column so sheets round-trip).
- **Applicants from anywhere** (old site, Google Forms, Excel): upload CSV / XLSX / JSON → map columns → import, skipping duplicates.
- WordPress's own Tools → Export (WXR) also includes Events and Microsites.

## Roles & permissions
Roles, highest first: **Developer** → **Super Admin** → **Manager** → **Viewer**.
WordPress *Administrator* is treated as Developer.

- **Developer** always has everything, and is the only role that sees WordPress's own areas: Dashboard, Posts, Pages, Comments, Appearance (incl. Branding and Menus), Plugins, Tools, Settings and ACF. These are never in the matrix: menus are removed, screens are blocked by direct URL, and the matching capabilities are stripped from every other role and user.
- Everyone else works only in **Event Plus**, **Media** (if allowed), **Users** (if allowed) and their **Profile**.
- **Super Admin, Manager and Viewer** get exactly what the matrix grants, so each role only sees the sections it is allowed (menus are hidden *and* the screens are blocked). The WordPress dashboard is skipped: they land on Event Plus → Overview.
- Event Plus → **Permissions** has the full matrix, per-user Allow/Deny overrides and a "who has which role" view. Anyone with *Manage permissions* can only change roles and users ranked **below** their own: a Developer edits Super Admin, Manager and Viewer; a Super Admin edits Manager and Viewer, and sees their own column locked.
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
| Settings (lists, email, tracking) | ✔ | – | – |
| Manage users | ✔ | – | – |
| Manage permissions | ✔ | – | – |

User management rules:
- Nobody can edit, delete or promote a user ranked above them.
- Roles can only be assigned up to your own level (a Super Admin can add another Super Admin, never a Developer).
- Developer (and Administrator) accounts are **ghosts** to everyone else: not in the user list, user counts, role filters or "Who has which role"; shown as "System" in activity logs; their author name shows as the site name; their author pages and REST user entries return "not found".

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

## Not in this phase
- SEO fields (per agreement – later phase).
- Admin screens of the current live site could not be reviewed (no session in the review browser); the field set was derived from the public pages.
