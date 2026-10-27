<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CannedResponseController;
use App\Http\Controllers\Api\CaptchaController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\FaqController;
use App\Http\Controllers\Api\LoginMethodController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\MentionSearchController;
use App\Http\Controllers\Api\ReplyController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\TicketExportController;
use App\Http\Controllers\Api\WeChatQrController;
use App\Http\Controllers\Api\WsTicketController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\TicketLogController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// 认证类是暴力破解的首要目标：按 IP 限流
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/admin/login', [AuthController::class, 'adminLogin'])->middleware('throttle:10,1');
Route::get('/captcha/issue', [CaptchaController::class, 'issue'])->middleware('throttle:30,1');
Route::post('/captcha/verify', [CaptchaController::class, 'verify'])->middleware('throttle:30,1');
Route::get('/meta', [MetaController::class, 'show'])->middleware('throttle:120,1');

// 备用登录方式（开关在后台「系统设置 → 登录方式」）
Route::post('/auth/phone/code', [LoginMethodController::class, 'phoneCode'])->middleware('throttle:5,1');
Route::post('/auth/phone/login', [LoginMethodController::class, 'phoneLogin'])->middleware('throttle:10,1');
Route::get('/auth/wechat/url', [LoginMethodController::class, 'wechatUrl'])->middleware('throttle:30,1');
Route::post('/auth/wechat/login', [LoginMethodController::class, 'wechatLogin'])->middleware('throttle:10,1');
Route::post('/auth/wechat/qr', [WeChatQrController::class, 'start'])->middleware('throttle:10,1');
Route::post('/auth/wechat/qr/confirm', [WeChatQrController::class, 'confirm'])->middleware('throttle:10,1');
Route::get('/auth/wechat/qr/{scene}', [WeChatQrController::class, 'status'])->middleware('throttle:120,1');

// 找回密码（无邮箱账号只能联系管理员，后续可接手机号/公众号验证）
Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

// 公开帮助中心：未登录也能浏览/搜索/反馈（只返回公开条目）
Route::get('/faqs', [FaqController::class, 'index'])->middleware(['auth.optional', 'throttle:120,1']);
Route::post('/faqs/{faq}/view', [FaqController::class, 'view'])->middleware('throttle:120,1');
Route::post('/faqs/{faq}/feedback', [FaqController::class, 'feedback'])->middleware('throttle:60,1');

Route::middleware(['auth:sanctum', 'active', 'throttle:240,1'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/profile/phone/code', [LoginMethodController::class, 'bindPhoneCode'])->middleware('throttle:5,1');
    Route::put('/profile/phone', [LoginMethodController::class, 'bindPhone']);
    Route::post('/profile/wechat/bind', [LoginMethodController::class, 'bindWechat'])->middleware('throttle:10,1');
    Route::delete('/profile/wechat', [LoginMethodController::class, 'unbindWechat']);

    // 连 WebSocket 前换取一次性票据，避免长期 token 出现在 URL 里
    Route::post('/ws-ticket', [WsTicketController::class, 'store'])->middleware('throttle:30,1');
    Route::post('/logout', [AuthController::class, 'logout']);

    // 工单：可见性由 TicketPolicy 控制（客户只看自己的）
    Route::get('tickets/export', [TicketExportController::class, 'index'])->middleware('permission:ticket.export');
    Route::post('tickets/bulk', [TicketController::class, 'bulk'])->middleware('staff');
    // 回收站：软删除工单的查看与恢复（需删除权限）
    Route::get('tickets/trash', [TicketController::class, 'trash'])->middleware('permission:ticket.delete');
    Route::post('tickets/{id}/restore', [TicketController::class, 'restore'])->middleware('permission:ticket.delete');
    Route::apiResource('tickets', TicketController::class);
    Route::post('/tickets/{ticket}/rating', [TicketController::class, 'rate']);
    Route::post('/tickets/{ticket}/reopen', [TicketController::class, 'reopen']);
    Route::apiResource('tickets.replies', ReplyController::class)->only(['index', 'store']);
    Route::post('/tickets/{ticket}/attachments', [AttachmentController::class, 'store']);
    Route::get('/tickets/{ticket}/attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->name('attachments.download');

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read', [NotificationController::class, 'read']);

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/mention-search', [MentionSearchController::class, 'index']);
    Route::get('/stats/mine', [StatsController::class, 'mine']);
    Route::get('/agents', [AgentController::class, 'index'])->middleware('staff');
    Route::get('/roles/options', [RoleController::class, 'options'])->middleware('staff');

    // 客服工作台
    Route::get('logs', [TicketLogController::class, 'index'])->middleware('permission:log.view');
    Route::get('/canned-responses', [CannedResponseController::class, 'index'])->middleware('permission:canned.manage');
    Route::post('/canned-responses', [CannedResponseController::class, 'store'])->middleware('permission:canned.manage');
    Route::put('/canned-responses/{cannedResponse}', [CannedResponseController::class, 'update'])->middleware('permission:canned.manage');
    Route::delete('/canned-responses/{cannedResponse}', [CannedResponseController::class, 'destroy'])->middleware('permission:canned.manage');
    Route::post('/canned-responses/{cannedResponse}/touch', [CannedResponseController::class, 'touch'])->middleware('permission:canned.manage');
    Route::get('/stats', [StatsController::class, 'index'])->middleware('permission:stats.view');
    Route::get('/stats/ratings', [StatsController::class, 'ratings'])->middleware('permission:stats.view');

    // 内容维护
    Route::post('/categories', [CategoryController::class, 'store'])->middleware('permission:category.manage');
    Route::put('/categories/{category}', [CategoryController::class, 'update'])->middleware('permission:category.manage');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->middleware('permission:category.manage');
    Route::post('/faqs', [FaqController::class, 'store'])->middleware('permission:faq.manage');
    Route::put('/faqs/{faq}', [FaqController::class, 'update'])->middleware('permission:faq.manage');
    Route::delete('/faqs/{faq}', [FaqController::class, 'destroy'])->middleware('permission:faq.manage');

    // 系统管理
    Route::get('/users', [UserController::class, 'index'])->middleware('permission:user.manage');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:user.manage');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:user.manage');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:user.manage');
    Route::put('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('permission:user.manage');
    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:role.manage');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:role.manage');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware('permission:role.manage');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:role.manage');
    Route::get('/settings', [SettingsController::class, 'index'])->middleware('permission:settings.manage');
    Route::post('/settings/sms-test', [SettingsController::class, 'testSms'])->middleware('permission:settings.manage');
    Route::put('/settings', [SettingsController::class, 'update'])->middleware('permission:settings.manage');
});
