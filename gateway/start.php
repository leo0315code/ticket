<?php
/**
 * GatewayWorker 启动脚本（单机版；ws / wss 兼容）
 *
 * 通过 artisan（推荐）：
 *   php artisan gateway:start -d                     守护启动（ws）
 *   php artisan gateway:start --stop / --restart / --status
 *   php artisan gateway:start -d --ssl --cert=fullchain.pem --key=key.pem   守护启动（wss）
 * 独立启动：php gateway/start.php start -d
 * 端口/证书可用 .env 配置：GATEWAY_PORT GATEWAY_SSL GATEWAY_SSL_CERT GATEWAY_SSL_KEY
 */

use GatewayWorker\BusinessWorker;
use GatewayWorker\Gateway;
use GatewayWorker\Register;
use Workerman\Worker;

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/Events.php';

// 独立运行时读取项目 .env（artisan 启动时 Laravel 已加载）
if (! getenv('GATEWAY_PORT')) {
    $dotenv = Dotenv\Dotenv::createUnsafeImmutable(dirname(__DIR__));
    $dotenv->safeLoad();
}

// 固定 pid/log 路径：保证 start/stop/status 无论从 artisan 还是独立脚本启动都指向同一份
Worker::$pidFile = storage_path('app/gateway.pid');
Worker::$logFile = storage_path('logs/gateway.log');
Worker::$stdoutFile = storage_path('logs/gateway_stdout.log');

$mode = getenv('GATEWAY_MODE') ?: 'direct';
$ssl = (getenv('GATEWAY_SSL') ?: 'false') === 'true';
$port = getenv('GATEWAY_PORT') ?: 1238;
// host 默认随模式：direct=0.0.0.0 / proxy=127.0.0.1（显式 GATEWAY_HOST 优先）

$context = [];
if ($ssl) {
    $cert = getenv('GATEWAY_SSL_CERT');
    $key = getenv('GATEWAY_SSL_KEY');
    if (! $cert || ! $key) {
        fwrite(STDERR, "wss 需要证书：请设置 GATEWAY_SSL_CERT / GATEWAY_SSL_KEY 或使用 --cert= --key=\n");
        exit(1);
    }
    $context = ['ssl' => [
        'local_cert' => $cert,
        'local_pk' => $key,
        'verify_peer' => false,
    ]];
}

// 注意：监听协议必须用 websocket://（GatewayWorker 约定，服务端握手由 Websocket 协议类实现；
// 不能用 ws:// —— 那是纯客户端协议类，且其 onClose 存在类型缺陷会让 Gateway 子进程崩溃）
// GATEWAY_HOST：直连模式 0.0.0.0；走 nginx 反代建议 127.0.0.1（仅本机可达，TLS 由反代终结）
$host = getenv('GATEWAY_HOST') ?: ($mode === 'proxy' ? '127.0.0.1' : '0.0.0.0');
$gateway = new Gateway('websocket://'.$host.':'.$port, $context);
$gateway->name = 'Gateway';
if ($ssl) {
    $gateway->transport = 'ssl'; // wss
}
$gateway->count = (int) (getenv('GATEWAY_COUNT') ?: 2);
$gateway->lanIp = '127.0.0.1';
$gateway->startPort = 2300;
$gateway->registerAddress = getenv('GATEWAY_REGISTER_ADDRESS') ?: '127.0.0.1:1236';
// 心跳：45s 内未回应心跳断开（浏览器会自动回 pong）
$gateway->pingInterval = 15;
$gateway->pingNotResponseLimit = 3;
$gateway->pingData = '{"type":"ping"}';

$business = new BusinessWorker();
$business->name = 'BusinessWorker';
$business->count = (int) (getenv('GATEWAY_BUSINESS_COUNT') ?: 2);
$business->eventHandler = 'Events';
$business->registerAddress = $gateway->registerAddress;

new Register('text://0.0.0.0:1236');

Worker::runAll();
