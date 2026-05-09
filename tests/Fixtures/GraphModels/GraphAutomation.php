<?php

namespace EloqViz\EloquentViz\Tests\Fixtures\GraphModels;

use Illuminate\Database\Eloquent\Model;

class GraphAutomation extends Model
{
    public function additionalExpenses()
    {
        return $this->hasMany(GraphAutomationExpense::class, 'automation_id');
    }
}
