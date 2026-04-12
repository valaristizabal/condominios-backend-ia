<?php

namespace App\Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CleaningAreaChecklist extends Model
{
    use HasFactory;

    protected $fillable = [
        'cleaning_area_id',
        'item_name',
    ];

    public function cleaningArea()
    {
        return $this->belongsTo(CleaningArea::class);
    }
}


