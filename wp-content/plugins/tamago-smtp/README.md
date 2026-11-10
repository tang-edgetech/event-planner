# Tamago SMTP

Sends every WordPress email through an SMTP server and keeps a detailed log of each email.
It replaces third-party SMTP plugins. Requirements: WordPress 6.3+ and PHP 7.4+. It uses no
third-party libraries and has no build step.

## Screens

**Settings → SMTP**. The parent menu can be changed with `tamago_menu_parent( 'options-general.php', 'smtp' )`.

| Tab | Capability | What it does |
|---|---|---|
| Settings | `tmg_smtp_manage` | Mail server, sender rules, email log options, failure alert, uninstall option |
| Test | `tmg_smtp_manage` | Sends a test email (HTML or text) or checks the connection (connect, STARTTLS, log in, without sending). Shows the SMTP conversation with credentials hidden, plus a plain-language hint when something fails |
| Logs | `tmg_smtp_view_logs` | Email log: search, filters (status, date range, sender), sorting, paging, bulk delete, Delete all, CSV export. The detail view shows every field, a sandboxed preview, the source, headers and SMTP conversation, and has Resend |
| Tools | `tmg_smtp_manage` | Export and import settings as JSON (passwords are never included), plus system information |

There is also a **Dashboard** widget: sent and failed emails for the last 7 days (SVG bars) and the latest failure.

## Settings (`tmg_smtp_settings`, one array option)

Every key can be locked in wp-config.php with `TMG_SMTP_<KEY>`. A locked field is shown read-only.

| Key | Default | Constant | Notes |
|---|---|---|---|
| enabled | false | TMG_SMTP_ENABLED | Defining TMG_SMTP_HOST also switches SMTP on, unless TMG_SMTP_ENABLED is false |
| provider | other | TMG_SMTP_PROVIDER | other, gmail, microsoft, ses, sendgrid, mailgun, brevo, zoho, postmark (only fills in the fields) |
| host | '' | TMG_SMTP_HOST | |
| port | 587 | TMG_SMTP_PORT | |
| encryption | tls | TMG_SMTP_ENCRYPTION (alias TMG_SMTP_SECURE) | none, ssl, tls |
| auto_tls | true | TMG_SMTP_AUTO_TLS | Only used when encryption is none |
| auth | true | TMG_SMTP_AUTH | |
| username | '' | TMG_SMTP_USERNAME (alias TMG_SMTP_USER) | |
| password | '' | TMG_SMTP_PASSWORD | Stored encrypted, never shown or exported. An empty input keeps it. It is removed when the host or username changes |
| timeout | 20 | TMG_SMTP_TIMEOUT | Seconds |
| from_email / force_from_email | '' / false | TMG_SMTP_FROM_EMAIL / TMG_SMTP_FORCE_FROM_EMAIL | Without force, only WordPress's default `wordpress@<domain>` is replaced |
| from_name / force_from_name | '' / false | TMG_SMTP_FROM_NAME / TMG_SMTP_FORCE_FROM_NAME | Without force, only the default name "WordPress" is replaced |
| return_path | false | TMG_SMTP_RETURN_PATH | Return-Path = From |
| reply_to | '' | TMG_SMTP_REPLY_TO | Used only when the email has no Reply-To |
| bcc | '' | TMG_SMTP_BCC | Comma-separated "always BCC" list |
| log_enabled | true | TMG_SMTP_LOG_ENABLED | |
| log_bodies | true | TMG_SMTP_LOG_BODIES | Privacy switch. Without bodies, resend is not possible |
| log_transcript_success | false | TMG_SMTP_LOG_TRANSCRIPT_SUCCESS | The transcript is always kept for failures |
| log_retention_days | 30 | TMG_SMTP_LOG_RETENTION_DAYS | 0 = keep forever. A daily cron (`tmg_smtp_purge_logs`) deletes older entries |
| log_max_body_kb | 512 | TMG_SMTP_LOG_MAX_BODY_KB | Longer bodies are cut (`body_truncated` = 1) |
| alert_enabled / alert_email | false / '' | TMG_SMTP_ALERT_ENABLED / TMG_SMTP_ALERT_EMAIL | Sends at most one alert per hour, never for its own failure or for test emails. An empty address means the admin email |
| delete_data | false | TMG_SMTP_DELETE_DATA | When on, uninstall removes everything |

The password is encrypted with AES-256-GCM, falling back to AES-256-CBC + HMAC-SHA256 when GCM is not available. The key is
derived from `AUTH_KEY` + `SECURE_AUTH_SALT`. If those keys change, type the password again.

## Capabilities

On activation, both capabilities are given to `administrator`. They are offered to Tamago Roles through `tamago_permissions`
(`smtp_settings`, `smtp_logs`).

- `tmg_smtp_manage`: Settings, Test and Tools tabs, test AJAX, settings save, import and export.
- `tmg_smtp_view_logs`: Logs tab, detail view, resend, delete, CSV export, dashboard widget.

## Hooks

| Hook | Type | Arguments |
|---|---|---|
| `tmg_smtp_before_send` | action | `$phpmailer`: runs after Tamago SMTP has configured PHPMailer (phpmailer_init, priority 10) |
| `tmg_smtp_log_enabled` | filter | `bool $log, array $wp_mail_args`: return false to leave this email out of the log |
| `tmg_smtp_log_row` | filter | `array $row` before insert. Return false to skip the row |
| `tmg_smtp_logged` | action | `int $log_id, array $row` |
| `tamago_permissions` | filter | Registers the two permissions (shared Tamago integration) |
| `tamago_menu_parent` | filter | `( 'options-general.php', 'smtp' )` |

The log uses `wp_mail` (last), `pre_wp_mail` (last), `phpmailer_init` (last), and `wp_mail_succeeded` / `wp_mail_failed` (first).
Open emails are kept on a stack, so an email sent from inside another email's hooks is logged correctly. Tamago SMTP sets its own
Message-ID on each email (`<random.time@from-domain>`) unless one is already set.

## WP-CLI

```
wp tamago-smtp test <email> [--text] [--transcript]
wp tamago-smtp check
wp tamago-smtp log list [--status=sent|failed] [--limit=20] [--format=table|csv|json|yaml|count]
wp tamago-smtp log purge [--days=<n>] [--all] [--yes]
wp tamago-smtp settings export [<file>]
wp tamago-smtp settings import <file>
```

## Data stored

- Option `tmg_smtp_settings` (autoloaded, small), `tmg_smtp_db_version`, `tmg_smtp_alert_last`.
- Table `{prefix}tmg_smtp_log`, one row per wp_mail() call. It stores:
  - time (UTC), status, to, cc, bcc, from, reply-to, subject, the full generated headers, and the HTML and text body
  - content type, attachment names and sizes (the files are not copied), mailer, Message-ID and error
  - the SMTP transcript with credentials removed. The message content after DATA is reduced to one summary line
  - duration in ms, source (`plugin:<slug>`, `theme:<slug>`, `mu-plugin:<slug>`, `core`, `other`), function and file:line,
    context (admin, front, ajax, rest, cron, cli), user ID, request URL (secret-looking query values are hidden),
    resend_of and resend_count
- User meta `tmg_smtp_logs_per_page` (screen option).
- Privacy: there is a personal-data exporter and eraser (Tools → Export/Erase Personal Data). They match exact addresses in
  to, cc, bcc, from and reply-to.

## Tests

`wp eval-file wp-content/plugins/tamago-smtp/tests/run.php` runs the self-test. It needs a local SMTP sink on 127.0.0.1:2525
(or `TMG_SMTP_TEST_PORT`). The sink must accept logins except user `reject` and password `wrong`, refuse senders `reject@…`
and refuse recipients `bounce@…`. Settings are restored afterwards, and test rows and users are removed.
