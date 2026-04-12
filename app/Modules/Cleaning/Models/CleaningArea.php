<?php

namespace App\Modules\Cleaning\Models;

use App\\Modules\\Core\\Models\\Condominium;
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

    public function checklistTemplateItems()
    {
        return $this->hasMany(CleaningAreaChecklist::class);
    }

    public function schedules()
    {
        return $this->hasMany(CleaningSchedule::class);
    }
}



