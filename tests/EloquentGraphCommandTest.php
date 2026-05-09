<?php

namespace EloqViz\EloquentViz\Tests;

use EloqViz\EloquentViz\Tests\Fixtures\GraphModels\GraphWidget;
use EloqViz\EloquentViz\Tests\Stubs\ScanModels\Post;
use EloqViz\EloquentViz\Tests\Stubs\ScanModels\User;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

class EloquentGraphCommandTest extends TestCase
{
    #[Test]
    public function it_outputs_valid_json_graph_for_default_models_path(): void
    {
        $this->app['config']->set('eloquent-viz.models_paths', [$this->stubScanModelsPath()]);

        $exit = Artisan::call('eloquent:graph');
        $this->assertSame(0, $exit);

        $data = json_decode(Artisan::output(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('nodes', $data);
        $this->assertArrayHasKey('edges', $data);
        $this->assertContains(User::class, $this->graphNodeIds($data));
        $this->assertContains(Post::class, $this->graphNodeIds($data));
    }

    #[Test]
    public function it_scans_custom_absolute_models_directory(): void
    {
        $fixtures = realpath(__DIR__.'/Fixtures/GraphModels');
        $this->assertNotFalse($fixtures);

        $exit = Artisan::call('eloquent:graph', [
            '--path' => [$fixtures],
        ]);
        $this->assertSame(0, $exit);

        $data = json_decode(Artisan::output(), true);
        $this->assertIsArray($data);
        $this->assertContains(GraphWidget::class, $this->graphNodeIds($data));
        $this->assertNotContains('AbstractGraphCatalog', $this->graphNodeLabels($data));
    }

    #[Test]
    public function it_fails_when_custom_path_does_not_exist(): void
    {
        $missing = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eloqviz-nope-'.uniqid('', true);

        $exit = Artisan::call('eloquent:graph', [
            '--path' => [$missing],
        ]);
        $this->assertSame(1, $exit);

        $combined = Artisan::output();
        $this->assertStringContainsString('Directory not found:', $combined);
        $this->assertStringContainsString(basename($missing), $combined);
    }

    #[Test]
    public function it_merges_models_when_multiple_path_options_are_given(): void
    {
        $stub = $this->stubScanModelsPath();
        $fixtures = realpath(__DIR__.'/Fixtures/GraphModels');
        $this->assertNotFalse($fixtures);

        $exit = Artisan::call('eloquent:graph', [
            '--path' => [$stub, $fixtures],
        ]);

        $this->assertSame(0, $exit);

        $data = json_decode(Artisan::output(), true);
        $this->assertIsArray($data);
        $this->assertContains(User::class, $this->graphNodeIds($data));
        $this->assertContains(GraphWidget::class, $this->graphNodeIds($data));
    }

    private function stubScanModelsPath(): string
    {
        return realpath(__DIR__.'/Stubs/ScanModels') ?: __DIR__.'/Stubs/ScanModels';
    }
}
