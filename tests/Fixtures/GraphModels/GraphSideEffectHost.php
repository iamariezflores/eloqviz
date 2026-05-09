<?php

namespace EloqViz\EloquentViz\Tests\Fixtures\GraphModels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Intentionally touches the query builder inside a relation method so tests can verify
 * the scanner runs that code only while the connection is in pretend mode.
 */
class GraphSideEffectHost extends Model
{
    public function widget(): BelongsTo
    {
        GraphWidget::query()->exists();

        return $this->belongsTo(GraphWidget::class);
    }
}
