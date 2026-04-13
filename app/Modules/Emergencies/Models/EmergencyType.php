<?php

namespace App\Modules\Emergencies\Models;
use App\Modules\Core\Models\Condominium;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmergencyType extends Model
{
    use HasFactory;

    protected $fillable = [
        'condominium_id',
        'name',
        'level',
        'is_active',
    ];

    /* Relationships*/

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function healthIncidents()
    {
        return $this->hasMany(HealthIncident::class);
    }

    public function emergencyContacts()
    {
        return $this->hasMany(EmergencyContact::class);
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




