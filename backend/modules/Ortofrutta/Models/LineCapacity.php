<?php

namespace Modules\Ortofrutta\Models;

use Illuminate\Database\Eloquent\Model;

class LineCapacity extends Model
{
    protected $table = 'orto_line_capacities';

    protected $guarded = [];

    public $timestamps = false;
    protected function casts(): array
    {
        return ['pallets_per_hour' => 'float'];
    }
}
