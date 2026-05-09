<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Eloquent Viz — {{ config('app.name', 'Laravel') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet">
    <style>
        :root { color-scheme: dark; }
        body { margin: 0; min-height: 100vh; font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif; background: #020617; color: #f1f5f9; -webkit-font-smoothing: antialiased; }
        .ev-shell { display: flex; min-height: 100vh; flex-direction: column; }
        .ev-header { border-bottom: 1px solid #1e293b; background: rgba(15, 23, 42, 0.85); padding: 1rem 1.25rem; backdrop-filter: blur(8px); }
        .ev-header-inner { max-width: 80rem; margin: 0 auto; display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; justify-content: space-between; }
        .ev-title { font-size: 1.125rem; font-weight: 600; color: #fff; letter-spacing: -0.02em; margin: 0; }
        .ev-status { margin: 0.35rem 0 0; font-size: 0.875rem; color: #94a3b8; }
        .ev-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .ev-btn { border-radius: 0.375rem; padding: 0.4rem 0.75rem; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; text-decoration: none; display: inline-block; }
        .ev-btn-primary { background: #0284c7; color: #fff; }
        .ev-btn-primary:hover { background: #0ea5e9; }
        .ev-btn-secondary { background: #1e293b; color: #e2e8f0; border: 1px solid #475569; }
        .ev-btn-secondary:hover { background: #334155; }
        .ev-body { display: flex; flex: 1; flex-direction: column; }
        @media (min-width: 1024px) { .ev-body { flex-direction: row; } }
        .ev-aside { width: 100%; border-bottom: 1px solid #1e293b; background: rgba(15, 23, 42, 0.5); padding: 1rem; box-sizing: border-box; max-height: min(55vh, 28rem); overflow: hidden; display: flex; flex-direction: column; min-width: 0; }
        @media (min-width: 1024px) { .ev-aside { width: min(22rem, 36vw); flex: 0 0 min(22rem, 36vw); border-bottom: none; border-right: 1px solid #1e293b; max-height: calc(100vh - 5.5rem); overflow: hidden; display: flex; flex-direction: column; min-width: 0; } }
        .ev-label { display: block; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; }
        .ev-input { margin-top: 0.5rem; width: 100%; box-sizing: border-box; border-radius: 0.375rem; border: 1px solid #334155; background: #020617; color: #f8fafc; padding: 0.5rem 0.75rem; font-size: 0.875rem; }
        .ev-input:focus { outline: none; border-color: #0ea5e9; box-shadow: 0 0 0 1px #0ea5e9; }
        .ev-input + .ev-label { margin-top: 1rem; }
        .ev-hint { margin-top: 2rem; font-size: 0.75rem; line-height: 1.5; color: #64748b; }
        .ev-aside-scroll { flex: 1; min-height: 0; overflow-y: auto; overflow-x: hidden; padding-right: 0.25rem; -webkit-overflow-scrolling: touch; }
        .ev-aside-scroll::-webkit-scrollbar { width: 6px; }
        .ev-aside-scroll::-webkit-scrollbar-thumb { background: #334155; border-radius: 3px; }
        .ev-selection { margin-top: 1rem; border: 1px solid #1e293b; border-radius: 0.5rem; background: rgba(15, 23, 42, 0.65); padding: 0.75rem; min-width: 0; }
        .ev-selection-title { margin: 0 0 0.5rem; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; }
        .ev-selection-empty { margin: 0; color: #94a3b8; font-size: 0.8125rem; line-height: 1.45; }
        .ev-selection-grid { margin: 0; display: block; font-size: 0.8125rem; color: #e2e8f0; }
        .ev-selection-grid dt { color: #94a3b8; font-size: 0.65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; margin: 0.65rem 0 0.2rem 0; line-height: 1.3; }
        .ev-selection-grid dt:first-child { margin-top: 0; }
        .ev-selection-grid dd { margin: 0; overflow-wrap: anywhere; word-break: break-word; line-height: 1.45; font-size: 0.8125rem; }
        .ev-code { display: block; font-family: ui-monospace, 'Cascadia Code', 'SF Mono', Menlo, Consolas, monospace; font-size: 0.75rem; background: rgba(15, 23, 42, 0.9); border: 1px solid #334155; border-radius: 0.25rem; padding: 0.35rem 0.45rem; margin-top: 0.15rem; white-space: pre-wrap; word-break: break-all; color: #e2e8f0; }
        .ev-selection-actions { margin-top: 0.75rem; display: flex; gap: 0.5rem; align-items: center; }
        .ev-selection-actions .ev-input { margin-top: 0; flex: 1; }
        .ev-main { position: relative; flex: 1; min-height: 420px; background: #020617; }
        @media (min-width: 1024px) { .ev-main { min-height: 0; } }
        #cy { position: absolute; inset: 0; width: 100%; height: 100%; min-height: 420px; }
        .ev-stack { display: flex; flex-direction: column; gap: 0.5rem; }
        .ev-filter-row { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; color: #e2e8f0; cursor: pointer; }
        .ev-checkbox { width: 1rem; height: 1rem; accent-color: #0ea5e9; }
        .ev-swatch { width: 0.6rem; height: 0.6rem; border-radius: 9999px; flex-shrink: 0; }
        .ev-btn-row { display: flex; gap: 0.5rem; margin-top: 0.75rem; }
        .ev-diagnostics { margin-top: 1rem; border: 1px solid #854d0e; border-radius: 0.5rem; background: rgba(120, 53, 15, 0.2); padding: 0.65rem 0.6rem; display: none; min-width: 0; max-height: 14rem; overflow: hidden; flex-direction: column; }
        .ev-diagnostics.is-visible { display: flex; }
        .ev-diagnostics-title { margin: 0 0 0.45rem; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: #fbbf24; flex-shrink: 0; }
        .ev-diagnostics-scroll { overflow-y: auto; overflow-x: hidden; min-height: 0; flex: 1; -webkit-overflow-scrolling: touch; padding-right: 0.2rem; }
        .ev-diagnostics-scroll::-webkit-scrollbar { width: 5px; }
        .ev-diagnostics-scroll::-webkit-scrollbar-thumb { background: #92400e; border-radius: 3px; }
        .ev-diagnostics ul.ev-diagnostics-top { margin: 0; padding: 0; list-style: none; font-size: 0.78rem; color: #fde68a; line-height: 1.45; }
        .ev-diagnostics-item { margin: 0 0 0.65rem 0; padding-bottom: 0.65rem; border-bottom: 1px solid rgba(180, 83, 9, 0.35); min-width: 0; }
        .ev-diagnostics-item:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }
        .ev-diagnostics-code { display: block; font-family: ui-monospace, 'Cascadia Code', 'SF Mono', Menlo, Consolas, monospace; font-size: 0.7rem; color: #fef3c7; background: rgba(69, 26, 3, 0.45); border-radius: 0.25rem; padding: 0.35rem 0.4rem; margin-top: 0.35rem; white-space: pre-wrap; word-break: break-all; overflow-wrap: anywhere; max-width: 100%; box-sizing: border-box; }
        .ev-diagnostics-candidates { margin: 0.4rem 0 0 0; padding: 0 0 0 1rem; list-style: disc; font-size: 0.72rem; color: #fcd34d; }
        .ev-diagnostics-candidates li { margin: 0.2rem 0; word-break: break-all; overflow-wrap: anywhere; }
        .ev-diagnostics-meta { font-size: 0.68rem; color: #fbbf24; opacity: 0.9; margin-bottom: 0.2rem; font-weight: 600; }
    </style>
    <script>
        window.__ELOQUENT_VIZ__ = {
            graphUrl: @json($graphUrl),
            availableModels: @json($availableModels ?? []),
            selectedModel: @json($selectedModel ?? null),
        };
    </script>
    @php
        $eloquentVizJs = public_path('vendor/eloquent-viz/eloquent-viz.js');
        $eloquentVizJsV = is_file($eloquentVizJs) ? filemtime($eloquentVizJs) : null;
    @endphp
    <script src="{{ asset('vendor/eloquent-viz/eloquent-viz.js') }}{{ $eloquentVizJsV ? '?v='.$eloquentVizJsV : '' }}" defer></script>
</head>
<body>
    <div class="ev-shell">
        <header class="ev-header">
            <div class="ev-header-inner">
                <div>
                    <h1 class="ev-title">Eloquent graph</h1>
                    <p id="graph-status" class="ev-status">Preparing…</p>
                </div>
                <div class="ev-actions">
                    <a href="{{ $graphUrl }}" class="ev-btn ev-btn-secondary">Raw JSON</a>
                    <button type="button" id="reset-zoom" class="ev-btn ev-btn-primary">Fit view</button>
                    <button type="button" id="relayout" class="ev-btn ev-btn-secondary">Re-layout</button>
                </div>
            </div>
        </header>

        <div class="ev-body">
            <aside class="ev-aside">
                <div class="ev-aside-scroll">
                    <label class="ev-label" for="selected-model">Root model</label>
                    <select id="selected-model" class="ev-input">
                        <option value="">All models</option>
                        @foreach (($availableModels ?? []) as $model)
                            @php
                                $mid = is_array($model) ? ($model['id'] ?? '') : $model;
                                $mlabel = is_array($model) ? ($model['label'] ?? $mid) : $model;
                            @endphp
                            <option value="{{ $mid }}" @selected(($selectedModel ?? null) === $mid)>{{ $mlabel }}</option>
                        @endforeach
                    </select>
                    <label class="ev-label" for="node-filter">Filter models</label>
                    <input id="node-filter" type="search" autocomplete="off" placeholder="Name contains…" class="ev-input">
                    <div id="rel-type-filters" style="margin-top:1.5rem;">
                        <p class="ev-label" style="margin-bottom:0.5rem;">Relationship types</p>
                        <div id="rel-type-filter-controls"></div>
                    </div>
                    <div id="scan-diagnostics" class="ev-diagnostics" aria-live="polite">
                        <h3 class="ev-diagnostics-title">Scan notes</h3>
                        <div class="ev-diagnostics-scroll">
                            <ul id="scan-diagnostics-list" class="ev-diagnostics-top"></ul>
                        </div>
                    </div>
                    <section class="ev-selection">
                        <h2 class="ev-selection-title">Selection details</h2>
                        <div id="selection-details">
                            <p class="ev-selection-empty">Click a model or relation to inspect details.</p>
                        </div>
                    </section>
                    <p class="ev-hint">
                        Drag nodes, scroll to zoom, drag the background to pan. Shift-drag to box-select.
                        Use filters to hide relationship types or narrow models by name. Full relation text appears in this panel when you select an edge.
                    </p>
                </div>
            </aside>

            <main class="ev-main">
                <div id="cy"></div>
            </main>
        </div>
    </div>
</body>
</html>
