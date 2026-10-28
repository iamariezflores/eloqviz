# EloqViz (`eloqviz/laravel-eloquent-viz`)

[![Packagist Version](https://img.shields.io/packagist/v/eloqviz/laravel-eloquent-viz)](https://packagist.org/packages/eloqviz/laravel-eloquent-viz)
[![PHP](https://img.shields.io/packagist/php-v/eloqviz/laravel-eloquent-viz)](https://packagist.org/packages/eloqviz/laravel-eloquent-viz)
[![License](https://img.shields.io/packagist/l/eloqviz/laravel-eloquent-viz)](https://packagist.org/packages/eloqviz/laravel-eloquent-viz)

**EloqViz** is a small Laravel package for developers who need to **see Eloquent relationships at a glance**—especially in large codebases with many models and edges. There is no dashboard or extra ceremony: scan `Model` classes, render a directed graph (or JSON), and focus the view when the full picture is too dense.

This repository **is** the package source (PHP, Blade, bundled Cytoscape UI). Consume it via Composer inside any Laravel application; local work here uses [Orchestra Testbench](https://github.com/orchestra/testbench) for tests instead of maintaining a demo Laravel app.

Available on Packagist: **[eloqviz/laravel-eloquent-viz](https://packagist.org/packages/eloqviz/laravel-eloquent-viz)**

## Expectations (what this is and isn’t)

**EloqViz is an inspection and orientation tool**—it reflects what Eloquent **declares in model code** (relation methods you can probe), not a substitute for migrations, DB dumps, or governance suites.

| **Use it when** | **Don’t expect it to** |
|-----------------|-------------------------|
| Onboarding or auditing a **large or unfamiliar** codebase (many models, modules, packages). | Replace **formal schema docs** or stay in sync if relations are built only in services/repos with no model methods. |
| **Refactoring** or module splits: “what touches what” before you move tables or rename pivots. | Prove **runtime** behaviour for every edge case (dynamic relations, heavy `newQuery()` tricks, cross-DB). |
| Keeping a **code-accurate sketch** of relationships when written as standard Eloquent relations. | Act as an **admin CRUD** framework, ORM designer, or Laravel Nova–style resource UI. |

**Smaller apps** with a handful of models often don’t need a graph; value **scales with size and unfamiliarity**. Teams sometimes pipe the **JSON** into custom checks or docs—that’s optional and up to you.

## What you get

- **Routes (no route file)** — The service provider **registers routes in code**, so installing the package is enough (`GET /{prefix}` for the UI, `GET /{prefix}/graph` for JSON). You do **not** need to paste routes into `routes/web.php`; adjust **`route_prefix`** and **`middleware`** in config when publishing.
- **Web UI** — Interactive graph (Cytoscape.js): stable **FQCN node ids**, configurable **labels**, search across label / FQCN / file path, **scan notes** (warnings + skipped relation probes). Selecting a model lists its **table columns** (name and type) and highlights the **foreign keys** that back a relationship. Polymorphic relations are drawn with a **dotted line**. Dragged node positions are stored in the browser for that focused model and stay put on reload; **Re-layout** replaces them.
- **JSON (`graphVersion` 2)** — Full graph payload with diagnostics (see below).
- **Artisan** — `php artisan eloquent:graph` prints the same JSON. Use **`--path=`** repeatedly (or once) to override config directories.

Relationships are discovered by inspecting public relation methods on each non-abstract Eloquent model. Each candidate method runs once inside `Model::withoutEvents()` and `Connection::pretend()` on that model’s connection so typical relations never hit the database.

Column lists are a separate read. When `include_columns` is true, each node calls Laravel’s schema builder (`Schema::getColumns()` and `Schema::getForeignKeys()`) on that model’s connection. A column is highlighted when it is the foreign key backing a scanned relationship, or when the database reports a foreign-key constraint. A `*_id` column that is neither stays a normal column. If the table is missing, the model still appears and the panel says the columns could not be listed.

## Requirements

- PHP **8.2+**
- Laravel **12.60+** (recommended) or **11.44+** (no longer receives security patches; Composer may block installs)

## Install in a Laravel application

```bash
composer require eloqviz/laravel-eloquent-viz
php artisan vendor:publish --tag=eloquent-viz
```

That single tag publishes **`config/eloquent-viz.php`** and the **bundled JS** under `public/vendor/eloquent-viz/`. Granular tags **`eloquent-viz-assets`** / **`eloquent-viz-config`** still exist.

Then visit **`/{your-prefix}`** (default **`/eloquent-viz`**). **`/{prefix}/graph`** returns JSON only.

When **`APP_ENV=production`**, EloqViz **does not register** HTTP routes or the `eloquent:graph` Artisan command—the graph is unreachable and the inspector cannot be run via Artisan. Outside production you can still add auth or custom middleware via **`middleware`** as needed.

## Configuration (`config/eloquent-viz.php`)

| Key | Purpose |
|-----|---------|
| `route_prefix` | URL prefix for UI + JSON. |
| `middleware` | Route middleware stack (default `web`). |
| `models_paths` | List of directories to scan recursively for `*.php` models. |
| `models_directory` | **Legacy only** — if set, overrides `models_paths` until removed. |
| `node_display` | `short` (default), `fqcn`, or `namespace_suffix` (`Post · App\Models`). With `short`, **duplicate basenames** in the same graph automatically get a disambiguating suffix. |
| `include_file_paths` | When true, each node includes a `file` path (relative to the app root when under `base_path()`, otherwise absolute). |
| `include_columns` | When true (default), each node includes `table`, `schemaStatus` (`ok`, `missing_table`, or `unavailable`), and `columns` from `Schema::getColumns()`. |
| `scan_diagnostics` | When true, populate `warnings` and `skippedRelations` (e.g. declared relation return type but runtime did not return a `Relation`). |
| `morph_map` | Alias-to-class map, same shape as `Relation::morphMap()`. Empty uses that registry at scan time. A non-empty value replaces the registry and connects every `morphTo` to every mapped model. |
| `morph_targets` | Optional per-method targets. Keys are the polymorphic model class and the relation method. When a method is listed, only those classes are drawn for it. |
| `ide` | Editor for file links. `null` (default), `cursor`, `vscode`, `phpstorm`, or `custom`. |
| `ide_url` | URL template used when `ide` is `custom`. Placeholders: `{path}`, `{path_encoded}`, `{line}`, `{column}`. |
| `project_root` | Host directory that replaces `base_path()` in editor links. Leave `null` when the app path is the path the editor has open. |

Configuration is **PHP only** (no `.env` keys from this package).

### `models_paths` example

```php
'models_paths' => [
    app_path('Models'),
    base_path('modules/Billing/src/Models'),
],
```

### Editor links

`ide` is `null` until you choose one. `cursor`, `vscode`, and `phpstorm` are built in. Any other editor uses `custom` and an `ide_url` template. The template must contain `{path}` or `{path_encoded}`.

| Placeholder | Becomes |
|-------------|---------|
| `{path}` | Absolute path with forward slashes. A Windows drive colon stays (`C:`). Spaces and other characters in each segment are encoded. |
| `{path_encoded}` | That same path passed through `rawurlencode`, so slashes become `%2F`. Use this inside a query string. |
| `{line}` | 1-based line of the class or relation method. |
| `{column}` | Always `1`. |

Zed, for example, has no preset. Its file URL is the same shape as Cursor:

```php
'ide' => 'custom',
'ide_url' => 'zed://file/{path}:{line}:{column}',
```

An editor that takes the file as a query parameter uses the encoded path:

```php
'ide' => 'custom',
'ide_url' => 'myeditor://open?file={path_encoded}&line={line}',
```

Clicking the link asks the browser to open that protocol, so the editor has to be installed on the same machine and registered for that scheme. A container path needs `project_root` set to the directory the editor has open.

## JSON API (`GET {prefix}/graph`, `eloquent:graph`)

**`graphVersion`** is `2`. Breaking change from older builds: **`nodes` are objects**, **`edges.from` / `edges.to`** are **FQCNs** (same as node `id`), not short class names.

### Payload shape

- **`graphVersion`**: `2`
- **`nodes`**: `[{ "id": "<fqcn>", "label": "<display>", "fqcn": "<fqcn>", "file": "<optional relative path>", "line": 12, "editorUrl": "<optional>", "table": "posts", "schemaStatus": "ok", "columns": [{ "name": "user_id", "type": "bigint unsigned", "nullable": false, "foreignKey": true, "relations": ["belongsTo User"] }] }]`. `columns` is omitted when `include_columns` is false. `foreignKey` is true only for columns that back a relationship or a database foreign key. `line` is the class declaration. `editorUrl` is present only when `ide` is configured.
- **`edges`**: `[{ "from": "<fqcn>", "to": "<fqcn|null>", "type": "belongsTo", "smart": { ... }, "methods": [{ "name": "user", "file": "...", "line": 18, "editorUrl": "<optional>" }], "multiplicity"?: n }]`. Polymorphic edges (`morphTo`, `morphOne`, `morphMany`, `morphToMany`, `morphedByMany`) are drawn with a dotted line. A resolved `morphTo` includes `smart.morphClass` and, when the map uses an alias, `smart.morphAlias`. `to` is `null` when a `morphTo` has no map entry. A deduped edge lists every method.
- **`warnings`**: `[{ "code": "...", "message": "...", ... }]`, e.g. `graph.external_model` when a related class is outside `models_paths`, or `graph.ambiguous_basename` / `graph.ambiguous_default_user` when `?model=` or default `User` focus is ambiguous.
- **`skippedRelations`**: `[{ "model": "<declaring fqcn>", "method": "...", "reason": "..." }]` for methods that advertise a relation return type but do not yield a `Relation` at runtime.
- **`availableModels`** (HTTP only): `[{ "id": "<fqcn>", "label": "<display>" }]` for the root-model dropdown (full scan, no component filter).
- **`selectedModel`**: FQCN of the focused model, or `null` (all models / no default when ambiguous).

**`?model=`** accepts either a **full class name** or a **basename** when it is unique among scanned models.

## Develop this repo

```bash
composer install
npm install
npm run build
composer test
composer format   # Laravel Pint, after PHP edits
```

## Honest limits

The graph is **best-effort static + one guarded call per candidate method** under `pretend()` / `withoutEvents()`. That is the right trade-off for most apps, not a guarantee for every pattern.

- **Cross-connection / exotic relations** may still run outside the scanned model’s `pretend()` scope; **warnings** and **skipped relations** record what we could detect.
- **Abstract** base `Model` classes are not listed as nodes.
- Relations defined **only** outside model methods (raw query builders, arbitrary PHP) will not appear as edges.
- **Columns** require the model’s table on its connection. `schemaStatus` is `missing_table` or `unavailable` when schema inspection cannot read them; the rest of the graph still loads.
- **Polymorphic targets** are not inferred from rows in the type column. They come from `morph_targets`, `morph_map`, or `Relation::morphMap()`. A global map does not know which subset each relation allows.
- **Editor links** open a file on the machine where the browser and the editor are installed. A container `base_path()` needs `project_root` set to the host directory the editor has open.

If you need a line in your internal docs: **“EloqViz shows the Eloquent surface of the codebase you configured to scan—not the full business or database story.”**

## License

MIT.
