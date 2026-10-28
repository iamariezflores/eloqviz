<?php

namespace EloqViz\EloquentViz\Tests;

use EloqViz\EloquentViz\EditorLink;
use EloqViz\EloquentViz\EloquentGraphScanner;
use EloqViz\EloquentViz\Tests\Fixtures\GraphModels\GraphDupHost;
use EloqViz\EloquentViz\Tests\Fixtures\GraphModels\GraphDupPost;
use EloqViz\EloquentViz\Tests\Fixtures\IdeModels\GraphAuthor;
use EloqViz\EloquentViz\Tests\Fixtures\IdeModels\GraphSubject;
use EloqViz\EloquentViz\Tests\Stubs\ScanModels\Post;
use EloqViz\EloquentViz\Tests\Stubs\ScanModels\User;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;

class EditorLinkTest extends TestCase
{
    #[Test]
    public function it_omits_editor_urls_until_an_editor_is_configured(): void
    {
        $graph = (new EloquentGraphScanner)->scan($this->stubScanModelsPath(), null, false);
        $post = collect($graph['nodes'])->firstWhere('id', Post::class);

        $this->assertIsArray($post);
        $this->assertSame((new ReflectionClass(Post::class))->getStartLine(), $post['line']);
        $this->assertArrayNotHasKey('editorUrl', $post);

        $edge = collect($graph['edges'])->first(
            fn (array $edge): bool => $edge['from'] === Post::class && $edge['to'] === User::class && $edge['type'] === 'belongsTo'
        );
        $this->assertIsArray($edge);
        $this->assertSame('user', $edge['methods'][0]['name']);
        $this->assertSame((new ReflectionMethod(Post::class, 'user'))->getStartLine(), $edge['methods'][0]['line']);
        $this->assertArrayNotHasKey('editorUrl', $edge['methods'][0]);
    }

    #[Test]
    public function it_links_a_model_and_relation_method_for_cursor(): void
    {
        $this->app['config']->set('eloquent-viz.ide', 'cursor');

        $graph = (new EloquentGraphScanner)->scan($this->stubScanModelsPath(), null, false);
        $line = (new ReflectionClass(Post::class))->getStartLine();
        $post = collect($graph['nodes'])->firstWhere('id', Post::class);

        $this->assertIsArray($post);
        $this->assertStringStartsWith('cursor://file/', $post['editorUrl']);
        $this->assertStringContainsString('Post.php:'.$line.':1', $post['editorUrl']);

        $methodLine = (new ReflectionMethod(Post::class, 'user'))->getStartLine();
        $edge = collect($graph['edges'])->first(
            fn (array $edge): bool => $edge['from'] === Post::class && $edge['to'] === User::class && $edge['type'] === 'belongsTo'
        );
        $this->assertIsArray($edge);
        $this->assertStringContainsString('Post.php:'.$methodLine.':1', $edge['methods'][0]['editorUrl']);
    }

    #[Test]
    public function it_keeps_every_method_when_edges_are_deduped(): void
    {
        $this->app['config']->set('eloquent-viz.ide', 'vscode');

        $graph = (new EloquentGraphScanner)->scan($this->graphFixturesPath(), null, false);
        $edge = collect($graph['edges'])->first(
            fn (array $edge): bool => $edge['from'] === GraphDupHost::class
                && $edge['to'] === GraphDupPost::class
                && $edge['type'] === 'hasMany'
        );

        $this->assertIsArray($edge);
        $this->assertSame(2, $edge['multiplicity']);
        $this->assertSame(
            ['articles', 'posts'],
            array_column($edge['methods'], 'name')
        );
        $this->assertStringStartsWith('vscode://file/', $edge['methods'][0]['editorUrl']);
    }

    #[Test]
    public function it_opens_a_trait_method_in_the_trait_file(): void
    {
        $this->app['config']->set('eloquent-viz.ide', 'cursor');

        $graph = (new EloquentGraphScanner)->scan($this->ideModelsPath(), null, false);
        $edge = collect($graph['edges'])->first(
            fn (array $edge): bool => $edge['from'] === GraphAuthor::class
                && $edge['to'] === GraphSubject::class
                && $edge['type'] === 'hasMany'
        );

        $this->assertIsArray($edge);
        $this->assertSame('comments', $edge['methods'][0]['name']);
        $this->assertStringContainsString('GraphComments.php', $edge['methods'][0]['file']);
        $this->assertSame(
            (new ReflectionMethod(GraphAuthor::class, 'comments'))->getStartLine(),
            $edge['methods'][0]['line']
        );
    }

    #[Test]
    public function it_rewrites_application_paths_and_encodes_a_phpstorm_url(): void
    {
        $base = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, realpath(base_path()) ?: base_path()), DIRECTORY_SEPARATOR);
        $file = $base.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR.'My Post.php';
        $this->app['config']->set('eloquent-viz.project_root', 'D:\host\demo');
        $this->app['config']->set('eloquent-viz.ide', 'phpstorm');

        $url = (new EditorLink)->url($file, 42);

        $this->assertSame(
            'phpstorm://open?file='.rawurlencode('D:/host/demo/app/Models/My Post.php').'&line=42',
            $url
        );
    }

    #[Test]
    public function it_uses_a_custom_url_template(): void
    {
        $this->app['config']->set('eloquent-viz.ide', 'custom');
        $this->app['config']->set('eloquent-viz.ide_url', 'myide://open?path={path}&line={line}');

        $url = (new EditorLink)->url(base_path().DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR.'Post.php', 9);

        $this->assertNotNull($url);
        $this->assertStringStartsWith('myide://open?path=', $url);
        $this->assertStringContainsString('Post.php&line=9', str_replace('%2F', '/', $url));
    }

    private function stubScanModelsPath(): string
    {
        return realpath(__DIR__.'/Stubs/ScanModels') ?: __DIR__.'/Stubs/ScanModels';
    }

    private function graphFixturesPath(): string
    {
        return realpath(__DIR__.'/Fixtures/GraphModels') ?: __DIR__.'/Fixtures/GraphModels';
    }

    private function ideModelsPath(): string
    {
        return realpath(__DIR__.'/Fixtures/IdeModels') ?: __DIR__.'/Fixtures/IdeModels';
    }
}
