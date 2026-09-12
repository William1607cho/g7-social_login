<?php

use Illuminate\Support\Facades\Route;
use Plugins\G7\SocialLogin\Http\Controllers\SocialAuthController;

/*
 * g7-social_login 플러그인 API 라우트
 *
 * URL prefix: /api/plugins/g7-social_login  (PluginRouteServiceProvider 자동 적용)
 */

Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect'])
    ->whereIn('provider', ['kakao', 'google'])
    ->middleware(['start.api.session', 'throttle:30,1'])
    ->name('redirect');

Route::get('{provider}/callback', [SocialAuthController::class, 'callback'])
    ->whereIn('provider', ['kakao', 'google'])
    ->middleware(['start.api.session', 'throttle:30,1'])
    ->name('callback');

Route::post('exchange', [SocialAuthController::class, 'exchange'])
    ->middleware(['throttle:30,1'])
    ->name('exchange');

Route::middleware(['auth:sanctum', 'check.user_status'])->group(function () {
    Route::get('accounts', [SocialAuthController::class, 'accounts'])->name('accounts');

    Route::post('{provider}/link/prepare', [SocialAuthController::class, 'linkPrepare'])
        ->whereIn('provider', ['kakao', 'google'])
        ->middleware(['throttle:30,1'])
        ->name('link.prepare');

    Route::delete('{provider}/unlink', [SocialAuthController::class, 'unlink'])
        ->whereIn('provider', ['kakao', 'google'])
        ->middleware(['throttle:30,1'])
        ->name('unlink');
});
