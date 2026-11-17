# Tamago Reports

First-party, privacy-friendly visitor analytics for WordPress, with targeting, events, goals and reports.
It needs no third-party scripts and stores no raw IP addresses. It works with page caches because counting is done by JavaScript only.

Requires WordPress 6.3+ and PHP 7.4+. It has no dependencies and no build step.

## Features

- **Collection.** A small deferred script (`assets/js/track.js`, about 5.4 KB minified-equivalent) sends a text/plain JSON beacon to `POST /wp-json/tamago-reports/v1/collect`. It uses `sendBeacon` and falls back to `fetch` with `keepalive`. It handles SPAs (`history.pushState` and `popstate` start a new page view). A page view gets an ID and a signed token, and engagement pings (visible seconds, deepest scroll) update that row.
- **Targeting.** You choose the content types to track: each public post type, the front page, the blog index, post type archives, taxonomy archives, author archives, date archives, search results and 404. You can always or never track specific posts (searchable picker), include or exclude URL path patterns (`*` wildcard), and strip query strings. You can exclude roles, IP / CIDR ranges (IPv4 and IPv6) and bots. Do Not Track and Global Privacy Control are switches. Rules are checked when the page renders and checked again on every hit, so a cached page still follows the current rules.
- **Consent.** There are two modes:
  - *Track without consent* (cookieless): nothing is stored in the browser. The visitor ID is an HMAC of IP, user agent and host with a random salt that rotates daily. Sessions are matched on the server with a 30-minute idle window.
  - *Wait for consent*: nothing is sent until `window.tamagoReports.consent(true)` is called or a configured cookie (optionally containing a value) exists. After that the visitor ID is kept in `localStorage` (`tmg_vid`), with a 30-minute idle session (`tmg_sid`). `consent(false)` clears both.
- **Events.** Automatic events cover outbound links, downloads (extension list), `mailto:` and `tel:` links, elements with `data-tmg-event="name"` (optional `data-tmg-label`, `data-tmg-value`), form submissions (opt-in, CSS selector), scroll depth and engaged time.
- **Goals.** A goal is a named conversion with an optional value. It is triggered by a page (post ID or path pattern), a JS event name (wildcards allowed; built-in names `outbound`, `download`, `mailto`, `tel`, `form`), or a server-side event name.
- **Location.** Sources are none, CDN / proxy headers (default; Cloudflare, CloudFront and generic GeoIP headers), ipapi.co, or ipinfo.io (needs a token). Lookups are cached for 7 days and time out after 2 s. Remote lookups happen only when the admin selects a remote provider.
- **Reports** (own top-level "Reports" menu, parent filterable):
  - Date ranges: today, yesterday, 7/30/90 days, this or last month, this year, custom. You can compare with the previous period.
  - Filters can be combined and show as removable chips: content type, content, path, channel, referrer, UTM source/medium/campaign/term/content, device, browser, OS, country, region, city, language, and completed goal.
  - KPI tiles with deltas: visitors, new visitors, sessions, page views, views per session, average engaged time, bounce rate, events, conversions, conversion rate.
  - An SVG time-series chart (views and visitors, hover tooltips, day / week / month).
  - Top-10 tables, each with "Show all" (paginated) and CSV: pages (views, visitors, avg time, scroll, conversions), entry pages, exit pages, content types, channels, referrers, UTM source / medium / campaign / term / content, events and clicks (name + label), goals, devices, browsers and versions, operating systems and versions, screen sizes, countries, regions, cities, languages, time zones, and new vs returning. There is also an hour × weekday heatmap.
  - A real-time panel (active visitors in the last 5 and 30 minutes and their current pages; refreshes every 15 s).
  - Single-content report: click any page, or open it from the post list or meta box.
  - "Views (30 days)" sortable column on list tables of tracked post types.
  - Report box on the post edit screen, and a Dashboard widget (7-day visitors and views with sparkline).
  - CSV export of raw hits (filtered range; no IP hash) and of every table.
- **Settings JSON.** Export and import settings in the Settings tab or with WP-CLI. Secrets are never exported.

## Screens

| Screen | Capability |
|---|---|
| Reports → Overview / Real-time | `tmg_reports_view` |
| CSV export buttons / links | `tmg_reports_export` |
| Reports → Settings (and settings export / import) | `tmg_reports_manage` |
| Post list column, post edit box, Dashboard widget | `tmg_reports_view` |

The menu parent is `apply_filters( 'tamago_menu_parent', '', 'reports' )`. An empty value means its own top-level menu; return a parent slug to nest it.

## Capabilities

`tmg_reports_view`, `tmg_reports_export` and `tmg_reports_manage` are granted to `administrator` on activation. They are registered for Tamago Roles through `tamago_permissions` with the keys `reports_view`, `reports_export` and `reports_manage`. No screen checks `manage_options`.

## Settings (`tmg_reports_settings` option)

Any key can be locked from `wp-config.php` with `TMG_REPORTS_<KEY>`, for example `define( 'TMG_REPORTS_RETENTION_DAYS', 365 );`. A locked setting shows read-only on the screen.

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `true` | Collect data |
| `untracked_types` | `['attachment','404']` | Post types / kinds (`front`, `blog`, `archive`, `tax`, `author`, `date`, `search`, `404`) **not** tracked. New post types are tracked by default. |
| `include_ids` / `exclude_ids` | `[]` | Post IDs always / never tracked |
| `include_paths` / `exclude_paths` | `[]` | Path patterns relative to the site address, `*` wildcard. Exclude rules beat include rules, which beat content types. |
| `query_strip` | `all` | `all` stores the path only. `listed` keeps the query string minus `utm_*` and `query_strip_params`. |
| `query_strip_params` | `fbclid, gclid, …` | Parameters removed in `listed` mode |
| `exclude_roles` | roles with `edit_posts` | Logged-in roles that get no script |
| `exclude_ips` | `[]` | IPs / CIDR ranges |
| `ip_header` | `''` | Trusted client-IP header behind a proxy (e.g. `HTTP_CF_CONNECTING_IP`). Empty means `REMOTE_ADDR`. |
| `exclude_bots`, `respect_dnt`, `respect_gpc` | `true`, `false`, `true` | Privacy and bot switches |
| `consent_mode` | `none` | `none` (cookieless) or `wait` |
| `consent_cookie`, `consent_cookie_value` | `''` | Cookie that signals consent (value = "contains") |
| `ev_outbound`, `ev_downloads`, `ev_mailto`, `ev_tel`, `ev_attr`, `ev_forms`, `ev_scroll`, `ev_time` | on, except forms | Event switches |
| `download_ext` | `pdf, zip, …` | Download extensions |
| `form_selector` | `form` | Forms tracked when `ev_forms` is on |
| `goals` | `[]` | List of `{id, name, type: page\|event\|server, match, value}` |
| `geo_provider` | `headers` | `none`, `headers`, `ipapi`, `ipinfo` |
| `geo_token` | `''` | ipinfo.io token: secret, AES-256-GCM encrypted, never exported or printed |
| `rate_limit` | `300` | Hits per IP hash per 10 minutes (0 = off) |
| `retention_days` | `730` | Raw hits older than this are deleted daily (0 = keep) |
| `delete_data` | `false` | Uninstall removes everything |

Other options: `tmg_reports_db_version`, `tmg_reports_summary_until` (last summarised day), and `tmg_reports_daily_salt` (today's cookieless salt).

## Hooks

| Hook | Type | Arguments |
|---|---|---|
| `tmg_reports_should_track` | filter | `bool $track, array $context` (kind, post_id, post_type, path, source = page / collect / server, user, ip, type / event) |
| `tmg_reports_hit_row` | filter | `array $row, array $context`. Return `false` to drop the hit. |
| `tmg_reports_hit` | action | `int $id, array $row, array $context` (after insert) |
| `tmg_reports_goal_completed` | action | `array $goal, array $row, int $id` |
| `tmg_reports_channels` | filter | `array $rules`: ordered `{channel, medium?, source?, referrer?}` regex rules; the first match wins |
| `tmg_reports_goals` | filter | `array $goals`: add goals in code (same shape as the setting) |
| `tmg_reports_script_config` | filter | `array $config, array $context`: front-end config |
| `tamago_permissions` | filter | Shared Tamago permission registry |
| `tamago_menu_parent` | filter | `string $parent, 'reports'` |

## PHP API

```php
// Server-side event (e.g. a registration). Goals of type "server" with this name make it a conversion.
tmg_reports_track( 'registration', array(
	'post_id'    => $event_id,
	'label'      => 'Registration #' . $applicant_id,
	'value'      => 50,
	'visitor_id' => $vid,        // optional: from a hidden <input name="tmg_vid"> (filled by the script)
	'session_id' => '',          // optional
	'goal'       => '',          // optional: force a goal key
	'source'     => array( 'referrer' => '', 'ref_domain' => '', 'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '', 'utm_term' => '', 'utm_content' => '' ),
) ); // returns the hit ID or 0
```

If you leave out `visitor_id`, the plugin uses a posted `tmg_vid` field or, in cookieless mode, the visitor's daily hash from the current request. If you leave out `source`, attribution and device / location are taken from the visitor's latest session.

```php
tmg_reports_query( array(
	'range'     => '30d',          // today|yesterday|7d|30d|90d|this_month|last_month|this_year|custom (+ from/to Y-m-d)
	'dimension' => 'page',         // '' = totals; page, post_type, ref_domain, channel, utm_*, device, browser, browser_version,
	                               // os, os_version, screen, country, region, city, language, timezone, new_returning,
	                               // hour_weekday, event, goal, day
	'metrics'   => array( 'views', 'visitors', 'conversions' ),
	'filters'   => array( 'post_type' => 'event', 'channel' => 'social' ),
	'order'     => 'views',
	'limit'     => 10,             // 0 = all
) );
// Totals: array( 'views' => 120, 'visitors' => 80, … ). With a dimension: list of array( 'key', 'label', metrics… ).
```

The metrics are `views`, `visitors`, `sessions`, `new_visitors`, `views_per_session`, `avg_time` (seconds), `avg_scroll` (%), `bounce_rate` (% of single-page sessions), `entries`, `exits`, `events`, `conversions`, `conversion_rate` (conversions / visitors) and `value`.

## JavaScript API

```js
window.tamagoReports.track('signup', 'footer form', 10); // custom event (name, label, value)
window.tamagoReports.consent(true);                      // wait-for-consent mode
window.tamagoReports.visitorId();                        // '' in cookieless mode
// Calls made before the script loads:
window.tamagoReports = window.tamagoReports || { q: [] }; window.tamagoReports.q.push(['consent', true]);
```

## WP-CLI

```
wp tamago-reports summary [--days=<n>]          # rebuild the last n finished days, then any pending days
wp tamago-reports purge [--days=<n>] [--all --yes]
wp tamago-reports seed <count> [--days=90] [--force]   # dev only: random hits (ip_hash "seed…")
wp tamago-reports seed --remove [--force]              # delete seeded rows and rebuild summaries
wp tamago-reports settings export [<file>]
wp tamago-reports settings import <file>
wp tamago-reports copy-legacy [--table=wp_epc_visits] [--goal=registration]
```

## Data stored

**`{prefix}tmg_reports_hits`**: one row per page view or event. It contains every column of Event Plus `epc_visits` with the same names and types: `id, created_at (UTC), post_id, post_type, event_type, label, visitor_id, session_id, is_new, path, referrer, ref_domain, channel, utm_source, utm_medium, utm_campaign, utm_term, utm_content, device, browser, browser_version, os, os_version, screen, viewport, language, timezone, country, country_name, region, city, ip_hash, duration, scroll`. It adds `event_name varchar(64)`, `goal varchar(64)` and `value decimal(15,2)`. Indexes: `(post_id, created_at)`, `created_at`, `visitor_id`, `session_id`, `(event_type, created_at, visitor_id)`, `(ip_hash, created_at)` and `(goal, created_at)`.

`event_type` is one of `pageview`, `outbound`, `download`, `mailto`, `tel`, `click`, `form`, `custom` or `server` (plus legacy `submit`). For non-singular pages, `post_type` holds the kind (`front`, `blog`, `tax`, `search`, …). `ip_hash` is `wp_hash( IP . Y-m )`, the same as Event Plus. No raw IP is stored. Every string field is length-limited before insert.

**`{prefix}tmg_reports_daily`**: one row per local day × dimension × value (`dim = '_total'` for day totals). It holds additive metrics: `views, visitors, sessions, new_visitors, entries, exits, bounces, dur_sum, dur_n, scroll_sum, scroll_n, events, conversions, value`.

## How reports stay fast

- An hourly cron summarises every finished day up to *today − 2* into the daily table. Days are not summarised sooner because engagement pings can still update a page view for 24 h.
- Unfiltered reports read summaries for those days and raw hits for the rest (normally today and yesterday). The Visitors tile is always an exact distinct count. In tables and week / month charts built from summaries, visitors are the sum of daily unique visitors.
- Filtered reports read raw hits. Each request computes only the metrics a table shows and builds the session list once, as a temporary table. When a filtered range covers 3 or more raw days, the table sections load in parallel over AJAX after the KPIs and chart.
- Measured on the lab site (MariaDB 10.11, XAMPP on Windows, 200,000 seeded hits over 90 days):

  | Report | Load time |
  |---|---|
  | Unfiltered 7, 30 or 90 days, or this year | about 1.2–1.5 s full page (all raw instead: 4 s / 21 s / 53 s) |
  | 90 days filtered by device | about 2 s page; sections 0.6–3.3 s each, loaded 3 at a time (before the optimisation: 32 s) |
  | Summarising one day (25 dimensions) | about 0.6 s |

- A daily cron deletes raw hits older than `retention_days`. Summaries hold no personal data and are kept.

## Event Plus migration

1. `wp tamago-reports copy-legacy --goal=registration` copies `wp_epc_visits` into `wp_tmg_reports_hits` with one `INSERT … SELECT` (same column names). It turns `event_type = 'submit'` rows into server events named `registration` that count as conversions of the `registration` goal, then rebuilds summaries. If you copy by hand, run:
   `INSERT INTO wp_tmg_reports_hits (created_at, post_id, …, scroll) SELECT created_at, post_id, …, scroll FROM wp_epc_visits;`
2. Add a goal in Settings: **Registration**, type *Server-side event*, match `registration`.
3. In the theme, replace the conversion insert with `tmg_reports_track( 'registration', array( 'post_id' => $event_id, 'label' => 'Registration #' . $id, 'visitor_id' => $visitor, 'source' => $src ) )`. `$src` keeps its `ref_domain` / `utm_*` keys.
4. Use `tmg_reports_query( array( 'dimension' => 'page', 'filters' => array( 'post_type' => 'event' ), 'metrics' => array( 'views', 'visitors', 'conversions', 'conversion_rate' ) ) )` for conversions per event.

## Tests

```
wp eval-file wp-content/plugins/tamago-reports/tests/run.php     # PHP self-test (runs in a transaction, rolled back)
node wp-content/plugins/tamago-reports/tests/track-js.test.js wp-content/plugins/tamago-reports/assets/js/track.js
```
