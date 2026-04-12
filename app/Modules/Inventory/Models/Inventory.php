<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Core\Models\Condominium;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Inventory extends Model
{
    use HasFactory;

    protected $fillable = [
        'condominium_id',
        'name',
        'is_active',
    ];

    /*Relationships*/

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}




