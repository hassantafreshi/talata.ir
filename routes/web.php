<?php

use App\Http\Controllers\App\AffiliateController;
use App\Http\Controllers\App\BackupsController;
use App\Http\Controllers\App\BillingController;
use App\Http\Controllers\App\CalculatorController;
use App\Http\Controllers\App\CustomerController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\InstallmentController;
use App\Http\Controllers\App\InvoiceController;
use App\Http\Controllers\App\InvoiceDraftController;
use App\Http\Controllers\App\MaznehController;
use App\Http\Controllers\App\PasskeyController;
use App\Http\Controllers\App\QuoteController;
use App\Http\Controllers\App\SettingsController;
use App\Http\Controllers\App\UsersController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasskeyLoginController;
use App\Http\Controllers\Public\MockGatewayController;
use App\Http\Controllers\Public\PaymentReturnController;
use App\Http\Controllers\Public\PublicInvoiceController;
use App\Http\Controllers\Public\ReferralController;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'home' : 'login'));

// ---------- guest: mobile + OTP ----------
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::get('/login/code', [LoginController::class, 'code'])->name('login.code');
    Route::middleware('throttle:30,1')->group(function () {
        Route::get('/api/auth/pow', [LoginController::class, 'pow'])->name('auth.pow');
        Route::post('/api/auth/otp/request', [LoginController::class, 'requestOtp'])->name('auth.otp.request');
        Route::post('/api/auth/otp/verify', [LoginController::class, 'verifyOtp'])->name('auth.otp.verify');
        Route::post('/api/auth/passkey/options', [PasskeyLoginController::class, 'options'])->name('auth.passkey.options');
        Route::post('/api/auth/passkey/verify', [PasskeyLoginController::class, 'verify'])->name('auth.passkey.verify');
    });
});
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// ---------- public (no login) ----------
Route::middleware('throttle:public')->group(function () {
    Route::get('/r/{code}', ReferralController::class)->where('code', '[A-Za-z0-9-]{4,20}')->name('public.referral');
    Route::get('/v/{token}', [PublicInvoiceController::class, 'verify'])->name('public.verify');
    Route::get('/i/{token}', [PublicInvoiceController::class, 'show'])->name('public.invoice');
    Route::get('/i/{token}/print', [PublicInvoiceController::class, 'print'])->name('public.invoice.print');
    Route::get('/logo/{tenant}/{version}', [PublicInvoiceController::class, 'logo'])->whereNumber('version')->name('public.logo');
});
Route::match(['get', 'post'], '/pay/callback/{gateway}', [PaymentReturnController::class, 'callback'])->middleware('throttle:60,1')->name('pay.callback');
Route::get('/pay/result/{order}', [PaymentReturnController::class, 'show'])->middleware('throttle:60,1')->name('pay.result');
Route::get('/pay/result/{order}/status', [PaymentReturnController::class, 'status'])->middleware('throttle:60,1')->name('pay.result.status');
Route::get('/pay/mock/{authority}', [MockGatewayController::class, 'show'])->middleware('throttle:60,1')->name('pay.mock');
Route::post('/pay/mock/{authority}', [MockGatewayController::class, 'decide'])->middleware('throttle:60,1')->name('pay.mock.decide');

// ---------- merchant app ----------
Route::middleware(['auth', 'tenant'])->group(function () {
    // First page this member may open (team permissions decide; owners land on «فاکتور جدید»).
    Route::get('/home', fn () => redirect()->route(app(TenantContext::class)->membership()?->homeRoute() ?? 'settings'))->name('home');
    Route::get('/invoices/new', [InvoiceDraftController::class, 'start'])->middleware('perm:invoice.issue')->name('invoices.new');
    Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('perm:invoices.view')->name('invoices.index');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices/{invoice}/items', [InvoiceDraftController::class, 'items'])->middleware('perm:invoice.issue')->name('invoices.items');
    Route::get('/invoices/{invoice}/review', [InvoiceDraftController::class, 'review'])->middleware('perm:invoice.issue')->name('invoices.review');
    Route::get('/invoices/{invoice}/issued', [InvoiceController::class, 'issued'])->name('invoices.issued');
    Route::get('/invoices/{invoice}/print', [InvoiceController::class, 'print'])->name('invoices.print');
    Route::get('/mazneh', MaznehController::class)->middleware('perm:mazneh.view')->name('mazneh');
    Route::get('/calculator', CalculatorController::class)->middleware('perm:calculator.use')->name('calculator');
    Route::get('/dashboard', [DashboardController::class, 'show'])->middleware('perm:reports.view')->name('dashboard');

    Route::get('/customers', [CustomerController::class, 'index'])->middleware('perm:customers.view')->name('customers.index');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->middleware('perm:customers.view')->name('customers.show');
    Route::get('/customers/{customer}/agreements/new', [InstallmentController::class, 'create'])->middleware('perm:customers.manage')->name('agreements.create');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::get('/settings/business', [SettingsController::class, 'business'])->middleware('perm:settings.manage')->name('settings.business');
    Route::get('/settings/appearance', [SettingsController::class, 'appearance'])->middleware('perm:settings.manage')->name('settings.appearance');
    Route::get('/settings/numbering', [SettingsController::class, 'numbering'])->middleware('perm:settings.manage')->name('settings.numbering');
    Route::get('/settings/sms-template', [SettingsController::class, 'smsTemplate'])->middleware('perm:settings.manage')->name('settings.sms_template');
    Route::get('/settings/users', [UsersController::class, 'index'])->middleware('perm:__owner')->name('settings.users');
    Route::get('/settings/backups', [BackupsController::class, 'index'])->middleware('perm:__owner')->name('settings.backups');
    Route::get('/settings/plan', [BillingController::class, 'plans'])->name('settings.plan');
    Route::get('/settings/sms', [BillingController::class, 'sms'])->name('settings.sms');
    Route::get('/affiliate', [AffiliateController::class, 'show'])->name('affiliate');
    Route::get('/settings/payments/{order}/receipt', [BillingController::class, 'receipt'])->middleware('perm:billing.manage')->name('settings.receipt');

    // JSON endpoints (AJAX, session + CSRF)
    Route::prefix('api')->group(function () {
        // User-level (not tenant permission): own invites and shop switching.
        Route::post('/invites/{membership}/accept', [UsersController::class, 'accept'])->whereNumber('membership')->name('api.invites.accept');
        Route::post('/invites/{membership}/decline', [UsersController::class, 'decline'])->whereNumber('membership')->name('api.invites.decline');
        Route::post('/passkeys/options', [PasskeyController::class, 'options'])->middleware('throttle:20,1')->name('api.passkeys.options');
        Route::post('/passkeys', [PasskeyController::class, 'store'])->middleware('throttle:20,1')->name('api.passkeys.store');
        Route::delete('/passkeys/{passkey}', [PasskeyController::class, 'destroy'])->whereNumber('passkey')->name('api.passkeys.destroy');
        Route::post('/memberships/{membership}/switch', [UsersController::class, 'switchTenant'])->whereNumber('membership')->name('api.memberships.switch');
        Route::get('/quotes/latest', [QuoteController::class, 'latest'])->middleware('perm:mazneh.view|invoice.issue|calculator.use')->name('api.quotes.latest');
        Route::get('/quotes/board', [QuoteController::class, 'board'])->middleware('perm:mazneh.view')->name('api.quotes.board');
        Route::get('/entitlements', [SettingsController::class, 'entitlements'])->name('api.entitlements');
        Route::get('/dashboard', [DashboardController::class, 'data'])->middleware(['perm:reports.view', 'throttle:60,1'])->name('api.dashboard');

        Route::middleware('perm:invoice.issue')->group(function () {
            Route::post('/invoices/drafts', [InvoiceDraftController::class, 'create'])->middleware('throttle:drafts')->name('api.drafts.create');
            Route::put('/invoices/drafts/{invoice}', [InvoiceDraftController::class, 'save'])->middleware('throttle:drafts')->name('api.drafts.save');
            Route::delete('/invoices/drafts/{invoice}', [InvoiceDraftController::class, 'destroy'])->name('api.drafts.destroy');
            Route::post('/invoices/drafts/{invoice}/issue', [InvoiceDraftController::class, 'issue'])->middleware('throttle:issue')->name('api.drafts.issue');
            Route::post('/invoices/{invoice}/share', [InvoiceController::class, 'share'])->middleware('throttle:30,1')->name('api.invoices.share');
            Route::post('/invoices/{invoice}/sms', [InvoiceController::class, 'resendSms'])->middleware('throttle:10,1')->name('api.invoices.sms');
            Route::get('/invoices/{invoice}/status', [InvoiceController::class, 'status'])->name('api.invoices.status');
        });
        Route::middleware('perm:invoice.void')->group(function () {
            Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->middleware('throttle:20,1')->name('api.invoices.void');
            Route::post('/invoices/{invoice}/replace', [InvoiceController::class, 'replace'])->middleware('throttle:20,1')->name('api.invoices.replace');
            Route::post('/invoices/{invoice}/share/revoke', [InvoiceController::class, 'revokeShare'])->name('api.invoices.share.revoke');
        });
        Route::get('/invoices', [InvoiceController::class, 'list'])->middleware('perm:invoices.view')->name('api.invoices.list');

        Route::get('/customers', [CustomerController::class, 'search'])->middleware('perm:customers.view|invoice.issue')->name('api.customers.search');
        Route::middleware('perm:customers.manage')->group(function () {
            Route::post('/customers', [CustomerController::class, 'store'])->middleware('throttle:customers')->name('api.customers.store');
            Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('api.customers.update');
            Route::post('/customers/{customer}/agreements', [InstallmentController::class, 'store'])->middleware('throttle:20,1')->name('api.agreements.store');
            Route::post('/agreements/{agreement}/payments', [InstallmentController::class, 'pay'])->middleware('throttle:30,1')->name('api.agreements.pay');
            Route::post('/payments/{payment}/reverse', [InstallmentController::class, 'reverse'])->name('api.payments.reverse');
            Route::post('/agreements/{agreement}/reminders', [InstallmentController::class, 'toggleReminders'])->name('api.agreements.reminders');
        });

        Route::middleware('perm:settings.manage')->group(function () {
            Route::post('/settings/business', [SettingsController::class, 'saveBusiness'])->middleware('throttle:20,1')->name('api.settings.business');
            Route::post('/settings/logo', [SettingsController::class, 'uploadLogo'])->middleware('throttle:10,1')->name('api.settings.logo');
            Route::delete('/settings/logo', [SettingsController::class, 'deleteLogo'])->name('api.settings.logo.delete');
            Route::put('/settings/appearance', [SettingsController::class, 'saveAppearance'])->middleware('throttle:30,1')->name('api.settings.appearance');
            Route::post('/settings/appearance/preview', [SettingsController::class, 'previewAppearance'])->middleware('throttle:60,1')->name('api.settings.appearance.preview');
            Route::put('/settings/numbering', [SettingsController::class, 'saveNumbering'])->middleware('throttle:20,1')->name('api.settings.numbering');
            Route::put('/settings/sms-template', [SettingsController::class, 'saveSmsTemplate'])->middleware('throttle:20,1')->name('api.settings.sms_template');
        });
        Route::middleware('perm:billing.manage')->group(function () {
            Route::post('/billing/discount', [BillingController::class, 'discount'])->middleware('throttle:'.config('talata.affiliate.validate_per_minute').',1')->name('api.billing.discount');
            Route::post('/billing/orders', [BillingController::class, 'createOrder'])->middleware('throttle:billing')->name('api.billing.orders');
        });
        Route::get('/billing/orders/{order}', [BillingController::class, 'order'])->name('api.billing.order');

        Route::middleware('perm:__owner')->group(function () {
            Route::post('/settings/backups', [BackupsController::class, 'store'])->middleware('throttle:10,1')->name('api.backups.store');
            Route::post('/settings/backups/{backup}/restore', [BackupsController::class, 'restore'])->whereNumber('backup')->middleware('throttle:10,1')->name('api.backups.restore');
            Route::post('/users/invite', [UsersController::class, 'invite'])->middleware('throttle:10,1')->name('api.users.invite');
            Route::put('/users/{membership}', [UsersController::class, 'update'])->name('api.users.update');
            Route::delete('/users/{membership}', [UsersController::class, 'remove'])->name('api.users.remove');
        });
    });
});
