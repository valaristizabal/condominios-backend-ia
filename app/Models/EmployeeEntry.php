<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmployeeEntry extends Model
{
    use HasFactory;

    protected $table = 'employee_entries';
    protected $fillable = [
        'condominium_id',
        'operative_id',
        'registered_by_id',
        'check_in_at',
        'check_out_at',
        'status',
        'observations',
    ];

    protected $casts = [
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
    ];

    /*Relationships*/

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function operative()
    {
        return $this->belongsTo(Operative::class);
    }

    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by_id');
    }

    /*Scopes*/

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByCondominium($query, $condominiumId)
    {
        return $query->where('condominium_id', $condominiumId);
    }
}
