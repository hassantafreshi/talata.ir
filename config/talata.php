<?php

use App\Domain\Billing\Gateways\MockGateway;
use App\Domain\Billing\Gateways\ZarinpalGateway;

/*
| Zarlio application configuration.
| Commercial values (prices, quotas, SMS prices) live in the versioned
| `pricing_versions` table (seeded from database/seeders/data/plans-pricing.json),
| never in this file or in domain code. This file only holds operational
| and security limits that the provider tunes per deployment.
*/

return [
    'timezone' => env('TALATA_TIMEZONE', 'Asia/Tehran'),
    'public_url' => rtrim(env('TALATA_PUBLIC_URL', env('APP_URL', 'http://localhost')), '/'),

    // Reverse proxies whose X-Forwarded-* headers are trusted (comma list or *). Read through config so it
    // still applies after `php artisan config:cache` (env() returns null then).
    'trusted_proxies' => env('TRUSTED_PROXIES', '127.0.0.1'),

    'drivers' => [
        'sms' => env('TALATA_SMS_DRIVER', 'log'),          // kavenegar | log | fake
        'quotes' => env('TALATA_QUOTE_DRIVER', 'demo'),    // brsapi | demo
        'payment' => env('TALATA_PAYMENT_DRIVER', 'mock'), // zarinpal | mock (any code registered in payments.gateways)
    ],

    'sms_dev_driver_allowed_in_production' => (bool) env('TALATA_ALLOW_DEV_SMS_IN_PRODUCTION', false),

    // Passkeys (fingerprint / face / device lock). RP ID = registrable domain, e.g. zarlio.ir.
    'webauthn' => [
        'rp_id' => env('TALATA_WEBAUTHN_RP_ID'),          // default: host of APP_URL
        'origins' => env('TALATA_WEBAUTHN_ORIGINS'),      // comma list; default: APP_URL origin
        'recent_auth_minutes' => 15,                       // adding a passkey needs a recent login
    ],

    // Platform administrator console (/admin). Separate guard, cookie and session.
    'admin' => [
        'session_cookie' => env('TALATA_ADMIN_SESSION_COOKIE', 'talata_admin'),
        'session_minutes' => 120,
        'idle_minutes' => (int) env('TALATA_ADMIN_IDLE_MINUTES', 30),
        // Dangerous actions (manual activation, credit, manual payment confirm, prices, tax, emergency rate, staff)
        // need a sign-in within this many minutes (step-up re-auth).
        'reauth_minutes' => (int) env('TALATA_ADMIN_REAUTH_MINUTES', 15),
        'allowed_ips' => env('TALATA_ADMIN_ALLOWED_IPS'),   // comma list; empty = any IP (OTP/passkey still required)
        // Staff must add a passkey before using anything but the dashboard and «حساب من» (A-00).
        'require_passkey' => (bool) env('TALATA_ADMIN_REQUIRE_PASSKEY', true),
        // Service owner (owner decision 2026-10-07): the first admin, and the only person who may add staff or
        // change roles. Other admins run the console but cannot grant access to anyone.
        'owner_mobile' => env('TALATA_ADMIN_OWNER_MOBILE', '09396727215'),
    ],

    // Affiliate program (همکاری در فروش). Assumptions documented in docs/AFFILIATE_PROGRAM.md.
    'affiliate' => [
        'hold_days' => (int) env('TALATA_AFFILIATE_HOLD_DAYS', 7),          // pending → payable after this many days
        'new_customer_days' => 60,          // a code attaches only to a shop younger than this with no paid order yet
        'link_cookie_days' => 30,           // referral link remembered for signup
        'max_commission_percent' => 50,
        'max_discount_percent' => 50,
        'validate_per_minute' => 20,        // discount-code checks per user (anti brute force)
    ],

    'logs' => [
        'tech_retention_days' => (int) env('TALATA_TECH_LOG_DAYS', 90),
    ],

    // Operations facts shown on the admin «سلامت سیستم» page (docs/DEPLOYMENT.md).
    'ops' => [
        'release' => env('TALATA_RELEASE', 'dev'),                          // set by the deploy script (e.g. git short hash)
        'backup_heartbeat_file' => env('TALATA_BACKUP_HEARTBEAT_FILE'),     // touched by the DB backup script after success
        'restore_drill_file' => env('TALATA_RESTORE_DRILL_FILE'),           // touched after a successful restore drill
    ],

    'otp' => [
        'length' => 6,
        'ttl_seconds' => 180,             // every SMS code (login, admin, number change, پیش‌فاکتور): 3 minutes
        'max_attempts' => 5,
        'resend_cooldown_seconds' => 90,
        'per_mobile_hour' => 4,
        'per_mobile_day' => 8,
        'per_ip_hour' => 12,
        'per_subnet_hour' => 40,          // /24 for IPv4, /48 for IPv6
        'global_daily_budget' => (int) env('TALATA_OTP_DAILY_BUDGET', 2000),            // new (unregistered) numbers
        'existing_users_daily_budget' => (int) env('TALATA_OTP_EXISTING_DAILY_BUDGET', 3000), // reserved for registered users
        'verify_per_ip_minute' => 20,
        'lockout_minutes' => 15,
    ],

    // Proof-of-work before any OTP SMS is sent (anti SMS-pumping without third-party captcha).
    'pow' => [
        // 15 bits ≈ 33k hashes: well under a second on a weak phone (18 took several seconds); still costs a pump.
        'bits' => (int) env('TALATA_POW_BITS', 15),
        'ttl_seconds' => 300,
        'min_form_seconds' => 2,
    ],

    // Web Push to the shop's installed web app (docs/PROFORMA.md «اعلان»). VAPID keys are generated once and kept
    // encrypted in platform settings; subject is the contact the push services see.
    'webpush' => [
        'subject' => env('TALATA_WEBPUSH_SUBJECT', 'mailto:support@zarlio.ir'),
        'max_per_user' => 10,
    ],

    'sms' => [
        'max_sends_per_invoice' => 3,          // to the customer (initial + resends)
        'shop_notice_daily_cap' => 50,          // «مشتری پیش‌فاکتور را تأیید کرد» SMS to the shop owner (operational, per shop per day)
        'max_copies_per_invoice' => 3,         // copies to other numbers the merchant typed
        'max_copy_recipients_per_send' => 3,
        'awaiting_credit_max_days' => 7,      // invoice SMS waiting for credit are sent when credit arrives within this window
        // Non-production only: seed new shops with this much SMS credit (toman) so invoice SMS can be tested
        // end-to-end without a purchase. Ignored in production; see TenantProvisioner.
        'starter_credit_toman' => (int) env('TALATA_STARTER_SMS_CREDIT_TOMAN', 0),
        'resend_min_minutes' => 10,
        'per_recipient_per_tenant_daily' => 3,
        'per_recipient_global_free_daily' => 2,
        'reminders_per_recipient_monthly' => 8,   // across all tenants
        'free_yearly_per_tenant_daily' => 2,
        'tenant_hourly_cap' => 60,
        'tenant_daily_cap' => ['free' => 10, 'basic' => 300, 'professional' => 1000],
        'requires_complete_profile' => true,
        'template_max_chars' => 160,
        'reminder_quiet_hours' => [21, 9],
        'unicode_single' => 70,
        'unicode_multi' => 67,
        'gsm_single' => 160,
        'gsm_multi' => 153,
        // Yearly free-SMS window (docs/PLANS_AND_QUOTAS.md: «۳۶۵ روز از اولین ثبت‌نام tenant یا سال تقویمی»):
        // registration_year (default) | jalali_year. docs/ASSUMPTIONS.md
        'free_yearly_window' => env('TALATA_FREE_SMS_WINDOW', 'registration_year'),
    ],

    'quotes' => [
        'interval_seconds' => 180,
        // Technical assumption (master prompt §8): one 180 s refresh plus 60 s transport grace. docs/ASSUMPTIONS.md
        'stale_after_seconds' => (int) env('TALATA_QUOTE_STALE_AFTER_SECONDS', 240),
        'client_poll_seconds' => 180,
        // Shared hosting without cron/scheduler: refresh when a price is read and the last fetch is older than
        // the interval (single-flight). Test server only; production uses the scheduler (talata:quotes).
        'refresh_on_read' => (bool) env('TALATA_QUOTES_REFRESH_ON_READ', false),
        // Toman per gram added to every gold quote from the API (owner decision 2026-10-09); admin console overrides.
        'gold_markup_toman' => (int) env('TALATA_GOLD_MARKUP_TOMAN', 100000),
    ],

    'invoices' => [
        'max_rows' => 60,
        'max_weight_g' => '100000',
        'max_amount_irr' => '1000000000000000',
        'max_percent' => '1000',
        // GOLD_IN rows (gold received from the customer): highest melting/impurity deduction allowed.
        // Assumption, configurable: docs/GOLD_RECEIVED_AND_DASHBOARD.md §7.
        'max_gold_in_deduction_percent' => '50',
        'issue_per_minute' => 30,
        'draft_save_per_minute' => 120,
    ],

    'payments' => [
        'order_expiry_minutes' => 20,
        'refund_hours_display' => 72,
        'orders_per_hour' => 10,
        'mock_allowed_in_production' => (bool) env('TALATA_ALLOW_MOCK_PAYMENTS_IN_PRODUCTION', false),
        'reconcile_max_hours' => 24,
        // PSP adapter registry: code => class implementing App\Domain\Billing\PaymentGateway.
        // Adding a PSP = one adapter class + one line here + TALATA_PAYMENT_DRIVER=<code>.
        'gateways' => [
            'zarinpal' => ZarinpalGateway::class,
            'mock' => MockGateway::class,
        ],
        'zarinpal' => [
            'merchant_id' => env('TALATA_ZARINPAL_MERCHANT_ID'),
            'sandbox' => (bool) env('TALATA_ZARINPAL_SANDBOX', false),
            'connect_timeout' => (int) env('TALATA_ZARINPAL_CONNECT_TIMEOUT', 5),
            'timeout' => (int) env('TALATA_ZARINPAL_TIMEOUT', 15),
            // Opt-in: send the payer's login mobile so ZarinPal can offer saved cards (data minimisation: off by default).
            'send_mobile' => (bool) env('TALATA_ZARINPAL_SEND_MOBILE', false),
            'description' => 'خرید از زرلیو',
        ],
    ],

    'uploads' => [
        'logo_max_kb' => 1024,
        'logo_min_px' => 200,
        'logo_max_px' => 4000,
    ],

    // Zarlio support contact shown to merchants when staff help is needed (e.g. approving a shop name).
    'support' => [
        'phone' => env('TALATA_SUPPORT_PHONE') ?: '09396727215', // owner 2026-10-07; admin console value wins
    ],

    'public' => [
        'verify_per_minute' => 30,
        // Customer share links (/i/…) expire after this many days; null = never (verification links /v/ never expire).
        'share_ttl_days' => env('TALATA_SHARE_TTL_DAYS') ? (int) env('TALATA_SHARE_TTL_DAYS') : null,
        // Buyer-details reveal on the verification page: the scanner proves they are the buyer by entering the
        // buyer's mobile. At most this many DISTINCT numbers may be tried per invoice before it locks (anti-probe),
        // and the attempt memory resets after this many minutes.
        'reveal_max_numbers' => 3,
        'reveal_window_minutes' => 1440,
    ],

    'customers' => [
        'create_per_minute' => 30,
    ],
];
