<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PosRenewalController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\InternetSoaController;
use App\Http\Controllers\BillingSoaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\DirectoryController;
use App\Http\Controllers\AutomationHealthController;
use App\Http\Controllers\SecurityAuditController;
use App\Http\Controllers\ProfileController;
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

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/request-revisions/{revision}/attachment/{side}', function (Request $request, \App\Models\RequestRevision $revision, string $side) {
        abort_unless(in_array($side, ['before', 'after'], true), 404);
        $snapshot = $revision->{$side};
        abort_unless(!empty($snapshot['attachment_path']) && \Illuminate\Support\Facades\Storage::disk('local')->exists($snapshot['attachment_path']), 404);
        return \App\Services\PrivateAttachments::respond($request, $snapshot['attachment_path'], $snapshot['attachment_name']);
    })->name('request-revisions.attachment');
    Route::get('/security-audit', function (Request $request, AuthController $auth) {
        abort_unless($request->user()->role === 'admin', 403);
        return response($auth->page($request, 'security-audit'))->header('Cache-Control', 'private, no-store');
    })->name('security-audit');
    Route::get('/security-audit/records', [SecurityAuditController::class, 'index'])->name('security-audit.records');
    Route::get('/automation-health', function (Request $request, AuthController $auth) {
        abort_unless($request->user()->role === 'admin', 403);
        return $auth->page($request, 'automation-health');
    })->name('automation-health');
    Route::get('/automation-health/records', [AutomationHealthController::class, 'index'])->name('automation-health.records');
    Route::post('/automation-health/emails/{delivery}/retry', [AutomationHealthController::class, 'retry'])->whereNumber('delivery');
    Route::get('/directory', fn (Request $request, AuthController $auth) => $auth->page($request, 'directory'))->name('directory');
    Route::get('/directory/records', [DirectoryController::class, 'index'])->name('directory.records');
    Route::post('/directory/{kind}/{id?}', [DirectoryController::class, 'save'])->whereIn('kind', ['stores', 'suppliers', 'accounts'])->whereNumber('id');

    Route::get('/users', function (Request $request, AuthController $auth) {
        abort_unless($request->user()->role === 'admin', 403);
        return $auth->page($request, 'users');
    })->name('users');
    Route::get('/users/records', [UserManagementController::class, 'index'])->name('users.records');
    Route::post('/users/records', [UserManagementController::class, 'store']);
    Route::post('/users/records/{user}', [UserManagementController::class, 'update']);
    Route::post('/users/records/{user}/status', [UserManagementController::class, 'status']);
    Route::post('/users/records/{user}/password', [UserManagementController::class, 'password']);
    foreach (['electricity', 'rental', 'water'] as $module) {
        Route::get('/'.$module, fn (Request $request, AuthController $auth) => $auth->page($request, $module))->name($module);
        Route::get('/'.$module.'/records', [BillingSoaController::class, 'index'])->defaults('module', $module)->name($module.'.records');
        Route::post('/'.$module.'/records', [BillingSoaController::class, 'store'])->defaults('module', $module);
        Route::get('/'.$module.'/records/{billingSoa}', function (Request $request, AuthController $auth, \App\Models\BillingSoa $billingSoa) use ($module) {
            abort_unless($billingSoa->module === $module, 404);
            return $auth->page($request, $module.'-voucher');
        })->defaults('module', $module)->name($module.'.detail');
        Route::get('/'.$module.'/records/{billingSoa}/details', [BillingSoaController::class, 'details'])->defaults('module', $module)->name($module.'.details');
        Route::post('/'.$module.'/records/{billingSoa}/comments', [BillingSoaController::class, 'comment'])->defaults('module', $module)->name($module.'.comments');
        Route::get('/'.$module.'/records/{billingSoa}/comments/{commentId}/attachment', [BillingSoaController::class, 'commentAttachment'])->whereNumber('commentId')->defaults('module', $module)->name($module.'.comment-attachment');
        Route::post('/'.$module.'/records/{billingSoa}/duplicate-cancel', [BillingSoaController::class, 'cancelDuplicate'])->defaults('module', $module);
        Route::post('/'.$module.'/records/{billingSoa}/return', [BillingSoaController::class, 'returnRequest'])->defaults('module', $module);
        Route::post('/'.$module.'/records/{billingSoa}/resubmit', [BillingSoaController::class, 'resubmit'])->defaults('module', $module);
        Route::post('/'.$module.'/records/{billingSoa}/reopen', [BillingSoaController::class, 'reopen'])->defaults('module', $module);
        Route::post('/'.$module.'/records/{billingSoa}/billing-metadata', [BillingSoaController::class, 'billingMetadata'])->defaults('module', $module);
        Route::post('/'.$module.'/records/{billingSoa}/recurrence', [BillingSoaController::class, 'recurrence'])->defaults('module', $module)->name($module.'.recurrence');
        Route::post('/'.$module.'/records/{billingSoa}/statement', [BillingSoaController::class, 'statement'])->defaults('module', $module)->name($module.'.statement');
        Route::post('/'.$module.'/records/{billingSoa}/voucher', [BillingSoaController::class, 'voucherAction'])->defaults('module', $module)->name($module.'.voucher');
        Route::post('/'.$module.'/records/{billingSoa}/payment', [BillingSoaController::class, 'payment'])->defaults('module', $module)->name($module.'.payment');
        Route::get('/'.$module.'/records/{billingSoa}/payment-proof', [BillingSoaController::class, 'paymentProof'])->defaults('module', $module)->name($module.'.payment-proof');
        Route::get('/'.$module.'/{billingSoa}/attachment', [BillingSoaController::class, 'attachment'])->defaults('module', $module)->name($module.'.attachment');
    }
    Route::get('/internet-soa', fn (Request $request, AuthController $auth) => $auth->page($request, 'internet-soa'))->name('internet-soa');
    Route::get('/internet-soa/records', [InternetSoaController::class, 'index'])->name('internet-soa.records');
    Route::post('/internet-soa/records', [InternetSoaController::class, 'store']);
    Route::get('/internet-soa/records/{internetSoa}', fn (Request $request, AuthController $auth, \App\Models\InternetSoa $internetSoa) => $auth->page($request, 'internet-voucher'))->name('internet-soa.detail');
    Route::get('/internet-soa/records/{internetSoa}/details', [InternetSoaController::class, 'details'])->name('internet-soa.details');
    Route::post('/internet-soa/records/{internetSoa}/comments', [InternetSoaController::class, 'comment'])->name('internet-soa.comments');
    Route::get('/internet-soa/records/{internetSoa}/comments/{commentId}/attachment', [InternetSoaController::class, 'commentAttachment'])->whereNumber('commentId')->name('internet-soa.comment-attachment');
    Route::post('/internet-soa/records/{internetSoa}/duplicate-cancel', [InternetSoaController::class, 'cancelDuplicate']);
    Route::post('/internet-soa/records/{internetSoa}/return', [InternetSoaController::class, 'returnRequest']);
    Route::post('/internet-soa/records/{internetSoa}/resubmit', [InternetSoaController::class, 'resubmit']);
    Route::post('/internet-soa/records/{internetSoa}/reopen', [InternetSoaController::class, 'reopen']);
    Route::post('/internet-soa/records/{internetSoa}/billing-metadata', [InternetSoaController::class, 'billingMetadata']);
    Route::post('/internet-soa/records/{internetSoa}/recurrence', [InternetSoaController::class, 'recurrence'])->name('internet-soa.recurrence');
    Route::post('/internet-soa/records/{internetSoa}/statement', [InternetSoaController::class, 'statement'])->name('internet-soa.statement');
    Route::post('/internet-soa/records/{internetSoa}/voucher', [InternetSoaController::class, 'voucherAction'])->name('internet-soa.voucher');
    Route::post('/internet-soa/records/{internetSoa}/payment', [InternetSoaController::class, 'payment'])->name('internet-soa.payment');
    Route::get('/internet-soa/records/{internetSoa}/payment-proof', [InternetSoaController::class, 'paymentProof'])->name('internet-soa.payment-proof');
    Route::get('/internet-soa/{internetSoa}/attachment', [InternetSoaController::class, 'attachment'])->name('internet-soa.attachment');
    Route::get('/settings', function (Request $request, AuthController $auth) {
        return $auth->page($request, 'settings');
    })->name('settings');
    Route::get('/settings/profile', [ProfileController::class, 'show'])->name('settings.profile');
    Route::post('/settings/profile', [ProfileController::class, 'update'])->middleware('throttle:10,1')->name('settings.profile.update');
    Route::post('/settings/password', [ProfileController::class, 'password'])->middleware('throttle:5,1')->name('settings.password.update');
    Route::get('/settings/notifications', [NotificationSettingsController::class, 'index'])->name('settings.notifications');
    Route::post('/settings/notifications', [NotificationSettingsController::class, 'store']);
    Route::get('/utak-pos', fn (Request $request, AuthController $auth) => $auth->page($request, 'utak-pos'))->name('utak-pos');
    Route::get('/utak-pos/records', [PosRenewalController::class, 'index'])->name('utak-pos.records');
    Route::post('/utak-pos/records', [PosRenewalController::class, 'store'])->name('utak-pos.store');
    Route::get('/utak-pos/records/{posRenewal}', fn (Request $request, AuthController $auth, \App\Models\PosRenewal $posRenewal) => $auth->page($request, 'pos-voucher'))->name('utak-pos.detail');
    Route::get('/utak-pos/records/{posRenewal}/details', [PosRenewalController::class, 'details'])->name('utak-pos.details');
    Route::post('/utak-pos/records/{posRenewal}/comments', [PosRenewalController::class, 'comment'])->name('utak-pos.comments');
    Route::get('/utak-pos/records/{posRenewal}/comments/{commentId}/attachment', [PosRenewalController::class, 'commentAttachment'])->whereNumber('commentId')->name('utak-pos.comment-attachment');
    Route::post('/utak-pos/records/{posRenewal}/payment', [PosRenewalController::class, 'payment'])->name('utak-pos.payment');
    Route::get('/utak-pos/records/{posRenewal}/payment-proof', [PosRenewalController::class, 'paymentProof'])->name('utak-pos.payment-proof');
    Route::post('/utak-pos/records/{posRenewal}/duplicate-cancel', [PosRenewalController::class, 'cancelDuplicate']);
    Route::post('/utak-pos/records/{posRenewal}/return', [PosRenewalController::class, 'returnRequest']);
    Route::post('/utak-pos/records/{posRenewal}/resubmit', [PosRenewalController::class, 'resubmit']);
    Route::post('/utak-pos/records/{posRenewal}/reopen', [PosRenewalController::class, 'reopen']);
    Route::post('/utak-pos/records/{posRenewal}/billing-metadata', [PosRenewalController::class, 'billingMetadata']);
    Route::post('/utak-pos/records/{posRenewal}/recurrence', [PosRenewalController::class, 'recurrence'])->name('utak-pos.recurrence');
    Route::post('/utak-pos/records/{posRenewal}/voucher', [PosRenewalController::class, 'voucherAction'])->name('utak-pos.voucher');
    Route::get('/dashboard', fn (Request $request, AuthController $auth) => $auth->page($request, 'dashboard'))->name('dashboard');
    Route::get('/dashboard/records', [DashboardController::class, 'index'])->name('dashboard.records');
    Route::get('/dashboard/activity', [DashboardController::class, 'activity'])->name('dashboard.activity');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
