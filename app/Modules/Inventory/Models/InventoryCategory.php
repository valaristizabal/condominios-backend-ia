<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Core\Models\Condominium;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'condominium_id',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}





