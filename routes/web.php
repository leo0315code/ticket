<?php

use Illuminate\Support\Facades\Route;

// SPA 入口：所有非 api 路径都交给 Vue Router 处理
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api).*$');
