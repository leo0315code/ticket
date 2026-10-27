<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CannedResponseController;
use App\Http\Controllers\Api\CaptchaController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\FaqController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\MentionSearchController;
use App\Http\Controllers\Api\ReplyController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\TicketExportController;
use App\Http\Controllers\Api\TicketLogController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// 认证类是暴力破解的首要目标：按 IP 限流
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/admin/login', [AuthController::class, 'adminLogin'])->middleware('throttle:10,1');
Route::get('/captcha/issue', [CaptchaController::class, 'issue'])->middleware('throttle:30,1');
Route::post('/captcha/verify', [CaptchaController::class, 'verify'])->middleware('throttle:30,1');

Route::middleware(['auth:sanctum', 'throttle:240,1'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('tickets/export', [TicketExportController::class, 'index'])->middleware('staff');
    Route::post('tickets/bulk', [TicketController::class, 'bulk'])->middleware('staff');
    Route::get('logs', [TicketLogController::class, 'index'])->middleware('staff');
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
    Route::get('/faqs', [FaqController::class, 'index']);
    Route::post('/faqs/{faq}/view', [FaqController::class, 'view']);
    Route::post('/faqs/{faq}/feedback', [FaqController::class, 'feedback']);
    Route::get('/mention-search', [MentionSearchController::class, 'index']);
    Route::get('/canned-responses', [CannedResponseController::class, 'index'])->middleware('staff');
    Route::post('/canned-responses', [CannedResponseController::class, 'store'])->middleware('staff');
    Route::put('/canned-responses/{cannedResponse}', [CannedResponseController::class, 'update'])->middleware('staff');
    Route::delete('/canned-responses/{cannedResponse}', [CannedResponseController::class, 'destroy'])->middleware('staff');
    Route::post('/canned-responses/{cannedResponse}/touch', [CannedResponseController::class, 'touch'])->middleware('staff');
    Route::get('/agents', [AgentController::class, 'index'])->middleware('staff');
    Route::get('/stats', [StatsController::class, 'index'])->middleware('staff');
    Route::get('/stats/ratings', [StatsController::class, 'ratings'])->middleware('staff');
    Route::get('/stats/mine', [StatsController::class, 'mine']);

    Route::middleware('admin')->group(function () {
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::put('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::post('/faqs', [FaqController::class, 'store']);
        Route::put('/faqs/{faq}', [FaqController::class, 'update']);
        Route::delete('/faqs/{faq}', [FaqController::class, 'destroy']);
    });
});
