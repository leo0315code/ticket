<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * 附件巡检：清掉“文件已丢”的记录和“没人引用”的孤儿文件。
 *
 * attachments:prune --dry-run   只报告不删除
 */
class PruneAttachments extends Command
{
    protected $signature = 'attachments:prune {--dry-run} {--orphan-hours=24}';

    protected $description = '清理丢失文件的上传记录与无引用的孤儿附件';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        // 与上传/下载使用同一个盘（默认 local）
        $disk = Storage::disk(config('filesystems.default'));

        // 1) 数据库有记录、磁盘没文件
        $missing = 0;
        Attachment::query()->chunkById(200, function ($rows) use ($disk, $dry, &$missing) {
            foreach ($rows as $row) {
                if (! $disk->exists($row->path)) {
                    $missing++;
                    if (! $dry) {
                        $row->delete();
                    }
                }
            }
        });

        // 2) 磁盘有文件、数据库没引用（留出上传中的竞态窗口）
        $referenced = Attachment::query()->pluck('path')->flip();
        $orphans = 0;
        $deadline = now()->subHours(max(1, (int) $this->option('orphan-hours')))->getTimestamp();

        foreach ($disk->allFiles('attachments') as $path) {
            if ($referenced->has($path) || $disk->lastModified($path) > $deadline) {
                continue;
            }
            $orphans++;
            if (! $dry) {
                $disk->delete($path);
            }
        }

        $this->info(sprintf(
            '%s：丢失文件的记录 %d 条，孤儿文件 %d 个',
            $dry ? '预演' : '已清理',
            $missing,
            $orphans,
        ));

        return self::SUCCESS;
    }
}
