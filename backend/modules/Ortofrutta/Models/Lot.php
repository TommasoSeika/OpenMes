<?php

namespace Modules\Ortofrutta\Models;

use Illuminate\Database\Eloquent\Model;

class Lot extends Model
{
    protected $table = 'orto_lots';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'gross_kg' => 'float',
            'tare_kg' => 'float',
            'net_kg' => 'float',
            'purchase_price_kg' => 'float',
        ];
    }

    public function producer()
    {
        return $this->belongsTo(Producer::class, 'producer_id');
    }

    public function cell()
    {
        return $this->belongsTo(Cell::class, 'cell_id');
    }

    public function sheetLots()
    {
        return $this->hasMany(WorkSheetLot::class, 'lot_id');
    }
}
