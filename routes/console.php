<?php

use App\Domain\Affiliate\AffiliateService;
use App\Domain\Audit\Audit;
use App\Domain\Billing\BillingService;
use App\Domain\Customers\InstallmentService;
use App\Domain\Market\QuoteService;
use App\Domain\Sms\SmsCredit;
use App\Domain\Sms\SmsGateway;
use App\Domain\Sms\SmsService;
use App\Jobs\SendSms;
use App\Models\SmsMessage;
use App\Models\StaffUser;
use App\Support\Mobile;
use App\Support\ScheduleMonitor;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('talata:quotes {--force : fetch now, ignoring the time-of-day interval}', fn (QuoteService $q) => $this->info(($this->option('force') ? $q->refresh() : $q->refreshIfDue(true)) ? 'quotes refreshed' : 'quote refresh not due/failed'))
    ->purpose('Quote fetch by Tehran time-of-day interval (App\\Domain\\Market\\QuoteSchedule; single-flight)');

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
        ->each(fn ($id) => SendSms::dispatch($id));
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
    // Only the service owner may hold the admin role from the command line; everyone else is added by the owner
    // in the console (staff.manage is owner-only), so no other number can become an admin on its own.
    if ($this->option('role') === 'admin' && ! $this->option('deactivate') && $mobile !== (string) config('talata.admin.owner_mobile')) {
        $this->error('Only the service owner (TALATA_ADMIN_OWNER_MOBILE) can be made admin here; the owner adds other staff in the console.');

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
    $n = DB::connection(config('database.log_connection'))->table('system_logs')->where('created_at', '<', now()->subDays($days))->delete();
    $this->info("deleted {$n} technical log rows older than {$days} days (activity log is append-only and kept)");
})->purpose('Apply technical log retention');

Artisan::command('talata:affiliate-approve', fn (AffiliateService $a) => $this->info('approved: '.$a->approveDue()))
    ->purpose('Move affiliate commissions past the hold period to payable');

// Hosts that disable proc_open (shared hosting) cannot run `schedule:run`, which starts every job as a shell process.
// `talata:tick` does the same due-check but runs each job inside this PHP process. Cron: `php artisan talata:tick`
// every minute. Elsewhere (a normal server) keep using `schedule:run`; both can never run the same job twice at once
// because overlapping protection uses the same lock.
Artisan::command('talata:tick', function () {
    $schedule = app(Illuminate\Console\Scheduling\Schedule::class);
    $ran = 0;
    foreach ($schedule->dueEvents($this->laravel) as $event) {
        if ($event instanceof CallbackEvent) {
            $event->run($this->laravel);
            $ran++;

            continue;
        }
        if (! preg_match('/artisan[\'"]?\s+(.+)$/', (string) $event->command, $m)) {
            continue;
        }
        if ($event->shouldSkipDueToOverlapping()) {
            continue;
        }
        $code = 1;
        try {
            $event->callBeforeCallbacks($this->laravel);
            $code = Artisan::call(trim($m[1]));
        } catch (Throwable $e) {
            report($e);
        } finally {
            $event->exitCode = $code;
            try {
                $event->callAfterCallbacks($this->laravel);
            } finally {
                $event->mutex->forget($event);
            }
        }
        $ran++;
    }
    $this->info("tick: {$ran} job(s)");
})->purpose('Run due scheduled jobs inside this process (hosts without proc_open)');

// Each job records its last run for the admin «سلامت سیستم» page (App\Support\ScheduleMonitor).
ScheduleMonitor::track(Schedule::command('talata:logs-prune')->dailyAt('03:30'), 'logs-prune');
ScheduleMonitor::track(Schedule::command('talata:affiliate-approve')->hourlyAt(17)->withoutOverlapping(), 'affiliate-approve');
ScheduleMonitor::track(Schedule::command('talata:quotes')->everyMinute()->withoutOverlapping(), 'quotes');
ScheduleMonitor::track(Schedule::command('talata:payments-reconcile')->everyMinute()->withoutOverlapping(), 'payments-reconcile');
ScheduleMonitor::track(Schedule::command('talata:sms-reconcile')->everyMinute()->withoutOverlapping(), 'sms-reconcile');
ScheduleMonitor::track(Schedule::command('talata:sms-credit-expire')->everyFiveMinutes()->withoutOverlapping(), 'sms-credit-expire');
ScheduleMonitor::track(Schedule::command('talata:reminders')->hourlyAt(5)->withoutOverlapping(), 'reminders');
ScheduleMonitor::track(Schedule::command('queue:prune-failed --hours=720')->daily(), 'failed-prune');
