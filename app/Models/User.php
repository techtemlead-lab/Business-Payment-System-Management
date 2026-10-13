<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Hidden(['password', 'remember_token', 'nid'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;
    protected $guarded = ['id'];
    protected $attributes = ['status'=>'active','role'=>'dealer'];
    public function staff(): bool { return array_key_exists($this->role, config('madina.roles')); }
    public function allows(string $capability): bool { return $this->status === 'active' && in_array($capability, config('madina.roles.'.$this->role, []), true); }
    public function safe(): array { return $this->only(['id','name','email','role','dealer_code','district','division','status']); }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'profile' => 'array',
            'nid' => 'encrypted',
            'directory_visible' => 'boolean',
        ];
    }
}
