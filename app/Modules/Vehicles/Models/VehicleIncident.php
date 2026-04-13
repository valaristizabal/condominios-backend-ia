<?php

namespace App\Modules\Vehicles\Models;
use App\Modules\Core\Models\Apartment;
use App\Modules\Core\Models\Condominium;
use App\Modules\Security\Models\User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VehicleIncident extends Model
{
    use HasFactory;

    protected $fillable = [
        'condominium_id',
        'vehicle_id',
        'apartment_id',
        'registered_by_id',
        'plate',
        'incident_type',
        'observations',
        'evidence_path',
        'evidence_paths',
        'resolved',
    ];

    protected $casts = [
        'resolved' => 'boolean',
        'evidence_paths' => 'array',
    ];

    /*Relaciones*/

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function apartment()
    {
        return $this->belongsTo(Apartment::class);
    }

    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by_id');
    }

    /*Scopes útiles (SaaS ready)*/

    public function scopePending($query)
    {
        return $query->where('resolved', false);
    }

    public function scopeResolved($query)
    {
        return $query->where('resolved', true);
    }

    public function scopeByCondominium($query, $condominiumId)
    {
        return $query->where('condominium_id', $condominiumId);
    }
}



