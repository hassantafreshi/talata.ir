<?php

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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('talata:quotes', fn (QuoteService $q) => $this->info($q->refresh() ? 'quotes refreshed' : 'quote refresh skipped/failed'))
    ->purpose('Central 180-second quote fetch (single-flight)');

Artisan::command('talata:payments-reconcile', fn (BillingService $b) => $this->info(json_encode($b->reconcile())))
    ->purpose('Retry ambiguous payment verifications and expire abandoned orders');

Artisan::command('talata:sms-reconcile', function (SmsGateway $gateway, SmsService $sms) {
    $n = 0;
    SmsMessage::query()->where('status', 'UNKNOWN')->where('updated_at', '<', now()->subMinutes(5))->whereNotNull('provider_message_id')->limit(200)->get()
        ->each(function (SmsMessage $m) use ($gateway, $sms, &$n) {
            $status = $gateway->status($m->provider_message_id);
            if ($status !== 'UNKNOWN') {
                $sms->applyOutcome($m, $status);
                $n++;
            }
        });
    // Delivery reports for recently sent messages (SENT → DELIVERED). An undelivered report keeps
    // the message SENT (the provider charged it) and records the reason.
    SmsMessage::query()->where('status', 'SENT')->whereNotNull('provider_message_id')->where('created_at', '>', now()->subDays(2))
        ->where('updated_at', '<', now()->subMinutes(2))->orderBy('updated_at')->limit(100)->get()
        ->each(function (SmsMessage $m) use ($gateway, $sms, &$n) {
            $status = $gateway->status($m->provider_message_id);
            if ($status === 'DELIVERED') {
                $sms->applyOutcome($m, 'DELIVERED');
                $n++;
            } elseif ($status === 'FAILED') {
                $m->forceFill(['last_error' => 'undelivered (provider report)'])->save();
            } else {
                $m->touch();
            }
        });
    // Without a provider id after 30 minutes the send is treated as failed and credit is returned.
    SmsMessage::query()->where('status', 'UNKNOWN')->whereNull('provider_message_id')->where('updated_at', '<', now()->subMinutes(30))->limit(200)->get()
        ->each(fn (SmsMessage $m) => $sms->applyOutcome($m, 'FAILED', null, 'no provider id after 30 minutes'));
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

Schedule::command('talata:logs-prune')->dailyAt('03:30');
Schedule::command('talata:quotes')->everyThreeMinutes()->withoutOverlapping();
Schedule::command('talata:payments-reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('talata:sms-reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('talata:sms-credit-expire')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('talata:reminders')->hourlyAt(5)->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->daily();
