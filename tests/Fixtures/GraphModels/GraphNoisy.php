<?php

namespace EloqViz\EloquentViz\Tests\Fixtures\GraphModels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class GraphNoisy extends Model
{
    public function untypedRelation()
    {
        return $this->belongsTo(GraphWidget::class);
    }

    public static function staticRelation(): HasMany
    {
        return (new GraphWidget)->hasMany(GraphGadget::class);
    }

    public function withRequiredParameter(string $required): BelongsTo
    {
        return $this->belongsTo(GraphWidget::class);
    }

    public function good(): BelongsTo
    {
        return $this->belongsTo(GraphWidget::class);
    }

    public function throwsAtRuntime(): BelongsTo
    {
        throw new RuntimeException('relation resolution fails');
    }
}
