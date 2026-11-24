<?php

namespace Modules\Ortofrutta\Models;

use Illuminate\Database\Eloquent\Model;

class WorkSheet extends Model
{
    protected $table = 'orto_work_sheets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'closed_at' => 'datetime',
            'kg_in' => 'float',
            'kg_packed' => 'float',
            'kg_waste_cider' => 'float',
            'kg_waste_industry' => 'float',
            'labour_cost' => 'float',
            'packaging_cost' => 'float',
            'cost_per_kg' => 'float',
        ];
    }

    public function line()
    {
        return $this->belongsTo(Line::class, 'line_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function people()
    {
        return $this->hasMany(WorkSheetPerson::class, 'work_sheet_id');
    }

    public function lots()
    {
        return $this->hasMany(WorkSheetLot::class, 'work_sheet_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'work_sheet_id');
    }
}
