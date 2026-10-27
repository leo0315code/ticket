<?php

namespace App\Support\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 通用 HTTP 短信网关：POST JSON
 * { phone, code, content, purpose }
 * 返回 2xx 视为成功；接阿里云/腾讯云时，用一层自己的转发服务对接即可。
 */
class HttpSmsSender implements SmsSender
{
    public function __construct(private readonly string $url, private readonly string $sign)
    {
    }

    public function send(string $phone, string $code, string $purpose): void
    {
        if (blank($this->url)) {
            throw new RuntimeException('短信网关地址未配置');
        }

        $content = "【{$this->sign}】验证码 {$code}，5 分钟内有效，请勿泄露给他人。";

        $response = Http::timeout(8)->acceptJson()->post($this->url, [
            'phone' => $phone,
            'code' => $code,
            'content' => $content,
            'purpose' => $purpose,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('短信发送失败（HTTP '.$response->status().'）');
        }
    }
}
