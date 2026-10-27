<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class Search
{
    public static function fulltextAvailable(): bool
    {
        return DB::getDriverName() === 'mysql';
    }

    /** 转成 BOOLEAN MODE 表达式：去掉 * + 等操作符，避免用户输入把查询搞坏 */
    public static function boolean(string $keyword): string
    {
        $clean = preg_replace('/[+\-><()~*"@]+/', ' ', $keyword) ?? $keyword;

        return trim($clean);
    }
}
