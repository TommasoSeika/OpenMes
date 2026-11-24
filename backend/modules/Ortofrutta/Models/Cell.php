<?php

namespace Modules\Ortofrutta\Models;

use Illuminate\Database\Eloquent\Model;

class Cell extends Model
{
    protected $table = 'orto_cells';

    protected $guarded = [];

    public function lots()
    {
        return $this->hasMany(Lot::class, 'cell_id');
    }
}
