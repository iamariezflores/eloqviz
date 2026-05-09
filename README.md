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
- **Web UI** — Interactive graph (Cytoscape.js): stable **FQCN node ids**, configurable **labels**, search across label / FQCN / file path, **scan notes** (warnings + skipped relation probes), selection panel shows **FQCN + file** for each model.
- **JSON (`graphVersion` 2)** — Full graph payload with diagnostics (see below).
- **Artisan** — `php artisan eloquent:graph` prints the same JSON. Use **`--path=`** repeatedly (or once) to override config directories.

Relationships are discovered by inspecting public relation methods on each non-abstract Eloquent model. Each candidate method runs once inside `Model::withoutEvents()` and `Connection::pretend()` on that model’s connection so typical relations never hit the database.

## Requirements

- PHP **8.2+**
- Laravel **11.44+** or **12.x**

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
| `scan_diagnostics` | When true, populate `warnings` and `skippedRelations` (e.g. declared relation return type but runtime did not return a `Relation`). |

Configuration is **PHP only** (no `.env` keys from this package).

### `models_paths` example

```php
'models_paths' => [
    app_path('Models'),
    base_path('modules/Billing/src/Models'),
],
```

## JSON API (`GET {prefix}/graph`, `eloquent:graph`)

**`graphVersion`** is `2`. Breaking change from older builds: **`nodes` are objects**, **`edges.from` / `edges.to`** are **FQCNs** (same as node `id`), not short class names.

### Payload shape

- **`graphVersion`**: `2`
- **`nodes`**: `[{ "id": "<fqcn>", "label": "<display>", "fqcn": "<fqcn>", "file": "<optional relative path>" }]`
- **`edges`**: `[{ "from": "<fqcn>", "to": "<fqcn|null>", "type": "belongsTo", "smart": { ... }, "multiplicity"?: n }]`
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

If you need a line in your internal docs: **“EloqViz shows the Eloquent surface of the codebase you configured to scan—not the full business or database story.”**

## License

MIT.
