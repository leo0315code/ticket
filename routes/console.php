<?php

use Illuminate\Support\Facades\Schedule;

// 每 5 分钟检查一次工单 SLA 超时（php artisan schedule:work 启动调度器）
Schedule::command('tickets:check-sla')->everyFiveMinutes();

// 待确认满 7 天自动关闭（每天凌晨 2 点）
Schedule::command('tickets:auto-close')->dailyAt('02:00');
