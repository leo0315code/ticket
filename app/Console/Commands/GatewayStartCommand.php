<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Workerman\Worker;

/**
 * GatewayWorker 启动封装：
 *   php artisan gateway:start           前台启动（ws://0.0.0.0:1238）
 *   php artisan gateway:start -d        守护进程启动
 *   php artisan gateway:start --stop    停止
 *   php artisan gateway:start --restart 重启
 *   php artisan gateway:start --status  查看状态
 *   php artisan gateway:start -d --ssl --cert=/path/full.pem --key=/path/key.pem   以 wss 启动
 * （协议/端口/证书也可用 .env：GATEWAY_PROTOCOL / GATEWAY_PORT / GATEWAY_SSL_CERT / GATEWAY_SSL_KEY）
 */
class GatewayStartCommand extends Command
{
    protected $signature = 'gateway:start
                            {--d|daemon : 守护进程模式运行}
                            {--stop : 停止网关}
                            {--restart : 重启网关}
                            {--status : 查看运行状态}
                            {--mode= : 部署形态覆盖：direct（直连）/ proxy（反代，网关只监听本机）}
                            {--ssl : 直连模式下以 wss 提供（需证书，等价 GATEWAY_SSL=true）}
                            {--cert= : SSL 证书路径（fullchain）}
                            {--key= : SSL 私钥路径}
                            {--internal-start : 内部使用：前台启动}';

    protected $description = '启动/停止 GatewayWorker 即时消息网关（direct 直连 / proxy 反代）';

    public function handle(): int
    {
        // 守护启动：分离子进程执行，避免 Workerman 持有终端管道导致命令挂住
        if ($this->option('daemon') && ! $this->option('internal-start')
            && ! $this->option('stop') && ! $this->option('restart') && ! $this->option('status')) {
            $extra = '';
            if ($mode = $this->option('mode')) {
                $extra .= ' --mode='.escapeshellarg($mode);
            }
            if ($this->option('ssl')) {
                $extra .= ' --ssl';
            }
            foreach (['cert' => 'cert', 'key' => 'key'] as $option => $pass) {
                if ($value = $this->option($option)) {
                    $extra .= ' --'.$pass.'='.escapeshellarg($value);
                }
            }
            $cmd = sprintf(
                'nohup %s %s gateway:start --internal-start%s < /dev/null > /dev/null 2>&1 &',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(base_path('artisan')),
                $extra
            );
            shell_exec($cmd);
            sleep(1);

            if (@file_get_contents(storage_path('app/gateway.pid'))) {
                $this->info(sprintf('Gateway 已在后台启动（模式：%s，监听 %s:%s，证书 %s）', $mode, getenv('GATEWAY_HOST'), getenv('GATEWAY_PORT') ?: '1238', getenv('GATEWAY_SSL') === 'true' ? '开启' : '关闭'));

                return self::SUCCESS;
            }

            $this->error('Gateway 启动失败，请查看 storage/logs/gateway.log');

            return self::FAILURE;
        }

        // 部署形态：命令行 --mode > .env GATEWAY_MODE > 默认 direct
        $mode = $this->option('mode') ?: config('gateway.mode', 'direct');
        if (! in_array($mode, ['direct', 'proxy'], true)) {
            $this->error("未知模式：{$mode}（可选 direct / proxy）");

            return self::FAILURE;
        }

        // 证书参数与 .env 合并：命令行优先
        if ($this->option('ssl')) {
            putenv('GATEWAY_SSL=true');
            $_ENV['GATEWAY_SSL'] = 'true';
            $_SERVER['GATEWAY_SSL'] = 'true';
        }
        // mode 推导 host/ssl 默认值（显式 env 不覆盖）
        putenv("GATEWAY_MODE={$mode}");
        $_ENV['GATEWAY_MODE'] = $mode;
        $_SERVER['GATEWAY_MODE'] = $mode;
        if (! getenv('GATEWAY_HOST')) {
            putenv('GATEWAY_HOST='.($mode === 'proxy' ? '127.0.0.1' : '0.0.0.0'));
        }
        if ($mode === 'proxy' && ! getenv('GATEWAY_SSL')) {
            putenv('GATEWAY_SSL=false'); // proxy 模式 TLS 由反代终结
        }
        foreach (['cert' => 'GATEWAY_SSL_CERT', 'key' => 'GATEWAY_SSL_KEY'] as $option => $envKey) {
            if ($value = $this->option($option)) {
                putenv("$envKey=$value");
                $_ENV[$envKey] = $value;
                $_SERVER[$envKey] = $value;
            }
        }

        // Workerman 从 global $argv 解析命令（argv[1] 为命令字）
        $argv = ['gateway:start'];
        if ($this->option('stop')) {
            $argv[] = 'stop';
        } elseif ($this->option('restart')) {
            $argv[] = 'restart';
        } elseif ($this->option('status')) {
            $argv[] = 'status';
        } else {
            $argv[] = 'start';
            if ($this->option('daemon') || $this->option('internal-start')) {
                $argv[] = '-d';
            }
        }

        // Workerman 从全局 argv 解析命令
        $GLOBALS['argv'] = $argv;
        $GLOBALS['argc'] = count($argv);
        $_SERVER['argv'] = $argv;
        $_SERVER['argc'] = count($argv);

        config(['gateway.protocol' => getenv('GATEWAY_PROTOCOL') ?: config('gateway.protocol')]);
        config(['gateway.ssl_cert' => getenv('GATEWAY_SSL_CERT') ?: config('gateway.ssl_cert')]);
        config(['gateway.ssl_key' => getenv('GATEWAY_SSL_KEY') ?: config('gateway.ssl_key')]);

        require __DIR__.'/../../../gateway/start.php';

        return self::SUCCESS;
    }
}
