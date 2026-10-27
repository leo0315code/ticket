<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/**
 * 权限/配置类敏感操作的审计流水（只追加，不修改）。
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id', 'actor_name', 'action', 'target_type', 'target_id', 'target_label', 'meta', 'ip',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    /** 记录一条审计：$meta 里不要放密钥明文 */
    public static function record(string $action, ?object $target = null, array $meta = []): self
    {
        $actor = request()->user() ?? auth()->user();

        return self::create([
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name ?? 'system',
            'action' => $action,
            'target_type' => $target ? class_basename($target) : '',
            'target_id' => $target?->id,
            'target_label' => (string) ($target->name ?? $target->label ?? $target->no ?? ''),
            'meta' => $meta ?: null,
            'ip' => (string) Request::ip(),
        ]);
    }
}
