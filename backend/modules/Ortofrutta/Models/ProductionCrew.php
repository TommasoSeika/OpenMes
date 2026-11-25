<?php

namespace Modules\Ortofrutta\Models;

use Illuminate\Database\Eloquent\Model;

/** One person on one order of a production. */
class ProductionCrew extends Model
{
    protected $table = 'orto_production_crew';

    protected $guarded = [];

    public $timestamps = false;

    public function person()
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
