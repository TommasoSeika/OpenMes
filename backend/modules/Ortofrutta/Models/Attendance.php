<?php

namespace Modules\Ortofrutta\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $table = 'orto_attendances';

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
}
