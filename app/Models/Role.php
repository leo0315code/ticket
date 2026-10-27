<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['name', 'label', 'permissions', 'is_system'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_system' => 'boolean',
        ];
    }

    /** @var array<string, self>|null */
    private static ?array $cache = null;

    /** @return array<string, self> */
    public static function allCached(): array
    {
        if (self::$cache === null) {
            self::$cache = self::query()->orderBy('id')->get()->keyBy('name')->all();
        }

        return self::$cache;
    }

    /** @return string[] */
    public static function permissionsFor(?string $name): array
    {
        $role = $name ? (self::allCached()[$name] ?? null) : null;

        return $role?->permissions ?? [];
    }

    /** 能进后台的角色：有 panel.access（或通配 *）的都算 */
    /** @return string[] */
    public static function staffNames(): array
    {
        return collect(self::allCached())
            ->filter(fn (self $role) => $role->has('panel.access'))
            ->keys()
            ->all();
    }

    public static function flushCache(): void
    {
        self::$cache = null;
    }

    public function has(string $permission): bool
    {
        $permissions = $this->permissions ?? [];

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public function permissionCount(): int
    {
        $permissions = $this->permissions ?? [];

        return in_array('*', $permissions, true)
            ? count(\App\Support\Permissions::keys())
            : count($permissions);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role', 'name');
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::flushCache());
        static::deleted(fn () => self::flushCache());
    }
}
