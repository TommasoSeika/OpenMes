<?php

namespace Modules\Ortofrutta\Models;

use Illuminate\Database\Eloquent\Model;

class Person extends Model
{
    protected $table = 'orto_people';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled_lines' => 'array', 'hourly_cost' => 'float'];
    }
}
