<?php

namespace App\Http\Controllers\App;

use App\Domain\DomainError;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Invoices\ProformaService;
use App\Domain\Market\QuoteService;
use App\Domain\Reports\DashboardService;
use App\Domain\Sms\SmsService;
use App\Domain\Tax\TaxRules;
use App\Models\Invoice;
use App\Models\Proforma;
use App\Models\SmsSetting;
use App\Support\Mobile;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;

class InvoiceDraftController extends BaseController
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function start(QuoteService $quotes, DashboardService $dashboard)
    {
        $tenant = $this->tenant();
        // Today's sales on the home screen: same rules as the dashboard (team permission reports.view, plan feature
        // dashboard.view, and the plan decides which cards are open and which are locked).
        $sales = $this->membership()->can('reports.view') && $this->ent()->can($tenant, 'dashboard.view')
            ? $dashboard->report($tenant, 'day') : null;
        $draft = Invoice::query()->where('status', 'draft')->where('created_by', auth()->id())->latest('updated_at')->withCount('items')->first();

        return view('app.rate', [
            'quote' => $quotes->latestDto($tenant->timezone),
            'board' => $quotes->board($tenant->timezone),
            'draft' => $draft,
            'quota' => $this->ent()->quota($tenant, 'invoices_per_month'),
            'canIssue' => $this->membership()->can('invoice.issue'),
            'pollSeconds' => QuoteService::pollSeconds(),
            'sales' => $sales,
        ]);
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'mode' => ['required', 'in:MARKET,MANUAL,NONE'],
            'value_irr' => ['nullable', 'string', 'max:24'],
            'value_toman' => ['nullable', 'string', 'max:30'],
            'reason' => ['nullable', 'string', 'max:30'],
        ]);
        $invoice = $this->invoices->createDraft($this->tenant(), $request->user(), $data);

        return response()->json(['draft_id' => $invoice->public_id, 'version' => $invoice->version, 'next' => route('invoices.items', $invoice)], 201);
    }

    public function items(Invoice $invoice, QuoteService $quotes, TaxRules $tax)
    {
        $this->assertDraft($invoice);
        $tenant = $this->tenant();

        return view('app.items', [
            'invoice' => $invoice,
            'rows' => $invoice->items()->get(),
            'state' => $this->invoices->state($invoice, $tenant),
            'latest' => $quotes->latestDto($tenant->timezone),
            // Market buy rate («خرید از شما»): the default value of gold received from the customer.
            'latestBuyIrr' => ($buy = $quotes->latest('GOLD_18_BUY')) && $quotes->freshness($buy) !== 'ERROR' ? (string) BigDecimal::of($buy->value)->toScale(0, RoundingMode::HalfUp) : null,
            'vat' => (string) BigDecimal::of($tax->for('GOLD_SERVICES', now())->rate_percent)->strippedOfTrailingZeros(),
            'canInstallments' => $this->ent()->can($tenant, 'installments.manage'),
        ]);
    }

    public function save(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'rows' => ['present', 'array', 'max:'.config('talata.invoices.max_rows')],
            'rows.*' => ['array'],
            'buyer' => ['nullable', 'array'],
            'buyer.name' => ['nullable', 'string', 'max:120'],
            'buyer.mobile' => ['nullable', 'string', 'max:20'],
            'use_latest_rate' => ['nullable', 'boolean'],
            'latest_rate_irr' => ['nullable', 'string', 'max:30'],
        ]);
        // JSON endpoint: saveDraft answers NOT_DRAFT for an issued invoice instead of a page redirect.
        $this->assertOwnDraft($invoice);

        return response()->json($this->invoices->saveDraft($invoice, (int) $data['version'], $data['rows'], $data['buyer'] ?? [], (bool) ($data['use_latest_rate'] ?? false), $this->tenant(), $data['latest_rate_irr'] ?? null));
    }

    public function destroy(Invoice $invoice)
    {
        // JSON endpoint: an issued invoice is never deleted, and the caller is told why (not redirected).
        if (! $invoice->isDraft()) {
            throw new DomainError('NOT_DRAFT', 'فاکتور صادرشده حذف نمی‌شود؛ در صورت نیاز آن را باطل کنید.', 409);
        }
        $this->assertOwnDraft($invoice);
        $invoice->delete();

        return response()->json(['ok' => true, 'next' => route('invoices.new')]);
    }

    public function review(Invoice $invoice, SmsService $sms)
    {
        $this->assertDraft($invoice);
        $tenant = $this->tenant();
        $state = $this->invoices->state($invoice, $tenant);
        if (! $state['valid']) {
            return redirect()->route('invoices.items', $invoice)->with('error', 'بعضی ردیف‌ها کامل یا درست نیستند. آن‌ها را اصلاح کنید.');
        }
        $preview = $sms->previewFor($invoice->forceFill(['payable_irr' => $state['totals']['payable_irr']]), $tenant);
        $links = $this->ent()->quota($tenant, 'links_per_month');

        return view('app.review', [
            'invoice' => $invoice,
            'rows' => $invoice->items()->get(),
            'state' => $state,
            'sms' => $preview,
            'links' => $links,
            'customersQuota' => $this->ent()->quota($tenant, 'new_customers_per_month'),
            'canSms' => $this->ent()->can($tenant, 'invoice.sms_share'),
            'autoSms' => SmsSetting::autoSend(),
            'profileComplete' => (bool) $tenant->profile?->isComplete(),
            'buyerMobile' => $invoice->buyer_mobile ? Mobile::display($invoice->buyer_mobile) : '',
            'proformaHours' => ProformaService::defaultHours(),
            'proformaAuto' => ProformaService::autoIssue($tenant),
        ]);
    }

    public function issue(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'mode' => ['required', 'in:ISSUE_ONLY,ISSUE_AND_SMS,AUTO'],
            'version' => ['required', 'integer'],
            'idempotency_key' => ['required', 'string', 'max:64'],
            'buyer' => ['nullable', 'array'],
            'buyer.name' => ['nullable', 'string', 'max:120'],
            'buyer.mobile' => ['nullable', 'string', 'max:20'],
            'buyer.national_id' => ['nullable', 'string', 'max:20'],
            'save_customer' => ['nullable', 'boolean'],
        ]);
        $this->assertOwnDraft($invoice);
        $result = $this->invoices->issue($invoice, $this->tenant(), $request->user(), $data);
        $issued = $result['invoice'];
        $sms = $result['sms'] ?? null;

        return response()->json([
            'status' => 'ISSUED', 'invoice_id' => $issued->public_id, 'number' => $issued->number, 'replayed' => $result['replayed'],
            'sms' => $sms instanceof DomainError ? ['status' => 'NOT_SENT', 'message_fa' => $sms->messageFa] : ($sms ? ['status' => $sms->status] : null),
            'next' => route('invoices.issued', $issued),
        ], $result['replayed'] ? 200 : 201);
    }

    private function assertDraft(Invoice $invoice): void
    {
        if ($invoice->status === 'proforma') {
            // Locked while its پیش‌فاکتور is out: open that instead.
            $p = Proforma::query()->where('invoice_id', $invoice->id)->latest('id')->first();
            abort(redirect()->route($p ? 'proformas.show' : 'invoices.index', $p ?? []));
        }
        if (! $invoice->isDraft()) {
            abort(redirect()->route('invoices.show', $invoice));
        }
        $this->assertOwnDraft($invoice);
    }

    /** A seller works on their own drafts; members who can see every invoice («invoices.view») may open any. */
    private function assertOwnDraft(Invoice $invoice): void
    {
        if ($invoice->created_by !== auth()->id() && ! $this->membership()?->can('invoices.view')) {
            throw new DomainError('FORBIDDEN', 'این پیش‌نویس را همکار دیگری ساخته است.', 403);
        }
    }
}
