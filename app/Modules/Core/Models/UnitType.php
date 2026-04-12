<?php

namespace App\Modules\Core\Models;
use App\Modules\Core\Models\Condominium;

use Illuminate\Database\Eloquent\Model;

class UnitType extends Model
{
    protected $table = 'unit_types';
    protected $fillable = [
        'condominium_id',
        'name',
        'allows_residents',
        'requires_parent',
        'is_active'
    ];

    protected $casts = [
        'allows_residents' => 'boolean',
        'requires_parent' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function apartments()
    {
        return $this->hasMany(Apartment::class);
    }

    public function canHaveResidents(): bool
    {
        return (bool) $this->allows_residents;
    }

    public function needsParentApartment(): bool
    {
        return (bool) $this->requires_parent;
    }
}



