<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * 解决时限计算：支持按营业时间累加（非营业时段不算时间）。
 *
 * 口径：resolution_hours 是“营业小时”。未开启营业时间时按自然小时累加。
 * 时区用 business_timezone（默认 Asia/Shanghai），与应用存储时区解耦。
 */
class Sla
{
    public static function resolutionDueAt(CarbonInterface $from, string $priority): CarbonImmutable
    {
        $defaults = ['urgent' => 24, 'high' => 48, 'medium' => 72, 'low' => 120];
        $hours = Settings::get('resolution_hours', $defaults);
        $minutes = max(60, (int) ($hours[$priority] ?? $defaults[$priority] ?? 72) * 60);

        $cursor = CarbonImmutable::instance($from);

        if (! Settings::get('business_hours_enabled', false)) {
            return $cursor->addMinutes($minutes)->setTimezone(config('app.timezone', 'UTC'));
        }

        $tz = Settings::get('business_timezone', 'Asia/Shanghai');
        $days = array_map('intval', (array) Settings::get('business_hours_days', [1, 2, 3, 4, 5]));
        $startAt = (string) Settings::get('business_hours_start', '09:00');
        $endAt = (string) Settings::get('business_hours_end', '18:00');

        $cursor = $cursor->setTimezone($tz);

        // 防御式循环：正常最多几十次，异常配置也不会把进程卡死
        for ($guard = 0; $guard < 2000 && $minutes > 0; $guard++) {
            $dayStart = $cursor->startOfDay()->setTimeFromTimeString($startAt);
            $dayEnd = $cursor->startOfDay()->setTimeFromTimeString($endAt);

            if (! in_array($cursor->isoWeekday(), $days, true) || $cursor->greaterThanOrEqualTo($dayEnd)) {
                $cursor = $cursor->startOfDay()->addDay()->setTimeFromTimeString($startAt);

                continue;
            }

            if ($cursor->lessThan($dayStart)) {
                $cursor = $dayStart;
            }

            $available = (int) (($dayEnd->getTimestamp() - $cursor->getTimestamp()) / 60);
            $take = min($minutes, max(1, $available));
            $cursor = $cursor->addMinutes($take);
            $minutes -= $take;

            if ($minutes > 0) {
                $cursor = $cursor->startOfDay()->addDay()->setTimeFromTimeString($startAt);
            }
        }

        return $cursor->setTimezone(config('app.timezone', 'UTC'));
    }
}
