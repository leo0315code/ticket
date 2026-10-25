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
    <script>
        // 即时消息网关地址（反代部署时配置 GATEWAY_PUBLIC_WS_URL，如 wss://your-domain/im）
        window.GATEWAY_WS_URL = "{{ config('gateway.public_ws_url') }}";
    </script>
</body>
</html>
