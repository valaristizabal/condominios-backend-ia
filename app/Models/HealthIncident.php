<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HealthIncident extends Model
{
    use HasFactory;

    protected $fillable = [
        'condominium_id',
        'emergency_type_id',
        'event_type',
        'event_location',
        'description',
        'event_date',
    ];

    protected $casts = [
        'event_date' => 'datetime',
    ];

    /*Relationships*/

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function emergencyType()
    {
        return $this->belongsTo(EmergencyType::class);
    }

    /*Scopes*/

    public function scopeByCondominium($query, $condominiumId)
    {
        return $query->where('condominium_id', $condominiumId);
    }
}
