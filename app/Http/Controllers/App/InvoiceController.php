<?php

namespace App\Http\Controllers\App;

use App\Domain\DomainError;
use App\Domain\Invoices\InvoicePresenter;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Invoices\Qr;
use App\Domain\Sms\SmsCredit;
use App\Domain\Sms\SmsService;
use App\Models\Invoice;
use App\Models\InvoiceShare;
use App\Models\SmsMessage;
use App\Support\Digits;
use App\Support\Jalali;
use App\Support\Money;
use Illuminate\Http\Request;

class InvoiceController extends BaseController
{
    public const SMS_STATUS_FA = [
        'QUEUED' => ['در صف ارسال', 'info'], 'SENDING' => ['در حال ارسال', 'info'], 'SENT' => ['ارسال شد', 'ok'],
        'DELIVERED' => ['تحویل شد', 'ok'], 'FAILED' => ['ناموفق', 'err'], 'UNKNOWN' => ['نامشخص؛ در حال بررسی', 'warn'],
        'AWAITING_CREDIT' => ['منتظر اعتبار پیامک', 'warn'], 'CANCELLED' => ['لغو شد', 'off'],
    ];

    public function __construct(private readonly InvoiceService $invoices) {}

    private function query(Request $request)
    {
        $tenant = $this->tenant();
        $q = Invoice::query()->withCount('items')->with(['smsMessages' => fn ($q) => $q->limit(1)]);
        if (! $this->ent()->can($tenant, 'history.all')) {
            [$start] = Jalali::monthBounds(now(), $tenant->timezone);
            $q->where(fn ($w) => $w->where('status', 'draft')->orWhere('issued_at', '>=', $start));
        }
        $filter = $request->query('filter', 'all');
        match ($filter) {
            'draft' => $q->where('status', 'draft'),
            'issued' => $q->where('status', 'issued'),
            'void' => $q->where('status', 'void'),
            default => null,
        };
        // Independent toggles, combinable with the status chips above.
        if ($request->boolean('month')) {
            [$start] = Jalali::monthBounds(now(), $tenant->timezone);
            $q->where('issued_at', '>=', $start); // issued this Jalali month (drafts have no issue date)
        }
        if ($request->boolean('installment')) {
            $q->whereHas('agreements');
        }
        if ($s = trim((string) $request->query('q', ''))) {
            $s = Digits::toLatin($s);
            $q->where(fn ($w) => $w->where('number', 'ilike', '%'.addcslashes($s, '%_\\').'%')->orWhere('buyer_name', 'ilike', '%'.addcslashes($s, '%_\\').'%')->orWhere('buyer_mobile', 'like', '%'.preg_replace('/\D/', '', $s).'%'));
        }

        return $q->orderByRaw("CASE WHEN status = 'draft' THEN 0 ELSE 1 END")->orderByDesc('issued_at')->orderByDesc('updated_at');
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant();

        return view('app.invoices', [
            'page' => $this->query($request)->paginate(25)->withQueryString(),
            'filter' => $request->query('filter', 'all'),
            'search' => $request->query('q', ''),
            'onlyMonth' => $request->boolean('month'),
            'onlyInstallment' => $request->boolean('installment'),
            'canInstallments' => $this->ent()->can($tenant, 'installments.manage'),
            'quota' => $this->ent()->quota($tenant, 'invoices_per_month'),
            'links' => $this->ent()->quota($tenant, 'links_per_month'),
            'historyRestricted' => ! $this->ent()->can($tenant, 'history.all'),
        ]);
    }

    public function list(Request $request)
    {
        $page = $this->query($request)->paginate(25)->withQueryString();

        return response()->json(['html' => view('app.partials.invoice-rows', ['page' => $page])->render(), 'total' => $page->total()]);
    }

    private function load(Invoice $invoice): Invoice
    {
        if ($invoice->isDraft()) {
            abort(redirect()->route('invoices.items', $invoice));
        }
        // Team access: "invoices.view" sees every invoice; a seller with only "invoice.issue" sees the ones they issued.
        $m = $this->membership();
        if (! $m?->can('invoices.view') && ! ($m?->can('invoice.issue') && in_array(auth()->id(), [$invoice->issued_by, $invoice->created_by], true))) {
            throw new DomainError('FORBIDDEN', 'دسترسی این کار را ندارید. از مالک فروشگاه بخواهید.', 403);
        }
        $tenant = $this->tenant();
        if (! $this->ent()->can($tenant, 'history.all')) {
            [$start] = Jalali::monthBounds(now(), $tenant->timezone);
            if ($invoice->issued_at->lt($start)) {
                throw new DomainError('HISTORY_RESTRICTED', 'فاکتورهای ماه‌های قبل در پلن پایه و حرفه‌ای در دسترس است. داده‌های شما حفظ شده‌اند.', 403);
            }
        }

        return $invoice;
    }

    public function show(Invoice $invoice)
    {
        $this->load($invoice);

        return view('app.invoice-show', $this->detailData($invoice));
    }

    public function issued(Invoice $invoice)
    {
        $this->load($invoice);

        return view('app.issued', $this->detailData($invoice));
    }

    private function detailData(Invoice $invoice): array
    {
        $tenant = $this->tenant();
        $share = InvoiceShare::query()->where('invoice_id', $invoice->id)->whereNull('revoked_at')->first();
        $sms = SmsMessage::query()->where('invoice_id', $invoice->id)->orderByDesc('id')->first();

        return [
            'invoice' => $invoice,
            'v' => InvoicePresenter::present($invoice),
            'qr' => Qr::svg($this->invoices->verifyUrl($invoice)),
            'verifyUrl' => $this->invoices->verifyUrl($invoice),
            'share' => $share,
            'shareUrl' => $share ? $this->invoices->shareUrl($share) : null,
            'sms' => $sms,
            'smsStatus' => $sms ? self::SMS_STATUS_FA[$sms->status] : null,
            'links' => $this->ent()->quota($tenant, 'links_per_month'),
            'balanceFa' => Money::toman(app(SmsCredit::class)->balance($tenant->id)),
            'replacement' => $invoice->replacement(),
            'canVoid' => $this->membership()->can('invoice.void'),
        ];
    }

    public function status(Invoice $invoice)
    {
        $this->load($invoice);
        $sms = SmsMessage::query()->where('invoice_id', $invoice->id)->orderByDesc('id')->first();

        return response()->json([
            'status' => $invoice->status,
            'sms' => $sms ? ['status' => $sms->status, 'label_fa' => self::SMS_STATUS_FA[$sms->status][0], 'kind' => self::SMS_STATUS_FA[$sms->status][1], 'final' => in_array($sms->status, SmsMessage::FINAL, true)] : null,
        ]);
    }

    public function print(Invoice $invoice)
    {
        $this->load($invoice);

        return view('print.invoice', [
            'v' => InvoicePresenter::present($invoice),
            'qr' => Qr::svg($this->invoices->verifyUrl($invoice)),
            'verifyShort' => preg_replace('#^https?://#', '', config('talata.public_url')).'/v/…',
            'back' => route('invoices.show', $invoice),
        ]);
    }

    public function share(Request $request, Invoice $invoice)
    {
        $this->load($invoice);
        $share = $this->invoices->ensureShare($invoice, $this->tenant(), $request->user());
        $links = $this->ent()->quota($this->tenant(), 'links_per_month');

        return response()->json(['url' => $this->invoices->shareUrl($share), 'links_remaining' => $links['remaining']]);
    }

    public function revokeShare(Invoice $invoice)
    {
        $this->invoices->revokeShare($this->load($invoice));

        return response()->json(['ok' => true]);
    }

    public function resendSms(Request $request, Invoice $invoice, SmsService $sms)
    {
        $this->load($invoice);
        $share = $this->invoices->ensureShare($invoice, $this->tenant(), $request->user());
        $message = $sms->queueInvoiceSms($this->tenant(), $invoice, $this->invoices->shareUrl($share), $request->user()->id, true);
        [$label, $kind] = self::SMS_STATUS_FA[$message->status];

        return response()->json(['status' => $message->status, 'label_fa' => $label, 'kind' => $kind, 'buy_url' => $message->status === 'AWAITING_CREDIT' ? route('settings.sms', ['return' => $invoice->public_id]) : null]);
    }

    public function void(Request $request, Invoice $invoice)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:30'], 'note' => ['nullable', 'string', 'max:250']]);
        $this->invoices->void($this->load($invoice), $data['reason'], $data['note'] ?? null);

        return response()->json(['ok' => true, 'next' => route('invoices.show', $invoice)]);
    }

    public function replace(Request $request, Invoice $invoice)
    {
        $draft = $this->invoices->replace($this->load($invoice), $this->tenant(), $request->user());

        return response()->json(['next' => route('invoices.items', $draft)]);
    }

    public function revokeVerification(Request $request, Invoice $invoice)
    {
        if ($invoice->isDraft()) {
            throw new DomainError('NOT_ISSUED', 'فقط فاکتور صادرشده کد بررسی دارد.', 409);
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:250']]);
        $this->invoices->revokeVerification($this->load($invoice), $request->user(), $data['reason']);

        return response()->json(['ok' => true]);
    }
}
