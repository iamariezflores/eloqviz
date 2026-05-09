<?php

namespace EloqViz\EloquentViz\Tests\Fixtures\GraphModels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Two relation methods that resolve to the same directed hasMany (for dedupe tests).
 */
class GraphDupHost extends Model
{
    public function posts(): HasMany
    {
        return $this->hasMany(GraphDupPost::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(GraphDupPost::class);
    }
}
