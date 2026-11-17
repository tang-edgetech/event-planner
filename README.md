# Event Plus

Event registration platform for Event Plus: events with registration forms, microsites built from sections,
applicants, reports, users with 2FA, and a full audit log. Rebuilt from WordPress into three apps.

## Folders

| Folder | What it is | Runs at |
|---|---|---|
| `apps/api/` | **API**: Laravel 13 + MySQL (database `event_plus`). No pages; `/` is a 404 | http://localhost:8000 (`php artisan serve`) |
| `apps/web/` | **Public site**: Next.js + Tailwind. Only `/event/{slug}` and `/e/{slug}` show content; the homepage shows "under maintenance" or a 404 (Settings → Extra). Not indexed by search engines | http://localhost:4003 |
| `apps/backoffice/` | **Backoffice**: Next.js + Tailwind + Ant Design under a **secret base path** (`BACKOFFICE_BASE_PATH`). Everything else, including `/`, is a plain 404 | http://localhost:4004/{secret}/login |
| `docs/` | Documentation for the new system | |
| `legacy/wordpress/` | The previous WordPress build, kept for reference and data migration. See its [README](legacy/wordpress/README.md) | http://localhost/event-planner/legacy/wordpress/ |

Apache (XAMPP) serves only the legacy WordPress site. The root `.htaccess` blocks `apps/`, `docs/`, `tools/` and dotfiles.

## How the pieces talk

```
Browser ──► web (4003) ──server-side, X-Internal-Key──► api /api/public/*   (404 for anyone without the key)
Browser ──► backoffice (4004)/{secret}/api/* ──same-origin proxy──► api /api/admin/*   (cookie session + CSRF)
```

- Browsers never see the API address. The public site's server calls it with a shared key; the backoffice proxies
  `{secret}/api/*` and `{secret}/media/*` to it, so the session cookie stays on the backoffice origin.
- Every save in the backoffice is a background request (axios + TanStack Query); pages never reload.
- Uploaded images live in `apps/api/storage/app/public/media` and are served as `/media/…` by both front ends.

## Getting started

Requirements: PHP 8.3+, Composer 2, Node.js 20+, MySQL / MariaDB (XAMPP).

```bash
# API
cd apps/api
composer install
cp .env.example .env          # set DB_*, INTERNAL_API_KEY (long random value)
php artisan key:generate
php artisan migrate --seed    # creates the hidden "developer" account
php artisan storage:link
php artisan db:seed --class=DemoSeeder   # optional sample event + microsite
php artisan serve             # http://localhost:8000

# Public site
cd apps/web
npm install
cp .env.example .env.local    # same INTERNAL_API_KEY as the API
npm run build && npm start    # http://localhost:4003

# Backoffice
cd apps/backoffice
npm install
cp .env.example .env.local    # set a new secret BACKOFFICE_BASE_PATH
npm run build && npm start    # http://localhost:4004/{secret}
```

First visit to `{secret}` opens **/setup** to create the first Super Admin; it closes for good afterwards.
The `developer` account (top role, hidden from everyone else) uses `DEVELOPER_PASSWORD` or the default from the
requirements; on any server other than local it must choose a new password at first sign-in.

Tests: `cd apps/api && php artisan test` (sign-in, 2FA, roles, setup, registration rules, exports).

## Backoffice map (each tab is its own address)

| Area | Addresses |
|---|---|
| Overview | `/overview` |
| Events | `/events`, `/events/new`, `/events/{id}/details · form · capacity · emails · applicants` |
| Microsites | `/microsites`, `/microsites/{id}/builder · settings` (builder has Preview changes on mobile / tablet / desktop) |
| Applicants | `/applicants` (search, event, status, date range, bulk status, CSV/JSON export), `/applicants/{id}` |
| Reports | `/reports/overview · events · sources` |
| Users | `/users`, `/users/new`, `/users/{id}/account · password · two-factor` |
| Audit logs | `/audit-logs` (search, filters, expandable plain-language details, export selected or all as CSV/JSON) |
| Settings | `/settings/general · security · email · appearance · logs · extra` |

## Roles

| Role | Can do |
|---|---|
| Developer | Everything; hidden from all other users (list, audit log) |
| Super Admin | Everything; manages Managers and Viewers |
| Manager | Events, microsites, applicants (incl. export), reports |
| Viewer | Read-only events, microsites, applicants, reports (personal data can be masked in Settings → Extra) |

Users can only manage accounts of a lower rank. Defined in `apps/api/config/permissions.php`.

## Security measures

- Sign-in by username, email or either (Settings → Security); failed attempts are rate-limited and logged.
- 2FA with authenticator apps, up to 2 devices per user; can be required for everyone.
- Inactivity sign-out (default 2 hours): a warning 2 minutes before, then a pop-up that signs the person out;
  the server enforces the same limit. Force logout, reset password and reset 2FA from the Users list.
- Backoffice hidden under a secret path, `noindex` headers, `Referrer-Policy: no-referrer`, no framing,
  signed-out requests look like 404s.
- Public registrations: signed form token (min. fill time), honeypot, per-IP rate limits, server-side validation of
  every field, a database lock against overbooking, rich text cleaned before display, CSV exports protected
  against spreadsheet formulas.
- Every activity, success or error, is in the audit log, kept for Settings → Logs → retention days (`audit:prune`
  runs nightly via `php artisan schedule:run`).
