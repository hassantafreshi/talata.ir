<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\TenantDirectory;
use App\Domain\Billing\PaymentGateways;
use App\Domain\Market\QuoteService;
use App\Models\AuditEvent;
use App\Models\BillingOrder;
use App\Models\Invoice;
use App\Models\Passkey;
use App\Models\ShopProfile;
use App\Models\SmsCreditLot;
use App\Models\SmsMessage;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Jalali;
use App\Support\ScheduleMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Admin dashboard (docs/handoff/04_SCREENS_ADMIN.md A-01): tiles, status row, open alerts, today's tasks. No charts. */
class DashboardController extends AdminController
{
    public function __invoke(QuoteService $quotes, PaymentGateways $gateways, TenantDirectory $directory)
    {
        $tz = config('talata.timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();
        [$monthStart, $monthEnd] = Jalali::monthBounds(now(), $tz);
        $day = now()->subDay();

        $plans = Subscription::withoutGlobalScope('tenant')->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->selectRaw('plan_code, count(distinct tenant_id) c')->groupBy('plan_code')->pluck('c', 'plan_code');
        $activeTenants = Tenant::query()->where('status', 'active')->count();
        $revenue = BillingOrder::withoutGlobalScope('tenant')->whereIn('status', ['PAID', 'FULFILLED'])->whereBetween('paid_at', [$monthStart, $monthEnd])
            ->selectRaw('product, sum(subtotal_irr) base, sum(vat_irr) vat, sum(amount_irr) total')->groupBy('product')->get()->keyBy('product');
        $issued7 = Invoice::withoutGlobalScope('tenant')->whereIn('status', ['issued', 'void'])->whereBetween('issued_at', [$today->subDays(7), $today])->count();
        $sms24 = SmsMessage::query()->where('created_at', '>=', $day)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $quote = $quotes->latestDto($tz);
        $gateway = $gateways->default();
        $paymentsAction = BillingOrder::withoutGlobalScope('tenant')->where(fn ($w) => $w->whereIn('status', ['PENDING_VERIFICATION', 'PAID'])
            ->orWhere(fn ($v) => $v->where('status', 'VERIFYING')->where('updated_at', '<', now()->subMinutes(5))))->count();
        $smsUnknown = SmsMessage::query()->where('status', 'UNKNOWN')->where('updated_at', '<', now()->subMinutes(30))->count();
        $failedJobs = DB::table('failed_jobs')->count();
        $otpToday = SmsMessage::query()->where('purpose', 'OTP')->where('created_at', '>=', $today)->count();
        $otpBudget = (int) config('talata.otp.global_daily_budget') + (int) config('talata.otp.existing_users_daily_budget');
        $lastQuoteRun = ScheduleMonitor::last('quotes');

        $alerts = [];
        $alert = function (string $level, string $text, ?string $url = null, $since = null) use (&$alerts) {
            $alerts[] = ['level' => $level, 'text' => $text, 'url' => $url, 'since' => $since];
        };
        if (app()->environment('production') && $gateway->isMock()) {
            $alert('high', 'درگاه پرداخت در محیط اصلی روی حالت آزمایشی است.', route('admin.integrations'));
        }
        if (app()->environment('production') && config('app.debug')) {
            $alert('high', 'حالت debug در محیط اصلی روشن است.', route('admin.system'));
        }
        if ($quote['freshness'] !== 'FRESH') {
            $alert($quote['freshness'] === 'ERROR' ? 'high' : 'medium', 'نرخ مظنه '.($quote['freshness'] === 'ERROR' ? 'دریافت نمی‌شود' : 'قدیمی است').'؛ در صورت نیاز نرخ اضطراری اعلام کنید.', route('admin.quotes'));
        }
        if ($quote['is_emergency'] ?? false) {
            $alert('medium', 'نرخ اعلامی (اضطراری) فعال است.', route('admin.quotes'));
        }
        if ($paymentsAction) {
            $alert('high', $paymentsAction.' پرداخت نیازمند بررسی است.', route('admin.payments', ['f' => 'action']));
        }
        if ($smsUnknown) {
            $alert('medium', $smsUnknown.' پیامک بیش از ۳۰ دقیقه وضعیت نامعلوم دارد.', route('admin.sms'));
        }
        if ($failedJobs) {
            $alert('medium', $failedJobs.' کار ناموفق در صف.', route('admin.system'));
        }
        if ($otpBudget && $otpToday >= 0.8 * $otpBudget) {
            $alert('high', 'مصرف کد ورود امروز به ۸۰٪ سقف روزانه رسید.', route('admin.sms'));
        }
        $backupFile = (string) config('talata.ops.backup_heartbeat_file');
        if (app()->environment('production') && ($backupFile === '' || ! is_file($backupFile) || filemtime($backupFile) < now()->subHours(26)->getTimestamp())) {
            $alert('high', 'پشتیبان روزانه پایگاه داده ثبت نشده است.', route('admin.system'));
        }

        return view('admin.dashboard', [
            'tiles' => [
                'tenants' => $activeTenants, 'plans' => $plans, 'free' => max(0, $activeTenants - (int) $plans->sum()),
                'revenue' => $revenue, 'revenue_total' => (string) $revenue->sum('total'), 'revenue_vat' => (string) $revenue->sum('vat'),
                'issued_today' => Invoice::withoutGlobalScope('tenant')->whereIn('status', ['issued', 'void'])->where('issued_at', '>=', $today)->count(),
                'issued_avg' => round($issued7 / 7, 1),
                'voids_today' => Invoice::withoutGlobalScope('tenant')->where('status', 'void')->where('voided_at', '>=', $today)->count(),
                'signups_week' => Tenant::query()->where('created_at', '>=', $today->subDays(7))->count(),
                'incomplete' => ShopProfile::withoutGlobalScope('tenant')->where(fn ($w) => $w->whereNull('name')->orWhereNull('business_mobile')->orWhereNull('address'))->count(),
                'logins' => AuditEvent::query()->whereIn('event', ['auth.login', 'auth.passkey_login'])->where('created_at', '>=', $day)->count(),
                'otp_failed' => AuditEvent::query()->whereIn('event', ['auth.otp_wrong', 'auth.otp_locked', 'auth.passkey_failed'])->where('created_at', '>=', $day)->count(),
            ],
            'status' => [
                'quote' => $quote,
                'sms_sent' => (int) (($sms24['SENT'] ?? 0) + ($sms24['DELIVERED'] ?? 0)), 'sms_unknown' => $smsUnknown, 'sms_failed' => (int) ($sms24['FAILED'] ?? 0),
                'sms_driver' => config('talata.drivers.sms'),
                'gateway' => $gateway->code(), 'gateway_mock' => $gateway->isMock(),
                'queued' => DB::table('jobs')->count(), 'failed_jobs' => $failedJobs, 'scheduler_at' => $lastQuoteRun['at'],
            ],
            'alerts' => $alerts,
            'tasks' => [
                ['label' => 'بررسی پرداخت‌های نامعلوم یا اعمال‌نشده', 'count' => $paymentsAction, 'url' => route('admin.payments', ['f' => 'action'])],
                ['label' => 'پلن‌هایی که تا ۷ روز دیگر تمام می‌شوند', 'count' => $directory->query(['period' => 'soon'])->count(), 'url' => route('admin.tenants', ['period' => 'soon'])],
                ['label' => 'فروشگاه‌های رایگانِ رسیده به سقف فاکتور', 'count' => $directory->query(['plan' => 'free', 'quota' => 'at_cap'])->count(), 'url' => route('admin.tenants', ['plan' => 'free', 'quota' => 'at_cap'])],
                ['label' => 'اعتبار پیامک رایگانِ رو به انقضا (۳ روز)', 'count' => SmsCreditLot::withoutGlobalScope('tenant')->where('remaining_irr', '>', 0)->whereBetween('expires_at', [now(), now()->addDays(3)])->count(), 'url' => route('admin.tenants')],
            ],
            'techErrors' => DB::connection('pgsql_log')->table('system_logs')->where('created_at', '>=', $day)->whereIn('level', ['warning', 'error', 'critical', 'alert', 'emergency'])
                ->selectRaw('service, level, count(*) c')->groupBy('service', 'level')->orderByDesc('c')->limit(8)->get(),
            'needsPasskey' => (bool) config('talata.admin.require_passkey') && ! Passkey::query()->where('owner_type', 'staff')->where('owner_id', $this->staff()->id)->exists(),
        ]);
    }
}
