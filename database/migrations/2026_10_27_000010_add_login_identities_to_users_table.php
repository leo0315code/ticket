<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 微信公众号 / 开放平台扫码登录标识
            $table->string('wechat_openid', 64)->nullable()->unique()->after('phone');
            $table->string('wechat_unionid', 64)->nullable()->index()->after('wechat_openid');
            $table->string('wechat_nickname', 64)->nullable()->after('wechat_unionid');
            $table->string('wechat_avatar', 255)->nullable()->after('wechat_nickname');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['wechat_openid']);
            $table->dropIndex(['wechat_unionid']);
            $table->dropColumn(['wechat_openid', 'wechat_unionid', 'wechat_nickname', 'wechat_avatar']);
        });
    }
};
