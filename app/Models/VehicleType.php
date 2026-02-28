<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VehicleType extends Model
{
    use HasFactory;

    protected $table = 'vehicle_types';
    protected $fillable = [
        'condominium_id',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /*Relationships */

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }

    /* Scopes */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCondominium($query, $condominiumId)
    {
        return $query->where('condominium_id', $condominiumId);
    }
}
