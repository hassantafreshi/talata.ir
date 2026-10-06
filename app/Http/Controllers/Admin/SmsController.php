<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Sms\Segments;
use App\Domain\Sms\SmsGateway;
use App\Domain\Sms\SmsService;
use App\Domain\Sms\SmsTemplate;
use App\Http\Controllers\App\InvoiceController;
use App\Jobs\SendSms;
use App\Models\SmsMessage;
use App\Support\Mobile;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * SMS operations (docs/handoff/04_SCREENS_ADMIN.md A-06). Recipients are masked and message bodies
 * are never shown: staff see status, cost and provider answers only. Resending stays merchant-only.
 */
class SmsController extends AdminController
{
    public const PURPOSE_FA = ['OTP' => 'کد ورود', 'INVOICE' => 'فاکتور', 'REMINDER' => 'یادآوری قسط', 'TEST' => 'آزمایشی'];

    public const CHARGE_FA = ['OPERATIONAL' => 'هزینه سرویس', 'FREE_YEARLY' => 'رایگان سالانه', 'CREDIT' => 'اعتبار فروشگاه', 'NONE' => '—'];

    public const FILTERS = ['action' => 'نیازمند بررسی', 'queued' => 'در صف', 'all' => 'همه'];

    public function index(Request $request)
    {
        $filter = array_key_exists($request->query('f'), self::FILTERS) ? $request->query('f') : 'action';
        $q = SmsMessage::query()->orderByDesc('id');
        match ($filter) {
            'action' => $q->where(fn ($w) => $w->where(fn ($u) => $u->where('status', 'UNKNOWN')->where('updated_at', '<', now()->subMinutes(30)))
                ->orWhere(fn ($f) => $f->where('status', 'FAILED')->where('created_at', '>', now()->subDay()))
                ->orWhere('status', 'AWAITING_CREDIT')),
            'queued' => $q->whereIn('status', ['QUEUED', 'SENDING']),
            default => null,
        };
        $page = $q->paginate(25, ['id', 'public_id', 'tenant_id', 'purpose', 'recipient', 'segments', 'cost_irr', 'charge_source', 'status', 'attempts', 'last_error', 'provider_message_id', 'created_at', 'updated_at'])->withQueryString();

        $day = now()->subDay();
        $today = CarbonImmutable::now(config('talata.timezone'))->startOfDay();
        $byPurpose = SmsMessage::query()->where('created_at', '>=', $day)->whereIn('status', ['SENT', 'DELIVERED'])->selectRaw('purpose, count(*) c')->groupBy('purpose')->pluck('c', 'purpose');
        $otp = config('talata.otp');

        return view('admin.sms', [
            'page' => $page, 'filter' => $filter,
            'shops' => $this->shopNames($page->getCollection()->pluck('tenant_id')),
            'tiles' => [
                'sent' => (int) $byPurpose->sum(), 'by_purpose' => $byPurpose,
                'delivered' => SmsMessage::query()->where('created_at', '>=', $day)->where('status', 'DELIVERED')->count(),
                'unknown_old' => SmsMessage::query()->where('status', 'UNKNOWN')->where('updated_at', '<', now()->subMinutes(30))->count(),
                'otp_today' => SmsMessage::query()->where('purpose', 'OTP')->where('created_at', '>=', $today)->count(),
                'otp_budget' => (int) $otp['global_daily_budget'] + (int) $otp['existing_users_daily_budget'],
                'queued' => SmsMessage::query()->whereIn('status', ['QUEUED', 'SENDING'])->count(),
            ],
            'statusFa' => InvoiceController::SMS_STATUS_FA,
            'rules' => config('talata.sms'),
            'otp' => $otp,
            'defaultTemplate' => SmsTemplate::DEFAULT,
            'driver' => config('talata.drivers.sms'),
            'canManage' => $this->staff()->allows('sms.manage'),
            'myMobile' => Mobile::mask($this->staff()->mobile),
        ]);
    }

    /** Asks the provider for the current status of one message (same rules as the reconcile job). */
    public function inquire(int $message, SmsGateway $gateway, SmsService $sms): JsonResponse
    {
        $m = SmsMessage::query()->findOrFail($message);
        if (! $m->provider_message_id) {
            throw new DomainError('NO_PROVIDER_ID', 'این پیامک شناسه ارائه‌دهنده ندارد؛ بررسی خودکار آن را پس از ۳۰ دقیقه می‌بندد.', 422);
        }
        $status = $gateway->status($m->provider_message_id);
        $changed = $sms->applyProviderReport($m, $status, $m->provider_message_id);
        Audit::record('sms.inquired', $m, ['provider_status' => $status], $m->tenant_id, 'staff');
        $m->refresh();

        return response()->json([
            'provider_status' => $status, 'changed' => $changed, 'status' => $m->status,
            'message_fa' => $status === 'UNKNOWN' ? 'ارائه‌دهنده هنوز وضعیت روشنی ندارد.' : 'وضعیت به‌روز شد: '.(InvoiceController::SMS_STATUS_FA[$m->status][0] ?? $m->status),
        ]);
    }

    /** «ارسال آزمایشی به شماره من»: a fixed text to the staff member's own mobile, on the operating budget. */
    public function test(): JsonResponse
    {
        $staff = $this->staff();
        $key = 'admin-sms-test:'.$staff->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw new DomainError('TEST_SMS_RATE', 'در هر ساعت حداکثر سه پیامک آزمایشی ارسال می‌شود.', 429);
        }
        RateLimiter::hit($key, 3600);
        $body = 'پیام آزمایشی زرلیو: اتصال ارسال پیامک برقرار است.';
        $message = SmsMessage::create([
            'tenant_id' => null, 'purpose' => 'TEST', 'recipient' => $staff->mobile, 'body' => $body,
            'segments' => Segments::count($body), 'cost_irr' => '0', 'charge_source' => 'OPERATIONAL', 'status' => 'QUEUED',
            'idempotency_key' => 'test:'.$staff->id.':'.now()->getTimestampMs(),
        ]);
        SendSms::dispatch($message->id)->onQueue('otp');
        Audit::record('sms.test_sent', $message, [], null, 'staff');

        return response()->json(['message_fa' => 'پیامک آزمایشی در صف ارسال به '.Mobile::mask($staff->mobile).' قرار گرفت.']);
    }
}
