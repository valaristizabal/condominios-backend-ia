<?php

namespace App\Modules\Emergencies\Models;
use App\Modules\Core\Models\Condominium;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmergencyContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'condominium_id',
        'emergency_type_id',
        'name',
        'phone_number',
        'icon',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function emergencyType()
    {
        return $this->belongsTo(EmergencyType::class);
    }
}




