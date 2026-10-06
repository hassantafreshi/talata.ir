<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Audit;
use App\Domain\Customers\InstallmentService;
use App\Domain\DomainError;
use App\Models\Customer;
use App\Models\InstallmentAgreement;
use App\Models\InstallmentPayment;
use App\Models\Invoice;
use App\Support\Jalali;
use App\Support\Money;
use Brick\Math\BigInteger;
use Illuminate\Http\Request;

class InstallmentController extends BaseController
{
    public function __construct(private readonly InstallmentService $installments) {}

    public function create(Customer $customer)
    {
        $tenant = $this->tenant();
        if (! $this->ent()->can($tenant, 'installments.manage')) {
            return redirect()->route('customers.show', $customer)->with('error', 'اقساط و یادآوری پیامکی در پلن حرفه‌ای است. ثبت مشتری و فاکتور آزاد است.');
        }
        $invoices = Invoice::query()->where('customer_id', $customer->id)->where('status', 'issued')
            ->whereNotExists(fn ($q) => $q->from('installment_agreements')->whereColumn('installment_agreements.invoice_id', 'invoices.id')->where('installment_agreements.status', 'active'))
            ->orderByDesc('issued_at')->limit(20)->get();
        $today = now()->setTimezone($tenant->timezone);
        [$jy, $jm, $jd] = Jalali::fromGregorian($today->year, $today->month, $today->day);

        return view('app.agreement-create', ['customer' => $customer, 'invoices' => $invoices, 'minDate' => sprintf('%04d/%02d/%02d', $jy, $jm, $jd)]);
    }

    public function store(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'invoice_id' => ['nullable', 'string', 'max:26'], 'principal_toman' => ['nullable', 'string', 'max:30'],
            'down_payment_toman' => ['nullable', 'string', 'max:30'], 'count' => ['required', 'integer'],
            'frequency' => ['required', 'in:monthly,weekly'], 'first_due' => ['required', 'string', 'max:12'], 'reminders' => ['nullable', 'boolean'],
            'preview' => ['nullable', 'boolean'],
        ]);
        if (! empty($data['preview'])) {
            return response()->json($this->preview($data));
        }
        $agreement = $this->installments->create($this->tenant(), $request->user(), $customer, $data);

        return response()->json(['id' => $agreement->public_id, 'next' => route('customers.show', $customer)], 201);
    }

    private function preview(array $data): array
    {
        $tz = $this->tenant()->timezone;
        $total = ! empty($data['invoice_id']) ? Invoice::query()->where('public_id', $data['invoice_id'])->value('payable_irr') : Money::parseTomanToIrr((string) ($data['principal_toman'] ?? ''));
        $down = Money::parseTomanToIrr((string) ($data['down_payment_toman'] ?? '0'), true);
        $first = Jalali::parse((string) $data['first_due'], $tz);
        $count = (int) $data['count'];
        if (! $total || $down === null || ! $first || $count < 1 || $count > 60 || BigInteger::of($total)->isLessThanOrEqualTo($down)) {
            return ['ok' => false];
        }
        $principal = (string) BigInteger::of($total)->minus($down);
        $lines = $this->installments->schedule($principal, $count, $data['frequency'], $first, $tz);

        return [
            'ok' => true, 'principal_fa' => Money::toman($principal), 'total_fa' => Money::toman($total),
            'lines' => array_map(fn ($l) => ['n' => $l['number'], 'due_fa' => Jalali::date($l['due'], $tz), 'amount_fa' => Money::toman($l['amount'])], $lines),
        ];
    }

    public function pay(Request $request, InstallmentAgreement $agreement)
    {
        $data = $request->validate(['amount_toman' => ['required', 'string', 'max:30'], 'method' => ['required', 'string'], 'paid_on' => ['required', 'string', 'max:12'], 'reference' => ['nullable', 'string', 'max:60'], 'idempotency_key' => ['required', 'string', 'max:64']]);
        $this->installments->pay($agreement, $request->user(), $data, $this->tenant()->timezone);

        return response()->json(['ok' => true]);
    }

    public function reverse(Request $request, InstallmentPayment $payment)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:250']]);
        $this->installments->reverse($payment, $data['reason']);

        return response()->json(['ok' => true]);
    }

    public function toggleReminders(Request $request, InstallmentAgreement $agreement)
    {
        $enable = $request->boolean('enabled');
        if ($enable && ! $agreement->reminder_mobile) {
            throw new DomainError('REMINDERS_NOT_ALLOWED', 'یادآوری پیامکی فقط برای اقساط فاکتوری ممکن است که با موبایل همین مشتری صادر شده باشد.', 422);
        }
        $agreement->update(['reminders_enabled' => $enable]);
        Audit::record('installment.reminders_toggled', $agreement, ['enabled' => $agreement->reminders_enabled]);

        return response()->json(['ok' => true, 'enabled' => $agreement->reminders_enabled]);
    }
}
