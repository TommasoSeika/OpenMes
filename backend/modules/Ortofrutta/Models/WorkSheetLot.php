<?php

namespace Modules\Ortofrutta\Models;

use Illuminate\Database\Eloquent\Model;

class WorkSheetLot extends Model
{
    protected $table = 'orto_work_sheet_lots';

    protected $guarded = [];

    public $timestamps = false;
    protected function casts(): array
    {
        return [
            'kg_in' => 'float',
            'kg_packed' => 'float',
            'kg_waste_cider' => 'float',
            'kg_waste_industry' => 'float',
        ];
    }

    public function lot()
    {
        return $this->belongsTo(Lot::class, 'lot_id');
    }

    public function workSheet()
    {
        return $this->belongsTo(WorkSheet::class, 'work_sheet_id');
    }
}
