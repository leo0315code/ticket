<?php

// GatewayWorker 即时消息网关
return [
    // Laravel 推送用的 Register 地址
    'register_address' => env('GATEWAY_REGISTER_ADDRESS', '127.0.0.1:1236'),

    /**
     * 部署形态总开关：
     * - direct（默认）：客户端直连网关。GATEWAY_SSL=true 时网关自己以 wss 对外（需证书）
     * - proxy        ：TLS 由 nginx 等反向代理终结。网关只监听 127.0.0.1 明文，
     *                  前端连 wss://your-domain/im（GATEWAY_PUBLIC_WS_URL）
     * mode 只决定 host/ssl 的默认值；显式设置的 GATEWAY_HOST / GATEWAY_SSL 优先级更高
     */
    'mode' => env('GATEWAY_MODE', 'direct'),

    // 网关监听端口
    'port' => (int) env('GATEWAY_PORT', 1238),
    // 监听地址：默认随 mode（direct=0.0.0.0 / proxy=127.0.0.1），可用 GATEWAY_HOST 覆盖
    'host' => env('GATEWAY_HOST'),
    // TLS：默认随 mode（direct 读 GATEWAY_SSL / proxy 恒 false），可用 GATEWAY_SSL 覆盖
    'ssl' => env('GATEWAY_SSL'),
    // 前端实际连接的 WebSocket 地址（注入 blade；proxy 模式如 wss://your-domain/im）
    'public_ws_url' => env('GATEWAY_PUBLIC_WS_URL', 'ws://127.0.0.1:1238'),

    // wss 证书（direct + GATEWAY_SSL=true 时必填）
    'ssl_cert' => env('GATEWAY_SSL_CERT'),
    'ssl_key' => env('GATEWAY_SSL_KEY'),

    'gateway_count' => (int) env('GATEWAY_COUNT', 2),
    'business_count' => (int) env('GATEWAY_BUSINESS_COUNT', 2),
];
