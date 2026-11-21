<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, MustVerifyEmailTrait, Notifiable, SoftDeletes;

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }

    public function adminAuditEvents(): HasMany
    {
        return $this->hasMany(AdminAuditLog::class, 'actor_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_admin' => 'boolean',
            'settings' => 'array',
            'password' => 'hashed',
            'suspended_at' => 'immutable_datetime',
        ];
    }

    public function isStudioAdmin(): bool
    {
        if ((bool) $this->getAttribute('is_admin')) {
            return true;
        }

        $adminEmail = strtolower(trim((string) config('studio.admin_email', '')));
        $email = strtolower(trim((string) $this->email));

        return $adminEmail !== '' && $email !== '' && hash_equals($adminEmail, $email);
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->isStudioAdmin() || $this->email_verified_at !== null;
    }
}
