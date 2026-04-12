<?php

namespace App\Modules\Security\Models;
use App\Modules\Core\Models\Condominium;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserModulePermission extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'condominium_id',
        'module',
        'can_view',
    ];

    protected $casts = [
        'can_view' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }
}



