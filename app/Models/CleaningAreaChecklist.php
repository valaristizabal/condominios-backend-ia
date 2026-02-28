<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CleaningAreaChecklist extends Model
{
    protected $fillable = [
        'cleaning_area_id',
        'item_name',
    ];

    public function cleaningArea()
    {
        return $this->belongsTo(CleaningArea::class);
    }
}