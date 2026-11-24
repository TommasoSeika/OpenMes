<?php

namespace Modules\Ortofrutta\Models;

use Illuminate\Database\Eloquent\Model;

class Producer extends Model
{
    protected $table = 'orto_producers';

    protected $guarded = [];

    public function lots()
    {
        return $this->hasMany(Lot::class, 'producer_id');
    }
}
