<?php

namespace EloqViz\EloquentViz\Tests\Fixtures\MorphModels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class MorphVideo extends Model
{
    public function comments(): MorphMany
    {
        return $this->morphMany(MorphComment::class, 'commentable');
    }
}
