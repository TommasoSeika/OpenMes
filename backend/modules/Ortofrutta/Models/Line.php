<?php

namespace Modules\Ortofrutta\Models;

use Illuminate\Database\Eloquent\Model;

class Line extends Model
{
    protected $table = 'orto_lines';

    protected $guarded = [];

    public function capacities()
    {
        return $this->hasMany(LineCapacity::class, 'line_id');
    }

    /** Pallets per hour for a format, or null when the line cannot run it. */
    public function capacityFor(string $format): ?float
    {
        $row = $this->capacities->firstWhere('format', $format);

        return $row ? (float) $row->pallets_per_hour : null;
    }
}
