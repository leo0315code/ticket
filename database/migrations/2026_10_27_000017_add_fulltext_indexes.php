<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MySQL 全文索引：工单标题/正文、回复正文、FAQ 问答。
 * SQLite（测试环境）不支持 MATCH AGAINST，代码里会自动退回 LIKE。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 中文必须用 ngram 分词器，否则整句被当成一个词；插件不可用时退回默认分词
        $indexes = [
            'ALTER TABLE tickets ADD FULLTEXT tickets_fulltext (title, content)',
            'ALTER TABLE ticket_replies ADD FULLTEXT ticket_replies_fulltext (content)',
            'ALTER TABLE faqs ADD FULLTEXT faqs_fulltext (question, answer)',
        ];

        foreach ($indexes as $sql) {
            try {
                DB::statement($sql.' WITH PARSER ngram');
            } catch (\Throwable $e) {
                DB::statement($sql);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE tickets DROP INDEX tickets_fulltext');
        DB::statement('ALTER TABLE ticket_replies DROP INDEX ticket_replies_fulltext');
        DB::statement('ALTER TABLE faqs DROP INDEX faqs_fulltext');
    }
};
