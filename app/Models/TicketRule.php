<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 触发器 Lite：新工单标题/正文命中关键词 → 自动设置分类和/或优先级。
 * 只取第一条命中的规则（按 sort、id 排序），不做完整规则引擎。
 */
class TicketRule extends Model
{
    protected $fillable = ['name', 'keywords', 'set_category_id', 'set_priority', 'is_active', 'sort'];

    protected $casts = ['is_active' => 'boolean', 'sort' => 'integer'];

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'set_category_id');
    }

    /** @return list<string> */
    public function keywordList(): array
    {
        return array_values(array_filter(array_map(
            fn ($k) => trim($k),
            preg_split('/[,，\s]+/u', $this->keywords) ?: []
        )));
    }

    public function matches(string $text): bool
    {
        foreach ($this->keywordList() as $kw) {
            if ($kw !== '' && mb_stripos($text, $kw) !== false) {
                return true;
            }
        }

        return false;
    }

    /** 对新建工单应用第一条命中的规则；返回命中的规则或 null */
    public static function applyTo(Ticket $ticket): ?self
    {
        $text = $ticket->title."\n".$ticket->content;
        $rule = static::where('is_active', true)->orderBy('sort')->orderBy('id')->get()
            ->first(fn (self $r) => $r->matches($text));

        if (! $rule) {
            return null;
        }

        $changes = [];
        if ($rule->set_category_id && $rule->set_category_id !== $ticket->category_id) {
            $ticket->category_id = $rule->set_category_id;
            $changes[] = '分类→'.($rule->category?->name ?? $rule->set_category_id);
        }
        if ($rule->set_priority && $rule->set_priority !== $ticket->priority) {
            $ticket->priority = $rule->set_priority;
            $ticket->sla_due_at = now()->addHours(Ticket::slaHours($rule->set_priority));
            $ticket->resolution_due_at = Ticket::resolutionDueFor($rule->set_priority);
            $changes[] = '优先级→'.$rule->set_priority;
        }

        if ($changes) {
            $ticket->save();
            $ticket->log('rule_applied', $ticket->user_id, "命中规则【{$rule->name}】：".implode('、', $changes));
        }

        return $rule;
    }
}
