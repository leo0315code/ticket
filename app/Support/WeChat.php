<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 微信扫码登录：
 * - open：开放平台「网站应用」扫码（qrconnect），适合 PC 端
 * - mp：公众号网页授权（oauth2/authorize），适合微信内置浏览器
 * 两者最终都用 code 换 openid。
 */
class WeChat
{
    public static function enabled(): bool
    {
        return (bool) Settings::get('wechat_login_enabled')
            && filled(Settings::get('wechat_app_id'))
            && filled(Settings::get('wechat_app_secret'));
    }

    public static function authorizeUrl(string $state, string $redirectUri): string
    {
        $query = http_build_query([
            'appid' => Settings::get('wechat_app_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => Settings::get('wechat_mode') === 'mp' ? 'snsapi_userinfo' : 'snsapi_login',
            'state' => $state,
        ]);

        $endpoint = Settings::get('wechat_mode') === 'mp'
            ? 'https://open.weixin.qq.com/connect/oauth2/authorize'
            : 'https://open.weixin.qq.com/connect/qrconnect';

        return $endpoint.'?'.$query.'#wechat_redirect';
    }

    /** @return array{openid:string,unionid:?string,nickname:?string,avatar:?string} */
    public static function identityByCode(string $code): array
    {
        $token = Http::timeout(8)->get('https://api.weixin.qq.com/sns/oauth2/access_token', [
            'appid' => Settings::get('wechat_app_id'),
            'secret' => Settings::get('wechat_app_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
        ])->json();

        if (empty($token['openid'])) {
            throw new RuntimeException($token['errmsg'] ?? '微信授权失败，请重新扫码');
        }

        $identity = [
            'openid' => (string) $token['openid'],
            'unionid' => $token['unionid'] ?? null,
            'nickname' => null,
            'avatar' => null,
        ];

        try {
            $profile = Http::timeout(8)->get('https://api.weixin.qq.com/sns/userinfo', [
                'access_token' => $token['access_token'] ?? '',
                'openid' => $identity['openid'],
                'lang' => 'zh_CN',
            ])->json();

            $identity['nickname'] = $profile['nickname'] ?? null;
            $identity['avatar'] = $profile['headimgurl'] ?? null;
        } catch (\Throwable) {
            // 头像昵称拿不到不影响登录
        }

        return $identity;
    }

    public static function callbackUrl(): string
    {
        $base = app()->runningInConsole() ? (string) config('app.url') : request()->getSchemeAndHttpHost();

        return rtrim($base, '/').'/wechat/callback';
    }

    /** 防 CSRF 的 state：时间戳 + HMAC，10 分钟内有效 */
    public static function state(): string
    {
        $ts = (string) now()->timestamp;

        return $ts.'.'.hash_hmac('sha256', $ts, (string) config('app.key'));
    }

    public static function stateValid(?string $state): bool
    {
        [$ts, $sig] = array_pad(explode('.', (string) $state, 2), 2, '');

        if (! ctype_digit($ts) || $sig === '') {
            return false;
        }

        if (now()->timestamp - (int) $ts > 600) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $ts, (string) config('app.key')), $sig);
    }
}
