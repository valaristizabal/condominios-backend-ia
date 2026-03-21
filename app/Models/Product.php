<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;

    public const TYPE_CONSUMABLE = 'consumable';
    public const TYPE_ASSET = 'asset';

    protected $fillable = [
        'inventory_id',
        'category_id',
        'supplier_id',
        'name',
        'category',
        'unit_measure',
        'unit_cost',
        'total_value',
        'stock',
        'minimum_stock',
        'type',
        'asset_code',
        'serial',
        'location',
        'is_active',
        'dado_de_baja',
        'dado_de_baja_por',
        'fecha_baja',
        'responsible_id',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'total_value' => 'decimal:2',
        'minimum_stock' => 'integer',
        'stock' => 'integer',
        'dado_de_baja' => 'boolean',
        'fecha_baja' => 'datetime',
    ];

    protected static function booted()
    {
        static::saving(function (Product $product) {
            $type = (string) ($product->type ?? self::TYPE_CONSUMABLE);

            if ($type === self::TYPE_ASSET) {
                $product->minimum_stock = 0;
                $product->stock = 1;
            } else {
                $product->asset_code = null;
                $product->serial = null;
                $product->location = null;
                $product->dado_de_baja = false;
                $product->dado_de_baja_por = null;
                $product->fecha_baja = null;
            }

            $product->total_value = $product->calculateTotalValue();
        });
    }

    /*Relationships*/

    public function inventory()
    {
        return $this->belongsTo(Inventory::class);
    }

    public function movements()
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function inventoryCategory()
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function responsible()
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function deactivatedBy()
    {
        return $this->belongsTo(User::class, 'dado_de_baja_por');
    }

    /*Business Logic (Stock handling)*/

    public function increaseStock($quantity)
    {
        $this->stock = (int) $this->stock + (int) $quantity;
        $this->save();
    }

    public function decreaseStock($quantity)
    {
        $this->stock = max(0, (int) $this->stock - (int) $quantity);
        $this->save();
    }

    public function isConsumable(): bool
    {
        return (string) $this->type !== self::TYPE_ASSET;
    }

    public function isAsset(): bool
    {
        return (string) $this->type === self::TYPE_ASSET;
    }

    public function isInactiveAsset(): bool
    {
        return $this->isAsset() && ((bool) $this->dado_de_baja || ! (bool) $this->is_active);
    }

    public function isBelowMinimumStock(): bool
    {
        return $this->isConsumable() && (int) $this->stock <= (int) $this->minimum_stock;
    }

    public function calculateTotalValue(): ?string
    {
        if ($this->unit_cost === null) {
            return null;
        }

        $stock = (int) $this->stock;
        $unitCost = (float) $this->unit_cost;

        return number_format($stock * $unitCost, 2, '.', '');
    }
}
