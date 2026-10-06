<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminActions;
use App\Domain\Billing\BillingService;
use App\Models\BillingOrder;
use App\Models\PaymentAttempt;
use App\Models\StaffUser;
use App\Support\Digits;
use App\Support\Jalali;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Payments (docs/handoff/04_SCREENS_ADMIN.md A-05): plan and SMS-credit orders, bank inquiry, manual review. */
class PaymentsController extends AdminController
{
    /** status => [label, badge kind] */
    public const STATUS_FA = [
        'AWAITING_PAYMENT' => ['در انتظار پرداخت', 'off'],
        'VERIFYING' => ['در حال تأیید بانک', 'warn'],
        'PENDING_VERIFICATION' => ['نامعلوم؛ استعلام خودکار', 'warn'],
        'PAID' => ['پرداخت‌شده؛ در حال اعمال', 'warn'],
        'FULFILLED' => ['انجام شد', 'ok'],
        'FAILED' => ['ناموفق', 'err'],
        'EXPIRED' => ['منقضی', 'off'],
    ];

    public const FILTERS = ['all' => 'همه', 'action' => 'نیازمند بررسی', 'done' => 'انجام‌شده', 'failed' => 'ناموفق و منقضی', 'plan' => 'پلن', 'sms' => 'اعتبار پیامک', 'manual' => 'ثبت دستی'];

    public const PRODUCT_FA = ['PLAN' => 'پلن', 'SMS_CREDIT' => 'اعتبار پیامک'];

    public function __construct(private readonly BillingService $billing) {}

    private function orders(): Builder
    {
        return BillingOrder::withoutGlobalScope('tenant');
    }

    /** Orders a person should look at: unknown bank answer, paid but not applied, or stuck in verification. */
    private function needsAction(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereIn('status', ['PENDING_VERIFICATION', 'PAID'])
            ->orWhere(fn ($v) => $v->where('status', 'VERIFYING')->where('updated_at', '<', now()->subMinutes(5))));
    }

    public function index(Request $request)
    {
        $filter = array_key_exists($request->query('f'), self::FILTERS) ? $request->query('f') : 'all';
        $q = $this->orders()->orderByDesc('id');
        match ($filter) {
            'action' => $this->needsAction($q),
            'done' => $q->where('status', 'FULFILLED'),
            'failed' => $q->whereIn('status', ['FAILED', 'EXPIRED']),
            'plan' => $q->where('product', 'PLAN'),
            'sms' => $q->where('product', 'SMS_CREDIT'),
            'manual' => $q->where('channel', 'MANUAL'),
            default => null,
        };
        $search = trim(Digits::toLatin((string) $request->query('q', '')));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $q->where(fn ($w) => $w->where('public_ref', 'ilike', $like)
                ->orWhereIn('id', PaymentAttempt::query()->where('authority', $search)->orWhere('ref_id', $search)->select('order_id')));
        }
        $page = $q->paginate(25)->withQueryString();

        $today = CarbonImmutable::now(config('talata.timezone'))->startOfDay();
        $failedToday = $this->orders()->whereIn('status', ['FAILED', 'EXPIRED'])->where('updated_at', '>=', $today);
        $topReason = (clone $failedToday)->selectRaw('failure_code, count(*) c')->groupBy('failure_code')->orderByDesc('c')->first();

        return view('admin.payments', [
            'page' => $page, 'filter' => $filter, 'search' => $search,
            'shops' => $this->shopNames($page->getCollection()->pluck('tenant_id')),
            'tiles' => [
                'done_count' => $this->orders()->where('status', 'FULFILLED')->where('fulfilled_at', '>=', $today)->count(),
                'done_sum' => (string) ($this->orders()->where('status', 'FULFILLED')->where('fulfilled_at', '>=', $today)->sum('amount_irr') ?: '0'),
                'pending' => $this->orders()->where('status', 'PENDING_VERIFICATION')->count(),
                'failed' => (clone $failedToday)->count(),
                'top_reason' => $topReason ? (BillingService::BANK_CODES_FA[$topReason->failure_code] ?? $topReason->failure_code) : null,
                'action' => $this->needsAction($this->orders())->count(),
            ],
            'canExport' => $this->staff()->allows('payments.export'),
        ]);
    }

    public function show(int $order)
    {
        $o = $this->orders()->findOrFail($order);

        return view('admin.payment', [
            'o' => $o,
            'attempts' => PaymentAttempt::query()->where('order_id', $o->id)->orderByDesc('id')->get(),
            'shop' => $this->shopNames(collect([$o->tenant_id]))->first(),
            'staffName' => $o->staff_id ? StaffUser::query()->whereKey($o->staff_id)->value('name') : null,
            'staff' => $this->staff(),
        ]);
    }

    public function inquire(int $order): JsonResponse
    {
        $result = $this->billing->inquire($this->orders()->findOrFail($order));
        $fa = ['OK' => 'بانک پرداخت را تأیید کرد.', 'FAILED' => 'بانک پرداختی برای این سفارش نمی‌شناسد.', 'UNKNOWN' => 'بانک الان پاسخ روشنی نداد؛ استعلام خودکار ادامه دارد.'];

        return response()->json($result + ['message_fa' => $fa[$result['result']] ?? '', 'status_fa' => self::STATUS_FA[$result['status']][0] ?? $result['status']]);
    }

    public function confirm(Request $request, int $order, AdminActions $actions): JsonResponse
    {
        $data = $request->validate(['bank_reference' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9\-\/۰-۹]{4,60}$/u'], 'idempotency_key' => ['required', 'string']]);
        $reason = $this->reason($request);
        $o = $this->orders()->findOrFail($order);
        $ref = Digits::toLatin($data['bank_reference']);
        $result = $actions->once($this->staff(), 'payment.manual_confirm', $data['idempotency_key'], $o->tenant_id, function () use ($o, $ref, $reason) {
            $done = $this->billing->manualConfirm($o, $this->staff(), $ref, $reason);

            return ['status' => $done->status];
        });

        return response()->json($result + ['message_fa' => 'پرداخت تأیید و سفارش اعمال شد.']);
    }

    public function fail(Request $request, int $order, AdminActions $actions): JsonResponse
    {
        $data = $request->validate(['idempotency_key' => ['required', 'string']]);
        $reason = $this->reason($request);
        $o = $this->orders()->findOrFail($order);
        $result = $actions->once($this->staff(), 'payment.mark_failed', $data['idempotency_key'], $o->tenant_id, function () use ($o, $reason) {
            return ['status' => $this->billing->markFailed($o, $this->staff(), $reason)->status];
        });

        return response()->json($result + ['message_fa' => 'سفارش ناموفق ثبت شد.']);
    }

    /** Monthly finance export (Jalali month, e.g. 1405-07): every order that was paid/fulfilled in that month. */
    public function export(Request $request)
    {
        $tz = config('talata.timezone');
        $month = Digits::toLatin((string) $request->query('month', ''));
        if (! preg_match('/^(\d{4})-(\d{1,2})$/', $month, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
            $now = now($tz);
            [$jy, $jm] = Jalali::fromGregorian($now->year, $now->month, $now->day);
        } else {
            [$jy, $jm] = [(int) $m[1], (int) $m[2]];
        }
        $start = CarbonImmutable::create(...[...Jalali::toGregorian($jy, $jm, 1), 0, 0, 0, $tz]);
        $end = CarbonImmutable::create(...[...Jalali::toGregorian($jy, $jm, Jalali::monthLength($jy, $jm)), 0, 0, 0, $tz])->addDay();
        $orders = $this->orders()->whereIn('status', ['PAID', 'FULFILLED'])->whereBetween('paid_at', [$start, $end])->orderBy('paid_at')->get();
        $shops = $this->shopNames($orders->pluck('tenant_id'));
        $refs = PaymentAttempt::query()->whereIn('order_id', $orders->pluck('id'))->where('status', 'PAID')->pluck('ref_id', 'order_id');

        return $this->csv(sprintf('zarlio-payments-%04d-%02d.csv', $jy, $jm),
            ['تاریخ', 'شماره سفارش', 'شناسه فروشگاه', 'فروشگاه', 'محصول', 'پایه (ریال)', 'مالیات (ریال)', 'جمع (ریال)', 'وضعیت', 'روش', 'شماره پیگیری بانک'],
            $orders->map(fn (BillingOrder $o) => [
                Jalali::date($o->paid_at, $tz, true), $o->public_ref, $o->tenant_id, $shops[$o->tenant_id] ?? '',
                self::PRODUCT_FA[$o->product] ?? $o->product, $o->subtotal_irr, $o->vat_irr, $o->amount_irr,
                self::STATUS_FA[$o->status][0] ?? $o->status, $o->channel === 'MANUAL' ? 'ثبت دستی' : 'درگاه', $o->manual_reference ?: ($refs[$o->id] ?? ''),
            ]));
    }

    public static function amount(?string $irr): string
    {
        return $irr === null ? '—' : Money::toman($irr);
    }
}
