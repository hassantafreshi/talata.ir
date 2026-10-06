<?php

/*
| Talata application configuration.
| Commercial values (prices, quotas, SMS prices) live in the versioned
| `pricing_versions` table (seeded from database/seeders/data/plans-pricing.json),
| never in this file or in domain code. This file only holds operational
| and security limits that the provider tunes per deployment.
*/

return [
    'timezone' => env('TALATA_TIMEZONE', 'Asia/Tehran'),
    'public_url' => rtrim(env('TALATA_PUBLIC_URL', env('APP_URL', 'http://localhost')), '/'),

    'drivers' => [
        'sms' => env('TALATA_SMS_DRIVER', 'log'),          // kavenegar | log | fake
        'quotes' => env('TALATA_QUOTE_DRIVER', 'demo'),    // demo
        'payment' => env('TALATA_PAYMENT_DRIVER', 'mock'), // mock
    ],

    'sms_dev_driver_allowed_in_production' => (bool) env('TALATA_ALLOW_DEV_SMS_IN_PRODUCTION', false),

    // Passkeys (fingerprint / face / device lock). RP ID = registrable domain, e.g. talata.ir.
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
        'allowed_ips' => env('TALATA_ADMIN_ALLOWED_IPS'),   // comma list; empty = any IP (OTP/passkey still required)
    ],

    'logs' => [
        'tech_retention_days' => (int) env('TALATA_TECH_LOG_DAYS', 90),
    ],

    'otp' => [
        'length' => 6,
        'ttl_seconds' => 120,
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
        'bits' => (int) env('TALATA_POW_BITS', 18),
        'ttl_seconds' => 300,
        'min_form_seconds' => 2,
    ],

    'sms' => [
        'max_sends_per_invoice' => 3,
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
        'free_yearly_window' => 'rolling_365_days',
    ],

    'quotes' => [
        'interval_seconds' => 180,
        'stale_after_minutes' => 8,
        'client_poll_seconds' => 180,
    ],

    'invoices' => [
        'max_rows' => 60,
        'max_weight_g' => '100000',
        'max_amount_irr' => '1000000000000000',
        'max_percent' => '1000',
        'issue_per_minute' => 30,
        'draft_save_per_minute' => 120,
    ],

    'payments' => [
        'order_expiry_minutes' => 20,
        'refund_hours_display' => 72,
        'orders_per_hour' => 10,
        'mock_allowed_in_production' => (bool) env('TALATA_ALLOW_MOCK_PAYMENTS_IN_PRODUCTION', false),
        'reconcile_max_hours' => 24,
    ],

    'uploads' => [
        'logo_max_kb' => 1024,
        'logo_min_px' => 200,
        'logo_max_px' => 4000,
    ],

    'public' => [
        'verify_per_minute' => 30,
    ],

    'customers' => [
        'create_per_minute' => 30,
    ],
];
