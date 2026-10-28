<?php

namespace EloqViz\EloquentViz\Tests;

use EloqViz\EloquentViz\EloquentGraphScanner;
use EloqViz\EloquentViz\Tests\Fixtures\MorphModels\MorphComment;
use EloqViz\EloquentViz\Tests\Fixtures\MorphModels\MorphPost;
use EloqViz\EloquentViz\Tests\Fixtures\MorphModels\MorphVideo;
use EloqViz\EloquentViz\Tests\Stubs\ScanModels\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\Test;

class MorphGraphScannerTest extends TestCase
{
    private function morphModelsPath(): string
    {
        return realpath(__DIR__.'/Fixtures/MorphModels') ?: __DIR__.'/Fixtures/MorphModels';
    }

    #[Test]
    public function it_leaves_an_unmapped_morph_to_without_a_self_edge(): void
    {
        $graph = (new EloquentGraphScanner)->scan($this->morphModelsPath(), null, false);

        $morphTo = collect($graph['edges'])->where('from', MorphComment::class)->where('type', 'morphTo');

        $this->assertCount(1, $morphTo);
        $this->assertNull($morphTo->first()['to']);
        $this->assertFalse(
            $morphTo->contains(fn (array $edge): bool => $edge['to'] === MorphComment::class)
        );

        $this->assertNotNull(collect($graph['edges'])->first(
            fn (array $edge): bool => $edge['from'] === MorphPost::class
                && $edge['to'] === MorphComment::class
                && $edge['type'] === 'morphMany'
        ));
    }

    #[Test]
    public function it_draws_morph_to_edges_from_the_runtime_morph_map(): void
    {
        Relation::morphMap([
            'post' => MorphPost::class,
            'video' => MorphVideo::class,
        ], false);

        $graph = (new EloquentGraphScanner)->scan($this->morphModelsPath(), null, false);
        $edges = $this->morphToByTarget($graph['edges']);

        $this->assertSame([MorphPost::class, MorphVideo::class], array_keys($edges));
        $this->assertSame('post', $edges[MorphPost::class]['smart']['morphAlias']);
        $this->assertSame('MorphPost', $edges[MorphPost::class]['smart']['morphClass']);
        $this->assertSame('commentable_type', $edges[MorphPost::class]['smart']['morphTypeColumn']);
        $this->assertSame('video', $edges[MorphVideo::class]['smart']['morphAlias']);
    }

    #[Test]
    public function it_uses_the_configured_morph_map_instead_of_the_registry(): void
    {
        Relation::morphMap([
            'post' => MorphPost::class,
        ], false);
        $this->app['config']->set('eloquent-viz.morph_map', [
            'video' => MorphVideo::class,
            'missing' => 'NotARealModel',
        ]);

        $graph = (new EloquentGraphScanner)->scan($this->morphModelsPath(), null, false);

        $this->assertSame(
            [MorphVideo::class],
            array_keys($this->morphToByTarget($graph['edges']))
        );
    }

    #[Test]
    public function it_limits_morph_to_edges_with_a_per_relation_target_list(): void
    {
        Relation::morphMap([
            'post' => MorphPost::class,
            'video' => MorphVideo::class,
        ], false);
        $this->app['config']->set('eloquent-viz.morph_targets', [
            MorphComment::class => [
                'commentable' => [MorphPost::class],
            ],
        ]);

        $graph = (new EloquentGraphScanner)->scan($this->morphModelsPath(), null, false);
        $edges = $this->morphToByTarget($graph['edges']);

        $this->assertSame([MorphPost::class], array_keys($edges));
        $this->assertArrayNotHasKey('morphAlias', $edges[MorphPost::class]['smart']);
        $this->assertNotNull(collect($graph['edges'])->first(
            fn (array $edge): bool => $edge['from'] === MorphVideo::class
                && $edge['to'] === MorphComment::class
                && $edge['type'] === 'morphMany'
        ));
    }

    #[Test]
    public function it_adds_a_mapped_model_that_lives_outside_the_scan_paths(): void
    {
        $this->app['config']->set('eloquent-viz.morph_map', [
            User::class,
        ]);

        $graph = (new EloquentGraphScanner)->scan($this->morphModelsPath(), null, false);

        $this->assertContains(User::class, $this->graphNodeIds($graph));
        $this->assertNotNull(collect($graph['edges'])->first(
            fn (array $edge): bool => $edge['from'] === MorphComment::class
                && $edge['to'] === User::class
                && $edge['type'] === 'morphTo'
        ));
        $this->assertTrue(collect($graph['warnings'])->contains(
            fn (array $warning): bool => $warning['code'] === 'graph.external_model' && $warning['target'] === User::class
        ));
    }

    /**
     * @param  list<array{from: string, to: string|null, type: string, smart?: array<string, string>}>  $edges
     * @return array<string, array{from: string, to: string|null, type: string, smart?: array<string, string>}>
     */
    private function morphToByTarget(array $edges): array
    {
        $out = [];
        foreach ($edges as $edge) {
            if ($edge['from'] !== MorphComment::class || $edge['type'] !== 'morphTo' || ! is_string($edge['to'])) {
                continue;
            }

            $out[$edge['to']] = $edge;
        }

        ksort($out, SORT_STRING);

        return $out;
    }
}
