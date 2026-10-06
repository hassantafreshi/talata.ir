<?php

use App\Domain\Billing\BillingService;
use App\Domain\Customers\InstallmentService;
use App\Domain\Market\QuoteService;
use App\Domain\Sms\SmsCredit;
use App\Domain\Sms\SmsGateway;
use App\Domain\Sms\SmsService;
use App\Models\SmsMessage;
use Illuminate\Support\Facades\Artisan;
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
    // Without a provider id after 30 minutes the send is treated as failed and credit is returned.
    SmsMessage::query()->where('status', 'UNKNOWN')->whereNull('provider_message_id')->where('updated_at', '<', now()->subMinutes(30))->limit(200)->get()
        ->each(fn (SmsMessage $m) => $sms->applyOutcome($m, 'FAILED', null, 'no provider id after 30 minutes'));
    $this->info("reconciled {$n}");
})->purpose('Resolve SMS messages with unknown delivery status');

Artisan::command('talata:sms-credit-expire', fn (SmsCredit $c) => $this->info('expired lots: '.$c->expireDue()))
    ->purpose('Expire month-end (Free plan) SMS credit');

Artisan::command('talata:reminders', fn (InstallmentService $i, SmsService $s) => $this->info('reminders: '.$i->sendReminders($s)))
    ->purpose('Installment SMS reminders (Professional)');

Schedule::command('talata:quotes')->everyThreeMinutes()->withoutOverlapping();
Schedule::command('talata:payments-reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('talata:sms-reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('talata:sms-credit-expire')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('talata:reminders')->hourlyAt(5)->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->daily();
