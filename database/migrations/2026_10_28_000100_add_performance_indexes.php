<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 为高频过滤/排序列补索引：SLA 巡检、报表按天统计、回收站、SLA 子查询、FAQ 搜索分析（已存在的跳过，兼容库漂移） */
return new class extends Migration
{
    /** @var array<string, list<string|list<string>>> 表 => 列（或复合列） */
    private const INDEXES = [
        'tickets' => ['sla_due_at', 'created_at', 'deleted_at'],
        'ticket_logs' => [['ticket_id', 'action']],
        'faq_search_logs' => ['keyword'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            foreach ($columns as $column) {
                $cols = (array) $column;
                $name = $table.'_'.implode('_', $cols).'_index';
                if (Schema::hasIndex($table, $name)) {
                    continue;
                }
                Schema::table($table, function (Blueprint $t) use ($cols) {
                    $t->index($cols);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            foreach ($columns as $column) {
                $cols = (array) $column;
                $name = $table.'_'.implode('_', $cols).'_index';
                if (! Schema::hasIndex($table, $name)) {
                    continue;
                }
                Schema::table($table, function (Blueprint $t) use ($name) {
                    $t->dropIndex($name);
                });
            }
        }
    }
};
