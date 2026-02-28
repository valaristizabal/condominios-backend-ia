<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'full_name',
        'document_number',
        'birth_date',
        'email',
        'password',
        'is_active',
        'phone',
        'photo',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'birth_date' => 'datetime',
            'is_active' => 'boolean',
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
}
