<?php

namespace App\Modules\Visits\Models;
use App\Modules\Core\Models\Apartment;
use App\Modules\Core\Models\Condominium;
use App\Modules\Security\Models\User;

use Illuminate\Database\Eloquent\Model;

class Visit extends Model
{
    protected $table = 'visits';
    protected $fillable = [
        'condominium_id',
        'apartment_id',
        'registered_by_id',
        'full_name',
        'document_number',
        'phone',
        'destination',
        'background_check',
        'carried_items',
        'photo',
        'status',
        'check_in_at',
        'check_out_at',
    ];

    protected $casts = [
        'background_check' => 'boolean',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
    ];

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function apartment()
    {
        return $this->belongsTo(Apartment::class);
    }

    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by_id');
    }
}


