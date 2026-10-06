<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Market\QuoteService;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\BillingOrder;
use App\Models\Invoice;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(QuoteService $quotes)
    {
        $day = now()->subDay();
        $sms = SmsMessage::query()->where('created_at', '>=', $day)->selectRaw('purpose, status, count(*) c')->groupBy('purpose', 'status')->get();
        $errors = DB::connection('pgsql_log')->table('system_logs')->where('created_at', '>=', $day)->whereIn('level', ['warning', 'error', 'critical', 'alert', 'emergency'])
            ->selectRaw('service, level, count(*) c')->groupBy('service', 'level')->orderByDesc('c')->get();

        return view('admin.dashboard', [
            'stats' => [
                'tenants' => Tenant::query()->count(),
                'tenants_new' => Tenant::query()->where('created_at', '>=', $day)->count(),
                'users' => User::query()->count(),
                'logins' => AuditEvent::query()->whereIn('event', ['auth.login', 'auth.passkey_login'])->where('created_at', '>=', $day)->count(),
                'otp_failed' => AuditEvent::query()->whereIn('event', ['auth.otp_wrong', 'auth.otp_locked', 'auth.passkey_failed'])->where('created_at', '>=', $day)->count(),
                'invoices' => Invoice::withoutGlobalScope('tenant')->where('issued_at', '>=', $day)->count(),
                'payments_ok' => BillingOrder::withoutGlobalScope('tenant')->where('status', 'FULFILLED')->where('updated_at', '>=', $day)->count(),
                'payments_failed' => BillingOrder::withoutGlobalScope('tenant')->whereIn('status', ['FAILED', 'EXPIRED'])->where('updated_at', '>=', $day)->count(),
                'payments_pending' => BillingOrder::withoutGlobalScope('tenant')->where('status', 'PENDING_VERIFICATION')->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'queued_jobs' => DB::table('jobs')->count(),
            ],
            'sms' => $sms,
            'techErrors' => $errors,
            'quote' => $quotes->latestDto(config('talata.timezone')),
            'smsDriver' => config('talata.drivers.sms'),
            'paymentDriver' => config('talata.drivers.payment'),
        ]);
    }
}
