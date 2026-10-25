<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
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

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/admin/login', [AuthController::class, 'adminLogin']);
Route::get('/captcha/issue', [CaptchaController::class, 'issue']);
Route::post('/captcha/verify', [CaptchaController::class, 'verify']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('tickets/export', [TicketExportController::class, 'index'])->middleware('staff');
    Route::post('tickets/bulk', [TicketController::class, 'bulk'])->middleware('staff');
    Route::get('logs', [TicketLogController::class, 'index'])->middleware('staff');
    Route::apiResource('tickets', TicketController::class);
    Route::post('/tickets/{ticket}/rating', [TicketController::class, 'rate']);
    Route::apiResource('tickets.replies', ReplyController::class)->only(['index', 'store']);
    Route::post('/tickets/{ticket}/attachments', [AttachmentController::class, 'store']);
    Route::get('/tickets/{ticket}/attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->name('attachments.download');

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read', [NotificationController::class, 'read']);

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/faqs', [FaqController::class, 'index']);
    Route::get('/mention-search', [MentionSearchController::class, 'index']);
    Route::get('/agents', [AgentController::class, 'index'])->middleware('staff');
    Route::get('/stats', [StatsController::class, 'index'])->middleware('staff');
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
