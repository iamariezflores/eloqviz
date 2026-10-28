<?php

namespace EloqViz\EloquentViz\Tests\Fixtures\IdeModels;

use Illuminate\Database\Eloquent\Relations\HasMany;

trait GraphComments
{
    public function comments(): HasMany
    {
        return $this->hasMany(GraphSubject::class);
    }
}
