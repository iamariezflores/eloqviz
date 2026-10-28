<?php

declare(strict_types=1);

namespace EloqViz\EloquentViz;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Resolves the models a morphTo relation can point at.
 *
 * A morph_targets entry for that method is used when present. Otherwise every
 * model in eloquent-viz.morph_map is a candidate, and an empty morph_map falls
 * through to the Relation::morphMap() registry already booted by the application.
 */
final class MorphTargetResolver
{
    /**
     * @return list<array{class: class-string<Model>, alias: string}>
     */
    public function targetsFor(string $modelFqcn, string $method): array
    {
        $specific = $this->configuredRelationTargets($modelFqcn, $method);
        if ($specific !== null) {
            return $specific;
        }

        return $this->classesFromMap($this->activeMap());
    }

    /**
     * @return list<array{class: class-string<Model>, alias: string}>|null
     */
    private function configuredRelationTargets(string $modelFqcn, string $method): ?array
    {
        $configured = config('eloquent-viz.morph_targets');
        if (! is_array($configured)) {
            return null;
        }

        $forModel = $configured[$modelFqcn] ?? null;
        if (! is_array($forModel) || ! array_key_exists($method, $forModel)) {
            return null;
        }

        $raw = $forModel[$method];
        if (is_string($raw)) {
            return $this->classesFromMap([$raw => $raw]);
        }

        if (! is_array($raw)) {
            return [];
        }

        return $this->classesFromMap($this->listToMap($raw));
    }

    /**
     * @return array<string, string>
     */
    private function activeMap(): array
    {
        $configured = config('eloquent-viz.morph_map');
        if (is_array($configured) && $configured !== []) {
            return $this->normalizeMap($configured);
        }

        $registry = Relation::morphMap();

        return is_array($registry) ? $this->normalizeMap($registry) : [];
    }

    /**
     * @param  array<mixed>  $map
     * @return array<string, string>
     */
    private function normalizeMap(array $map): array
    {
        if (array_is_list($map)) {
            return $this->listToMap($map);
        }

        $normalized = [];
        foreach ($map as $alias => $class) {
            if (! is_string($class) || $class === '') {
                continue;
            }

            $normalized[(string) $alias] = $class;
        }

        return $normalized;
    }

    /**
     * @param  list<mixed>  $classes
     * @return array<string, string>
     */
    private function listToMap(array $classes): array
    {
        $map = [];
        foreach ($classes as $class) {
            if (is_string($class) && $class !== '') {
                $map[$class] = $class;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $map
     * @return list<array{class: class-string<Model>, alias: string}>
     */
    private function classesFromMap(array $map): array
    {
        $targets = [];
        $seen = [];

        foreach ($map as $alias => $class) {
            $resolved = $this->resolveModelClass($class);
            if ($resolved === null || isset($seen[$resolved])) {
                continue;
            }

            $seen[$resolved] = true;
            $targets[] = [
                'class' => $resolved,
                'alias' => (string) $alias,
            ];
        }

        usort($targets, static fn (array $left, array $right): int => $left['class'] <=> $right['class']);

        return $targets;
    }

    /**
     * @return class-string<Model>|null
     */
    private function resolveModelClass(string $class): ?string
    {
        $actual = Relation::getMorphedModel($class);
        if (! is_string($actual) || $actual === '') {
            $actual = $class;
        }

        if (! class_exists($actual) || ! is_subclass_of($actual, Model::class)) {
            return null;
        }

        return $actual;
    }
}
