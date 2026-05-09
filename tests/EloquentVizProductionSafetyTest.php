<?php

declare(strict_types=1);

namespace EloqViz\EloquentViz\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

final class EloquentVizProductionSafetyTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @see \Illuminate\Foundation\Application::isProduction() */
        $app['env'] = 'production';
    }

    #[Test]
    public function viz_named_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('eloquent-viz.index'));
        $this->assertFalse(Route::has('eloquent-viz.graph'));
    }

    #[Test]
    public function viz_http_endpoints_are_not_found(): void
    {
        $stub = realpath(__DIR__.'/Stubs/ScanModels') ?: __DIR__.'/Stubs/ScanModels';
        $this->app['config']->set('eloquent-viz.models_paths', [$stub]);

        $this->get('/eloquent-viz')->assertNotFound();
        $this->getJson('/eloquent-viz/graph')->assertNotFound();
    }

    #[Test]
    public function eloquent_graph_command_is_not_registered(): void
    {
        $this->assertArrayNotHasKey('eloquent:graph', Artisan::all());
    }
}
