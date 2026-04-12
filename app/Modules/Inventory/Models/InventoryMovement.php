<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class InventoryMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'type',
        'quantity',
        'movement_date',
        'fecha_entrada',
        'fecha_salida',
        'registered_by_id',
        'observations',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'movement_date' => 'date',
        'fecha_entrada' => 'datetime',
        'fecha_salida' => 'datetime',
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
        static::creating(function ($movement) {
            if (empty($movement->movement_date)) {
                $movement->movement_date = Carbon::today()->toDateString();
            }

            $now = Carbon::now();
            if ($movement->type === 'entry') {
                $movement->fecha_entrada = $movement->fecha_entrada ?? $now;
                $movement->fecha_salida = null;
            }

            if ($movement->type === 'exit') {
                $movement->fecha_salida = $movement->fecha_salida ?? $now;
                $movement->fecha_entrada = null;
            }
        });

        static::created(function ($movement) {

            $product = $movement->product;
            if (! $product) {
                return;
            }

            if ($product->isAsset()) {
                if ($product->isInactiveAsset()) {
                    throw ValidationException::withMessages([
                        'product_id' => ['El activo fijo ya se encuentra inactivo o dado de baja.'],
                    ]);
                }

                if ($movement->type !== 'exit') {
                    throw ValidationException::withMessages([
                        'type' => ['Los activos fijos solo permiten movimientos de salida individual.'],
                    ]);
                }

                return;
            }

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





