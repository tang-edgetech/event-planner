# Tamago plugins: shared conventions

Four standalone plugins, each usable on any WordPress site:

| Plugin | Folder / main file | Class prefix | Function / option / table prefix | Text domain |
|---|---|---|---|---|
| Tamago SMTP | `tamago-smtp/tamago-smtp.php` | `TMG_SMTP_` | `tmg_smtp_` | `tamago-smtp` |
| Tamago Reports | `tamago-reports/tamago-reports.php` | `TMG_Reports_` | `tmg_reports_` | `tamago-reports` |
| Tamago Import & Export | `tamago-io/tamago-io.php` | `TMG_IO_` | `tmg_io_` | `tamago-io` |
| Tamago Roles | `tamago-roles/tamago-roles.php` | `TMG_Roles_` | `tmg_roles_` | `tamago-roles` |

## Requirements
WordPress 6.3+, PHP 7.4+. No Composer, no npm, no build step, no third-party libraries, no CDN assets.
Vanilla JS and plain CSS only. Charts are drawn with the plugin's own SVG code. The plugins work
without ACF; when ACF (free or Pro) is active they use it.

## Folder layout (plain names, one job per file)
```
tamago-x/
  tamago-x.php        header, constants (TMG_X_VERSION, TMG_X_FILE, TMG_X_DIR, TMG_X_URL), requires, activation/deactivation
  includes/           one file per job, plain names (settings.php, logger.php, admin-screen.php …), no "class-" prefix
  assets/css, assets/js
  tests/run.php       WP-CLI eval-file self-test: prints "PASS name" / "FAIL name" and a total
  uninstall.php       removes data only when the "Delete all data when the plugin is deleted" setting is on
  README.md           developer guide: features, screens, settings keys, capabilities, hooks, WP-CLI, data stored
```
Every PHP file starts with a docblock saying what it does and a `Screens:` or `Used by:` line.

## Shared integration points (identical in all four)
1. **Permissions** — each plugin registers its capabilities as friendly permissions, and Tamago Roles shows them:
   ```php
   add_filter( 'tamago_permissions', function ( array $p ) {
       $p['smtp_settings'] = array(
           'group'       => 'Email (SMTP)',
           'label'       => 'Manage email settings',
           'description' => 'Change the mail server, send test emails.',
           'caps'        => array( 'tmg_smtp_manage' ),
       );
       return $p;
   } );
   ```
   On activation each plugin grants its own capabilities to `administrator` (and on multisite, super admins pass anyway).
   Every screen and action checks its own capability, never `manage_options`.
2. **Menu placement** — each screen's parent is filterable so a theme can group them:
   `apply_filters( 'tamago_menu_parent', $default_parent, 'smtp' | 'reports' | 'io' | 'roles' )`.
   Defaults: SMTP → `options-general.php`, Reports → its own top-level menu, Import & Export → `tools.php`, Roles → `users.php`.
3. **Settings as JSON** — each plugin's screen has an "Export settings / Import settings" box, plus
   `wp tamago-<x> settings export [<file>]` and `wp tamago-<x> settings import <file>`.
   File shape: `{ "plugin": "tamago-smtp", "version": "1.0.0", "exported": "<ISO date>", "settings": { … } }`.
   Secrets (passwords, API tokens) are never exported.
4. Settings live in one option per plugin (`tmg_<x>_settings`, an array, autoload only if small) with a
   defaults array in code. Any setting may be locked by a `TMG_<X>_<NAME>` constant in wp-config.php
   (the screen shows it read-only with a note).

## Security rules
- Nonce + capability check on every form, AJAX, REST and admin-post handler. Escape all output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- `$wpdb->prepare()` for every query with input. Custom tables via `dbDelta`, versioned with a `tmg_<x>_db_version` option.
- No remote requests unless the admin turned on a feature that needs one; use `wp_safe_remote_get`.
- Uploaded files: check extension + content, size limit, store outside the web root or in a protected uploads subfolder, delete after use.
- Never print secrets back into a form. Encrypt stored secrets with AES-256-GCM (key derived from AUTH_KEY + SECURE_AUTH_SALT), fall back to CBC+HMAC when GCM is missing.
- Uninstall removes options, tables, capabilities, cron events and user meta only when the delete-data setting is on.

## Code style
WordPress Coding Standards (tabs, Yoda conditions optional, `array()`), strict comparisons, type hints where PHP 7.4 allows.
Translatable strings with the plugin's text domain. Plain, short comments that explain why, not what.
Admin UI uses native WordPress markup (`wrap`, `nav-tab-wrapper`, `form-table`, `WP_List_Table`, `notice`) so it looks like WordPress.
