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
        'location',
        'is_active',
        'responsible_id',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'total_value' => 'decimal:2',
        'minimum_stock' => 'integer',
        'stock' => 'integer',
    ];

    protected static function booted()
    {
        static::saving(function (Product $product) {
            $type = (string) ($product->type ?? self::TYPE_CONSUMABLE);

            if ($type === self::TYPE_ASSET) {
                $product->minimum_stock = 0;
            } else {
                $product->asset_code = null;
                $product->location = null;
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
