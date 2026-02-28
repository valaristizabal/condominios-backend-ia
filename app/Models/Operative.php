<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Operative extends Model
{
    use HasFactory;

    protected $table = 'operatives';

    protected $fillable = [
        'user_id',
        'condominium_id',
        'position',
        'contract_type',
        'salary',
        'financial_institution',
        'account_type',
        'account_number',
        'contract_start_date',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'salary' => 'decimal:2',
        'contract_start_date' => 'date',
    ];

    /*Relationships*/

    // Relación con usuario (identidad)
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relación con condominio
    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }
    public function cleaningRecords()
    {
        return $this->hasMany(CleaningRecord::class);
    }


    /*Scopes (muy útiles para API + React)*/

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePlanta($query)
    {
        return $query->where('contract_type', 'planta');
    }

    public function scopeContratista($query)
    {
        return $query->where('contract_type', 'contratista');
    }

    public function scopeByCondominium($query, $condominiumId)
    {
        return $query->where('condominium_id', $condominiumId);
    }

    /*Accessors (útil para frontend)*/

    public function getFullNameAttribute()
    {
        return $this->user?->full_name;
    }
}
