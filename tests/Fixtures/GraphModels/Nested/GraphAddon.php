<?php

namespace EloqViz\EloquentViz\Tests\Fixtures\GraphModels\Nested;

use EloqViz\EloquentViz\Tests\Fixtures\GraphModels\GraphWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GraphAddon extends Model
{
    public function widget(): BelongsTo
    {
        return $this->belongsTo(GraphWidget::class);
    }
}
