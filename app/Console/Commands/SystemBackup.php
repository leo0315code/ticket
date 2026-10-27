<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * 备份数据库 + 附件目录，并按保留天数清理旧备份。
 *
 * 用法：php artisan system:backup [--keep-days=14]
 * 建议放在 scheduler 里每天凌晨跑，备份目录（storage/backups）再同步到异地。
 */
class SystemBackup extends Command
{
    protected $signature = 'system:backup {--keep-days=14} {--dir=}';

    protected $description = '备份 MySQL/SQLite 数据库与 storage/app 下的附件';

    public function handle(): int
    {
        $dir = $this->option('dir') ?: storage_path('backups');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->error("无法创建备份目录：{$dir}");

            return self::FAILURE;
        }

        $stamp = now()->format('Ymd-His');
        $done = [];

        if ($this->backupDatabase($dir, $stamp)) {
            $done[] = 'database';
        }

        if ($this->backupAttachments($dir, $stamp)) {
            $done[] = 'attachments';
        }

        $removed = $this->prune($dir, (int) $this->option('keep-days'));

        $this->info(sprintf(
            '备份完成：%s；清理旧备份 %d 个；目录 %s',
            $done ? implode(' + ', $done) : '无',
            $removed,
            $dir,
        ));

        return $done ? self::SUCCESS : self::FAILURE;
    }

    private function backupDatabase(string $dir, string $stamp): bool
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");
        $target = "{$dir}/db-{$stamp}.sql.gz";

        if ($config['driver'] === 'sqlite') {
            $source = $config['database'];
            if (! is_file($source)) {
                $this->warn('SQLite 文件不存在，跳过数据库备份');

                return false;
            }
            $sql = file_get_contents($source);
            file_put_contents($target, gzencode($sql));

            return true;
        }

        if ($config['driver'] !== 'mysql') {
            $this->warn("暂不支持的数据库驱动：{$config['driver']}");

            return false;
        }

        $command = [
            'mysqldump',
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            '--single-transaction',
            '--routines',
            '--triggers',
            '--default-character-set=utf8mb4',
            $config['database'],
        ];

        // 密码走环境变量，避免出现在进程列表里
        $process = new Process($command, null, ['MYSQL_PWD' => (string) $config['password']]);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('mysqldump 失败：'.trim($process->getErrorOutput()));

            return false;
        }

        file_put_contents($target, gzencode($process->getOutput()));

        return true;
    }

    private function backupAttachments(string $dir, string $stamp): bool
    {
        // 附件默认落在 storage/app/private/attachments（local 盘），public 盘也可能有历史文件，一并打包
        $source = storage_path('app');
        if (! is_dir($source)) {
            return false;
        }

        $target = "{$dir}/storage-app-{$stamp}.tar.gz";
        $process = new Process(['tar', '-czf', $target, '-C', $source, '.']);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('打包附件失败：'.trim($process->getErrorOutput()));

            return false;
        }

        return true;
    }

    /** 只清理本命令产生的备份文件，避免误删目录里的其他东西 */
    private function prune(string $dir, int $keepDays): int
    {
        $deadline = now()->subDays(max(1, $keepDays))->getTimestamp();
        $removed = 0;

        $files = array_merge(
            glob("{$dir}/db-*.sql.gz") ?: [],
            glob("{$dir}/storage-app-*.tar.gz") ?: [],
        );

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $deadline) {
                @unlink($file);
                $removed++;
            }
        }

        return $removed;
    }
}
