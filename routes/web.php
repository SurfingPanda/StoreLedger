<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PosRenewalController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\InternetSoaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', 'login');

Route::middleware('guest')->group(function () {
    Route::get('/login', fn (Request $request, AuthController $auth) => $auth->page($request))->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::get('/forgot-password', fn (Request $request, AuthController $auth) => $auth->page($request, 'forgot-password'))->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', fn (Request $request, AuthController $auth) => $auth->page($request, 'reset-password'))->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::get('/internet-soa', fn (Request $request, AuthController $auth) => $auth->page($request, 'internet-soa'))->name('internet-soa');
    Route::get('/internet-soa/records', [InternetSoaController::class, 'index'])->name('internet-soa.records');
    Route::post('/internet-soa/records', [InternetSoaController::class, 'store']);
    Route::get('/internet-soa/{internetSoa}/attachment', [InternetSoaController::class, 'attachment'])->name('internet-soa.attachment');
    Route::get('/settings', function (Request $request, AuthController $auth) {
        abort_unless($request->user()->role === 'admin', 403);
        return $auth->page($request, 'settings');
    })->name('settings');
    Route::get('/settings/notifications', [NotificationSettingsController::class, 'index'])->name('settings.notifications');
    Route::post('/settings/notifications', [NotificationSettingsController::class, 'store']);
    Route::get('/utak-pos', fn (Request $request, AuthController $auth) => $auth->page($request, 'utak-pos'))->name('utak-pos');
    Route::get('/utak-pos/records', [PosRenewalController::class, 'index'])->name('utak-pos.records');
    Route::post('/utak-pos/records', [PosRenewalController::class, 'store'])->name('utak-pos.store');
    Route::get('/dashboard', fn (Request $request, AuthController $auth) => $auth->page($request, 'dashboard'))->name('dashboard');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
