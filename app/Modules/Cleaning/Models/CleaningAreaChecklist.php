<?php

namespace App\Modules\Cleaning\Models;

use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CleaningAreaChecklist extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'cleaning_area_id',
        'assigned_user_id',
        'item_name',
        'frequency_type',
        'repeat_interval',
        'days_of_week',
        'start_date',
        'end_date',
        'status',
        'last_executed_by_id',
        'last_executed_at',
    ];

    protected $casts = [
        'days_of_week' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'last_executed_at' => 'datetime',
        'repeat_interval' => 'integer',
    ];

    public function cleaningArea()
    {
        return $this->belongsTo(CleaningArea::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function lastExecutedBy()
    {
        return $this->belongsTo(User::class, 'last_executed_by_id');
    }
}


