<?php

namespace EloqViz\EloquentViz\Tests;

use PHPUnit\Framework\Attributes\Test;

class EloquentVizPageTest extends TestCase
{
    #[Test]
    public function it_serves_the_visualization_page(): void
    {
        $stub = realpath(__DIR__.'/Stubs/ScanModels') ?: __DIR__.'/Stubs/ScanModels';
        $this->app['config']->set('eloquent-viz.models_paths', [$stub]);

        $this->withoutVite();

        $response = $this->get('/eloquent-viz');

        $response->assertOk()
            ->assertSee('Eloquent graph', false)
            ->assertSee('id="cy"', false)
            ->assertSee('Relationship types', false);
    }
}
