<?php

namespace Modules\Ortofrutta\Models;

use Illuminate\Database\Eloquent\Model;

class PackagingComponent extends Model
{
    protected $table = 'orto_packaging_components';

    protected $guarded = [];

    public $timestamps = false;
    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_cost' => 'float'];
    }
}
