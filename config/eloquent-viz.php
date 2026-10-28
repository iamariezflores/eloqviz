<?php

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP route prefix
    |--------------------------------------------------------------------------
    |
    | The UI and JSON graph are served under this URL prefix, for example
    | /eloquent-viz and /eloquent-viz/graph when prefix is "eloquent-viz".
    |
    */

    'route_prefix' => 'eloquent-viz',

    /*
    |--------------------------------------------------------------------------
    | HTTP middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Model directories to scan (recursive .php files)
    |--------------------------------------------------------------------------
    |
    | Deprecated: singular models_directory — when still present in a published
    | config, it takes precedence over models_paths until you remove it.
    |
    */

    'models_paths' => [
        app_path('Models'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Node label on the graph (graphVersion 2 uses FQCN as stable node id)
    |--------------------------------------------------------------------------
    |
    | - short: class basename only (default, clearest for large graphs).
    | - fqcn: full class name on the node.
    | - namespace_suffix: "Post · App\Models" (basename plus parent namespace).
    |
    */

    'node_display' => 'short',

    /*
    |--------------------------------------------------------------------------
    | Include source file path per model in JSON (and selection panel)
    |--------------------------------------------------------------------------
    */

    'include_file_paths' => true,

    /*
    |--------------------------------------------------------------------------
    | Include database columns on each node
    |--------------------------------------------------------------------------
    |
    | When true, each node lists columns from Schema::getColumns() on the
    | model's connection. Columns that back a relationship, and columns
    | reported by Schema::getForeignKeys(), are flagged so the UI can
    | highlight them. A missing table does not fail the scan.
    |
    */

    'include_columns' => true,

    /*
    |--------------------------------------------------------------------------
    | Scan diagnostics (warnings, skipped relation probes)
    |--------------------------------------------------------------------------
    */

    'scan_diagnostics' => true,

    /*
    |--------------------------------------------------------------------------
    | Polymorphic targets
    |--------------------------------------------------------------------------
    |
    | morphTo has no single related model until a map names the classes that
    | may appear in the type column. EloqViz draws one edge from the
    | polymorphic model to each of those classes.
    |
    | morph_targets is optional and wins for the methods it lists. Keys are
    | the polymorphic model's class and the relation method name.
    |
    | morph_map uses the same alias => class shape as Relation::morphMap().
    | Leave it empty to read that registry at scan time. A non-empty morph_map
    | is used instead of the registry. Every morphTo then points at every
    | mapped model, so use morph_targets when each relation allows a subset.
    |
    */

    'morph_targets' => [
        // \App\Models\Comment::class => [
        //     'commentable' => [
        //         \App\Models\Post::class,
        //         \App\Models\Video::class,
        //     ],
        // ],
    ],

    'morph_map' => [
        // 'post' => \App\Models\Post::class,
        // 'video' => \App\Models\Video::class,
    ],

];
