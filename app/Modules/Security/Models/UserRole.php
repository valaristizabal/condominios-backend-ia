<?php

namespace App\Modules\Security\Models;
use App\\Modules\\Core\\Models\\Condominium;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserRole extends Model
{
    use HasFactory;

    protected $table = 'user_role';

    protected $fillable = [
        'user_id',
        'role_id',
        'condominium_id',
    ];

    /*Relationships*/

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function condominium()
    {
        return $this->belongsTo(Condominium::class);
    }
}



