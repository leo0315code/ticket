<?php

namespace App\Support\Sms;

interface SmsSender
{
    /** 发送一条验证码短信；失败时抛异常，由调用方转成用户可读错误 */
    public function send(string $phone, string $code, string $purpose): void;
}
