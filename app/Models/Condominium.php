<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Condominium extends Model
{
    use HasFactory;
    protected $table = 'condominiums';

    protected $fillable = [
        'name',
        'tenant_code',
        'type',
        'common_areas',
        'tower',
        'floors',
        'address',
        'contact_info',
        'logo_path',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'floors' => 'integer',
    ];

    /* Relationships*/

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_role')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    public function operatives()
    {
        return $this->hasMany(Operative::class);
    }

    public function residents()
    {
        return $this->hasManyThrough(Resident::class, Apartment::class);
    }
    public function vehicleIncidents()
    {
        return $this->hasMany(VehicleIncident::class);
    }
    public function cleaningAreas()
    {
        return $this->hasMany(CleaningArea::class);
    }

    public function cleaningRecords()
    {
        return $this->hasMany(CleaningRecord::class);
    }
    public function emergencyTypes()
    {
        return $this->hasMany(EmergencyType::class);
    }

    public function correspondences()
    {
        return $this->hasMany(Correspondence::class);
    }

    public function healthIncidents()
    {
        return $this->hasMany(HealthIncident::class);
    }
    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }
}
