<?php

namespace App\Support\Sms;

use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** 云片短信（单条发送 API） */
class YunpianSmsSender implements SmsSender
{
    public function __construct(private readonly string $apiKey, private readonly string $sign)
    {
    }

    public function send(string $phone, string $code, string $purpose): void
    {
        if (blank($this->apiKey)) {
            throw new RuntimeException('云片短信 APIKEY 未配置');
        }

        $minutes = max(1, (int) round((int) Settings::get('sms_code_ttl', 300) / 60));
        $text = "【{$this->sign}】验证码 {$code}，{$minutes} 分钟内有效，请勿泄露给他人。";

        $response = Http::timeout(8)->asForm()->post('https://sms.yunpian.com/v2/sms/single_send.json', [
            'apikey' => $this->apiKey,
            'mobile' => $phone,
            'text' => $text,
        ]);

        $body = $response->json() ?: [];
        if ($response->failed() || (int) ($body['code'] ?? -1) !== 0) {
            throw new RuntimeException('短信发送失败：'.($body['msg'] ?? 'HTTP '.$response->status()));
        }
    }
}
