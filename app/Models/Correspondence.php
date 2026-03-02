<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Correspondence extends Model
{
    use HasFactory;

    public const STATUS_RECEIVED = 'RECEIVED_BY_SECURITY';

    public const STATUS_DELIVERED = 'DELIVERED';

    protected $fillable = [
        'condominium_id',
        'courier_company',
        'package_type',
        'evidence_photo',
        'status',
        'digital_signature',
        'received_by_id',
        'resident_receiver_id',
        'delivered_by_id',
        'delivered_at',
        'apartment_id',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
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

    public function residentReceiver()
    {
        return $this->belongsTo(Resident::class, 'resident_receiver_id');
    }

    /*Scopes*/

    public function scopeDelivered($query)
    {
        return $query->where('status', self::STATUS_DELIVERED);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_RECEIVED);
    }

    public function scopeByCondominium($query, $condominiumId)
    {
        return $query->where('condominium_id', $condominiumId);
    }
}
