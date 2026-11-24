<?php

namespace Modules\Ortofrutta\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $table = 'orto_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['kg_per_carton' => 'float', 'cartons_per_pallet' => 'integer'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function components()
    {
        return $this->hasMany(PackagingComponent::class, 'item_id');
    }

    public function kgPerPallet(): float
    {
        return (float) $this->kg_per_carton * (int) $this->cartons_per_pallet;
    }
}
