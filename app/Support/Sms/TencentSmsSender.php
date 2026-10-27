<?php

namespace App\Support\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** 腾讯云短信（sms 2021-01-11，TC3-HMAC-SHA256 签名） */
class TencentSmsSender implements SmsSender
{
    private const HOST = 'sms.tencentcloudapi.com';

    public function __construct(
        private readonly string $secretId,
        private readonly string $secretKey,
        private readonly string $sdkAppId,
        private readonly string $signName,
        private readonly string $templateId,
        private readonly string $region = 'ap-guangzhou',
    ) {
    }

    public function send(string $phone, string $code, string $purpose): void
    {
        if (blank($this->secretId) || blank($this->secretKey)) {
            throw new RuntimeException('腾讯云短信 SecretId/SecretKey 未配置');
        }
        if (blank($this->sdkAppId) || blank($this->signName) || blank($this->templateId)) {
            throw new RuntimeException('腾讯云短信应用、签名或模板未配置');
        }

        $payload = json_encode([
            'PhoneNumberSet' => [str_starts_with($phone, '+') ? $phone : '+86'.$phone],
            'SmsSdkAppId' => $this->sdkAppId,
            'SignName' => $this->signName,
            'TemplateId' => $this->templateId,
            'TemplateParamSet' => [$code],
        ], JSON_UNESCAPED_UNICODE);

        $timestamp = time();
        $date = gmdate('Y-m-d', $timestamp);
        $service = 'sms';
        $action = 'SendSms';
        $version = '2021-01-11';

        $canonicalRequest = "POST\n/\n\ncontent-type:application/json; charset=utf-8\nhost:".self::HOST."\n\ncontent-type;host\n".hash('sha256', $payload);
        $scope = "{$date}/{$service}/tc3_request";
        $stringToSign = "TC3-HMAC-SHA256\n{$timestamp}\n{$scope}\n".hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $date, 'TC3'.$this->secretKey, true);
        $kService = hash_hmac('sha256', $service, $kDate, true);
        $kSigning = hash_hmac('sha256', 'tc3_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = "TC3-HMAC-SHA256 Credential={$this->secretId}/{$scope}, SignedHeaders=content-type;host, Signature={$signature}";

        $response = Http::timeout(8)
            ->withHeaders([
                'Authorization' => $authorization,
                'Content-Type' => 'application/json; charset=utf-8',
                'Host' => self::HOST,
                'X-TC-Action' => $action,
                'X-TC-Timestamp' => (string) $timestamp,
                'X-TC-Version' => $version,
                'X-TC-Region' => $this->region,
            ])
            ->withBody($payload, 'application/json; charset=utf-8')
            ->post('https://'.self::HOST);

        if ($response->failed()) {
            throw new RuntimeException('短信发送失败（HTTP '.$response->status().'）');
        }

        $body = $response->json();
        $error = $body['Response']['Error'] ?? null;
        if ($error) {
            throw new RuntimeException('短信发送失败：'.($error['Message'] ?? '未知错误'));
        }

        $status = $body['Response']['SendStatusSet'][0]['Code'] ?? '';
        if ($status !== 'Ok') {
            throw new RuntimeException('短信发送失败：'.($body['Response']['SendStatusSet'][0]['Message'] ?? $status));
        }
    }
}
