<?php

namespace Modules\Ortofrutta\Models;

use Illuminate\Database\Eloquent\Model;

/** Bins of one lot to bring from a cell to a line for one order. */
class Mission extends Model
{
    protected $table = 'orto_missions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'due_at' => 'datetime',
            'picked_at' => 'datetime',
            'delivered_at' => 'datetime',
            'kg' => 'float',
            'bins' => 'integer',
            'number' => 'integer',
            'priority' => 'integer',
        ];
    }

    /** How the mission is called on the lists and on the terminal. */
    public function code(): string
    {
        return sprintf('M-%03d', $this->number);
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function line()
    {
        return $this->belongsTo(Line::class, 'line_id');
    }

    public function lot()
    {
        return $this->belongsTo(Lot::class, 'lot_id');
    }

    public function cell()
    {
        return $this->belongsTo(Cell::class, 'cell_id');
    }

    public function person()
    {
        return $this->belongsTo(Person::class, 'person_id');
    }
}
