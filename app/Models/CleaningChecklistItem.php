<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CleaningChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cleaning_record_id',
        'item_name',
        'completed',
    ];

    /* Relaciones */

    public function cleaningRecord()
    {
        return $this->belongsTo(CleaningRecord::class);
    }
}
