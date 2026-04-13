<?php

namespace App\Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CleaningChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cleaning_record_id',
        'source_checklist_item_id',
        'item_name',
        'completed',
    ];

    protected $casts = [
        'completed' => 'boolean',
    ];

    /* Relaciones */

    public function cleaningRecord()
    {
        return $this->belongsTo(CleaningRecord::class);
    }

    public function sourceChecklistItem()
    {
        return $this->belongsTo(CleaningAreaChecklist::class, 'source_checklist_item_id');
    }
}


