<?php

namespace App\Modules\Portfolio\Models;

use App\Modules\Core\Models\Apartment;
use App\Modules\Core\Models\Condominium;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PortfolioCollection extends Model
{
    use HasFactory;

    protected $table = 'portfolio_collections';

    protected $fillable = [
        'condominium_id',
        'charge_id',
        'apartment_id',
        'amount',
        'payment_date',
        'evidence_path',
        'evidence_name',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function charge()
    {
        return $this->belongsTo(PortfolioCharge::class, 'charge_id');
    }

    public function apartment()
    {
        return $this->belongsTo(Apartment::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

