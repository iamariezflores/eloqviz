<?php

namespace EloqViz\EloquentViz\Tests\Fixtures\MorphModels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MorphComment extends Model
{
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
