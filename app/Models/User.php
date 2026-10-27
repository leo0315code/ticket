<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'username', 'phone', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    public const ROLES = ['user', 'agent', 'admin'];

    /** 权限缓存：一次请求内同一用户只查一次角色表 */
    private ?array $permissionCache = null;

    public function isActive(): bool
    {
        return $this->is_active !== false;
    }

    /** @return string[] */
    public function permissions(): array
    {
        if ($this->permissionCache === null) {
            $this->permissionCache = Role::permissionsFor($this->role);
        }

        return $this->permissionCache;
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions();

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    /** 能进后台的所有角色（含自定义角色） */
    public function scopeStaff(Builder $query): Builder
    {
        return $query->whereIn('role', Role::staffNames());
    }

    /** 能进后台 = 有 panel.access（或通配） */
    public function isStaff(): bool
    {
        return $this->hasPermission('panel.access');
    }

    public function isAdmin(): bool
    {
        return $this->hasPermission('*');
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** @return HasMany<Ticket, $this> */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'agent_id');
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
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }
}
