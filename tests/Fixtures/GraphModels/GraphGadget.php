<?php

namespace EloqViz\EloquentViz\Tests\Fixtures\GraphModels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GraphGadget extends Model
{
    public function widget(): BelongsTo
    {
        return $this->belongsTo(GraphWidget::class);
    }
}
