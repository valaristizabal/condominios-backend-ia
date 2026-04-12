<?php

namespace App\Modules\Security\Models;
use App\\Modules\\Core\\Models\\Correspondence;
use App\\Modules\\Core\\Models\\Operative;
use App\Modules\Vehicles\Models\VehicleIncident;
use App\Modules\Residents\Models\Resident;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const MODULE_VISITS = 'visits';
    public const MODULE_VEHICLES = 'vehicles';
    public const MODULE_VEHICLE_INCIDENTS = 'vehicle-incidents';
    public const MODULE_EMPLOYEE_ENTRIES = 'employee-entries';
    public const MODULE_CORRESPONDENCES = 'correspondences';
    public const MODULE_EMERGENCIES = 'emergencies';
    public const MODULE_CLEANING = 'cleaning';
    public const MODULE_INVENTORY = 'inventory';
    public const MODULE_SETTINGS = 'settings';

    public const AVAILABLE_MODULES = [
        self::MODULE_VISITS,
        self::MODULE_VEHICLES,
        self::MODULE_VEHICLE_INCIDENTS,
        self::MODULE_EMPLOYEE_ENTRIES,
        self::MODULE_CORRESPONDENCES,
        self::MODULE_EMERGENCIES,
        self::MODULE_CLEANING,
        self::MODULE_INVENTORY,
        self::MODULE_SETTINGS,
    ];

    protected $fillable = [
        'full_name',
        'document_number',
        'birth_date',
        'email',
        'password',
        'is_active',
        'is_platform_admin',
        'phone',
        'photo',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'api_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'birth_date' => 'datetime',
            'is_active' => 'boolean',
            'is_platform_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /*Relationships*/

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_role')
            ->withPivot('condominium_id')
            ->withTimestamps();
    }

    public function operatives()
    {
        return $this->hasMany(Operative::class);
    }

    public function residents()
    {
        return $this->hasMany(Resident::class);
    }

    public function modulePermissions()
    {
        return $this->hasMany(UserModulePermission::class);
    }

    public function registeredVehicleIncidents()
    {
        return $this->hasMany(VehicleIncident::class, 'registered_by_id');
    }
    public function receivedCorrespondences()
    {
        return $this->hasMany(Correspondence::class, 'received_by_id');
    }

    public function deliveredCorrespondences()
    {
        return $this->hasMany(Correspondence::class, 'delivered_by_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function isTenantAdmin(?int $activeCondominiumId = null): bool
    {
        if ($this->is_platform_admin) {
            return true;
        }

        return $this->roles()
            ->when(
                $activeCondominiumId && $activeCondominiumId > 0,
                fn ($query) => $query->where('user_role.condominium_id', $activeCondominiumId)
            )
            ->whereIn('name', [
                'Administrador Propiedad',
                'administrador_propiedad',
                'admin_condominio',
            ])
            ->exists();
    }

    public function userHasModulePermission(string $module, ?int $activeCondominiumId = null): bool
    {
        if (! in_array($module, self::AVAILABLE_MODULES, true)) {
            return false;
        }

        if ($this->is_platform_admin || $this->isTenantAdmin($activeCondominiumId)) {
            return true;
        }

        if (! $activeCondominiumId || $activeCondominiumId <= 0) {
            return false;
        }

        return $this->modulePermissions()
            ->where('condominium_id', $activeCondominiumId)
            ->where('module', $module)
            ->where('can_view', true)
            ->exists();
    }

    public function modulePermissionsMap(?int $activeCondominiumId = null): array
    {
        $result = [];

        foreach (self::AVAILABLE_MODULES as $module) {
            $result[$module] = $this->userHasModulePermission($module, $activeCondominiumId);
        }

        return $result;
    }
}




