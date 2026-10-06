<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AffiliatesController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
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
    Route::get('/tenants/{tenant:id}', [TenantsController::class, 'show'])->whereNumber('tenant')->name('tenant');
    Route::get('/account', [AccountController::class, 'show'])->name('account');
    Route::post('/api/account/passkeys/options', [AccountController::class, 'options'])->middleware('throttle:20,1')->name('account.passkeys.options');
    Route::post('/api/account/passkeys', [AccountController::class, 'store'])->middleware('throttle:20,1')->name('account.passkeys.store');
    Route::delete('/api/account/passkeys/{passkey}', [AccountController::class, 'destroy'])->whereNumber('passkey')->name('account.passkeys.destroy');
    Route::get('/tech', [TechLogController::class, 'index'])->middleware('staff:admin')->name('tech');

    // Affiliate program (همکاری در فروش): support can view, only admins change anything.
    Route::get('/affiliates', [AffiliatesController::class, 'index'])->name('affiliates');
    Route::get('/affiliates/{affiliate}', [AffiliatesController::class, 'show'])->whereNumber('affiliate')->name('affiliate');
    Route::middleware('staff:admin')->group(function () {
        Route::post('/api/affiliates', [AffiliatesController::class, 'store'])->name('affiliates.store');
        Route::put('/api/affiliates/{affiliate}', [AffiliatesController::class, 'update'])->whereNumber('affiliate')->name('affiliates.update');
        Route::post('/api/affiliates/{affiliate}/payouts', [AffiliatesController::class, 'payout'])->whereNumber('affiliate')->name('affiliates.payout');
        Route::post('/api/affiliate-commissions/{commission}/void', [AffiliatesController::class, 'void'])->whereNumber('commission')->name('affiliates.void');
    });
});
