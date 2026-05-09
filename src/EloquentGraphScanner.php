<?php

namespace EloqViz\EloquentViz;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Throwable;

/**
 * Scans PHP model classes and reflects Eloquent relationships into a graph payload (graphVersion 2).
 *
 * Nodes use stable {@see class-string} ids (FQCN) with human labels from {@see config('eloquent-viz.node_display')}.
 * Related models are resolved by calling each relationship method once on a bare instance; that
 * work runs inside {@see Model::withoutEvents()} and {@see \Illuminate\Database\Connection::pretend()}
 * on the scanned model's connection so SQL is not executed. Relationship methods that query other
 * connection names are not covered by that pretend scope.
 */
class EloquentGraphScanner
{
    /**
     * Discover Eloquent models under configured paths (or overrides) and build a directed graph.
     *
     * @param  string|list<string>|null  $modelsPaths  Null reads config eloquent-viz.models_paths (and legacy models_directory).
     * @param  string|null  $selectedModel  FQCN node id, or a unique basename match; null with $applyDefaultUserFocus uses a single User model when unambiguous.
     * @param  bool  $applyDefaultUserFocus  When $selectedModel is null/empty and exactly one User exists, restrict to that component.
     *
     * @return array{
     *     graphVersion: 2,
     *     nodes: list<array{id: string, label: string, fqcn: string, file?: string}>,
     *     edges: list<array{from: string, to: string|null, type: string, smart?: array<string, string>, multiplicity?: positive-int}>,
     *     warnings: list<array{code: string, message: string, ...}>,
     *     skippedRelations: list<array{model: string, method: string, reason: string}>
     * }
     */
    public function scan(array|string|null $modelsPaths = null, ?string $selectedModel = null, bool $applyDefaultUserFocus = true): array
    {
        $directories = $this->resolvedScanDirectories($modelsPaths);
        if ($directories === []) {
            return $this->emptyGraphPayload();
        }

        $fqcnToFile = $this->discoverModelFqcnToFileMap($directories);
        $scannedFqcns = array_keys($fqcnToFile);
        if ($scannedFqcns === []) {
            return $this->emptyGraphPayload();
        }

        $diagnostics = $this->diagnosticsEnabled();
        /** @var list<array{code: string, message: string}> $warnings */
        $warnings = [];
        /** @var list<array{model: string, method: string, reason: string}> $skippedRelations */
        $skippedRelations = [];

        $basenameIndex = $this->buildBasenameToFqcnsIndex($scannedFqcns);

        /** @var array<string, true> $referencedExternal */
        $referencedExternal = [];

        /** @var list<array{from: string, to: string|null, type: string, smart?: array<string, string>}> $edges */
        $edges = [];

        foreach ($scannedFqcns as $fromFqcn) {
            $reflection = new ReflectionClass($fromFqcn);
            if ($reflection->isAbstract()) {
                continue;
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic()) {
                    continue;
                }

                if (! $this->looksLikeUserDefinedRelationMethod($method)) {
                    continue;
                }

                $hintBasename = $this->relationReturnClassName($method)
                    ?? $this->relationReturnClassNameFromDocblock($method);

                $described = $this->describeRelationIfAny($reflection, $method, $hintBasename, $skippedRelations, $diagnostics);
                if ($described === null) {
                    continue;
                }

                $toFqcn = $described['targetFqcn'];
                if ($toFqcn !== null && ! isset($fqcnToFile[$toFqcn]) && ! isset($referencedExternal[$toFqcn])) {
                    $referencedExternal[$toFqcn] = true;
                    if ($diagnostics) {
                        $warnings[] = [
                            'code' => 'graph.external_model',
                            'message' => 'Related model is outside configured scan paths: '.$toFqcn,
                            'target' => $toFqcn,
                        ];
                    }
                }

                $edge = [
                    'from' => $fromFqcn,
                    'to' => $toFqcn,
                    'type' => $this->relationTypeToGraph($described['relationBasename']),
                ];

                if ($described['smart'] !== []) {
                    $edge['smart'] = $described['smart'];
                }

                $edges[] = $edge;
            }
        }

        /** @var list<string> $nodeIds */
        $nodeIds = array_values(array_unique(array_merge($scannedFqcns, array_keys($referencedExternal))));
        sort($nodeIds, SORT_STRING);

        $basenameDupes = $this->basenamesWithDuplicates($nodeIds);

        /** @var list<array{id: string, label: string, fqcn: string, file?: string}> $nodeRows */
        $nodeRows = [];
        foreach ($nodeIds as $id) {
            $nodeRows[] = $this->buildNodeDescriptor($id, $fqcnToFile[$id] ?? null, $basenameDupes);
        }

        $resolution = $this->resolveSelectedModelId(
            $selectedModel,
            $nodeIds,
            $basenameIndex,
            $applyDefaultUserFocus,
            $diagnostics,
            $warnings
        );
        $selectedId = $resolution['id'];

        if ($selectedId !== null) {
            [$nodeRows, $edges] = $this->filterGraphToModelComponentById($nodeRows, $edges, $selectedId);
        }

        return [
            'graphVersion' => 2,
            'nodes' => $nodeRows,
            'edges' => $this->dedupeEdges($edges),
            'warnings' => $warnings,
            'skippedRelations' => $skippedRelations,
        ];
    }

    /**
     * Match `?model=` query to a node id (FQCN preferred; basename when unique).
     *
     * @param  list<array{id: string, label: string, fqcn: string, file?: string}>  $nodeRows
     */
    public function matchModelSelection(?string $query, array $nodeRows): ?string
    {
        if ($query === null || $query === '') {
            return null;
        }

        $ids = array_column($nodeRows, 'id');
        $basenameIndex = $this->buildBasenameToFqcnsIndex($ids);
        /** @var list<array{code: string, message: string}> $warnings */
        $warnings = [];

        return $this->resolveSelectedModelId($query, $ids, $basenameIndex, false, $this->diagnosticsEnabled(), $warnings)['id'];
    }

    /**
     * Default graph focus: single {@code User} basename in the scan set.
     *
     * @param  list<array{id: string, label: string, fqcn: string, file?: string}>  $nodeRows
     */
    public function defaultFocusedModelId(array $nodeRows): ?string
    {
        $ids = array_column($nodeRows, 'id');
        $basenameIndex = $this->buildBasenameToFqcnsIndex($ids);
        $users = $basenameIndex['User'] ?? [];

        return count($users) === 1 ? $users[0] : null;
    }

    /**
     * @return array{graphVersion: 2, nodes: array{}, edges: array{}, warnings: array{}, skippedRelations: array{}}
     */
    private function emptyGraphPayload(): array
    {
        return [
            'graphVersion' => 2,
            'nodes' => [],
            'edges' => [],
            'warnings' => [],
            'skippedRelations' => [],
        ];
    }

    private function diagnosticsEnabled(): bool
    {
        return (bool) config('eloquent-viz.scan_diagnostics', true);
    }

    private function includeFilePaths(): bool
    {
        return (bool) config('eloquent-viz.include_file_paths', true);
    }

    private function nodeDisplayMode(): string
    {
        $mode = config('eloquent-viz.node_display', 'short');
        if (! is_string($mode)) {
            return 'short';
        }

        return in_array($mode, ['short', 'fqcn', 'namespace_suffix'], true) ? $mode : 'short';
    }

    /**
     * @param  list<string>  $nodeIds
     * @return list<string>
     */
    private function basenamesWithDuplicates(array $nodeIds): array
    {
        $counts = [];
        foreach ($nodeIds as $id) {
            $b = class_basename($id);
            $counts[$b] = ($counts[$b] ?? 0) + 1;
        }

        $dupes = [];
        foreach ($counts as $basename => $count) {
            if ($count > 1) {
                $dupes[] = $basename;
            }
        }

        return $dupes;
    }

    /**
     * @param  list<string>  $nodeIds
     * @return array<string, list<string>>
     */
    private function buildBasenameToFqcnsIndex(array $nodeIds): array
    {
        /** @var array<string, list<string>> $map */
        $map = [];
        foreach ($nodeIds as $fqcn) {
            $map[class_basename($fqcn)][] = $fqcn;
        }

        foreach ($map as &$list) {
            sort($list, SORT_STRING);
        }

        return $map;
    }

    /**
     * @param  list<string>  $nodeIds
     * @param  array<string, list<string>>  $basenameIndex
     * @param  list<array{code: string, message: string}>  $warnings
     * @return array{id: ?string}
     */
    private function resolveSelectedModelId(
        ?string $selectedModel,
        array $nodeIds,
        array $basenameIndex,
        bool $applyDefaultUserFocus,
        bool $diagnostics,
        array &$warnings
    ): array {
        if ($selectedModel !== null && $selectedModel !== '') {
            if (in_array($selectedModel, $nodeIds, true)) {
                return ['id' => $selectedModel];
            }

            $matches = $basenameIndex[$selectedModel] ?? [];
            if (count($matches) === 1) {
                return ['id' => $matches[0]];
            }

            if (count($matches) > 1) {
                if ($diagnostics) {
                    $warnings[] = [
                        'code' => 'graph.ambiguous_basename',
                        'message' => 'Multiple models share basename "'.$selectedModel.'"; pass the full class name as ?model=.',
                        'candidates' => $matches,
                    ];
                }

                return ['id' => $matches[0]];
            }

            return ['id' => null];
        }

        if (! $applyDefaultUserFocus) {
            return ['id' => null];
        }

        $users = $basenameIndex['User'] ?? [];
        if (count($users) === 1) {
            return ['id' => $users[0]];
        }

        if (count($users) > 1 && $diagnostics) {
            $warnings[] = [
                'code' => 'graph.ambiguous_default_user',
                'message' => 'Multiple User models found; default graph focus skipped. Pick a root model or pass ?model= with a full class name.',
                'candidates' => $users,
            ];
        }

        return ['id' => null];
    }

    /**
     * @param  list<array{id: string, label: string, fqcn: string, file?: string}>  $nodeRows
     * @param  list<array{from: string, to: string|null, type: string, smart?: array<string, string>}>  $edges
     * @return array{0: list<array{id: string, label: string, fqcn: string, file?: string}>, 1: list<array{from: string, to: string|null, type: string, smart?: array<string, string>}>}
     */
    private function filterGraphToModelComponentById(array $nodeRows, array $edges, string $selectedId): array
    {
        $nodeIds = array_column($nodeRows, 'id');

        /** @var array<string, list<string>> $adjacency */
        $adjacency = [];
        foreach ($nodeIds as $node) {
            $adjacency[$node] = [];
        }

        foreach ($edges as $edge) {
            $from = $edge['from'];
            $to = $edge['to'] ?? null;
            if ($to === null || ! isset($adjacency[$from], $adjacency[$to])) {
                continue;
            }

            $adjacency[$from][] = $to;
            $adjacency[$to][] = $from;
        }

        $queue = [$selectedId];
        /** @var array<string, true> $visited */
        $visited = [$selectedId => true];

        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === null) {
                break;
            }

            foreach ($adjacency[$current] ?? [] as $neighbor) {
                if (isset($visited[$neighbor])) {
                    continue;
                }

                $visited[$neighbor] = true;
                $queue[] = $neighbor;
            }
        }

        $filteredRows = array_values(array_filter(
            $nodeRows,
            static fn (array $row): bool => isset($visited[$row['id']])
        ));

        $filteredEdges = array_values(array_filter(
            $edges,
            static function (array $edge) use ($visited): bool {
                $to = $edge['to'] ?? null;

                return isset($visited[$edge['from']]) && $to !== null && isset($visited[$to]);
            }
        ));

        usort($filteredRows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return [$filteredRows, $filteredEdges];
    }

    /**
     * @param  list<string>  $directories
     * @return array<string, string>  fqcn => absolute file path
     */
    private function discoverModelFqcnToFileMap(array $directories): array
    {
        /** @var array<string, string> $map */
        $map = [];

        foreach ($directories as $directory) {
            foreach (File::allFiles($directory) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getRealPath();
                if ($path === false) {
                    continue;
                }

                $fqcn = $this->fqcnFromFile($path);
                if ($fqcn === null || ! class_exists($fqcn)) {
                    continue;
                }

                if (! is_subclass_of($fqcn, Model::class)) {
                    continue;
                }

                try {
                    if ((new ReflectionClass($fqcn))->isAbstract()) {
                        continue;
                    }
                } catch (Throwable) {
                    continue;
                }

                if (! isset($map[$fqcn])) {
                    $map[$fqcn] = $path;
                }
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $basenameDupes  basenames that appear more than once in the graph
     * @return array{id: string, label: string, fqcn: string, file?: string}
     */
    private function buildNodeDescriptor(string $fqcn, ?string $absoluteFile, array $basenameDupes): array
    {
        $mode = $this->nodeDisplayMode();
        $row = [
            'id' => $fqcn,
            'fqcn' => $fqcn,
            'label' => $this->computeNodeLabel($fqcn, $mode, $basenameDupes),
        ];

        if ($this->includeFilePaths() && $absoluteFile !== null && $absoluteFile !== '') {
            $rel = $this->relativizeToBasePath($absoluteFile);
            if ($rel !== '') {
                $row['file'] = $rel;
            }
        }

        return $row;
    }

    /**
     * @param  list<string>  $basenameDupes
     */
    private function computeNodeLabel(string $fqcn, string $mode, array $basenameDupes): string
    {
        $basename = class_basename($fqcn);
        $ns = $this->namespacePrefix($fqcn);

        return match ($mode) {
            'fqcn' => $fqcn,
            'namespace_suffix' => $basename.($ns !== '' ? ' · '.$ns : ''),
            default => in_array($basename, $basenameDupes, true)
                ? $basename.($ns !== '' ? ' · '.$ns : '')
                : $basename,
        };
    }

    private function namespacePrefix(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? '' : substr($fqcn, 0, $pos);
    }

    private function relativizeToBasePath(string $absolutePath): string
    {
        $base = realpath(base_path()) ?: base_path();
        $base = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base), DIRECTORY_SEPARATOR);
        $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absolutePath), DIRECTORY_SEPARATOR);

        if ($base !== '' && str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            return ltrim(substr($path, strlen($base)), DIRECTORY_SEPARATOR);
        }

        return $absolutePath;
    }

    /**
     * Collapse identical directed edges (same from, to, type) so e.g. several morphMany methods
     * become one edge with {@see $multiplicity} for labels like "morphMany ×3".
     *
     * @param  list<array{from: string, to: string|null, type: string, smart?: array<string, string>}>  $edges
     * @return list<array{from: string, to: string|null, type: string, smart?: array<string, string>, multiplicity?: positive-int}>
     */
    private function dedupeEdges(array $edges): array
    {
        /** @var array<string, array{from: string, to: string|null, type: string, smart?: array<string, string>, multiplicity: int}> $byKey */
        $byKey = [];

        foreach ($edges as $edge) {
            $to = $edge['to'] ?? null;
            $key = $edge['from']."\0".($to ?? '')."\0".$edge['type'];

            if (! isset($byKey[$key])) {
                $edge['multiplicity'] = 1;
                $byKey[$key] = $edge;

                continue;
            }

            $byKey[$key]['multiplicity']++;
        }

        $out = [];
        foreach ($byKey as $edge) {
            if ($edge['multiplicity'] === 1) {
                unset($edge['multiplicity']);
            }

            $out[] = $edge;
        }

        return array_values($out);
    }

    private function relationTypeToGraph(string $relationClassBasename): string
    {
        return lcfirst($relationClassBasename);
    }

    /**
     * @param  string|list<string>|null  $pathsOverride
     * @return list<string>
     */
    private function resolvedScanDirectories(array|string|null $pathsOverride): array
    {
        /** @var list<string> $raw */
        $raw = [];

        if ($pathsOverride !== null) {
            $raw = is_array($pathsOverride) ? $pathsOverride : [$pathsOverride];
        } else {
            /** @var mixed $legacyDir */
            $legacyDir = config('eloquent-viz.models_directory');

            // Prefer legacy singular key when still present (older published configs).
            if (is_string($legacyDir) && $legacyDir !== '') {
                $resolved = $this->normalizePotentialDirectoryPath($legacyDir);

                return is_dir($resolved) ? [$resolved] : [];
            }

            /** @var mixed $fromConfig */
            $fromConfig = config('eloquent-viz.models_paths', []);
            if (is_array($fromConfig) && $fromConfig !== []) {
                foreach ($fromConfig as $path) {
                    if (is_string($path) && $path !== '') {
                        $raw[] = $path;
                    }
                }
            } else {
                $default = app_path('Models');
                if (is_string($default) && $default !== '') {
                    $raw[] = $default;
                }
            }
        }

        $out = [];

        foreach ($raw as $path) {
            $resolved = $this->normalizePotentialDirectoryPath($path);
            if ($resolved !== '' && is_dir($resolved)) {
                $out[] = $resolved;
            }
        }

        return array_values(array_unique($out));
    }

    private function normalizePotentialDirectoryPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (! $this->pathLooksAbsolute($path)) {
            $path = base_path($path);
        }

        $real = realpath($path);

        return $real !== false ? $real : $path;
    }

    private function pathLooksAbsolute(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':' && ($path[2] === '\\' || $path[2] === '/'));
    }

    private function fqcnFromFile(string $path): ?string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $namespace = null;
        $class = null;

        if (preg_match('/namespace\s+([^;]+)\s*;/', $contents, $m)) {
            $namespace = trim($m[1]);
        }

        if (preg_match('/\bclass\s+(\w+)/', $contents, $m)) {
            $class = $m[1];
        }

        if ($class === null) {
            return null;
        }

        return $namespace !== null && $namespace !== ''
            ? $namespace.'\\'.$class
            : $class;
    }

    private function looksLikeUserDefinedRelationMethod(ReflectionMethod $method): bool
    {
        if ($method->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        $declaring = $method->getDeclaringClass()->getName();

        if ($declaring === Model::class) {
            return false;
        }

        return ! str_starts_with($method->getName(), '__');
    }

    private function relationReturnClassName(ReflectionMethod $method): ?string
    {
        $type = $method->getReturnType();
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionNamedType) {
            return $this->relationBasenameFromNamedType($type);
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $inner) {
                if ($inner instanceof ReflectionNamedType) {
                    $basename = $this->relationBasenameFromNamedType($inner);
                    if ($basename !== null) {
                        return $basename;
                    }
                }
            }

            return null;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $inner) {
                if ($inner instanceof ReflectionNamedType) {
                    $basename = $this->relationBasenameFromNamedType($inner);
                    if ($basename !== null) {
                        return $basename;
                    }
                }
            }
        }

        return null;
    }

    private function relationBasenameFromNamedType(ReflectionNamedType $type): ?string
    {
        if ($type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();
        if (! class_exists($name) || ! is_subclass_of($name, Relation::class)) {
            return null;
        }

        return class_basename($name);
    }

    /**
     * Parse PHPDoc "@return" when native return types are omitted (common in older apps).
     */
    private function relationReturnClassNameFromDocblock(ReflectionMethod $method): ?string
    {
        $doc = $method->getDocComment();
        if ($doc === false || $doc === '') {
            return null;
        }

        if (! preg_match('/@return\s+(.+)/', $doc, $m)) {
            return null;
        }

        $line = trim(preg_split('/\R/', trim($m[1]), 2)[0] ?? '');
        if ($line === '') {
            return null;
        }

        $firstUnion = trim(explode('|', $line)[0]);
        $firstUnion = preg_replace('/<[^>]*>/', '', $firstUnion) ?? $firstUnion;
        $firstUnion = trim($firstUnion);
        $firstUnion = ltrim($firstUnion, '\\');

        if ($firstUnion === '') {
            return null;
        }

        $candidates = [$firstUnion];
        if (! str_contains($firstUnion, '\\')) {
            $candidates[] = 'Illuminate\\Database\\Eloquent\\Relations\\'.$firstUnion;
        }

        foreach ($candidates as $class) {
            if (! class_exists($class)) {
                continue;
            }
            if (is_subclass_of($class, Relation::class)) {
                return class_basename($class);
            }
        }

        return null;
    }

    /**
     * Resolve relation type and related model by invoking the method once (inside pretend + withoutEvents).
     * Native or PHPDoc return hints set the relation "type" when present; otherwise the runtime relation class is used.
     *
     * @param  ReflectionClass<object>  $modelReflection
     * @param  list<array{model: string, method: string, reason: string}>  $skippedRelations
     * @return array{relationBasename: string, targetBasename: string|null, targetFqcn: string|null, smart: array<string, string>}|null
     */
    private function describeRelationIfAny(
        ReflectionClass $modelReflection,
        ReflectionMethod $method,
        ?string $hintBasename,
        array &$skippedRelations,
        bool $diagnostics
    ): ?array {
        $model = $this->makeBareModelForRelationProbe($modelReflection);
        if (! $model instanceof Model) {
            return null;
        }

        $relation = null;

        $this->whileGuardingAgainstAccidentalQueries($model, function () use ($model, $method, &$relation): void {
            try {
                $candidate = $this->callRelationMethod($model, $method->getName());
                $relation = $candidate instanceof Relation ? $candidate : null;
            } catch (Throwable) {
                $relation = null;
            }
        });

        if (! $relation instanceof Relation) {
            if ($diagnostics && $hintBasename !== null) {
                $skippedRelations[] = [
                    'model' => $modelReflection->getName(),
                    'method' => $method->getName(),
                    'reason' => 'Advertises a relation return type but did not return a Relation (or the call threw).',
                ];
            }

            return null;
        }

        $relationBasename = $hintBasename ?? class_basename($relation::class);

        $targetFqcn = null;
        $targetBasename = null;

        try {
            $related = $relation->getRelated();
            $targetFqcn = $related::class;
            $targetBasename = class_basename($targetFqcn);
        } catch (Throwable) {
            $targetFqcn = null;
            $targetBasename = null;
        }

        $smart = $this->smartEdgeMeta($relation);

        return [
            'relationBasename' => $relationBasename,
            'targetBasename' => $targetBasename,
            'targetFqcn' => $targetFqcn,
            'smart' => $smart,
        ];
    }

    /**
     * Foreign keys, pivot table, and polymorphic columns for edge labels / API consumers.
     *
     * @return array<string, string>
     */
    private function smartEdgeMeta(Relation $relation): array
    {
        $meta = [];

        try {
            if ($relation instanceof MorphToMany) {
                $meta['pivotTable'] = $relation->getTable();
                $meta['foreignPivotKey'] = $relation->getForeignPivotKeyName();
                $meta['relatedPivotKey'] = $relation->getRelatedPivotKeyName();
                $meta['morphTypeColumn'] = $relation->getMorphType();
                $meta['morphClass'] = $this->shortMorphClass($relation->getMorphClass());

                return $this->dropEmptySmartValues($meta);
            }

            if ($relation instanceof BelongsToMany) {
                $meta['pivotTable'] = $relation->getTable();
                $meta['foreignPivotKey'] = $relation->getForeignPivotKeyName();
                $meta['relatedPivotKey'] = $relation->getRelatedPivotKeyName();

                return $this->dropEmptySmartValues($meta);
            }

            if ($relation instanceof MorphTo) {
                $meta['morphTypeColumn'] = $relation->getMorphType();
                $meta['foreignKey'] = $relation->getForeignKeyName();
                $meta['ownerKey'] = (string) $relation->getOwnerKeyName();

                return $this->dropEmptySmartValues($meta);
            }

            if ($relation instanceof MorphOneOrMany) {
                $meta['morphTypeColumn'] = $relation->getMorphType();
                $meta['foreignKey'] = $relation->getForeignKeyName();
                $meta['localKey'] = $relation->getLocalKeyName();
                $meta['morphClass'] = $this->shortMorphClass($relation->getMorphClass());

                return $this->dropEmptySmartValues($meta);
            }

            if ($relation instanceof HasOneOrManyThrough) {
                $meta['throughModel'] = class_basename($relation->throughParent::class);
                $meta['throughTable'] = $relation->throughParent->getTable();
                $meta['firstKey'] = $relation->getFirstKeyName();
                $meta['foreignKey'] = $relation->getForeignKeyName();
                $meta['localKey'] = $relation->getLocalKeyName();
                $meta['secondLocalKey'] = $relation->getSecondLocalKeyName();

                return $this->dropEmptySmartValues($meta);
            }

            if ($relation instanceof HasOneOrMany) {
                $meta['foreignKey'] = $relation->getForeignKeyName();
                $meta['localKey'] = $relation->getLocalKeyName();

                return $this->dropEmptySmartValues($meta);
            }

            if ($relation instanceof BelongsTo) {
                $meta['foreignKey'] = $relation->getForeignKeyName();
                $meta['ownerKey'] = $relation->getOwnerKeyName();

                return $this->dropEmptySmartValues($meta);
            }
        } catch (Throwable) {
            return [];
        }

        return [];
    }

    /**
     * @param  array<string, string>  $meta
     * @return array<string, string>
     */
    private function dropEmptySmartValues(array $meta): array
    {
        return array_filter(
            $meta,
            static fn (string $v): bool => $v !== ''
        );
    }

    private function shortMorphClass(string $morphClass): string
    {
        if ($morphClass === '') {
            return '';
        }

        if (class_exists($morphClass)) {
            return class_basename($morphClass);
        }

        return $morphClass;
    }

    /**
     * Prefer {@see ReflectionClass::newInstanceWithoutConstructor()} so model boot side effects stay minimal.
     * Some models only construct via {@see ReflectionClass::newInstance()} (constructor, traits, readonly properties).
     *
     * @param  ReflectionClass<object>  $modelReflection
     */
    private function makeBareModelForRelationProbe(ReflectionClass $modelReflection): ?Model
    {
        try {
            $model = $modelReflection->newInstanceWithoutConstructor();
            if ($model instanceof Model) {
                return $model;
            }
        } catch (Throwable) {
            // fall through
        }

        try {
            /** @var Model $model */
            $model = $modelReflection->newInstance([]);
            if ($model instanceof Model) {
                return $model;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function callRelationMethod(Model $model, string $method): mixed
    {
        return $model->{$method}();
    }

    /**
     * @param  callable(): void  $callback
     */
    private function whileGuardingAgainstAccidentalQueries(Model $model, callable $callback): void
    {
        Model::withoutEvents(function () use ($model, $callback): void {
            $model->getConnection()->pretend(function () use ($callback): void {
                $callback();
            });
        });
    }
}
