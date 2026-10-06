<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Passkey;
use App\Models\SmsMessage;
use App\Models\StaffUser;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Digits;
use App\Support\Jalali;
use App\Support\Mobile;
use Illuminate\Http\Request;

/** Activity log (audit_events) per user, per shop and per service. Admin console only. */
class ActivityController extends Controller
{
    public const EVENT_LABELS = [
        'auth.login' => 'ورود با کد پیامکی', 'auth.passkey_login' => 'ورود با اثر انگشت', 'auth.logout' => 'خروج', 'auth.otp_requested' => 'درخواست کد ورود',
        'auth.otp_wrong' => 'کد ورود اشتباه', 'auth.otp_locked' => 'قفل ورود پس از تلاش ناموفق', 'auth.passkey_failed' => 'ورود ناموفق با اثر انگشت', 'auth.honeypot' => 'ربات (فیلد تله)',
        'passkey.registered' => 'فعال‌سازی اثر انگشت', 'passkey.removed' => 'حذف اثر انگشت',
        'invoice.issued' => 'صدور فاکتور', 'invoice.voided' => 'ابطال فاکتور', 'invoice.replacement_started' => 'شروع فاکتور جایگزین', 'invoice.share_created' => 'ساخت لینک فاکتور', 'invoice.share_revoked' => 'غیرفعال‌کردن لینک',
        'sms.queued' => 'پیامک فاکتور در صف', 'sms.resend_queued' => 'ارسال دوباره پیامک', 'sms.awaiting_credit' => 'پیامک منتظر اعتبار', 'sms.not_sent_after_issue' => 'پیامک پس از صدور ارسال نشد', 'sms.template_updated' => 'تغییر متن پیامک', 'sms_credit.expired' => 'انقضای اعتبار پیامک',
        'billing.order_created' => 'سفارش پرداخت', 'billing.fulfilled' => 'پرداخت موفق', 'billing.failed' => 'پرداخت ناموفق', 'billing.amount_mismatch' => 'عدم تطابق مبلغ بانک',
        'customer.created' => 'ثبت مشتری', 'customer.updated' => 'ویرایش مشتری', 'installment.created' => 'قرارداد اقساط', 'installment.payment_recorded' => 'ثبت دریافت قسط', 'installment.payment_reversed' => 'برگشت دریافت قسط', 'installment.reminders_toggled' => 'تغییر یادآوری قسط',
        'membership.invited' => 'دعوت همکار', 'membership.accepted' => 'پذیرش دعوت', 'membership.declined' => 'رد دعوت', 'membership.removed' => 'حذف همکار', 'membership.permissions_changed' => 'تغییر دسترسی همکار',
        'profile.updated' => 'ویرایش اطلاعات کسب‌وکار', 'profile.logo_uploaded' => 'بارگذاری لوگو', 'profile.logo_removed' => 'حذف لوگو', 'layout.updated' => 'تغییر ظاهر فاکتور', 'tenant.created' => 'ساخت فروشگاه',
        'admin.login' => 'ورود مدیر', 'admin.logout' => 'خروج مدیر', 'admin.viewed_user' => 'مشاهده فعالیت کاربر', 'admin.viewed_tenant' => 'مشاهده فروشگاه', 'admin.exported' => 'خروجی گزارش',
        'admin.staff_saved' => 'ثبت یا ویرایش مدیر',
        'affiliate.enrolled' => 'فعال‌سازی همکاری در فروش', 'affiliate.updated' => 'تغییر شرایط همکار فروش', 'affiliate.referral_attached' => 'ثبت مشتری معرفی‌شده', 'affiliate.commission_created' => 'ثبت کمیسیون', 'affiliate.commission_voided' => 'لغو کمیسیون', 'affiliate.payout_recorded' => 'ثبت واریز کمیسیون', 'pricing.published' => 'تغییر قیمت پلن یا پیامک', 'settings.backup_restored' => 'بازگرداندن پشتیبان تنظیمات', 'settings.numbering_changed' => 'تغییر شماره‌گذاری فاکتور', 'admin.staff_deactivated' => 'غیرفعال‌کردن مدیر', 'admin.passkey_registered' => 'کلید عبور مدیر', 'admin.passkey_removed' => 'حذف کلید عبور مدیر',
    ];

    private function query(Request $request)
    {
        $q = AuditEvent::query()->orderByDesc('id');
        if ($s = $request->query('service')) {
            $q->where('service', $s);
        }
        if ($e = trim((string) $request->query('event'))) {
            $q->where('event', 'like', addcslashes($e, '%_\\').'%');
        }
        if ($a = $request->query('actor')) {
            $q->where('actor_type', $a);
        }
        if ($u = $request->query('user')) {
            $q->where('actor_user_id', (int) $u);
        }
        if ($t = $request->query('tenant')) {
            $q->where('tenant_id', (int) $t);
        }
        if ($m = trim((string) $request->query('mobile'))) {
            $mobile = Mobile::normalize($m);
            $q->whereIn('actor_user_id', User::query()->where('mobile', $mobile ?: '-')->select('id'));
        }
        if ($r = trim((string) $request->query('request'))) {
            $q->where('request_id', strtolower($r));
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            if ($d = Jalali::parse(Digits::toLatin((string) $request->query($key)), config('talata.timezone'))) {
                $q->where('created_at', $op, $key === 'to' ? $d->addDay() : $d);
            }
        }

        return $q;
    }

    public function index(Request $request)
    {
        $page = $this->query($request)->paginate(50)->withQueryString();

        return view('admin.activity', ['page' => $page, 'filters' => $request->query(), 'services' => Audit::SERVICE_LABELS] + $this->lookups($page->getCollection()));
    }

    /** Names for the users, staff and shops on the current page (one query each). */
    private function lookups($events): array
    {
        return [
            'users' => User::query()->whereIn('id', $events->pluck('actor_user_id')->filter()->unique())->pluck('mobile', 'id'),
            'staff' => StaffUser::query()->whereIn('id', $events->pluck('staff_id')->filter()->unique())->pluck('name', 'id'),
            'tenants' => Tenant::query()->whereIn('id', $events->pluck('tenant_id')->filter()->unique())->with(['profile' => fn ($q) => $q->withoutGlobalScope('tenant')])->get()->keyBy('id'),
        ];
    }

    public function user(Request $request, User $user)
    {
        Audit::record('admin.viewed_user', $user, [], null, 'staff');
        $request->query->set('user', $user->id);
        $page = $this->query($request)->paginate(50)->withQueryString();

        return view('admin.user', [
            'user' => $user, 'page' => $page, 'filters' => $request->query(), 'services' => Audit::SERVICE_LABELS,
            'memberships' => Membership::query()->where('user_id', $user->id)->with(['tenant.profile' => fn ($q) => $q->withoutGlobalScope('tenant')])->get(),
            'passkeys' => Passkey::query()->where('owner_type', 'user')->where('owner_id', $user->id)->get(),
            'otp' => SmsMessage::query()->where('purpose', 'OTP')->where('recipient', $user->mobile)->latest('id')->limit(10)->get(),
            'byService' => AuditEvent::query()->where('actor_user_id', $user->id)->selectRaw('service, count(*) c')->groupBy('service')->pluck('c', 'service'),
        ] + $this->lookups($page->getCollection()));
    }
}
