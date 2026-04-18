<?php

namespace App\Modules\Portfolio\Models;

use App\Modules\Core\Models\Apartment;
use App\Modules\Core\Models\Condominium;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PortfolioCharge extends Model
{
    use HasFactory;

    protected $table = 'portfolio_charges';

    protected $fillable = [
        'condominium_id',
        'apartment_id',
        'period',
        'amount_total',
        'amount_paid',
        'balance',
        'due_date',
        'status',
        'notes',
        'generated_by',
    ];

    protected $casts = [
        'period' => 'date',
        'due_date' => 'date',
        'amount_total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function apartment()
    {
        return $this->belongsTo(Apartment::class);
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function collections()
    {
        return $this->hasMany(PortfolioCollection::class, 'charge_id');
    }
}

