<?php

namespace App\Modules\Core\Models;
use App\Modules\Residents\Models\Resident;
use App\Modules\Vehicles\Models\VehicleIncident;

use Illuminate\Database\Eloquent\Model;

class Apartment extends Model
{
    protected $table = 'apartments';
    protected $fillable = [
        'condominium_id',
        'unit_type_id',
        'tower',
        'number',
        'floor',
        'is_active'
    ];

    /*Relationships*/

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function unitType()
    {
        return $this->belongsTo(UnitType::class);
    }

    public function residents()
    {
        return $this->hasMany(Resident::class);
    }
    public function vehicleIncidents()
    {
        return $this->hasMany(VehicleIncident::class);
    }
}

//hoa//
//hola jeeeess como estáaas


