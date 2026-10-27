<?php

namespace App\Models;

use App\Support\Settings;
use App\Support\Sla;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUSES = ['open', 'in_progress', 'pending_resolved', 'closed'];
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];
    public const STATUS_LABELS = [
        'open' => '待处理',
        'in_progress' => '处理中',
        'pending_resolved' => '待确认',
        'closed' => '已关闭',
    ];
    public const PRIORITY_LABELS = [
        'low' => '低',
        'medium' => '中',
        'high' => '高',
        'urgent' => '紧急',
    ];

    /** SLA 响应时限（小时），按优先级 */
    public const SLA_HOURS = [
        'urgent' => 4,
        'high' => 8,
        'medium' => 24,
        'low' => 72,
    ];

    /** 按优先级取首次响应时限（后台「系统设置」可改，默认见 SLA_HOURS） */
    public static function slaHours(string $priority): int
    {
        $hours = Settings::get('sla_hours', self::SLA_HOURS);

        return (int) ($hours[$priority] ?? self::SLA_HOURS[$priority] ?? 24);
    }

    protected $fillable = [
        'no', 'title', 'content', 'status', 'priority',
        'user_id', 'agent_id', 'category_id',
        'resolved_at', 'closed_at', 'rating', 'rating_comment',
        'sla_due_at', 'resolution_due_at', 'first_response_at',
        'closed_reason', 'reopen_count', 'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'first_response_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'reopen_count' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    /** 按优先级取解决时限（营业小时，后台可配） */
    public static function resolutionHours(string $priority): int
    {
        $hours = Settings::get('resolution_hours', ['urgent' => 24, 'high' => 48, 'medium' => 72, 'low' => 120]);

        return (int) ($hours[$priority] ?? 72);
    }

    public static function resolutionDueFor(string $priority, ?\Carbon\CarbonInterface $from = null): \Carbon\CarbonInterface
    {
        return Sla::resolutionDueAt($from ?? now(), $priority);
    }

    /** 解决时限已过且仍未解决（关闭/待确认都算已解决） */
    public function isResolutionOverdue(): bool
    {
        return $this->resolution_due_at !== null
            && $this->resolved_at === null
            && ! in_array($this->status, ['pending_resolved', 'closed'], true)
            && $this->resolution_due_at->isPast();
    }

    /** 超时＝首次响应时限已过且尚未响应（已响应的工单不再算超时） */
    public function isOverdue(): bool
    {
        return $this->first_response_at === null
            && in_array($this->status, ['open', 'in_progress'], true)
            && $this->sla_due_at !== null
            && $this->sla_due_at->isPast();
    }

    /** 当前用户能否重开：本人 + 已关闭 + 未超重开窗口/次数（后台可配） */
    public function canBeReopenedBy(?User $user): bool
    {
        if (! $user || $user->id !== $this->user_id || $this->status !== 'closed') {
            return false;
        }
        if ((int) $this->reopen_count >= (int) Settings::get('reopen_limit', 3)) {
            return false;
        }

        $days = (int) Settings::get('reopen_days', 14);

        return ! ($this->closed_at && $this->closed_at->lt(now()->subDays($days)));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<TicketReply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }

    /** 关注者（收到公开回复与状态变更通知，但不改变可见性） */
    public function watchers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ticket_watchers')->withTimestamps();
    }

    public function isWatchedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->relationLoaded('watchers')
            ? $this->watchers->contains('id', $user->id)
            : $this->watchers()->where('users.id', $user->id)->exists();
    }

    /** @return HasMany<TicketLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(TicketLog::class);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function log(string $action, int $userId, ?string $detail = null): void
    {
        $this->logs()->create(['user_id' => $userId, 'action' => $action, 'detail' => $detail]);
    }

    /** 生成工单号：TK-20261024-0001（序号按数值取最大，超过 9999 也不会回绕） */
    public static function nextNo(): string
    {
        $prefix = 'TK-'.now()->format('Ymd').'-';
        $max = static::withTrashed()
            ->where('no', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(no, ?) AS UNSIGNED)) AS seq', [strlen($prefix) + 1])
            ->value('seq');

        return $prefix.str_pad((string) ((int) $max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** @param Builder<static> $query */
    /** 数据范围：普通用户只看自己的；客服按角色范围（全部 / 仅我受理 / 仅我提交） */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->isStaff()) {
            return $query->where('user_id', $user->id);
        }

        return match ($user->ticketScope()) {
            'assigned' => $query->where('agent_id', $user->id),
            'own' => $query->where('user_id', $user->id),
            default => $query,
        };
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['priority'] ?? null, fn (Builder $q, $v) => $q->where('priority', $v))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $v) => $q->where('category_id', $v))
            ->when($filters['keyword'] ?? null, function (Builder $q, $v) {
                $q->where(function (Builder $q) use ($v) {
                    $q->where('title', 'like', "%{$v}%")->orWhere('no', 'like', "%{$v}%");
                });
            })
            ->when($filters['mine'] ?? null, fn (Builder $q, $v) => $q->where('agent_id', $v))
            ->when($filters['overdue'] ?? null, function (Builder $q) {
                $q->whereIn('status', ['open', 'in_progress'])
                    ->whereNull('first_response_at')
                    ->whereNotNull('sla_due_at')
                    ->where('sla_due_at', '<', now());
            });
    }
}
