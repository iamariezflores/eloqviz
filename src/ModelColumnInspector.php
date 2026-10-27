<?php

declare(strict_types=1);

namespace EloqViz\EloquentViz;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a model's table with Laravel's schema builder and flags foreign-key columns.
 *
 * Column lists come from {@see Schema::getColumns()}. A column is marked when it is
 * the foreign key of a scanned relationship on that table, or when
 * {@see Schema::getForeignKeys()} reports a constraint. Other *_id columns stay unmarked.
 */
final class ModelColumnInspector
{
    /**
     * Relation types whose foreign key column lives on the defining model.
     *
     * @var list<string>
     */
    private const FOREIGN_KEY_ON_DEFINING_MODEL = [
        'belongsTo',
        'morphTo',
    ];

    /**
     * Relation types whose foreign key column lives on the related model.
     *
     * @var list<string>
     */
    private const FOREIGN_KEY_ON_RELATED_MODEL = [
        'hasOne',
        'hasMany',
        'morphOne',
        'morphMany',
        'hasOneThrough',
        'hasManyThrough',
    ];

    /**
     * @var list<string>
     */
    private const THROUGH_RELATION_TYPES = [
        'hasOneThrough',
        'hasManyThrough',
    ];

    /**
     * @param  list<array{id: string, label: string, fqcn: string, file?: string}>  $nodeRows
     * @param  list<array{from: string, to?: string|null, type?: string, smart?: array<string, mixed>}>  $edges
     * @param  callable(string): ?Model  $resolveModel
     * @return list<array{id: string, label: string, fqcn: string, file?: string, table: string, schemaStatus: string, columns: list<array{name: string, type: string, nullable: bool, foreignKey: bool, relations: list<string>}>}>
     */
    public function decorateNodes(array $nodeRows, array $edges, callable $resolveModel): array
    {
        [$byModel, $byTable] = $this->indexRelationshipForeignKeys($edges);

        /** @var array<string, array{status: string, columns: list<array<string, mixed>>, constraints: array<string, list<string>>}> $schemaCache */
        $schemaCache = [];

        foreach ($nodeRows as $index => $row) {
            $model = $resolveModel($row['id']);
            if (! $model instanceof Model) {
                $nodeRows[$index]['table'] = '';
                $nodeRows[$index]['columns'] = [];
                $nodeRows[$index]['schemaStatus'] = 'unavailable';

                continue;
            }

            $described = $this->describe(
                $model,
                $byModel[$row['id']] ?? [],
                $byTable,
                $schemaCache,
            );

            $nodeRows[$index]['table'] = $described['table'];
            $nodeRows[$index]['columns'] = $described['columns'];
            $nodeRows[$index]['schemaStatus'] = $described['schemaStatus'];
        }

        return $nodeRows;
    }

    /**
     * @param  array<string, list<string>>  $relationshipColumns
     * @param  array<string, array<string, list<string>>>  $throughColumnsByTable
     * @param  array<string, array{status: string, columns: list<array<string, mixed>>, constraints: array<string, list<string>>}>  $schemaCache
     * @return array{table: string, schemaStatus: string, columns: list<array{name: string, type: string, nullable: bool, foreignKey: bool, relations: list<string>}>}
     */
    private function describe(Model $model, array $relationshipColumns, array $throughColumnsByTable, array &$schemaCache): array
    {
        try {
            $table = $model->getTable();
        } catch (Throwable) {
            return $this->emptyDescription('', 'unavailable');
        }

        if ($table === '') {
            return $this->emptyDescription('', 'unavailable');
        }

        $schema = $this->readSchema($model, $table, $schemaCache);
        if ($schema['status'] !== 'ok') {
            return $this->emptyDescription($table, $schema['status']);
        }

        $relationshipColumns = $this->mergeLabels(
            $relationshipColumns,
            $this->labelsForTable($table, $throughColumnsByTable),
        );

        $columns = [];
        foreach ($schema['columns'] as $column) {
            $name = isset($column['name']) ? trim((string) $column['name']) : '';
            if ($name === '') {
                continue;
            }

            $relations = $this->labelsForColumn($name, $relationshipColumns);
            if ($relations === []) {
                $relations = $this->labelsForColumn($name, $schema['constraints']);
            }

            $type = trim((string) ($column['type'] ?? ''));
            if ($type === '') {
                $type = trim((string) ($column['type_name'] ?? ''));
            }

            $columns[] = [
                'name' => $name,
                'type' => $type,
                'nullable' => (bool) ($column['nullable'] ?? false),
                'foreignKey' => $relations !== [],
                'relations' => $relations,
            ];
        }

        return [
            'table' => $table,
            'schemaStatus' => 'ok',
            'columns' => $columns,
        ];
    }

    /**
     * @return array{table: string, schemaStatus: string, columns: list<array{name: string, type: string, nullable: bool, foreignKey: bool, relations: list<string>}>}
     */
    private function emptyDescription(string $table, string $schemaStatus): array
    {
        return [
            'table' => $table,
            'schemaStatus' => $schemaStatus,
            'columns' => [],
        ];
    }

    /**
     * @param  array<string, array{status: string, columns: list<array<string, mixed>>, constraints: array<string, list<string>>}>  $schemaCache
     * @return array{status: string, columns: list<array<string, mixed>>, constraints: array<string, list<string>>}
     */
    private function readSchema(Model $model, string $table, array &$schemaCache): array
    {
        try {
            $connectionName = $model->getConnection()->getName();
        } catch (Throwable) {
            return [
                'status' => 'unavailable',
                'columns' => [],
                'constraints' => [],
            ];
        }

        $cacheKey = $connectionName."\0".$table;
        if (isset($schemaCache[$cacheKey])) {
            return $schemaCache[$cacheKey];
        }

        try {
            $schema = Schema::connection($connectionName);
            if (! $schema->hasTable($table)) {
                return $schemaCache[$cacheKey] = [
                    'status' => 'missing_table',
                    'columns' => [],
                    'constraints' => [],
                ];
            }

            $columns = $schema->getColumns($table);
            $constraints = [];

            try {
                $foreignKeys = $schema->getForeignKeys($table);
                $constraints = $this->constraintLabels(is_array($foreignKeys) ? $foreignKeys : []);
            } catch (Throwable) {
                $constraints = [];
            }
        } catch (Throwable) {
            return $schemaCache[$cacheKey] = [
                'status' => 'unavailable',
                'columns' => [],
                'constraints' => [],
            ];
        }

        $columnRows = [];
        foreach ($columns as $column) {
            if (is_array($column)) {
                $columnRows[] = $column;
            }
        }

        return $schemaCache[$cacheKey] = [
            'status' => 'ok',
            'columns' => $columnRows,
            'constraints' => $constraints,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $foreignKeys
     * @return array<string, list<string>>
     */
    private function constraintLabels(array $foreignKeys): array
    {
        /** @var array<string, list<string>> $labels */
        $labels = [];

        foreach ($foreignKeys as $foreignKey) {
            $label = $this->referenceLabel($foreignKey);
            foreach ($this->localColumns($foreignKey) as $column) {
                $this->addLabel($labels, $column, $label);
            }
        }

        return $labels;
    }

    /**
     * @param  array<string, mixed>  $foreignKey
     * @return list<string>
     */
    private function localColumns(array $foreignKey): array
    {
        $columns = $foreignKey['columns'] ?? [];
        if (is_string($columns)) {
            $columns = $columns === '' ? [] : explode(',', $columns);
        }

        if (! is_array($columns)) {
            return [];
        }

        $names = [];
        foreach ($columns as $column) {
            if (is_string($column) && trim($column) !== '') {
                $names[] = trim($column);
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $foreignKey
     */
    private function referenceLabel(array $foreignKey): string
    {
        $table = trim((string) ($foreignKey['foreign_table'] ?? ''));
        $columns = $foreignKey['foreign_columns'] ?? [];
        if (is_string($columns)) {
            $columns = $columns === '' ? [] : explode(',', $columns);
        }

        $columnList = [];
        if (is_array($columns)) {
            foreach ($columns as $column) {
                if (is_string($column) && trim($column) !== '') {
                    $columnList[] = trim($column);
                }
            }
        }

        if ($table === '') {
            return 'foreign key';
        }

        if ($columnList === []) {
            return 'references '.$table;
        }

        return 'references '.$table.'.'.implode(',', $columnList);
    }

    /**
     * @param  list<array{from: string, to?: string|null, type?: string, smart?: array<string, mixed>}>  $edges
     * @return array{0: array<string, array<string, list<string>>>, 1: array<string, array<string, list<string>>>}
     */
    private function indexRelationshipForeignKeys(array $edges): array
    {
        /** @var array<string, array<string, list<string>>> $byModel */
        $byModel = [];
        /** @var array<string, array<string, list<string>>> $byTable */
        $byTable = [];

        foreach ($edges as $edge) {
            $from = isset($edge['from']) ? (string) $edge['from'] : '';
            $type = isset($edge['type']) ? (string) $edge['type'] : '';
            if ($from === '' || $type === '') {
                continue;
            }

            $to = $edge['to'] ?? null;
            $to = is_string($to) && $to !== '' ? $to : null;
            $smart = is_array($edge['smart'] ?? null) ? $edge['smart'] : [];
            $foreignKey = isset($smart['foreignKey']) ? (string) $smart['foreignKey'] : '';
            $targetLabel = $to !== null ? class_basename($to) : 'related';

            if ($foreignKey !== '' && in_array($type, self::FOREIGN_KEY_ON_DEFINING_MODEL, true)) {
                $byModel[$from] ??= [];
                $this->addLabel($byModel[$from], $foreignKey, $type.' '.$targetLabel);
            }

            if ($to !== null && $foreignKey !== '' && in_array($type, self::FOREIGN_KEY_ON_RELATED_MODEL, true)) {
                $byModel[$to] ??= [];
                $this->addLabel($byModel[$to], $foreignKey, $type.' from '.class_basename($from));
            }

            if (! in_array($type, self::THROUGH_RELATION_TYPES, true)) {
                continue;
            }

            $throughTable = isset($smart['throughTable']) ? trim((string) $smart['throughTable']) : '';
            $firstKey = isset($smart['firstKey']) ? (string) $smart['firstKey'] : '';
            if ($throughTable === '' || $firstKey === '') {
                continue;
            }

            $byTable[$throughTable] ??= [];
            $this->addLabel($byTable[$throughTable], $firstKey, $type.' from '.class_basename($from));
        }

        return [$byModel, $byTable];
    }

    /**
     * @param  array<string, list<string>>  $map
     */
    private function addLabel(array &$map, string $column, string $label): void
    {
        $column = Str::afterLast(trim($column), '.');
        $label = trim($label);
        if ($column === '' || $label === '') {
            return;
        }

        $map[$column] ??= [];
        if (in_array($label, $map[$column], true)) {
            return;
        }

        $map[$column][] = $label;
        sort($map[$column], SORT_STRING);
    }

    /**
     * @param  array<string, list<string>>  $base
     * @param  array<string, list<string>>  $extra
     * @return array<string, list<string>>
     */
    private function mergeLabels(array $base, array $extra): array
    {
        $merged = $base;
        foreach ($extra as $column => $labels) {
            foreach ($labels as $label) {
                $this->addLabel($merged, (string) $column, (string) $label);
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, array<string, list<string>>>  $byTable
     * @return array<string, list<string>>
     */
    private function labelsForTable(string $table, array $byTable): array
    {
        if (isset($byTable[$table])) {
            return $byTable[$table];
        }

        $lower = strtolower($table);
        foreach ($byTable as $name => $columns) {
            if (strtolower((string) $name) === $lower) {
                return $columns;
            }
        }

        return [];
    }

    /**
     * @param  array<string, list<string>>  $indexed
     * @return list<string>
     */
    private function labelsForColumn(string $name, array $indexed): array
    {
        if (isset($indexed[$name])) {
            return $indexed[$name];
        }

        $lower = strtolower($name);
        foreach ($indexed as $column => $labels) {
            if (strtolower((string) $column) === $lower) {
                return $labels;
            }
        }

        return [];
    }
}
