<?php

namespace App\Modules\Emergencies\Models;
use App\Modules\Core\Models\Condominium;
use App\Modules\Security\Models\User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HealthIncident extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'OPEN';
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const STATUS_CLOSED = 'CLOSED';

    protected $fillable = [
        'condominium_id',
        'emergency_type_id',
        'reported_by_id',
        'event_type',
        'event_location',
        'description',
        'event_date',
        'status',
        'resolved_at',
    ];

    protected $casts = [
        'event_date' => 'datetime',
        'resolved_at' => 'datetime',
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

    public function reportedBy()
    {
        return $this->belongsTo(User::class, 'report    ed_by_id');
    }

    /*Scopes*/

    public function scopeByCondominium($query, $condominiumId)
    {
        return $query->where('condominium_id', $condominiumId);
    }
}






