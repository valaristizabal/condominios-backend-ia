<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_id',
        'category',
        'name',
        'unit_measure',
        'unit_cost',
        'stock',
        'is_active',
        'responsible_id',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
    ];

    /*Relationships*/

    public function inventory()
    {
        return $this->belongsTo(Inventory::class);
    }

    public function movements()
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function responsible()
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /*Business Logic (Stock handling)*/

    public function increaseStock($quantity)
    {
        $this->increment('stock', $quantity);
    }

    public function decreaseStock($quantity)
    {
        $this->decrement('stock', $quantity);
    }
}
