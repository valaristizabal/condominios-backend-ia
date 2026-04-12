<?php

namespace App\Modules\Cleaning\Models;
use App\\Modules\\Core\\Models\\Condominium;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CleaningSchedule extends Model
{
    use HasFactory;

    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_MONTHLY = 'monthly';
    public const FREQUENCY_CUSTOM = 'custom';

    protected $fillable = [
        'condominium_id',
        'cleaning_area_id',
        'name',
        'description',
        'frequency_type',
        'repeat_interval',
        'days_of_week',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'days_of_week' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'repeat_interval' => 'integer',
    ];

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function cleaningArea()
    {
        return $this->belongsTo(CleaningArea::class);
    }
}




