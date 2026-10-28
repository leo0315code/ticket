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

#[Fillable(['name', 'username', 'phone', 'email', 'password', 'role', 'is_active', 'notify_email', 'wechat_openid', 'wechat_unionid', 'wechat_nickname', 'wechat_avatar'])]
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

    /** 工单数据范围：all / assigned / own（由角色决定）
     *  「全部工单」需要同时拥有 ticket.view_all，否则按「仅我受理的」降级，避免误开放全量数据。 */
    public function ticketScope(): string
    {
        $scope = Role::scopeFor($this->role);

        if ($scope === 'all' && ! $this->hasPermission('ticket.view_all')) {
            return 'assigned';
        }

        return $scope;
    }

    public function canSeeTicket(\App\Models\Ticket $ticket): bool
    {
        if (! $this->isStaff()) {
            return $ticket->user_id === $this->id;
        }

        return match ($this->ticketScope()) {
            'assigned' => $ticket->agent_id === $this->id,
            'own' => $ticket->user_id === $this->id,
            default => true,
        };
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions();

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    /** 能进后台的所有角色（含自定义角色） */
    /** WS 在线状态的兜底过期窗口（正常断开会立即清空，此处防网关崩溃残留） */
    public const ONLINE_STALE_MINUTES = 10;

    /** 在线 = WebSocket 连接存活（ws_connected_at 由网关在连接/心跳时刷新，断开即清空；10 分钟兜底防网关异常退出残留） */
    public function scopeOnlineStaff(Builder $query): Builder
    {
        return $query->staff()->where('is_active', true)
            ->where('ws_connected_at', '>=', now()->subMinutes(self::ONLINE_STALE_MINUTES));
    }

    public function isOnline(): bool
    {
        return $this->ws_connected_at !== null
            && $this->ws_connected_at->gte(now()->subMinutes(self::ONLINE_STALE_MINUTES));
    }

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
            'notify_email' => 'boolean',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ws_connected_at' => 'datetime',
        ];
    }
}
