<?php

use App\Domain\Affiliate\AffiliateService;
use App\Domain\Audit\Audit;
use App\Domain\Billing\BillingService;
use App\Domain\Customers\InstallmentService;
use App\Domain\Market\QuoteService;
use App\Domain\Sms\SmsCredit;
use App\Domain\Sms\SmsGateway;
use App\Domain\Sms\SmsService;
use App\Models\SmsMessage;
use App\Models\StaffUser;
use App\Support\Mobile;
use App\Support\ScheduleMonitor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('talata:quotes', fn (QuoteService $q) => $this->info($q->refresh() ? 'quotes refreshed' : 'quote refresh skipped/failed'))
    ->purpose('Central 180-second quote fetch (single-flight)');

Artisan::command('talata:payments-reconcile', fn (BillingService $b) => $this->info(json_encode($b->reconcile())))
    ->purpose('Retry ambiguous payment verifications and expire abandoned orders');

Artisan::command('talata:sms-reconcile', function (SmsGateway $gateway, SmsService $sms) {
    $n = 0;
    $apply = function (SmsMessage $m, string $status, ?string $providerId = null) use ($sms, &$n) {
        $n += $sms->applyProviderReport($m, $status, $providerId) ? 1 : 0;
    };

    // 1) Ambiguous sends that have a provider id, and 2) delivery reports for recent SENT messages (batched).
    $pending = SmsMessage::query()->whereNotNull('provider_message_id')
        ->where(fn ($q) => $q->where('status', 'UNKNOWN')->where('updated_at', '<', now()->subMinutes(5))
            ->orWhere(fn ($w) => $w->where('status', 'SENT')->where('created_at', '>', now()->subDays(2))->where('updated_at', '<', now()->subMinutes(2))))
        ->orderBy('updated_at')->limit(400)->get();
    if ($pending->isNotEmpty()) {
        $statuses = $gateway->statusMany($pending->pluck('provider_message_id')->all());
        foreach ($pending as $m) {
            $apply($m, $statuses[$m->provider_message_id] ?? 'UNKNOWN');
        }
    }

    // 3) Ambiguous sends without a provider id: look them up by our local id before refunding.
    SmsMessage::query()->where('status', 'UNKNOWN')->whereNull('provider_message_id')->where('updated_at', '<', now()->subMinutes(5))->limit(100)->get()
        ->each(function (SmsMessage $m) use ($gateway, $sms, $apply) {
            $found = $m->purpose === 'OTP' ? null : $gateway->lookupLocal($m->public_id);
            if ($found) {
                SmsMessage::query()->whereKey($m->id)->update(['provider_message_id' => $found['provider_id']]);
                $apply($m->refresh(), $found['status'] === 'UNKNOWN' ? 'SENT' : $found['status'], $found['provider_id']);
            } elseif ($m->updated_at->lt(now()->subMinutes(30))) {
                // The provider never received it: release the credit (and free allowance).
                $sms->applyOutcome($m, 'FAILED', null, 'not found at provider after 30 minutes');
            }
        });

    // 4) Stuck messages. QUEUED for long = the job was lost: dispatch again (SendSms claims QUEUED atomically,
    //    so a duplicate dispatch still sends once). SENDING for long = the worker died mid-send: the outcome is
    //    unknown, so it goes through the same lookup-then-refund path as any ambiguous send (never a blind resend).
    //    A login code that old has expired anyway: cancel it instead of sending a useless SMS.
    SmsMessage::query()->where('status', 'QUEUED')->where('purpose', 'OTP')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'CANCELLED', 'last_error' => 'expired before sending', 'updated_at' => now()]);
    SmsMessage::query()->where('status', 'QUEUED')->where('purpose', '!=', 'OTP')->where('updated_at', '<', now()->subMinutes(10))->limit(200)->pluck('id')
        ->each(fn ($id) => \App\Jobs\SendSms::dispatch($id));
    SmsMessage::query()->where('status', 'SENDING')->where('updated_at', '<', now()->subMinutes(10))->limit(200)->get()
        ->each(fn (SmsMessage $m) => $sms->applyOutcome($m, 'UNKNOWN', null, 'worker stopped while sending'));
    $this->info("reconciled {$n}");
})->purpose('Resolve SMS messages with unknown delivery status');

Artisan::command('talata:sms-credit-expire', fn (SmsCredit $c) => $this->info('expired lots: '.$c->expireDue()))
    ->purpose('Expire month-end (Free plan) SMS credit');

Artisan::command('talata:reminders', fn (InstallmentService $i, SmsService $s) => $this->info('reminders: '.$i->sendReminders($s)))
    ->purpose('Installment SMS reminders (Professional)');

Artisan::command('talata:staff {mobile} {name} {--role=admin} {--deactivate}', function () {
    $mobile = Mobile::normalize((string) $this->argument('mobile'));
    if (! $mobile || ! array_key_exists($this->option('role'), StaffUser::ROLES)) {
        $this->error('Invalid mobile or role (admin|support).');

        return 1;
    }
    $staff = StaffUser::query()->updateOrCreate(['mobile' => $mobile], [
        'name' => (string) $this->argument('name'), 'role' => $this->option('role'), 'active' => ! $this->option('deactivate'),
    ]);
    Audit::record($this->option('deactivate') ? 'admin.staff_deactivated' : 'admin.staff_saved', $staff, ['role' => $staff->role], null, 'system');
    $this->info(($staff->active ? 'Active' : 'Deactivated')." staff #{$staff->id} ({$staff->role}). Sign in at /admin/login");

    return 0;
})->purpose('Create, update or deactivate a platform administrator (admin console access)');

Artisan::command('talata:logs-prune', function () {
    $days = (int) config('talata.logs.tech_retention_days');
    $n = DB::connection('pgsql_log')->table('system_logs')->where('created_at', '<', now()->subDays($days))->delete();
    $this->info("deleted {$n} technical log rows older than {$days} days (activity log is append-only and kept)");
})->purpose('Apply technical log retention');

Artisan::command('talata:affiliate-approve', fn (AffiliateService $a) => $this->info('approved: '.$a->approveDue()))
    ->purpose('Move affiliate commissions past the hold period to payable');

// Each job records its last run for the admin «سلامت سیستم» page (App\Support\ScheduleMonitor).
ScheduleMonitor::track(Schedule::command('talata:logs-prune')->dailyAt('03:30'), 'logs-prune');
ScheduleMonitor::track(Schedule::command('talata:affiliate-approve')->hourlyAt(17)->withoutOverlapping(), 'affiliate-approve');
ScheduleMonitor::track(Schedule::command('talata:quotes')->everyThreeMinutes()->withoutOverlapping(), 'quotes');
ScheduleMonitor::track(Schedule::command('talata:payments-reconcile')->everyMinute()->withoutOverlapping(), 'payments-reconcile');
ScheduleMonitor::track(Schedule::command('talata:sms-reconcile')->everyMinute()->withoutOverlapping(), 'sms-reconcile');
ScheduleMonitor::track(Schedule::command('talata:sms-credit-expire')->everyFiveMinutes()->withoutOverlapping(), 'sms-credit-expire');
ScheduleMonitor::track(Schedule::command('talata:reminders')->hourlyAt(5)->withoutOverlapping(), 'reminders');
ScheduleMonitor::track(Schedule::command('queue:prune-failed --hours=720')->daily(), 'failed-prune');
