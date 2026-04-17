<?php

namespace App\Modules\Residents\Models;
use App\Modules\Core\Models\Apartment;
use App\Modules\Security\Models\User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Resident extends Model
{
    use HasFactory;

    protected $table = 'residents';

    protected $fillable = [
        'user_id',
        'apartment_id',
        'type',
        'administration_fee',
        'administration_maturity',
        'property_owner_full_name',
        'property_owner_document_number',
        'property_owner_email',
        'property_owner_phone',
        'property_owner_birth_date',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'administration_fee' => 'decimal:2',
        'administration_maturity' => 'date',
        'property_owner_birth_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function apartment()
    {
        return $this->belongsTo(Apartment::class);
    }

    public function scopePropietario($query)
    {
        return $query->where('type', 'propietario');
    }

    public function scopeArrendatario($query)
    {
        return $query->where('type', 'arrendatario');
    }
}


