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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
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
    public function it_lists_table_columns_and_marks_only_real_foreign_keys(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('external_id')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('organization_id')->nullable()->constrained('users');
            $table->string('title');
            $table->string('stripe_id')->nullable();
            $table->timestamps();
        });

        $scanner = new EloquentGraphScanner;
        $graph = $scanner->scan($this->stubScanModelsPath(), null, false);

        $post = collect($graph['nodes'])->firstWhere('id', Post::class);
        $user = collect($graph['nodes'])->firstWhere('id', User::class);

        $this->assertIsArray($post);
        $this->assertIsArray($user);
        $this->assertSame('posts', $post['table']);
        $this->assertSame('users', $user['table']);
        $this->assertSame('ok', $post['schemaStatus']);
        $this->assertSame('ok', $user['schemaStatus']);
        $this->assertArrayHasKey('fqcn', $post);
        $this->assertArrayHasKey('file', $post);

        $this->assertSame(
            ['id', 'user_id', 'organization_id', 'title', 'stripe_id', 'created_at', 'updated_at'],
            array_column($post['columns'], 'name')
        );

        $userId = collect($post['columns'])->firstWhere('name', 'user_id');
        $this->assertIsArray($userId);
        $this->assertTrue($userId['foreignKey']);
        $this->assertFalse($userId['nullable']);
        $this->assertMatchesRegularExpression('/int/i', $userId['type']);
        $this->assertSame(['belongsTo User', 'hasMany from User'], $userId['relations']);

        $organizationId = collect($post['columns'])->firstWhere('name', 'organization_id');
        $this->assertIsArray($organizationId);
        $this->assertTrue($organizationId['foreignKey']);
        $this->assertTrue($organizationId['nullable']);
        $this->assertSame(['references users.id'], $organizationId['relations']);

        $stripeId = collect($post['columns'])->firstWhere('name', 'stripe_id');
        $this->assertIsArray($stripeId);
        $this->assertFalse($stripeId['foreignKey']);
        $this->assertTrue($stripeId['nullable']);
        $this->assertSame([], $stripeId['relations']);

        $title = collect($post['columns'])->firstWhere('name', 'title');
        $this->assertIsArray($title);
        $this->assertFalse($title['foreignKey']);
        $this->assertMatchesRegularExpression('/char|string|text|varchar/i', $title['type']);

        $externalId = collect($user['columns'])->firstWhere('name', 'external_id');
        $this->assertIsArray($externalId);
        $this->assertFalse($externalId['foreignKey']);

        $userPrimaryKey = collect($user['columns'])->firstWhere('name', 'id');
        $this->assertIsArray($userPrimaryKey);
        $this->assertFalse($userPrimaryKey['foreignKey']);
    }

    #[Test]
    public function it_marks_relationship_foreign_keys_that_do_not_use_the_id_suffix(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eloqviz_fk_'.uniqid('', true);
        File::makeDirectory($dir);

        $team = <<<'PHP'
<?php

namespace EloqVizDynamic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GraphTeam extends Model
{
    protected $table = 'graph_teams';

    public function members(): HasMany
    {
        return $this->hasMany(GraphMember::class, 'team_ref');
    }
}
PHP;

        $member = <<<'PHP'
<?php

namespace EloqVizDynamic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GraphMember extends Model
{
    protected $table = 'graph_members';

    public function team(): BelongsTo
    {
        return $this->belongsTo(GraphTeam::class, 'team_ref');
    }

    public function backupTeam(): BelongsTo
    {
        return $this->belongsTo(GraphTeam::class, 'backup_ref');
    }
}
PHP;

        try {
            File::put($dir.DIRECTORY_SEPARATOR.'GraphTeam.php', $team);
            File::put($dir.DIRECTORY_SEPARATOR.'GraphMember.php', $member);
            require_once $dir.DIRECTORY_SEPARATOR.'GraphTeam.php';
            require_once $dir.DIRECTORY_SEPARATOR.'GraphMember.php';

            Schema::create('graph_teams', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            });

            Schema::create('graph_members', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('team_ref');
                $table->unsignedBigInteger('backup_ref');
                $table->unsignedBigInteger('legacy_id')->nullable();
            });

            $scanner = new EloquentGraphScanner;
            $graph = $scanner->scan($dir, null, false);

            $memberId = 'EloqVizDynamic\\GraphMember';
            $teamId = 'EloqVizDynamic\\GraphTeam';
            $memberNode = collect($graph['nodes'])->firstWhere('id', $memberId);
            $this->assertIsArray($memberNode);
            $this->assertSame('ok', $memberNode['schemaStatus']);

            $belongsTo = collect($graph['edges'])->first(
                fn (array $edge): bool => $edge['from'] === $memberId
                    && $edge['to'] === $teamId
                    && $edge['type'] === 'belongsTo'
            );
            $this->assertIsArray($belongsTo);
            $this->assertSame(2, $belongsTo['multiplicity']);

            $teamRef = collect($memberNode['columns'])->firstWhere('name', 'team_ref');
            $backupRef = collect($memberNode['columns'])->firstWhere('name', 'backup_ref');
            $legacyId = collect($memberNode['columns'])->firstWhere('name', 'legacy_id');

            $this->assertIsArray($teamRef);
            $this->assertTrue($teamRef['foreignKey']);
            $this->assertSame(['belongsTo GraphTeam', 'hasMany from GraphTeam'], $teamRef['relations']);

            $this->assertIsArray($backupRef);
            $this->assertTrue($backupRef['foreignKey']);
            $this->assertSame(['belongsTo GraphTeam'], $backupRef['relations']);

            $this->assertIsArray($legacyId);
            $this->assertFalse($legacyId['foreignKey']);
            $this->assertSame([], $legacyId['relations']);

            $teamNode = collect($graph['nodes'])->firstWhere('id', $teamId);
            $this->assertIsArray($teamNode);
            $teamPrimaryKey = collect($teamNode['columns'])->firstWhere('name', 'id');
            $this->assertIsArray($teamPrimaryKey);
            $this->assertFalse($teamPrimaryKey['foreignKey']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    #[Test]
    public function it_reports_a_missing_table_without_failing_the_scan(): void
    {
        $scanner = new EloquentGraphScanner;
        $graph = $scanner->scan($this->stubScanModelsPath(), null, false);
        $post = collect($graph['nodes'])->firstWhere('id', Post::class);

        $this->assertIsArray($post);
        $this->assertSame('posts', $post['table']);
        $this->assertSame([], $post['columns']);
        $this->assertSame('missing_table', $post['schemaStatus']);
        $this->assertNotEmpty($graph['edges']);
    }

    #[Test]
    public function it_omits_columns_when_schema_inspection_is_disabled(): void
    {
        config(['eloquent-viz.include_columns' => false]);

        $scanner = new EloquentGraphScanner;
        $graph = $scanner->scan($this->stubScanModelsPath(), null, false);
        $post = collect($graph['nodes'])->firstWhere('id', Post::class);

        $this->assertIsArray($post);
        $this->assertArrayNotHasKey('columns', $post);
        $this->assertArrayNotHasKey('table', $post);
        $this->assertArrayNotHasKey('schemaStatus', $post);
    }

    #[Test]
    public function it_marks_schema_unavailable_when_the_model_connection_is_missing(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eloqviz_offline_'.uniqid('', true);
        File::makeDirectory($dir);

        $contents = <<<'PHP'
<?php

namespace EloqVizDynamic;

use Illuminate\Database\Eloquent\Model;

class GraphOffline extends Model
{
    protected $connection = 'eloqviz_missing_connection';

    protected $table = 'graph_offline';
}
PHP;

        try {
            $path = $dir.DIRECTORY_SEPARATOR.'GraphOffline.php';
            File::put($path, $contents);
            require_once $path;

            $scanner = new EloquentGraphScanner;
            $graph = $scanner->scan($dir, null, false);
            $node = collect($graph['nodes'])->firstWhere('id', 'EloqVizDynamic\\GraphOffline');

            $this->assertIsArray($node);
            $this->assertSame('graph_offline', $node['table']);
            $this->assertSame([], $node['columns']);
            $this->assertSame('unavailable', $node['schemaStatus']);
        } finally {
            File::deleteDirectory($dir);
        }
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
