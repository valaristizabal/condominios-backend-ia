<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitType extends Model
{
    protected $table = 'unit_types';
    protected $fillable = [
        'condominium_id',
        'name',
        'is_active'
    ];

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function apartments()
    {
        return $this->hasMany(Apartment::class);
    }
}

