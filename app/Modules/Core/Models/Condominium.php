<?php

namespace App\Modules\Core\Models;

use App\Modules\Cleaning\Models\CleaningArea;
use App\Modules\Cleaning\Models\CleaningRecord;
use App\Modules\Emergencies\Models\EmergencyType;
use App\Modules\Emergencies\Models\HealthIncident;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Providers\Models\Supplier;
use App\Modules\Residents\Models\Resident;
use App\Modules\Security\Models\User;
use App\Modules\Vehicles\Models\VehicleIncident;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Condominium extends Model
{
    use HasFactory;
    protected $table = 'condominiums';
    protected $appends = ['logo_url'];

    protected $fillable = [
        'name',
        'tenant_code',
        'type',
        'tower',
        'floors',
        'address',
        'contact_info',
        'logo_path',
        'is_active',
        'expiration_date',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'floors' => 'integer',
        'expiration_date' => 'date',
    ];

    public function getLogoUrlAttribute(): ?string
    {
        $path = $this->attributes['logo_path'] ?? null;

        if (! $path) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', 'data:image'])) {
            return $path;
        }

        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

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

    public function suppliers()
    {
        return $this->hasMany(Supplier::class);
    }
}



