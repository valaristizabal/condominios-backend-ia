<?php

namespace App\Modules\Vehicles\Models;
use App\Modules\Core\Models\Apartment;
use App\Modules\Core\Models\Condominium;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vehicle extends Model
{
    use HasFactory;

    protected $table = 'vehicles';
    protected $fillable = [
        'condominium_id',
        'vehicle_type_id',
        'apartment_id',
        'plate',
        'owner_type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /*Relationships*/

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function vehicleType()
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function apartment()
    {
        return $this->belongsTo(Apartment::class);
    }

    public function entries()
    {
        return $this->hasMany(VehicleEntry::class);
    }
    public function incidents()
    {
        return $this->hasMany(VehicleIncident::class);
    }


    /*Scopes*/

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCondominium($query, $condominiumId)
    {
        return $query->where('condominium_id', $condominiumId);
    }
}



