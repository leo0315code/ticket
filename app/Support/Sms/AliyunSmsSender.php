<?php

namespace App\Support\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** 阿里云短信（dysmsapi 2017-05-25，RPC 签名） */
class AliyunSmsSender implements SmsSender
{
    private const ENDPOINT = 'https://dysmsapi.aliyuncs.com/';

    public function __construct(
        private readonly string $keyId,
        private readonly string $keySecret,
        private readonly string $signName,
        private readonly string $templateCode,
    ) {
    }

    public function send(string $phone, string $code, string $purpose): void
    {
        if (blank($this->keyId) || blank($this->keySecret)) {
            throw new RuntimeException('阿里云短信 AccessKey 未配置');
        }
        if (blank($this->signName) || blank($this->templateCode)) {
            throw new RuntimeException('阿里云短信签名或模板未配置');
        }

        $params = [
            'AccessKeyId' => $this->keyId,
            'Action' => 'SendSms',
            'Format' => 'JSON',
            'PhoneNumbers' => $phone,
            'RegionId' => 'cn-hangzhou',
            'SignName' => $this->signName,
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureNonce' => (string) Str::uuid(),
            'SignatureVersion' => '1.0',
            'TemplateCode' => $this->templateCode,
            'TemplateParam' => json_encode(['code' => $code], JSON_UNESCAPED_UNICODE),
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'Version' => '2017-05-25',
        ];
        ksort($params);

        $canonical = [];
        foreach ($params as $k => $v) {
            $canonical[] = $this->encode($k).'='.$this->encode((string) $v);
        }
        $stringToSign = 'GET&%2F&'.$this->encode(implode('&', $canonical));
        $params['Signature'] = base64_encode(hash_hmac('sha1', $stringToSign, $this->keySecret.'&', true));

        $response = Http::timeout(8)->acceptJson()->get(self::ENDPOINT, $params);

        if ($response->failed()) {
            throw new RuntimeException('短信发送失败（HTTP '.$response->status().'）');
        }

        $body = $response->json();
        if (($body['Code'] ?? '') !== 'OK') {
            throw new RuntimeException('短信发送失败：'.($body['Message'] ?? '未知错误'));
        }
    }

    /** 阿里云要求的 RFC3986 编码：空格→%20、*→%2A、~ 不编码 */
    private function encode(string $value): string
    {
        return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], urlencode($value));
    }
}
