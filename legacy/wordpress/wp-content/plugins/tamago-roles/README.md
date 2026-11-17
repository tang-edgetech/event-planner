# Tamago Roles

Roles, ranks, permissions and access control for WordPress. Replaces role editors, capability managers,
admin-menu editors and content-restriction plugins. WordPress 6.3+, PHP 7.4+, no build step, works with or without ACF.

## Screens
Own top-level menu **Roles & Permissions** (below Users). Return a parent slug from `tamago_menu_parent` (context `'roles'`) to nest it; move it with `tamago_menu_position`.

| Tab | What it does | Capability |
|---|---|---|
| Roles | Roles with rank, user count, capability count. Add (optionally copying a role), edit name + rank, clone, delete (users move to another role first), make default for new users, reset to defaults, save current as defaults, advanced capability editor (every known capability, search, "only granted", add custom capabilities). | `tmg_roles_manage` |
| Permissions | Matrix: permission rows (grouped) × role columns, group "select all", search, "show raw capabilities". Protected capabilities box with the "Technical WordPress areas" preset. | `tmg_roles_manage` |
| Users | Users you may manage, their roles, rank and overrides; per-user grant/deny overrides with the effective result. | `tmg_roles_manage_users` |
| Admin menus | Per role: admin menu checklist (captured from the live menu), admin bar items, front-end toolbar, dashboard widgets, login / logout redirect, no wp-admin access. | `tmg_roles_manage` |
| Content access | Default rule per public post type, default message, overview of terms and items with rules. | `tmg_roles_manage` |
| Settings | Rank rules, staff public name, admin-rule scope, log size, delete-data switch, export / import (with diff + confirm), safe-mode instructions. | `tmg_roles_manage` (import: top rank only) |
| Log | Who changed what and when, search, CSV export. | `tmg_roles_manage` |

Also: **"Who can view"** box on every public post type, rule fields on public taxonomy term edit screens (users with
`tmg_roles_manage`), **"Visible to"** on each item in Appearance → Menus, and on **Users → Edit user**: "Additional roles"
and "Permission overrides".

## Rules
- **Rank**: every role has a number; a user's rank is the highest of their roles. Administrator (and multisite super admin)
  is always the top rank (at least as high as any role). A user may only create / edit / delete / promote / remove users
  ranked strictly below them (equal rank: top rank always, others only with "peer management"), only assign roles below
  their own (rank-0 roles: top rank only unless "assign unranked" is on), only change roles ranked below them, and only
  hand out permissions/capabilities they hold themselves (top rank excepted).
- **Hidden users**: users ranked above the viewer (optionally only those at or above a given rank) are left out of
  wp-admin user lists, role filter links and REST user queries. Counts on "All" are removed.
- **Matrix**: a permission is "on" for a role when the role has all its capabilities. Switching off removes its
  capabilities unless another permission the role keeps still needs them; `read` is never removed by the matrix.
  The Administrator role can gain capabilities but is never reduced.
- **Overrides** are applied at run time through `user_has_cap` (nothing is written into the user's caps). Deny never removes `read`.
- **Protected capabilities**: only roles at or above `protected_min_rank` may hold them; they are removed at run time from
  everyone below, whatever grants them (roles, user caps, overrides, other plugins).
- **Admin experience rules** apply to a user only when *every* one of their roles has them (roles add access). Administrators
  are exempt unless "restrict_admins" is on. Hidden menu items are removed and their screens blocked when opened directly
  (redirect to the role's login page, the dashboard, the first allowed menu item or the profile, with a notice). Hiding a
  post type's menu also blocks `post.php` / `post-new.php` for it. The Roles & Permissions screen can never be hidden from administrators.
- **Content access**: the item's own rule wins, else rules of its terms (all must pass), else the post type default.
  Editors of the item and administrators always see it. Denied visitors get: login redirect (logged-in users get the
  message instead), a message (optional excerpt), a 404, or a redirect. Restricted items are removed from archives,
  search, feeds, REST lists (single REST items return 404), sitemaps and comments.
- **Staff privacy**: users with "staff roles" appear under the public name (default site name) as authors / commenters,
  in oEmbed data and REST; they are excluded from REST user lists and user sitemaps; single REST lookups 404 (or show the
  public name); author archives 404. Logged-in users at or above `public_name_reveal_rank` (0 = top rank) see real names.
- **Safe mode**: `define( 'TMG_ROLES_SAFE_MODE', true );` in wp-config.php switches every restriction off (filter `tmg_roles_safe_mode` does the same in code).

## Settings (`tmg_roles_settings`, one autoloaded array)
`ranks` (role => int), `hide_higher_users`, `hide_higher_min_rank`, `peer_management`, `assign_unranked`, `protected_caps`,
`protected_min_rank`, `custom_caps`, `restrict_admins`, `menu_rules` (role => ids: `slug` or `parent|slug`),
`adminbar_rules` (role => node IDs), `toolbar_front_hidden` (roles), `widget_rules` (role => widget IDs),
`login_redirect` / `logout_redirect` (role => URL, "/path" or wp-admin relative "admin.php?page=x"), `no_admin` (roles),
`content_defaults` (post type => rule), `content_message`, `public_name_roles`, `public_name`, `public_name_reveal_rank`,
`public_name_rest_hide`, `log_limit`, `delete_data`.
Scalar settings can be locked with `TMG_ROLES_<KEY>` constants (e.g. `TMG_ROLES_PEER_MANAGEMENT`); the screen shows them read-only.

## Capabilities
`tmg_roles_manage` (whole screen), `tmg_roles_manage_users` (Users tab, overrides). Registered through `tamago_permissions`
and granted to `administrator` on activation; administrators always keep both.

## Hooks
| Hook | Type | Purpose |
|---|---|---|
| `tamago_permissions` | filter | Register friendly permissions: `key => [group, label, description, caps[]]`. |
| `tmg_roles_protected_caps` | filter | Change the protected capability list. |
| `tmg_roles_menu_hidden` | filter | `( $hidden_ids, $user )` menu items hidden and blocked for a user. |
| `tmg_roles_can_view_post` | filter | `( $can, $post_id, $user, $rule )` final front-end access decision. |
| `tmg_roles_changed` | action | `( $action, $object, $details )` after anything is changed (also logged). |
| `tmg_roles_safe_mode` | filter | Force safe mode on/off. |
| `tamago_menu_parent` | filter | Parent menu of the screen (`'roles'`). |

## Helpers
`tmg_roles_rank( $user = null ): int`, `tmg_roles_can_manage_user( int $user_id ): bool`,
`tmg_roles_public_name( int $user_id = 0 ): string` (no ID = the public name), `tmg_roles_user_can_view( int $post_id, $user = null ): bool`.

## WP-CLI
```
wp tamago-roles list [--format=table|csv|json]
wp tamago-roles permissions
wp tamago-roles grant <role> <permission> [--cap]
wp tamago-roles deny  <role> <permission> [--cap]
wp tamago-roles settings export [<file>]
wp tamago-roles settings import <file> [--dry-run] [--yes]
wp tamago-roles safe-mode
```

## Export / import
`{ "plugin": "tamago-roles", "version", "exported", "settings": { roles: {key: {name, rank, caps}}, default_role, matrix, options, term_rules } }`.
Not exported: per-user overrides, per-item rules, menu-item rules, `delete_data`. Import is previewed as a diff, needs
confirmation, never deletes roles that are not in the file, never reduces the Administrator role, and never removes the
importer's own access (manage capability, rank, menu entry, wp-admin access, protection).

## Data stored
Options: `tmg_roles_settings`, `tmg_roles_role_defaults` (role defaults snapshot), `tmg_roles_menu_snapshot`,
`tmg_roles_widget_snapshot`, `tmg_roles_db_version`; WordPress's own `wp_user_roles` and `default_role`.
Table: `{prefix}tmg_roles_log`. User meta `tmg_roles_overrides`. Post meta `_tmg_roles_access` (items) and
`_tmg_roles_visibility` (menu items). Term meta `_tmg_roles_access`. Transient `tmg_roles_import_<user>` (15 min).
Uninstall removes all of it (and the plugin's capabilities) only when "Delete all data" is on; created roles stay.

## Tests (lab sites only)
```
wp eval-file wp-content/plugins/tamago-roles/tests/setup-event-plus.php   # needs the lab mu-plugin
wp eval-file wp-content/plugins/tamago-roles/tests/run.php
wp tamago-roles settings import wp-content/plugins/tamago-roles/tests/fixtures/event-plus-like.json --dry-run
```
`tests/fixtures/event-plus-like.json` is the Event Plus-like configuration exported from the lab.

## Limitations
- Menu-item visibility applies to classic menus (`wp_nav_menu`); block-theme Navigation blocks are not filtered.
- Menu checklist shows what an administrator sees; items visible only to other roles can be typed in ("Other hidden menu items").
- Blocking redirects instead of a 403 page; screens the user has no capability for are refused by WordPress itself.
