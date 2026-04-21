<?php

namespace App\Modules\Core\Models;

use App\Modules\Portfolio\Models\PortfolioCharge;
use App\Modules\Portfolio\Models\PortfolioCollection;
use App\Modules\Residents\Models\Resident;
use App\Modules\Vehicles\Models\VehicleIncident;
use Illuminate\Database\Eloquent\Model;
class Apartment extends Model
{
    public const OWNER_FALLBACK_LABEL = 'Sin propietario parametrizado';

    protected $table = 'apartments';

    protected $fillable = [
        'condominium_id',
        'unit_type_id',
        'parent_id',
        'tower',
        'number',
        'floor',
        'is_active',
    ];

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function unitType()
    {
        return $this->belongsTo(UnitType::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function residents()
    {
        return $this->hasMany(Resident::class);
    }

    public function ownerResident()
    {
        return $this->hasOne(Resident::class)
            ->where('type', 'propietario')
            ->where('is_active', true)
            ->oldest('id');
    }

    public function getOwnerResidentByApartment(): ?Resident
    {
        if ($this->relationLoaded('residents')) {
            return $this->residents->first(
                fn (Resident $resident) => $resident->type === 'propietario'
                    && (bool) $resident->is_active
                    && $resident->user !== null
            );
        }

        return $this->residents()
            ->with('user:id,full_name')
            ->where('type', 'propietario')
            ->where('is_active', true)
            ->oldest('id')
            ->first();
    }

    public function resolveOwnerName(): string
    {
        $owner = $this->getOwnerResidentByApartment();
        $ownerName = trim((string) ($owner?->user?->full_name ?? ''));

        return $ownerName !== '' ? $ownerName : self::OWNER_FALLBACK_LABEL;
    }

    public function vehicleIncidents()
    {
        return $this->hasMany(VehicleIncident::class);
    }

    public function portfolioCharges()
    {
        return $this->hasMany(PortfolioCharge::class);
    }

    public function portfolioCollections()
    {
        return $this->hasMany(PortfolioCollection::class);
    }

    public function isPrimaryApartment(): bool
    {
        return (bool) $this->unitType?->canHaveResidents();
    }

    public function isSecondaryUnit(): bool
    {
        return (bool) $this->unitType?->needsParentApartment();
    }

    public static function normalizeUnitTypeName(?string $value): string
    {
        return \Illuminate\Support\Str::of((string) $value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->trim()
            ->replace(' ', '')
            ->value();
    }
}
