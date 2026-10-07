<?php

namespace App\Http\Controllers\App;

use App\Domain\Customers\CustomerService;
use App\Models\Customer;
use App\Models\InstallmentLine;
use App\Models\Invoice;
use App\Support\Digits;
use App\Support\Jalali;
use App\Support\Mobile;
use Illuminate\Http\Request;

class CustomerController extends BaseController
{
    public function __construct(private readonly CustomerService $customers) {}

    private function query(Request $request)
    {
        $tenant = $this->tenant();
        $q = Customer::query()->withCount('invoices')->orderBy('name');
        // «مانده حساب» here is the outstanding installment amount (the only running balance in Phase 1):
        // the unpaid part of this customer's active agreements. Shown on the row and used by the filter.
        $q->addSelect(['outstanding_irr' => InstallmentLine::query()
            ->selectRaw('COALESCE(SUM(installment_lines.amount_irr - installment_lines.paid_irr), 0)')
            ->join('installment_agreements as a', 'a.id', '=', 'installment_lines.agreement_id')
            ->whereColumn('a.customer_id', 'customers.id')
            ->where('a.tenant_id', $tenant->id)
            ->where('a.status', 'active')]);

        if ($s = trim((string) $request->query('q', ''))) {
            $digits = preg_replace('/\D/', '', Digits::toLatin($s));
            $q->where(fn ($w) => $w->whereLike('name', '%'.addcslashes($s, '%_\\').'%')->when($digits !== '', fn ($w) => $w->orWhere('mobile', 'like', '%'.$digits.'%')));
        }

        // Balance filter (installments are Professional-only, so the balance is always 0 on other plans).
        $owing = fn () => InstallmentLine::query()->select('installment_lines.id')
            ->join('installment_agreements as a', 'a.id', '=', 'installment_lines.agreement_id')
            ->whereColumn('a.customer_id', 'customers.id')->where('a.tenant_id', $tenant->id)
            ->where('a.status', 'active')->whereColumn('installment_lines.amount_irr', '>', 'installment_lines.paid_irr');
        match ($request->query('bal')) {
            'owing' => $q->whereExists($owing()),
            'settled' => $q->whereExists(fn ($e) => $e->from('installment_agreements as a')->select('a.id')
                ->whereColumn('a.customer_id', 'customers.id')->where('a.tenant_id', $tenant->id))
                ->whereNotExists($owing()),
            default => null,
        };

        return $q;
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant();

        return view('app.customers', [
            'page' => $this->query($request)->paginate(25)->withQueryString(),
            'search' => $request->query('q', ''),
            'bal' => in_array($request->query('bal'), ['owing', 'settled'], true) ? $request->query('bal') : 'all',
            'quota' => $this->ent()->quota($tenant, 'new_customers_per_month'),
            'canInstallments' => $this->ent()->can($tenant, 'installments.manage'),
            'canManage' => $this->membership()->can('customers.manage'),
            'total' => Customer::query()->count(),
        ]);
    }

    public function search(Request $request)
    {
        $page = $this->query($request)->limit(8)->get();

        return response()->json(['items' => $page->map(fn (Customer $c) => ['id' => $c->public_id, 'name' => $c->name, 'mobile' => $c->mobile, 'mobile_fa' => Mobile::display($c->mobile)])]);
    }

    public function show(Customer $customer)
    {
        $tenant = $this->tenant();
        $agreements = $customer->agreements()->with('lines', 'payments', 'invoice')->orderByDesc('id')->get();
        // Same visibility as the invoice list: all invoices with «invoices.view», otherwise only the member's own;
        // plans without full history see the current month only.
        $m = $this->membership();
        $invoices = Invoice::query()->where('customer_id', $customer->id)->where('status', '!=', 'draft')
            ->when(! $m->can('invoices.view'), fn ($q) => $q->where(fn ($w) => $w->where('issued_by', auth()->id())->orWhere('created_by', auth()->id())))
            ->when(! $this->ent()->can($tenant, 'history.all'), fn ($q) => $q->where('issued_at', '>=', Jalali::monthBounds(now(), $tenant->timezone)[0]))
            ->orderByDesc('issued_at')->limit(50)->get();

        return view('app.customer-show', [
            'customer' => $customer, 'agreements' => $agreements, 'invoices' => $invoices,
            'canInstallments' => $this->ent()->can($tenant, 'installments.manage'),
            'canManage' => $this->membership()->can('customers.manage'),
            'tz' => $tenant->timezone,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:120'], 'mobile' => ['nullable', 'string', 'max:20'], 'note' => ['nullable', 'string', 'max:250']]);
        $customer = $this->customers->create($this->tenant(), $request->user(), $data);

        return response()->json(['id' => $customer->public_id, 'next' => route('customers.show', $customer)], 201);
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:120'], 'mobile' => ['nullable', 'string', 'max:20'], 'note' => ['nullable', 'string', 'max:250'], 'sms_opt_out' => ['nullable', 'boolean']]);
        $this->customers->update($customer, $data);

        return response()->json(['ok' => true]);
    }
}
