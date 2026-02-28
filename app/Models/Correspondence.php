<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Correspondence extends Model
{
    use HasFactory;

    protected $fillable = [
        'condominium_id',
        'courier_company',
        'package_type',
        'evidence_photo',
        'delivered',
        'digital_signature',
        'received_by_id',
        'delivered_by_id',
        'apartment_id',
    ];

    /*Relationships*/

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }

    public function apartment()
    {
        return $this->belongsTo(\App\Models\Apartment::class);
    }
    
    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by_id');
    }

    public function deliveredBy()
    {
        return $this->belongsTo(User::class, 'delivered_by_id');
    }

    /*Scopes*/

    public function scopeDelivered($query)
    {
        return $query->where('delivered', true);
    }

    public function scopePending($query)
    {
        return $query->where('delivered', false);
    }

    public function scopeByCondominium($query, $condominiumId)
    {
        return $query->where('condominium_id', $condominiumId);
    }
}
