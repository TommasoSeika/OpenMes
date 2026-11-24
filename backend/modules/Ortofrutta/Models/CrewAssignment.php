<?php

namespace Modules\Ortofrutta\Models;

use Illuminate\Database\Eloquent\Model;

class CrewAssignment extends Model
{
    protected $table = 'orto_crew_assignments';

    protected $guarded = [];

    public $timestamps = false;
    protected function casts(): array
    {
        return ['day' => 'date'];
    }

    public function person()
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    public function line()
    {
        return $this->belongsTo(Line::class, 'line_id');
    }
}
