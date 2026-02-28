<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CleaningRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'condominium_id',
        'cleaning_area_id',
        'operative_id',
        'registered_by_id',
        'cleaning_date',
        'status',
        'observations',
    ];

    /* Relaciones */

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function cleaningArea()
    {
        return $this->belongsTo(CleaningArea::class);
    }

    public function operative()
    {
        return $this->belongsTo(Operative::class);
    }

    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by_id');
    }

    public function checklistItems()
    {
        return $this->hasMany(CleaningChecklistItem::class);
    }

    /* Scopes útiles */

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}
