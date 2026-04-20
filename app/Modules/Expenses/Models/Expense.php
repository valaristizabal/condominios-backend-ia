<?php

namespace App\Modules\Expenses\Models;

use App\Modules\Core\Models\Condominium;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasFactory;

    protected $table = 'expenses';

    protected $fillable = [
        'condominium_id',
        'registered_at',
        'expense_type',
        'amount',
        'payment_method',
        'observations',
        'support_path',
        'registered_by',
        'status',
    ];

    protected $casts = [
        'registered_at' => 'date',
        'amount' => 'float',
    ];

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }
}
