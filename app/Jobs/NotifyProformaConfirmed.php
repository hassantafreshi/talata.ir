<?php

namespace App\Jobs;

use App\Domain\Invoices\ProformaService;
use App\Domain\Notifications\PushService;
use App\Domain\Sms\SmsService;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Proforma;
use App\Models\SmsSetting;
use App\Models\User;
use App\Support\Digits;
use App\Support\Mobile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * «مشتری پیش‌فاکتور را تأیید کرد»: SMS to the shop owner (switchable in تنظیمات ← پیش‌فاکتور) and a Web Push
 * notification to the owner's and the sender's devices that enabled it, both linking to the invoice (or to the
 * پیش‌فاکتور when the shop issues manually). Never affects the confirmation itself.
 */
class NotifyProformaConfirmed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $proformaId) {}

    public function handle(SmsService $sms, PushService $push): void
    {
        $p = Proforma::withoutGlobalScope('tenant')->find($this->proformaId);
        if (! $p || $p->status !== 'CONFIRMED') {
            return;
        }
        $invoice = $p->issued_at ? Invoice::withoutGlobalScope('tenant')->whereKey($p->invoice_id)->where('status', 'issued')->first() : null;
        $url = $invoice ? route('invoices.issued', $invoice) : route('proformas.show', $p);
        $who = $p->buyer_name ?: Digits::toPersian(Mobile::mask($p->buyer_mobile));
        $number = Digits::toPersian($p->number);
        $amount = ProformaService::amountFa($p);
        $next = $invoice ? 'فاکتور فروش صادر شد.' : 'برای صدور فاکتور فروش بزنید.';

        $owners = Membership::query()->withoutGlobalScopes()->where('tenant_id', $p->tenant_id)->where('role', 'owner')->where('status', 'active')->pluck('user_id')->all();

        $push->toUsers(array_merge($owners, [$p->sent_by]), [
            'title' => 'پیش‌فاکتور تأیید شد ✓',
            'body' => "{$who} پیش‌فاکتور {$number} ({$amount}) را تأیید کرد. {$next}",
            'url' => $url, 'tag' => 'proforma-'.$p->public_id,
        ]);

        $smsOn = SmsSetting::withoutGlobalScope('tenant')->where('tenant_id', $p->tenant_id)->value('proforma_notify_sms');
        if ($smsOn === null || $smsOn) {
            foreach (User::query()->whereIn('id', $owners)->pluck('mobile') as $mobile) {
                $sms->queueShopNotice($p->tenant_id, $mobile, "زرلیو: {$who} پیش‌فاکتور {$number} به مبلغ {$amount} را تأیید کرد. {$next}\n{$url}", 'pfok:'.$p->id.':'.$mobile);
            }
        }
    }
}
