<?php

namespace EloqViz\EloquentViz\Tests;

use EloqViz\EloquentViz\EloquentGraphScanner;
use EloqViz\EloquentViz\Tests\Fixtures\GraphModels\GraphAutomation;
use EloqViz\EloquentViz\Tests\Fixtures\GraphModels\GraphAutomationExpense;
use EloqViz\EloquentViz\Tests\Fixtures\GraphModels\GraphDupHost;
use EloqViz\EloquentViz\Tests\Fixtures\GraphModels\GraphDupPost;
use EloqViz\EloquentViz\Tests\Fixtures\GraphModels\GraphGadget;
use EloqViz\EloquentViz\Tests\Fixtures\GraphModels\GraphNoisy;
use EloqViz\EloquentViz\Tests\Fixtures\GraphModels\GraphSideEffectHost;
use EloqViz\EloquentViz\Tests\Fixtures\GraphModels\GraphWidget;
use EloqViz\EloquentViz\Tests\Fixtures\GraphModels\Nested\GraphAddon;
use EloqViz\EloquentViz\Tests\Stubs\ScanModels\Post;
use EloqViz\EloquentViz\Tests\Stubs\ScanModels\User;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

class EloquentGraphScannerTest extends TestCase
{
    private function graphFixturesPath(): string
    {
        return realpath(__DIR__.'/Fixtures/GraphModels') ?: __DIR__.'/Fixtures/GraphModels';
    }

    private function stubScanModelsPath(): string
    {
        return realpath(__DIR__.'/Stubs/ScanModels') ?: __DIR__.'/Stubs/ScanModels';
    }

    private function emptyPayload(): array
    {
        return [
            'graphVersion' => 2,
            'nodes' => [],
            'edges' => [],
            'warnings' => [],
            'skippedRelations' => [],
        ];
    }

    #[Test]
    public function it_deduplicates_when_the_same_directory_is_passed_twice(): void
    {
        $stub = realpath(__DIR__.'/Stubs/ScanModels') ?: __DIR__.'/Stubs/ScanModels';
        $scanner = new EloquentGraphScanner;
        $once = $scanner->scan($stub);
        $twice = $scanner->scan([$stub, $stub]);

        $this->assertSame($once['nodes'], $twice['nodes']);
        $this->assertSame($once['edges'], $twice['edges']);
    }

    #[Test]
    public function it_merges_classes_from_multiple_scan_directories(): void
    {
        $stub = realpath(__DIR__.'/Stubs/ScanModels') ?: __DIR__.'/Stubs/ScanModels';
        $fixtures = realpath(__DIR__.'/Fixtures/GraphModels') ?: __DIR__.'/Fixtures/GraphModels';
        $scanner = new EloquentGraphScanner;
        $graph = $scanner->scan([$stub, $fixtures], null, false);

        $this->assertContains(User::class, $this->graphNodeIds($graph));
        $this->assertContains(GraphWidget::class, $this->graphNodeIds($graph));
    }

    #[Test]
    public function it_scans_models_directory_and_resolves_user_post_edges(): void
    {
        $scanner = new EloquentGraphScanner;
        $graph = $scanner->scan($this->stubScanModelsPath());

        $labels = $this->graphNodeLabels($graph);
        sort($labels, SORT_STRING);
        $this->assertSame(['Post', 'User'], $labels);

        $edges = $this->edgesKeyedByFromToType($graph['edges']);

        $this->assertSame(
            [
                'from' => Post::class,
                'to' => User::class,
                'type' => 'belongsTo',
                'smart' => ['foreignKey' => 'user_id', 'ownerKey' => 'id'],
            ],
            $edges[Post::class.'|'.User::class.'|belongsTo']
        );
        $this->assertSame(
            [
                'from' => User::class,
                'to' => Post::class,
                'type' => 'hasMany',
                'smart' => ['foreignKey' => 'user_id', 'localKey' => 'id'],
            ],
            $edges[User::class.'|'.Post::class.'|hasMany']
        );
    }

    #[Test]
    public function it_defaults_to_user_model_component_when_no_model_is_selected(): void
    {
        $scanner = new EloquentGraphScanner;
        $graph = $scanner->scan($this->stubScanModelsPath());

        $labels = $this->graphNodeLabels($graph);
        sort($labels, SORT_STRING);
        $this->assertSame(['Post', 'User'], $labels);

        $this->assertSame(
            2,
            collect($graph['edges'])
                ->whereIn('from', [Post::class, User::class])
                ->whereIn('to', [Post::class, User::class])
                ->count()
        );
    }

    #[Test]
    public function it_filters_graph_by_selected_model_component(): void
    {
        $scanner = new EloquentGraphScanner;
        $graph = $scanner->scan($this->graphFixturesPath(), 'GraphAutomation');

        $this->assertSame(
            ['GraphAutomation', 'GraphAutomationExpense'],
            $this->graphNodeLabels($graph)
        );

        $edge = collect($graph['edges'])->first(
            fn (array $e): bool => $e['from'] === GraphAutomation::class
                && $e['to'] === GraphAutomationExpense::class
                && $e['type'] === 'hasMany'
        );

        $this->assertNotNull($edge);
        $this->assertSame(0, collect($graph['edges'])->where('from', GraphWidget::class)->count());
    }

    #[Test]
    public function it_includes_nested_fixture_models_and_edges(): void
    {
        $scanner = new EloquentGraphScanner;
        $graph = $scanner->scan($this->graphFixturesPath());

        foreach (
            [
                GraphAutomation::class,
                GraphAutomationExpense::class,
                GraphAddon::class,
                GraphDupHost::class,
                GraphDupPost::class,
                GraphGadget::class,
                GraphNoisy::class,
                GraphSideEffectHost::class,
                GraphWidget::class,
            ] as $fqcn
        ) {
            $this->assertContains($fqcn, $this->graphNodeIds($graph));
        }

        $this->assertNotContains('AbstractGraphCatalog', $this->graphNodeLabels($graph));

        $edges = $this->edgesKeyedByFromToType($graph['edges']);

        $this->assertSame(
            [
                'from' => GraphWidget::class,
                'to' => GraphGadget::class,
                'type' => 'hasMany',
                'smart' => ['foreignKey' => 'graph_widget_id', 'localKey' => 'id'],
            ],
            $edges[GraphWidget::class.'|'.GraphGadget::class.'|hasMany']
        );
        $this->assertSame(
            [
                'from' => GraphWidget::class,
                'to' => GraphAddon::class,
                'type' => 'hasMany',
                'smart' => ['foreignKey' => 'graph_widget_id', 'localKey' => 'id'],
            ],
            $edges[GraphWidget::class.'|'.GraphAddon::class.'|hasMany']
        );
        $this->assertSame(
            [
                'from' => GraphGadget::class,
                'to' => GraphWidget::class,
                'type' => 'belongsTo',
                'smart' => ['foreignKey' => 'widget_id', 'ownerKey' => 'id'],
            ],
            $edges[GraphGadget::class.'|'.GraphWidget::class.'|belongsTo']
        );
        $this->assertSame(
            [
                'from' => GraphAddon::class,
                'to' => GraphWidget::class,
                'type' => 'belongsTo',
                'smart' => ['foreignKey' => 'widget_id', 'ownerKey' => 'id'],
            ],
            $edges[GraphAddon::class.'|'.GraphWidget::class.'|belongsTo']
        );
        $this->assertSame(
            [
                'from' => GraphAutomation::class,
                'to' => GraphAutomationExpense::class,
                'type' => 'hasMany',
                'smart' => ['foreignKey' => 'automation_id', 'localKey' => 'id'],
            ],
            $edges[GraphAutomation::class.'|'.GraphAutomationExpense::class.'|hasMany']
        );

        $dupEdges = collect($graph['edges'])->where('from', GraphDupHost::class)->where('to', GraphDupPost::class)->where('type', 'hasMany');
        $this->assertCount(1, $dupEdges);
        $this->assertSame(2, $dupEdges->first()['multiplicity']);
    }

    #[Test]
    public function it_resolves_relations_when_method_builds_queries_under_connection_pretend_mode(): void
    {
        $scanner = new EloquentGraphScanner;
        $graph = $scanner->scan($this->graphFixturesPath());

        $edge = collect($graph['edges'])->first(
            fn (array $e): bool => $e['from'] === GraphSideEffectHost::class
                && $e['to'] === GraphWidget::class
                && $e['type'] === 'belongsTo'
        );

        $this->assertNotNull($edge);
    }

    #[Test]
    public function it_skips_static_methods_required_parameters_missing_return_types_and_keeps_resolvable_relations(): void
    {
        $scanner = new EloquentGraphScanner;
        $graph = $scanner->scan($this->graphFixturesPath());

        $fromNoisy = array_values(array_filter(
            $graph['edges'],
            fn (array $edge): bool => $edge['from'] === GraphNoisy::class
        ));

        $this->assertCount(1, $fromNoisy);

        $noisyWidget = collect($graph['edges'])->first(
            fn (array $e): bool => $e['from'] === GraphNoisy::class
                && $e['to'] === GraphWidget::class
                && $e['type'] === 'belongsTo'
        );
        $this->assertNotNull($noisyWidget);
        $this->assertSame(2, $noisyWidget['multiplicity']);

        $this->assertTrue(
            collect($graph['skippedRelations'] ?? [])->contains(
                fn (array $s): bool => ($s['method'] ?? '') === 'throwsAtRuntime'
            )
        );

        $this->assertSame(
            1,
            collect($graph['edges'])->where('from', GraphNoisy::class)->where('to', GraphWidget::class)->where('type', 'belongsTo')->count()
        );
        $this->assertSame(
            0,
            collect($graph['edges'])->where('from', GraphNoisy::class)->whereNull('to')->where('type', 'belongsTo')->count()
        );
    }

    #[Test]
    public function it_returns_empty_graph_when_directory_missing(): void
    {
        $scanner = new EloquentGraphScanner;
        $graph = $scanner->scan(sys_get_temp_dir().DIRECTORY_SEPARATOR.'eloqviz_missing_'.uniqid('', true));

        $this->assertSame($this->emptyPayload(), $graph);
    }

    #[Test]
    public function it_returns_empty_graph_for_empty_directory(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eloqviz_empty_'.uniqid('', true);
        File::makeDirectory($dir);

        try {
            $scanner = new EloquentGraphScanner;
            $graph = $scanner->scan($dir);

            $this->assertSame($this->emptyPayload(), $graph);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    #[Test]
    public function it_discovers_models_from_dynamically_loaded_file_in_custom_directory(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eloqviz_dyn_'.uniqid('', true);
        File::makeDirectory($dir);

        $path = $dir.DIRECTORY_SEPARATOR.'GraphLeaf.php';
        $contents = <<<'PHP'
<?php

namespace EloqVizDynamic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GraphLeaf extends Model
{
    public function selfRef(): BelongsTo
    {
        return $this->belongsTo(self::class);
    }
}
PHP;

        try {
            File::put($path, $contents);
            require_once $path;

            $scanner = new EloquentGraphScanner;
            $graph = $scanner->scan($dir);

            $this->assertSame(['GraphLeaf'], $this->graphNodeLabels($graph));
            $this->assertSame(['EloqVizDynamic\GraphLeaf'], $this->graphNodeIds($graph));

            $leaf = 'EloqVizDynamic\GraphLeaf';
            $edge = collect($graph['edges'])->first(
                fn (array $e): bool => $e['from'] === $leaf && $e['to'] === $leaf && $e['type'] === 'belongsTo'
            );
            $this->assertNotNull($edge);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * @param  list<array{from: string, to: string|null, type: string}>  $edges
     * @return array<string, array{from: string, to: string|null, type: string}>
     */
    private function edgesKeyedByFromToType(array $edges): array
    {
        $out = [];
        foreach ($edges as $edge) {
            $key = $edge['from'].'|'.($edge['to'] ?? 'null').'|'.$edge['type'];
            $out[$key] = $edge;
        }

        return $out;
    }
}
