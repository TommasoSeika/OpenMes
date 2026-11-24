<?php

namespace Modules\Ortofrutta\Models;

use Illuminate\Database\Eloquent\Model;

class WorkSheetPerson extends Model
{
    protected $table = 'orto_work_sheet_people';

    protected $guarded = [];

    public $timestamps = false;
    protected function casts(): array
    {
        return ['hours' => 'float', 'hourly_cost' => 'float'];
    }

    public function person()
    {
        return $this->belongsTo(Person::class, 'person_id');
    }
}
