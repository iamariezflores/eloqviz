<?php

namespace EloqViz\EloquentViz\Tests;

use EloqViz\EloquentViz\EloquentVizServiceProvider;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  array<string, mixed>  $graph
     * @return list<string>
     */
    protected function graphNodeIds(array $graph): array
    {
        return array_values(array_column($graph['nodes'] ?? [], 'id'));
    }

    /**
     * @param  array<string, mixed>  $graph
     * @return list<string>
     */
    protected function graphNodeLabels(array $graph): array
    {
        return array_values(array_column($graph['nodes'] ?? [], 'label'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $dist = dirname(__DIR__).'/public/dist/eloquent-viz.js';
        if (is_file($dist)) {
            File::ensureDirectoryExists(public_path('vendor/eloquent-viz'));
            File::copy($dist, public_path('vendor/eloquent-viz/eloquent-viz.js'));
        }
    }

    protected function getPackageProviders($app): array
    {
        return [EloquentVizServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
