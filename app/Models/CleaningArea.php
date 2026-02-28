<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CleaningArea extends Model
{
    use HasFactory;

    protected $fillable = [
        'condominium_id',
        'name',
        'description',
        'is_active',
    ];

    /* Relaciones */

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function cleaningRecords()
    {
        return $this->hasMany(CleaningRecord::class);
    }
}
