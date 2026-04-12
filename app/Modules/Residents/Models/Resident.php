<?php

namespace App\Modules\Residents\Models;
use App\\Modules\\Core\\Models\\Apartment;
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
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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



