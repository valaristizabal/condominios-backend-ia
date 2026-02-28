<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InventoryMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'type',
        'quantity',
        'registered_by_id',
        'observations',
    ];

    /* Relationships*/

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by_id');
    }

    /*Boot Logic (Auto stock update)*/

    protected static function booted()
    {
        static::created(function ($movement) {

            $product = $movement->product;

            if ($movement->type === 'entry') {
                $product->increaseStock($movement->quantity);
            }

            if ($movement->type === 'exit') {

                if ($product->stock < $movement->quantity) {
                    throw new \Exception('Insufficient stock.');
                }

                $product->decreaseStock($movement->quantity);
            }
        });
    }
}
