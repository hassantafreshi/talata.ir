<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminActions;
use App\Domain\Admin\ShopAdmin;
use App\Domain\Admin\TenantDirectory;
use App\Domain\Audit\Audit;
use App\Domain\Billing\BillingService;
use App\Domain\DomainError;
use App\Domain\Plans\CommercialConfig;
use App\Domain\Plans\Entitlements;
use App\Domain\Settings\SettingsBackups;
use App\Domain\Sms\SmsCredit;
use App\Models\AuditEvent;
use App\Models\BillingOrder;
use App\Models\FeatureOverride;
use App\Models\Membership;
use App\Models\SettingsBackup;
use App\Models\SmsCreditEntry;
use App\Models\SmsCreditLot;
use App\Models\StaffUser;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Digits;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Shops (docs/handoff/04_SCREENS_ADMIN.md A-02, A-03): list, detail, manual actions. */
class TenantsController extends AdminController
{
    public const OVERRIDE_DAYS = ['7' => 'یک هفته', '30' => 'یک ماه', '90' => 'سه ماه', '365' => 'یک سال'];

    private function filters(Request $request): array
    {
        return $request->only(['q', 'plan', 'status', 'quota', 'period']);
    }

    public function index(Request $request, TenantDirectory $directory)
    {
        $page = $directory->query($this->filters($request))->paginate(25)->withQueryString();

        return view('admin.tenants', [
            'page' => $page, 'rows' => $directory->rows($page->getCollection()), 'f' => $this->filters($request),
            'counts' => [
                'all' => Tenant::query()->count(),
                'suspended' => Tenant::query()->where('status', 'suspended')->count(),
                'at_cap' => $directory->query(['quota' => 'at_cap'])->count(),
                'soon' => $directory->query(['period' => 'soon'])->count(),
            ],
            'canExport' => $this->staff()->allows('tenants.export'),
        ]);
    }

    /** CSV of the filtered list (shop data and usage only; no merchant customer data). */
    public function export(Request $request, TenantDirectory $directory)
    {
        $tenants = $directory->query($this->filters($request))->limit(5000)->get();
        $rows = $directory->rows($tenants);
        Audit::record('admin.tenants_exported', null, ['count' => $tenants->count(), 'filters' => $this->filters($request)], null, 'staff');

        return $this->csv('zarlio-shops-'.now()->format('Ymd').'.csv',
            ['شناسه', 'فروشگاه', 'موبایل کسب‌وکار', 'پلن', 'دوره', 'پایان دوره', 'فاکتور این ماه', 'سقف فاکتور', 'مشتری جدید این ماه', 'اعتبار پیامک (تومان)', 'وضعیت', 'تاریخ ثبت‌نام'],
            $tenants->map(function (Tenant $t) use ($rows) {
                $r = $rows[$t->id];

                return [$t->id, $r['name'], $r['business_mobile'], $r['plan_fa'], $r['period'], $r['ends_fa'], $r['invoices'], $r['invoice_limit'] ?? 'نامحدود',
                    $r['customers'], (string) BigInteger::of($r['credit_irr'])->quotient(10), $t->isActive() ? 'فعال' : 'تعلیق', jdate($t->created_at)];
            }));
    }

    public function show(Tenant $tenant, Entitlements $ent, SmsCredit $credit, CommercialConfig $config)
    {
        Audit::record('admin.viewed_tenant', $tenant, [], $tenant->id, 'staff');
        $members = Membership::query()->where('tenant_id', $tenant->id)->with('user')->get();
        $orders = BillingOrder::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(20)->get();
        $overrides = FeatureOverride::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(30)->get();
        $staffIds = $overrides->pluck('created_by_staff')->merge($orders->pluck('staff_id'))->filter()->unique();

        return view('admin.tenant', [
            'tenant' => $tenant, 'profile' => $tenant->profile()->withoutGlobalScope('tenant')->first(),
            'owner' => $members->first(fn ($m) => $m->isOwner())?->user,
            'summary' => $ent->summary($tenant),
            'members' => $members,
            'subscriptions' => Subscription::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(20)->get(),
            'orders' => $orders,
            'balance' => $credit->balance($tenant->id),
            'lots' => SmsCreditLot::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(20)->get(),
            'entries' => SmsCreditEntry::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->whereIn('type', ['CREDIT', 'ADJUST', 'EXPIRE'])->orderByDesc('id')->limit(20)->get(),
            'overrides' => $overrides,
            'staffNames' => StaffUser::query()->whereIn('id', $staffIds)->pluck('name', 'id'),
            // Recent changes; staff page views stay in the full audit log.
            'events' => AuditEvent::query()->where('tenant_id', $tenant->id)->where('event', '!=', 'admin.viewed_tenant')->orderByDesc('id')->limit(15)->get(),
            'byService' => AuditEvent::query()->where('tenant_id', $tenant->id)->selectRaw('service, count(*) c')->groupBy('service')->pluck('c', 'service'),
            'backups' => SettingsBackup::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(50)->get(),
            'sections' => SettingsBackups::SECTIONS,
            'plans' => collect(['basic', 'professional'])->mapWithKeys(fn ($c) => [$c => $config->plan($c)]),
            'vat' => $config->vatRatePercent(),
            'carryDefault' => (bool) ($config->sms()['carry_over'][$ent->planCode($tenant)] ?? true),
            'overridable' => Entitlements::OVERRIDABLE_FA,
            'overrideDays' => self::OVERRIDE_DAYS,
            'staff' => $this->staff(),
        ]);
    }

    /** Support restores a shop's settings backup on the owner's request (audited as staff). */
    public function restoreBackup(Request $request, Tenant $tenant, int $backup, TenantContext $context, SettingsBackups $backups)
    {
        $data = $request->validate(['sections' => ['required', 'array'], 'sections.*' => ['string', 'max:20']]);
        $row = SettingsBackup::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->findOrFail($backup);
        $undo = $context->runAs($tenant, fn () => $backups->restore($tenant, $row, $data['sections'], $this->staff()->id));

        return response()->json(['ok' => true, 'undo_backup' => $undo?->id]);
    }

    /** Manual plan activation (bank transfer, POS…): the same fulfilment as an online payment. */
    public function activate(Request $request, Tenant $tenant, BillingService $billing, AdminActions $actions): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'in:basic,professional'], 'period' => ['required', 'in:monthly,yearly'],
            'received_toman' => ['required', 'string', 'max:20'], 'reference' => ['required', 'string', 'min:3', 'max:60'],
            'idempotency_key' => ['required', 'string'],
        ]);
        $reason = $this->reason($request);
        $received = Money::parseTomanToIrr($data['received_toman'], true);
        if ($received === null) {
            throw new DomainError('AMOUNT_INVALID', 'مبلغ دریافتی را به تومان وارد کنید (صفر برای رایگان).', 422, ['errors' => ['received_toman' => ['مبلغ دریافتی را درست وارد کنید.']]]);
        }
        $reference = trim(strip_tags(Digits::toLatin($data['reference'])));
        $result = $actions->once($this->staff(), 'tenant.manual_activation', $data['idempotency_key'], $tenant->id, function () use ($billing, $tenant, $data, $received, $reference, $reason) {
            $order = $billing->manualActivation($tenant, $this->staff(), $data['plan'], $data['period'], $received, $reference, $reason);

            return ['order' => $order->public_ref, 'status' => $order->status];
        });

        return response()->json($result + ['message_fa' => 'پلن فعال شد (سفارش '.$result['order'].').'], 201);
    }

    /** ± SMS credit with a reason (compensation, correction). */
    public function credit(Request $request, Tenant $tenant, SmsCredit $credit, Entitlements $ent, AdminActions $actions): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:add,deduct'], 'amount_toman' => ['required', 'string', 'max:20'],
            'carries_over' => ['nullable', 'boolean'], 'reference' => ['nullable', 'string', 'max:60'], 'idempotency_key' => ['required', 'string'],
        ]);
        $reason = $this->reason($request);
        $irr = Money::parseTomanToIrr($data['amount_toman']);
        if (! $irr || BigInteger::of($irr)->isGreaterThan('100000000000')) {
            throw new DomainError('AMOUNT_INVALID', 'مبلغ را به تومان وارد کنید.', 422, ['errors' => ['amount_toman' => ['مبلغ را درست وارد کنید.']]]);
        }
        $signed = $data['direction'] === 'deduct' ? '-'.$irr : $irr;
        $note = $reason.(! empty($data['reference']) ? ' · '.trim(strip_tags($data['reference'])) : '');
        $result = $actions->once($this->staff(), 'tenant.sms_credit_adjust', $data['idempotency_key'], $tenant->id, function () use ($credit, $ent, $tenant, $signed, $data, $note, $reason) {
            return DB::transaction(function () use ($credit, $ent, $tenant, $signed, $data, $note, $reason) {
                Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
                $r = $credit->adjust($tenant, $signed, (bool) ($data['carries_over'] ?? true), $note, $this->staff()->id, $ent->planCode($tenant));
                Audit::record('sms_credit.adjusted', $tenant, ['amount_irr' => $signed, 'reason' => $reason, 'reference' => $data['reference'] ?? null, 'balance_irr' => $r['balance_irr']], $tenant->id, 'staff');

                return $r;
            });
        });

        return response()->json($result + ['message_fa' => 'اعتبار ثبت شد. موجودی: '.Money::toman($result['balance_irr']).' تومان.'], 201);
    }

    public function override(Request $request, Tenant $tenant, ShopAdmin $shops, AdminActions $actions): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:60'], 'enabled' => ['required', 'boolean'],
            'days' => ['required', 'in:'.implode(',', array_keys(self::OVERRIDE_DAYS))], 'idempotency_key' => ['required', 'string'],
        ]);
        $reason = $this->reason($request);
        $result = $actions->once($this->staff(), 'tenant.feature_override', $data['idempotency_key'], $tenant->id, function () use ($shops, $tenant, $data, $reason) {
            $row = $shops->override($tenant, $this->staff(), $data['key'], (bool) $data['enabled'], CarbonImmutable::now()->addDays((int) $data['days']), $reason);

            return ['id' => $row->id];
        });

        return response()->json($result + ['message_fa' => 'قابلیت ویژه ثبت شد.'], 201);
    }

    public function endOverride(Request $request, Tenant $tenant, int $override, ShopAdmin $shops): JsonResponse
    {
        $shops->endOverride($tenant, $this->staff(), $override, $this->reason($request));

        return response()->json(['message_fa' => 'قابلیت ویژه پایان یافت؛ از این لحظه پلن تعیین می‌کند.']);
    }

    public function suspend(Request $request, Tenant $tenant, ShopAdmin $shops): JsonResponse
    {
        $shops->suspend($tenant, $this->staff(), $this->reason($request));

        return response()->json(['message_fa' => 'فروشگاه تعلیق شد. صفحه‌های تأیید فاکتورهای صادرشده همچنان کار می‌کنند.']);
    }

    public function unsuspend(Request $request, Tenant $tenant, ShopAdmin $shops): JsonResponse
    {
        $shops->unsuspend($tenant, $this->staff(), $this->reason($request));

        return response()->json(['message_fa' => 'تعلیق برداشته شد.']);
    }
}
