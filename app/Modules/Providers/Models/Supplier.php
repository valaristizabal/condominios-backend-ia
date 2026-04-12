<?php

namespace App\Modules\Providers\Models;

use App\Modules\Core\Models\Condominium;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'condominium_id',
        'name',
        'rut',
        'certificacion_bancaria',
        'documento_representante_legal',
        'contact_name',
        'phone',
        'email',
        'address',
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
        return $this->hasMany(Product::class);
    }
}






