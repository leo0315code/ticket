<?php

// 管理后台（SPA）路径配置
return [
    // 后台路由前缀：避免使用 admin 等常见路径被扫描探测；建议改成随机串（改后无需重新构建前端）
    'path' => env('ADMIN_PATH', 'panel'),
];
