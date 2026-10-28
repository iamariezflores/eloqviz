<?php

declare(strict_types=1);

namespace EloqViz\EloquentViz;

/**
 * Builds a local editor URL for a PHP file and line.
 *
 * The editor is chosen with eloquent-viz.ide. Paths inside the application
 * are rewritten from base_path() to eloquent-viz.project_root when that
 * override is set, so a container path can point at the directory the IDE has open.
 */
final class EditorLink
{
    public function url(?string $absolutePath, ?int $line): ?string
    {
        if ($absolutePath === null || $absolutePath === '' || $line === null || $line < 1) {
            return null;
        }

        $template = $this->template();
        if ($template === null) {
            return null;
        }

        $forward = str_replace('\\', '/', $this->hostPath($absolutePath));

        return strtr($template, [
            '{path}' => $this->encodePath($forward),
            '{path_encoded}' => rawurlencode($forward),
            '{line}' => (string) $line,
            '{column}' => '1',
        ]);
    }

    private function template(): ?string
    {
        $ide = config('eloquent-viz.ide');
        if (! is_string($ide) || $ide === '' || $ide === 'off') {
            return null;
        }

        return match ($ide) {
            'cursor' => 'cursor://file/{path}:{line}:{column}',
            'vscode' => 'vscode://file/{path}:{line}:{column}',
            'phpstorm' => 'phpstorm://open?file={path_encoded}&line={line}',
            'custom' => $this->customTemplate(),
            default => null,
        };
    }

    private function customTemplate(): ?string
    {
        $template = config('eloquent-viz.ide_url');
        if (! is_string($template) || $template === '') {
            return null;
        }

        if (! str_contains($template, '{path}') && ! str_contains($template, '{path_encoded}')) {
            return null;
        }

        return $template;
    }

    private function hostPath(string $absolutePath): string
    {
        $absolute = $this->normalize($absolutePath);
        $root = config('eloquent-viz.project_root');
        if (! is_string($root) || trim($root) === '') {
            return $absolute;
        }

        $base = $this->normalize(realpath(base_path()) ?: base_path());
        $root = $this->normalize($root);
        if ($absolute === $base) {
            return $root;
        }

        $prefix = $base.DIRECTORY_SEPARATOR;
        if (! str_starts_with($absolute, $prefix)) {
            return $absolute;
        }

        return $root.substr($absolute, strlen($base));
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    private function encodePath(string $forward): string
    {
        $prefix = '';
        $rest = $forward;
        if (preg_match('/^[A-Za-z]:\//', $forward) === 1) {
            $prefix = substr($forward, 0, 2);
            $rest = substr($forward, 2);
        }

        $encoded = array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', $rest),
        );

        return $prefix.implode('/', $encoded);
    }
}
