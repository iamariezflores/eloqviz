<?php

namespace EloqViz\EloquentViz;

use EloqViz\EloquentViz\Console\EloquentGraphCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class EloquentVizServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/eloquent-viz.php', 'eloquent-viz');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/eloquent-viz.php' => config_path('eloquent-viz.php'),
        ], ['eloquent-viz', 'eloquent-viz-config']);

        $this->publishes([
            __DIR__.'/../public/dist' => public_path('vendor/eloquent-viz'),
        ], ['eloquent-viz', 'eloquent-viz-assets']);

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'eloquent-viz');

        if ($this->app->runningInConsole() && ! $this->app->isProduction()) {
            $this->commands([
                EloquentGraphCommand::class,
            ]);
        }

        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        if ($this->app->isProduction()) {
            return;
        }

        $prefix = (string) config('eloquent-viz.route_prefix', 'eloquent-viz');
        $middleware = config('eloquent-viz.middleware', ['web']);
        $middleware = is_array($middleware) ? $middleware : [$middleware];

        Route::middleware($middleware)
            ->prefix($prefix)
            ->group(function (): void {
                Route::get('/', function (EloquentGraphScanner $scanner, Request $request) {
                    $catalog = $scanner->scan(null, null, false);
                    $nodeRows = $catalog['nodes'];
                    $queryModel = $request->query('model');
                    $selectedModel = is_string($queryModel) && $queryModel !== ''
                        ? $scanner->matchModelSelection($queryModel, $nodeRows)
                        : $scanner->defaultFocusedModelId($nodeRows);

                    $availableModels = array_map(
                        static fn (array $n): array => ['id' => $n['id'], 'label' => $n['label']],
                        $nodeRows
                    );

                    return view('eloquent-viz::graph', [
                        'graphUrl' => route('eloquent-viz.graph'),
                        'availableModels' => $availableModels,
                        'selectedModel' => $selectedModel,
                    ]);
                })->name('eloquent-viz.index');

                Route::get('/graph', function (EloquentGraphScanner $scanner, Request $request) {
                    $catalog = $scanner->scan(null, null, false);
                    $nodeRows = $catalog['nodes'];
                    $queryModel = $request->query('model');
                    $selectedModel = is_string($queryModel) && $queryModel !== ''
                        ? $scanner->matchModelSelection($queryModel, $nodeRows)
                        : $scanner->defaultFocusedModelId($nodeRows);

                    $graph = $scanner->scan(null, $selectedModel, false);

                    $availableModels = array_map(
                        static fn (array $n): array => ['id' => $n['id'], 'label' => $n['label']],
                        $nodeRows
                    );

                    return response()->json([
                        ...$graph,
                        'availableModels' => $availableModels,
                        'selectedModel' => $selectedModel,
                    ]);
                })->name('eloquent-viz.graph');
            });
    }
}
