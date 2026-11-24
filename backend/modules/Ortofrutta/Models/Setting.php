<?php

namespace Modules\Ortofrutta\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'orto_settings';

    protected $guarded = [];

    public $timestamps = false;
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';
}
