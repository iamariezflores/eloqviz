<?php

namespace EloqViz\EloquentViz\Console;

use EloqViz\EloquentViz\EloquentGraphScanner;
use Illuminate\Console\Command;

class EloquentGraphCommand extends Command
{
    protected $signature = 'eloquent:graph {--path=* : Absolute or project-relative models directory (repeatable; overrides config)}';

    protected $description = 'Scan Eloquent models and print a JSON graph { nodes: string[], edges: {from,to,type}[] }';

    public function handle(EloquentGraphScanner $scanner): int
    {
        if ($this->laravel->isProduction()) {
            $this->error(
                'The eloquent:graph command is disabled when the application environment is production.',
            );

            return self::FAILURE;
        }

        $rawPaths = $this->option('path');
        $segments = [];

        if (is_string($rawPaths) && $rawPaths !== '') {
            $segments = [$rawPaths];
        } elseif (is_array($rawPaths)) {
            foreach ($rawPaths as $p) {
                if (is_string($p) && $p !== '') {
                    $segments[] = $p;
                }
            }
        }

        $resolved = [];

        foreach ($segments as $path) {
            $absolute = str_starts_with($path, DIRECTORY_SEPARATOR)
                || str_starts_with($path, '/')
                || (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':' && ($path[2] === '\\' || $path[2] === '/'))
                ? $path
                : base_path($path);

            if (! is_dir($absolute)) {
                $this->error("Directory not found: {$absolute}");

                return self::FAILURE;
            }

            $resolved[] = $absolute;
        }

        $graph = $scanner->scan(
            $resolved === [] ? null : array_values(array_unique($resolved)),
            null,
            false,
        );

        $this->line(json_encode($graph, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
