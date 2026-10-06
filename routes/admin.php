<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AffiliatesController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\IntegrationsController;
use App\Http\Controllers\Admin\PaymentsController;
use App\Http\Controllers\Admin\PricingController;
use App\Http\Controllers\Admin\QuotesController;
use App\Http\Controllers\Admin\SmsController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\TaxController;
use App\Http\Controllers\Admin\TechLogController;
use App\Http\Controllers\Admin\TenantsController;
use Illuminate\Support\Facades\Route;

// Loaded with the `admin` middleware group (own session cookie scoped to /admin), prefix /admin, names admin.*.
Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/api/pow', [AuthController::class, 'pow'])->name('pow');
    Route::post('/api/otp/request', [AuthController::class, 'requestOtp'])->name('otp.request');
    Route::post('/api/otp/verify', [AuthController::class, 'verifyOtp'])->name('otp.verify');
    Route::post('/api/passkey/options', [AuthController::class, 'passkeyOptions'])->name('passkey.options');
    Route::post('/api/passkey/verify', [AuthController::class, 'passkeyVerify'])->name('passkey.verify');
});

Route::middleware('staff')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/activity', [ActivityController::class, 'index'])->name('activity');
    Route::get('/users/{user}', [ActivityController::class, 'user'])->whereNumber('user')->name('user');
    Route::get('/tenants', [TenantsController::class, 'index'])->name('tenants');
    Route::get('/tenants/export.csv', [TenantsController::class, 'export'])->middleware(['staff:tenants.export', 'throttle:10,1'])->name('tenants.export');
    Route::get('/tenants/{tenant:id}', [TenantsController::class, 'show'])->whereNumber('tenant')->name('tenant');
    Route::post('/api/tenants/{tenant:id}/backups/{backup}/restore', [TenantsController::class, 'restoreBackup'])->whereNumber(['tenant', 'backup'])->middleware(['staff:tenants.restore', 'throttle:20,1'])->name('tenant.backup.restore');
    // Manual shop actions (A-03): permission + recent sign-in + reason + idempotency key; all audited.
    Route::middleware(['staff:tenants.manage,fresh', 'throttle:30,1'])->whereNumber(['tenant', 'override'])->group(function () {
        Route::post('/api/tenants/{tenant:id}/activation', [TenantsController::class, 'activate'])->name('tenant.activate');
        Route::post('/api/tenants/{tenant:id}/sms-credit', [TenantsController::class, 'credit'])->name('tenant.credit');
        Route::post('/api/tenants/{tenant:id}/overrides', [TenantsController::class, 'override'])->name('tenant.override');
        Route::post('/api/tenants/{tenant:id}/overrides/{override}/end', [TenantsController::class, 'endOverride'])->name('tenant.override.end');
    });
    Route::middleware(['staff:tenants.suspend,fresh', 'throttle:30,1'])->whereNumber('tenant')->group(function () {
        Route::post('/api/tenants/{tenant:id}/suspend', [TenantsController::class, 'suspend'])->name('tenant.suspend');
        Route::post('/api/tenants/{tenant:id}/unsuspend', [TenantsController::class, 'unsuspend'])->name('tenant.unsuspend');
    });

    // Payments (A-05).
    Route::get('/payments', [PaymentsController::class, 'index'])->name('payments');
    Route::get('/payments/export.csv', [PaymentsController::class, 'export'])->middleware(['staff:payments.export', 'throttle:10,1'])->name('payments.export');
    Route::get('/payments/{order}', [PaymentsController::class, 'show'])->whereNumber('order')->name('payment');
    Route::post('/api/payments/{order}/inquire', [PaymentsController::class, 'inquire'])->whereNumber('order')->middleware(['staff:payments.inquire', 'throttle:20,1'])->name('payment.inquire');
    Route::middleware(['staff:payments.manage,fresh', 'throttle:20,1'])->whereNumber('order')->group(function () {
        Route::post('/api/payments/{order}/manual-confirm', [PaymentsController::class, 'confirm'])->name('payment.confirm');
        Route::post('/api/payments/{order}/mark-failed', [PaymentsController::class, 'fail'])->name('payment.fail');
    });

    // SMS operations (A-06).
    Route::get('/sms', [SmsController::class, 'index'])->name('sms');
    Route::middleware(['staff:sms.manage', 'throttle:20,1'])->group(function () {
        Route::post('/api/sms/{message}/inquire', [SmsController::class, 'inquire'])->whereNumber('message')->name('sms.inquire');
        Route::post('/api/sms/test', [SmsController::class, 'test'])->name('sms.test');
    });

    // Quotes and the emergency 18K rate (A-07).
    Route::get('/quotes', [QuotesController::class, 'index'])->name('quotes');
    Route::post('/api/quotes/refresh', [QuotesController::class, 'refresh'])->middleware(['staff:quotes.manage', 'throttle:6,1'])->name('quotes.refresh');
    Route::middleware(['staff:quotes.manage,fresh', 'throttle:20,1'])->group(function () {
        Route::post('/api/quotes/emergency', [QuotesController::class, 'announce'])->name('quotes.emergency');
        Route::post('/api/quotes/emergency/cancel', [QuotesController::class, 'cancel'])->name('quotes.emergency.cancel');
    });

    // Tax rules (A-08).
    Route::get('/tax-rules', [TaxController::class, 'index'])->name('tax');
    Route::middleware(['staff:tax.manage,fresh', 'throttle:20,1'])->group(function () {
        Route::post('/api/tax-rules', [TaxController::class, 'store'])->name('tax.store');
        Route::post('/api/tax-rules/{rule}/disable', [TaxController::class, 'disable'])->whereNumber('rule')->name('tax.disable');
    });

    // Integrations (A-09, read-only), staff (A-10), system health (A-12).
    Route::get('/integrations', [IntegrationsController::class, 'index'])->name('integrations');
    Route::get('/staff', [StaffController::class, 'index'])->name('staff');
    Route::middleware(['staff:staff.manage,fresh', 'throttle:20,1'])->group(function () {
        Route::post('/api/staff', [StaffController::class, 'store'])->name('staff.store');
        Route::put('/api/staff/{staff}', [StaffController::class, 'update'])->whereNumber('staff')->name('staff.update');
    });
    Route::get('/system', [SystemController::class, 'index'])->name('system');
    Route::post('/api/system/failed-jobs/{uuid}/retry', [SystemController::class, 'retry'])->middleware(['staff:system.manage', 'throttle:20,1'])->name('system.retry');
    Route::get('/account', [AccountController::class, 'show'])->name('account');
    Route::post('/api/account/passkeys/options', [AccountController::class, 'options'])->middleware('throttle:20,1')->name('account.passkeys.options');
    Route::post('/api/account/passkeys', [AccountController::class, 'store'])->middleware('throttle:20,1')->name('account.passkeys.store');
    Route::delete('/api/account/passkeys/{passkey}', [AccountController::class, 'destroy'])->whereNumber('passkey')->name('account.passkeys.destroy');
    Route::get('/tech', [TechLogController::class, 'index'])->middleware('staff:logs.tech')->name('tech');

    // Plan and SMS prices: every change publishes a new pricing version (docs/ADMIN_PRICING.md).
    Route::get('/pricing', [PricingController::class, 'index'])->name('pricing');
    Route::middleware(['staff:pricing.manage,fresh', 'throttle:20,1'])->group(function () {
        Route::post('/api/pricing', [PricingController::class, 'publish'])->name('pricing.publish');
        Route::post('/api/pricing/{version}/restore', [PricingController::class, 'restore'])->whereNumber('version')->name('pricing.restore');
    });

    // Affiliate program (همکاری در فروش): support can view, only admins change anything.
    Route::get('/affiliates', [AffiliatesController::class, 'index'])->name('affiliates');
    Route::get('/affiliates/{affiliate}', [AffiliatesController::class, 'show'])->whereNumber('affiliate')->name('affiliate');
    Route::middleware('staff:affiliates.manage')->group(function () {
        Route::post('/api/affiliates', [AffiliatesController::class, 'store'])->name('affiliates.store');
        Route::put('/api/affiliates/{affiliate}', [AffiliatesController::class, 'update'])->whereNumber('affiliate')->name('affiliates.update');
        Route::post('/api/affiliates/{affiliate}/payouts', [AffiliatesController::class, 'payout'])->whereNumber('affiliate')->name('affiliates.payout');
        Route::post('/api/affiliate-commissions/{commission}/void', [AffiliatesController::class, 'void'])->whereNumber('commission')->name('affiliates.void');
    });
});
