<?php

use Illuminate\Support\Facades\Schedule;

// 每 5 分钟检查一次工单 SLA 超时（php artisan schedule:work 启动调度器）
Schedule::command('tickets:check-sla')->everyFiveMinutes();
