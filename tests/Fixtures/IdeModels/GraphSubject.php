<?php

namespace EloqViz\EloquentViz\Tests\Fixtures\IdeModels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GraphSubject extends Model
{
    public function author(): BelongsTo
    {
        return $this->belongsTo(GraphAuthor::class);
    }
}
