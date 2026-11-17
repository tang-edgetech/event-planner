# Tamago Import & Export

Import and export any WordPress content — every post type (also non-public ones and media),
all custom fields, ACF values (free and Pro) and the ACF structure itself, terms, users, options,
menus — as a JSON package, a ZIP with the media files, CSV or Excel. Replaces third-party
import/export plugins on client sites. No libraries, no build step.

Requires WordPress 6.3+, PHP 7.4+. ACF is optional; ZIP and Excel need the PHP `zip` extension.

## Screens

Own top-level menu **Import & Export** (below Tools; parent and position filterable, see Hooks). Tabs:

| Tab | Capability | What it does |
|---|---|---|
| Export | `tmg_io_export` | Choose content and filters, download. Save the choices as a preset, rerun presets in one click. Data sources added by themes appear below. |
| Import | `tmg_io_import` | Upload → (column mapping for plain spreadsheets) → preview with options → dry run or import with a progress bar → report. |
| History | `tmg_io_export` or `tmg_io_import` | Every import, dry run and export with results; continue unfinished imports, download reports (CSV / Excel). Users see their own runs, managers see all. |
| Settings | `tmg_io_manage` | Limits, media downloads, skipped meta keys, protected options, history retention, mapping templates, settings export / import. |

## Export

- **Content**: any registered post type the user can edit (attachments need `upload_files`).
  Filters: status, date range, author, terms (`category:news, post_tag:featured`), IDs, limit.
- **Per post**: all core fields, author (login + email), parent, menu order, password, GUID,
  permalink, all post meta read raw from the database and unserialized without creating PHP
  objects (values holding objects are exported raw and refused on import), multi-value meta,
  ACF values by name (`acf` → `{key, type, value}` raw), terms (slugs), featured image,
  optional comments (with comment meta).
- **Taxonomies**: all terms with hierarchy, term meta and ACF term fields.
- **Users** (needs `list_users`): core fields, roles, user meta and ACF user fields. Never
  passwords, sessions, capabilities / user level internals or keys looking like secrets.
- **Options** (needs `tmg_io_manage` + `manage_options`): exact names or prefixes (`options_`,
  `my_plugin_*`), plus ACF options pages (their `post_id`, `options` or custom). Site URLs, salts,
  active plugins, roles, cron, transients and the list under *Protected options* never travel.
- **Menus** with all items (and item meta / ACF fields) and theme locations.
- **ACF structure**: field groups, post types, taxonomies, options pages — only what the
  installed ACF offers (free vs Pro, 6.1+ internal post types). Written in ACF's own export
  format, so the file also imports with ACF → Tools. "Download ACF JSON only" gives exactly that.

### Formats

| Format | Contents |
|---|---|
| JSON | The package: full fidelity. Media are described (URL, file name, title, alt, caption, sha1) and re-downloaded on import. |
| ZIP | `package.json` + `media/<id>/<file>`; media come from the ZIP. |
| Excel (.xlsx) | One sheet per post type plus Terms, Users, Menus, Options, ACF, About. Readable columns (`post_title`, `tax:category`, `acf:field_name`, `meta:key`, `featured_image` …), repeaters / groups / flexible content as JSON text, and a `_tmg_json` column (split into `_tmg_json_2` … above Excel's cell limit) with the full item. |
| CSV | Same columns; one file per sheet (a ZIP of CSV files when there are several). |

Flat files round-trip completely. Cells **edited** in Excel / CSV are detected (per-column hash)
and applied on import through the same type-aware conversion as the mapping step.
Cells starting with `=`, `+`, `-`, `@`, tab or CR are prefixed with `'` (formula injection);
the prefix is removed again on import.

### Package shape (`format: "tamago-io-package"`)

```
{ format, version, plugin, site, exported, acf: ""|"free"|"pro",
  posts: [ { id, type, title, slug, status, date, date_gmt, content, excerpt, parent, menu_order, guid,
             author: {id, login, email}, meta: {}, meta_multi: {}, meta_raw: {}, refs: { meta_key: media|post|term|user },
             acf: { name: {key, type, value} }, terms: { taxonomy: [slugs] }, comments: [] } ],
  terms: [ { id, taxonomy, name, slug, description, parent, meta, refs, acf } ],
  users: [ { id, login, email, display_name, roles, meta, refs, acf, … } ],
  options: { name: value }, options_refs: { name: kind }, options_raw: { name: "serialized" },
  menus: [ { id, name, slug, locations, items: [ { id, type, object, object_id, url, parent, order, meta, refs, … } ] } ],
  acf_structure: [ ACF export items ],
  media: { id: { url, filename, title, alt, caption, description, mime, sha1, file? } },
  lookup: { posts: { id: {type, slug, guid} }, terms: { id: {taxonomy, slug, name, parent} }, users: { id: {login, email} } } }
```

`refs` is how IDs are found: ACF stores every value (also inside repeaters, groups, flexible
content and clones) as its own meta row with a `_name` → field key reference, so each meta key
whose field is an image / file / gallery / post object / relationship / page link / taxonomy /
user field is marked. Hand-written packages may give ACF values by name only (`acf`), they are
converted like mapped cells.

## Import

1. **Upload** JSON, ZIP, CSV / TSV or XLSX. Extension and content must agree (real type check),
   size limit = setting and server limit. Files go to `uploads/tamago-io/` (denied by
   `.htaccess` / `web.config`, random names; on Nginx rely on the random names or deny the
   folder) and are deleted when the run ends; a daily cron removes leftovers after 24 h.
   An ACF export file imports its structure.
2. **Mapping** (CSV / XLSX / plain JSON without `_tmg_json`): choose the sheet and post type,
   map each column to a post field, a taxonomy (comma-separated, missing terms created), a known
   or new custom field, or an ACF field (listed per field group). Columns are guessed by name and
   label. Mappings can be saved as templates.
   Conversion by ACF type: dates (any format, Excel serials, day-first when obvious) → `Ymd` /
   `Y-m-d H:i:s`; true/false (`yes`, `1`, `x` …); select / checkbox / radio by value or label;
   image / file / gallery by URL (downloaded, de-duplicated) or ID; post object / relationship /
   page link by ID, slug or title; taxonomy by ID, slug or name; user by ID, login or email;
   link from URL or JSON; repeater / group / flexible content / clone from JSON (sub fields by name
   or key, converted recursively).
3. **Preview**: counts per section (would be created / updated / skipped / other) for the chosen
   options. Options: sections to import, match by **slug / ID / GUID / custom or ACF field / none**,
   for matches **skip / update / create a copy**, empty fields missing from the file (or keep),
   download media from their URLs, comments, menu locations, keep original IDs when free.
   Terms match by slug, users by login then email, menus by slug, media by original URL / sha1 /
   local URL.
4. **Run**: AJAX steps (`step_seconds`, `batch_size`), resumable — reload the page or use
   History → Continue. **Dry run** goes through every step without writing. A report lists every
   item (created / updated / skipped / error / note with reasons); download as CSV or Excel.

Phases: ACF structure → terms → users → media → posts (create / match) → post data (parents,
meta with remapped IDs, terms, comments, media links in content) → term meta → user meta →
menus → options. IDs are remapped across the whole run (parents, featured images, every ACF
reference field including nested ones, menu items, term parents, content `wp-image-N` classes
and image URLs); references to items not in the file are resolved on the target site through
the `lookup` tables (slug / GUID / login), or kept when importing into the same site.

### Security

The importer acts as the current user:
- posts: `create_posts` / `edit_post` / `publish_posts` per type (publishing without the right
  becomes *pending*), changing the author needs `edit_others_posts`;
- raw HTML only with `unfiltered_html` (content through WordPress' own kses, meta / options / term
  descriptions through `wp_kses_post`);
- hidden (`_`) meta only with `tmg_io_manage` (ACF field references and `_thumbnail_id`,
  `_wp_page_template`, `_wp_attachment_image_alt` are always allowed);
- terms `manage_terms` / `edit_terms` / `assign_terms`; media `upload_files`; menus
  `edit_theme_options`; comments `moderate_comments`;
- options and ACF structure `tmg_io_manage` + `manage_options`; protected options are refused;
- users `create_users`, `edit_user`, `promote_users`. A role is given only when it is editable by
  the importer, has no higher user level and no capability the importer lacks; otherwise the
  default role (when allowed) or no role. Passwords are never imported — new users get a random
  one. Capabilities, sessions and secret-looking keys in the file are ignored.
- ZIP: never extracted; entries are read by name after a path check (zip slip) and size check.
- XLSX: XML with a DOCTYPE / entities is refused (XXE), no network access, part size limit.
- Media downloads: `http` / `https` only, `wp_safe_remote_get`, size limit, WordPress' allowed
  file types; can be switched off in Settings.
- PHP objects in serialized values are never unserialized or imported.

## Capabilities

| Capability | Permission (Tamago Roles) |
|---|---|
| `tmg_io_export` | Export content |
| `tmg_io_import` | Import content |
| `tmg_io_manage` | Settings, options, ACF structure, hidden meta, all users' history |

Registered through the `tamago_permissions` filter (group "Import & Export"), granted to
administrators on activation.

## Settings (`tmg_io_settings`)

| Key | Default | |
|---|---|---|
| `max_upload_mb` | 64 | Largest upload (also capped by the server). |
| `batch_size` | 25 | Items per AJAX step. |
| `step_seconds` | 15 | Time budget per step. |
| `remote_media` | 1 | Allow downloading media by URL. |
| `media_max_mb` | 32 | Largest media file (download or ZIP entry). |
| `history_days` | 90 | History retention. |
| `skip_meta_keys` | '' | Extra meta keys never exported / imported (one per line, `*` = prefix). |
| `protected_options` | '' | Extra option names never exported / imported. |
| `delete_data` | 0 | Delete everything on uninstall. |

Any key can be locked in `wp-config.php` with `TMG_IO_<KEY>` (e.g. `define( 'TMG_IO_REMOTE_MEDIA', 0 );`).
Settings file: `{ "plugin": "tamago-io", "version", "exported", "settings": { …, "presets": {}, "mapping_templates": {} } }`.

## Hooks

| Hook | Type | Arguments |
|---|---|---|
| `tamago_menu_parent` | filter | `$parent` (default `''` = own top-level menu), `'io'` |
| `tamago_permissions` | filter | `$permissions` |
| `tmg_io_sources` | filter | `$sources` (see below) |
| `tmg_io_export_post` | filter | `$entry`, `WP_Post $post`, `$choices` — return false to leave a post out |
| `tmg_io_package` | filter | `$package`, `$choices` — whole package before writing |
| `tmg_io_meta_refs` | filter | `$refs`, `$meta`, `$object_type` (post / term / user / option), `$object` — mark non-ACF meta that holds IDs (`media`, `post`, `term`, `user`) |
| `tmg_io_skip_meta_keys` | filter | `$keys`, `$object_type` — meta never exported / imported (`*` = prefix) |
| `tmg_io_protected_options` | filter | `$names` |
| `tmg_io_allowed_hidden_meta` | filter | `$keys`, `$object_type` — hidden meta any importer may write |
| `tmg_io_import_post` | filter | `$args` (for `wp_insert_post`), `$entry` (package item, or `['row' => …, 'type' => …]` for mapped rows), `$opts` — return false to skip |
| `tmg_io_imported_post` | action | `$post_id`, `$entry`, `$action` (created / updated), `$opts` — after meta and terms are written |
| `tmg_io_import_finished` | action | `$run_id`, `$counts`, `$opts` |

## Data sources (`tmg_io_sources`)

Site-specific data with its own columns (for example a theme's form submissions) plugs into the
Export and Import tabs:

```php
add_filter( 'tmg_io_sources', function ( array $sources ) {
	$sources['epc_applicants'] = array(
		'label'      => 'Applicants of an event',
		'capability' => 'epc_export_applicants',         // checked for export and import
		'formats'    => array( 'xlsx', 'csv', 'json' ),   // export buttons, first = primary
		// Options form; inputs must be named choices[...]. $context: 'export' | 'import'.
		'fields'     => function ( string $context ): string {
			$html = '<p><label>Event <select name="choices[event]">';
			foreach ( get_posts( array( 'post_type' => 'epc_event', 'numberposts' => -1 ) ) as $e ) {
				$html .= '<option value="' . (int) $e->ID . '">' . esc_html( $e->post_title ) . '</option>';
			}
			return $html . '</select></label></p>';
		},
		// Sheets: name => rows, row 0 = headers (associative rows are accepted too).
		'export'     => function ( array $choices ): array {
			$event = absint( $choices['event'] ?? 0 );
			return array( 'Applicants' => EPC_Import_Export::to_table( EPC_Import_Export::applicant_rows( $event, '' ) ) );
		},
		// Called once per chunk of up to 200 rows. $rows: header => cell (formula escapes removed).
		// $mapping: header => one of your column keys (unmapped headers are absent).
		// $opts: choices, dry_run, existing (skip|update|copy), offset, user_id.
		'import'     => function ( array $rows, array $mapping, array $opts ): array {
			$report = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() );
			foreach ( $rows as $i => $row ) {
				$data = array();
				foreach ( $mapping as $header => $key ) {
					$data[ $key ] = sanitize_text_field( $row[ $header ] ?? '' );
				}
				if ( empty( $data['email'] ) ) {
					$report['errors'][ $i ] = 'Missing email.';   // key = row index in this chunk
					continue;
				}
				if ( ! $opts['dry_run'] ) {
					// … store the applicant for (int) $opts['choices']['event'] …
				}
				++$report['created'];
			}
			return $report; // optional 'items' => [ [row, title, action, id, message], … ]
		},
		// Target columns for the mapping step: key => label (keys: letters, digits, _ - .).
		'columns'    => function ( array $choices ): array {
			$cols = array( 'status' => 'Status', 'date' => 'Registration date' );
			foreach ( epc_get_data_fields( absint( $choices['event'] ?? 0 ) ) as $f ) {
				$cols[ $f['name'] ] = wp_strip_all_tags( $f['label'] );
			}
			return $cols;
		},
	);
	return $sources;
} );
```

- `export` is required; `import` and `columns` are optional (`null` = export only).
- The export is logged in History; the download goes through `tmg_io_send_download()`.
- On import the user picks the source under *Import into*, fills its `fields` (context
  `import`), maps the file's columns to `columns`, previews and runs; chunks run as AJAX steps and
  the counts / errors appear in the run report. Saved mapping templates work for sources too.
- All sources' `fields` are on the same upload form: prefix input names inside `choices[...]`
  if two sources could clash.

## Public helpers

```php
$path   = tmg_io_xlsx_write( array( 'Sheet' => $rows ) );   // temp .xlsx in the protected folder; delete after use
$sheets = tmg_io_read_file( $path );                         // JSON / CSV / XLSX / ZIP → sheet name => rows
tmg_io_send_download( $sheets, 'applicants-2026', 'xlsx' );   // xlsx | csv | json, then exit
```

## WP-CLI

```
wp tamago-io export [--types=post,page] [--format=json|zip|csv|xlsx] [--file=<path>]
    [--status=] [--date-from=] [--date-to=] [--author=] [--terms=category:news] [--ids=] [--limit=]
    [--comments] [--taxonomies=category,genre] [--users] [--roles=editor]
    [--options=blogname,options_] [--options-pages=<slug>] [--menus=all|<id|slug>]
    [--acf-structure[=field_groups,post_types,taxonomies,options_pages]] [--preset=<name>]
wp tamago-io import <file> [--match=slug|id|guid|none|meta:<key>] [--existing=skip|update|copy]
    [--dry-run] [--empty-missing] [--no-media] [--no-comments] [--keep-ids]
    [--sections=acf_structure,terms,users,media,posts,menus,options]
    [--post-type=<type>] [--mapping=<template>] [--sheet=<name>] [--report=<file.csv>]
wp tamago-io settings export [<file>]
wp tamago-io settings import <file>
wp tamago-io runs [--limit=20]
```

Commands act as `--user` (or the first administrator) with the same capability checks. The
import works on a copy of the file. Data-source imports are screen-only.

## Data stored

- Options: `tmg_io_settings`, `tmg_io_presets`, `tmg_io_templates` (not autoloaded), `tmg_io_db_version`.
- Table `{prefix}tmg_io_runs`: kind, status, user, dry run, file name, format, counts, options, report.
- Folder `uploads/tamago-io/`: uploads and run state while a run is open.
- Attachment meta `_tmg_io_src` (original URL) and `_tmg_io_sha1` (file hash) for de-duplication.
- Transients `tmg_io_notice_<user>`, `tmg_io_lock_<run>`; cron `tmg_io_cleanup` (daily).

`uninstall.php` removes all of it when *Delete all data when the plugin is deleted* is on.

## Tests

```
wp eval-file wp-content/plugins/tamago-io/tests/run.php --user=<admin>
```

Needs ACF Pro. Creates fixtures (ACF post type, taxonomy, options page, a field group with every
common field type incl. repeater / group / flexible content / clone, terms with meta, a user,
media, a menu, comments, options), then checks: export → wipe → ZIP import with remapped IDs,
skip / update / copy / match by ID or ACF field / empty missing, dry runs, batched steps, CSV and
XLSX round trips and edited cells, column mapping with type conversion and URL media download,
ACF's own import tool accepting the structure file, capability refusals, zip slip, formula
injection, XXE, upload checks, protected folder, data sources, settings and presets. Prints
`PASS` / `FAIL` lines and a total; leaves the fixtures on the site.

## Limitations

- Exports are built in one request (with a raised time limit); very large media libraries are
  better exported as JSON (media re-downloaded) than ZIP.
- Values stored by ACF's optional datastore (ACF 6.8 `acf/settings/enable_datastore`) are not
  read; the default meta storage is.
- Meta outside ACF that holds IDs is copied as is unless marked through `tmg_io_meta_refs`.
- Comments are de-duplicated by post + author email + date.
