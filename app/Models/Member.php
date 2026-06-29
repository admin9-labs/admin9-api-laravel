<?php

namespace App\Models;

use App\Models\Traits\HasModelDefaults;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

#[Fillable(['name', 'email', 'mobile', 'password', 'is_active', 'last_login_at', 'last_login_ip'])]
#[Hidden(['password', 'remember_token'])]
class Member extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory, HasModelDefaults, Notifiable;

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * @return array<string, string>
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'guard' => 'member',
        ];
    }
}
