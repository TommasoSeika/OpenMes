<?php

namespace Modules\Ortofrutta\Models;

use Illuminate\Database\Eloquent\Model;

class PriceListEntry extends Model
{
    protected $table = 'orto_price_list';

    protected $guarded = [];

    public $timestamps = false;
    protected function casts(): array
    {
        return ['price_kg' => 'float', 'discount_pct' => 'float'];
    }
}
