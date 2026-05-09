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
    | Scan diagnostics (warnings, skipped relation probes)
    |--------------------------------------------------------------------------
    */

    'scan_diagnostics' => true,

];
