<?php

namespace EloqViz\EloquentViz\Tests\Fixtures\GraphModels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GraphWidget extends Model
{
    public function gadgets(): HasMany
    {
        return $this->hasMany(GraphGadget::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(Nested\GraphAddon::class);
    }
}
