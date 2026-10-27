<?php

namespace App\Support\Sms;

use Illuminate\Support\Facades\Log;

/** 开发/联调用：验证码写进 laravel.log，不真正发短信 */
class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $code, string $purpose): void
    {
        Log::info('[sms] 验证码', ['phone' => $phone, 'code' => $code, 'purpose' => $purpose]);
    }
}
