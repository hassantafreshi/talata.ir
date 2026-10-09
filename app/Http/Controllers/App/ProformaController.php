<?php

namespace App\Http\Controllers\App;

use App\Domain\DomainError;
use App\Domain\Invoices\InvoicePresenter;
use App\Domain\Invoices\ProformaService;
use App\Domain\Invoices\Qr;
use App\Models\Invoice;
use App\Models\Proforma;
use App\Models\SmsMessage;
use App\Support\Jalali;
use Illuminate\Http\Request;

/** پیش‌فاکتور, shop side: send from the review, list, detail, SMS/share, cancel/edit, issue (docs/PROFORMA.md). */
class ProformaController extends BaseController
{
    public function __construct(private readonly ProformaService $proformas) {}

    /** View data shared by the shop page, print and the public page. */
    public static function present(Proforma $p, bool $public): array
    {
        $doc = (new Invoice)->forceFill(['status' => 'proforma', 'snapshot' => $p->snapshot]);
        $v = InvoicePresenter::present($doc, $public);
        $tz = $p->snapshot['timezone'] ?? config('talata.timezone');

        return $v + [
            'doc' => 'proforma', 'state' => $p->state(), 'until_fa' => ProformaService::until($p, $tz),
            'hours_fa' => ProformaService::hoursFa($p->valid_hours), 'amount_fa' => ProformaService::amountFa($p),
            'confirmed_fa' => $p->confirmed_at ? Jalali::date($p->confirmed_at, $tz, true) : null,
        ];
    }

    public function send(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'version' => ['required', 'integer'], 'hours' => ['required', 'integer'], 'send_sms' => ['nullable', 'boolean'],
            'buyer' => ['nullable', 'array'], 'buyer.name' => ['nullable', 'string', 'max:120'], 'buyer.mobile' => ['nullable', 'string', 'max:20'],
        ]);
        if ($invoice->created_by !== auth()->id() && ! $this->membership()?->can('invoices.view')) {
            throw new DomainError('FORBIDDEN', 'این پیش‌نویس را همکار دیگری ساخته است.', 403);
        }
        [$p, $sms] = $this->proformas->send($invoice, $this->tenant(), $request->user(), $data);

        return response()->json([
            'proforma_id' => $p->public_id, 'next' => route('proformas.show', $p),
            'sms' => $sms instanceof DomainError ? ['status' => 'NOT_SENT', 'message_fa' => $sms->messageFa] : ($sms ? ['status' => $sms->status] : null),
        ], 201);
    }

    public function index()
    {
        $items = Proforma::query()->latest('id')->limit(100)->get();

        return view('app.proformas', ['items' => $items, 'tz' => $this->tenant()->timezone]);
    }

    public function show(Proforma $proforma)
    {
        $this->assertSees($proforma);
        $sms = SmsMessage::query()->where('invoice_id', $proforma->invoice_id)->where('purpose', 'PROFORMA')->where('idempotency_key', 'like', 'pf:'.$proforma->id.':%')->orderByDesc('id')->first();
        $invoice = $proforma->invoice;

        return view('app.proforma-show', [
            'p' => $proforma, 'v' => self::present($proforma, false), 'link' => ProformaService::link($proforma), 'sms' => $sms,
            'invoice' => $invoice, 'canSms' => $this->ent()->can($this->tenant(), 'invoice.sms_share'),
            'shareText' => ProformaService::smsBody($proforma, ProformaService::link($proforma), $this->tenant()->timezone),
        ]);
    }

    public function print(Proforma $proforma)
    {
        $this->assertSees($proforma);
        $link = ProformaService::link($proforma);

        return view('print.invoice', ['v' => self::present($proforma, false), 'qr' => Qr::svg($link), 'verifyShort' => preg_replace('#^https?://#', '', $link), 'back' => route('proformas.show', $proforma)]);
    }

    public function sms(Request $request, Proforma $proforma)
    {
        $this->assertSees($proforma);
        $m = $this->proformas->resendSms($proforma, $this->tenant(), $request->user());

        return response()->json(['status' => $m->status]);
    }

    public function cancel(Request $request, Proforma $proforma)
    {
        $this->assertSees($proforma);
        $data = $request->validate(['reason' => ['required', 'in:EDIT,CUSTOMER,PRICE,OTHER']]);
        $this->proformas->cancel($proforma, $data['reason']);

        return response()->json(['ok' => true, 'next' => $data['reason'] === 'EDIT' ? route('invoices.items', $proforma->invoice) : route('proformas.show', $proforma)]);
    }

    public function issue(Request $request, Proforma $proforma)
    {
        $this->assertSees($proforma);
        $invoice = $this->proformas->issueFrom($proforma, $request->user());
        if (! $invoice) {
            throw new DomainError('PROFORMA_ISSUE_FAILED', $proforma->refresh()->issue_error ?: 'صدور فاکتور ممکن نشد.', 409);
        }

        return response()->json(['next' => route('invoices.issued', $invoice)]);
    }

    private function assertSees(Proforma $p): void
    {
        $m = $this->membership();
        if (! $m?->can('invoices.view') && $p->sent_by !== auth()->id()) {
            throw new DomainError('FORBIDDEN', 'دسترسی این کار را ندارید. از مالک فروشگاه بخواهید.', 403);
        }
    }
}
