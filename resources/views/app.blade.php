<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>工单系统</title>
    @vite(['resources/js/app.js'])
</head>
<body>
    <div id="app"></div>
    @php
        // 即时消息网关地址：未配置 GATEWAY_PUBLIC_WS_URL 时，自动用当前站点的 /ws
        // （https 页面 → wss://host/ws，需要 nginx 把 /ws 反代到网关，见 docs/REALTIME.md）
        $gatewayWsUrl = config('gateway.public_ws_url')
            ?: (request()->isSecure() ? 'wss' : 'ws')
                .'://'.request()->getHttpHost().config('gateway.public_ws_path', '/ws');
    @endphp
    <script>
        // 即时消息网关地址（直连网关时配置 GATEWAY_PUBLIC_WS_URL，如 wss://your-domain/im）
        window.GATEWAY_WS_URL = "{{ $gatewayWsUrl }}";
        // 管理后台路径前缀（ADMIN_PATH，可配置避免暴露）
        window.ADMIN_PATH = "{{ config('admin.path') }}";
    </script>
</body>
</html>
