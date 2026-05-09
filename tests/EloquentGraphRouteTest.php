<?php

namespace EloqViz\EloquentViz\Tests;

use EloqViz\EloquentViz\Tests\Stubs\ScanModels\Post;
use EloqViz\EloquentViz\Tests\Stubs\ScanModels\User;
use PHPUnit\Framework\Attributes\Test;

class EloquentGraphRouteTest extends TestCase
{
    #[Test]
    public function it_returns_json_graph_with_expected_shape(): void
    {
        $stub = realpath(__DIR__.'/Stubs/ScanModels') ?: __DIR__.'/Stubs/ScanModels';
        $this->app['config']->set('eloquent-viz.models_paths', [$stub]);

        $response = $this->getJson('/eloquent-viz/graph');

        $response->assertOk()
            ->assertJsonStructure([
                'graphVersion',
                'nodes' => [
                    '*' => ['id', 'label', 'fqcn'],
                ],
                'edges' => [
                    '*' => ['from', 'to', 'type'],
                ],
                'warnings',
                'skippedRelations',
                'availableModels' => [
                    '*' => ['id', 'label'],
                ],
                'selectedModel',
            ]);

        $data = $response->json();
        $this->assertSame(2, $data['graphVersion']);
        $this->assertIsList($data['nodes']);
        foreach ($data['nodes'] as $node) {
            $this->assertIsArray($node);
            $this->assertArrayHasKey('id', $node);
        }

        $ids = $this->graphNodeIds($data);
        $this->assertContains(User::class, $ids);
        $this->assertContains(Post::class, $ids);

        $edgeKeys = collect($data['edges'])->map(
            fn (array $e): string => $e['from'].'|'.($e['to'] ?? 'null').'|'.$e['type']
        )->all();
        $this->assertContains(User::class.'|'.Post::class.'|hasMany', $edgeKeys);
        $this->assertContains(Post::class.'|'.User::class.'|belongsTo', $edgeKeys);
    }

    #[Test]
    public function it_includes_available_and_selected_model_in_json_payload(): void
    {
        $stub = realpath(__DIR__.'/Stubs/ScanModels') ?: __DIR__.'/Stubs/ScanModels';
        $this->app['config']->set('eloquent-viz.models_paths', [$stub]);

        $data = $this->getJson('/eloquent-viz/graph')->assertOk()->json();

        $this->assertArrayHasKey('availableModels', $data);
        $labels = array_column($data['availableModels'], 'label');
        $this->assertContains('User', $labels);
        $this->assertContains('Post', $labels);
        $this->assertSame(User::class, $data['selectedModel']);
    }

    #[Test]
    public function it_honours_the_model_query_parameter_for_selection_and_filtering(): void
    {
        $stub = realpath(__DIR__.'/Stubs/ScanModels') ?: __DIR__.'/Stubs/ScanModels';
        $this->app['config']->set('eloquent-viz.models_paths', [$stub]);

        $data = $this->getJson('/eloquent-viz/graph?model=Post')->assertOk()->json();

        $this->assertSame(Post::class, $data['selectedModel']);
        $this->assertContains(Post::class, $this->graphNodeIds($data));
        $this->assertContains(User::class, $this->graphNodeIds($data));
    }

    #[Test]
    public function it_prefers_legacy_models_directory_over_models_paths(): void
    {
        $stub = realpath(__DIR__.'/Stubs/ScanModels') ?: __DIR__.'/Stubs/ScanModels';
        $fixtures = realpath(__DIR__.'/Fixtures/GraphModels') ?: __DIR__.'/Fixtures/GraphModels';

        $this->app['config']->set('eloquent-viz.models_paths', [$fixtures]);
        $this->app['config']->set('eloquent-viz.models_directory', $stub);

        $data = $this->getJson('/eloquent-viz/graph')->assertOk()->json();

        $this->assertContains(User::class, $this->graphNodeIds($data));
        $this->assertNotContains(
            \EloqViz\EloquentViz\Tests\Fixtures\GraphModels\GraphWidget::class,
            $this->graphNodeIds($data)
        );
    }
}
