<?php

namespace Modules\Ortofrutta\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'orto_orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'arrived_at' => 'datetime',
            'truck_at' => 'datetime',
            'released' => 'boolean',
            'pallets' => 'integer',
            'priority' => 'integer',
            'position' => 'integer',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function line()
    {
        return $this->belongsTo(Line::class, 'line_id');
    }

    public function workSheet()
    {
        return $this->belongsTo(WorkSheet::class, 'work_sheet_id');
    }
}
