<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Affiliate\AffiliateService;
use App\Domain\DomainError;
use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Models\AffiliateReferral;
use App\Models\BillingOrder;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Mobile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Affiliate program management (admin console). Write actions: admin role only. */
class AffiliatesController extends Controller
{
    public function __construct(private readonly AffiliateService $affiliates) {}

    public function index(Request $request)
    {
        $q = Affiliate::query()->with('user')->withCount('referrals')->orderByDesc('id');
        if ($s = trim((string) $request->query('q'))) {
            $mobile = Mobile::normalize($s);
            $q->where(fn ($w) => $w->where('code', strtoupper($s))->orWhereIn('user_id', User::query()->where('mobile', $mobile ?: '-')->select('id')));
        }
        $page = $q->paginate(50)->withQueryString();

        return view('admin.affiliates', [
            'page' => $page, 'search' => $request->query('q', ''),
            'totals' => $page->getCollection()->mapWithKeys(fn ($a) => [$a->id => $this->affiliates->totals($a)]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['mobile' => ['required', 'string', 'max:20'], 'commission_percent' => ['required', 'string', 'max:6'], 'commission_mode' => ['required', 'string'],
            'discount_percent' => ['nullable', 'string', 'max:6'], 'include_sms_credit' => ['nullable', 'boolean'], 'code' => ['nullable', 'string', 'max:20'], 'note' => ['nullable', 'string', 'max:250']]);
        $mobile = Mobile::normalize($data['mobile']);
        $user = $mobile ? User::query()->where('mobile', $mobile)->first() : null;
        // Only someone who already has a shop panel (an active membership) can become an affiliate.
        if (! $user || ! Membership::query()->where('user_id', $user->id)->where('status', 'active')->exists()) {
            throw new DomainError('VALIDATION', 'این شماره پنل فعال در زرلیو ندارد.', 422, ['errors' => ['mobile' => ['این شماره پنل فعال در زرلیو ندارد. اول باید ثبت‌نام کند.']]]);
        }
        $affiliate = $this->affiliates->enroll($user, $data, Auth::guard('staff')->id());

        return response()->json(['id' => $affiliate->id, 'next' => route('admin.affiliate', $affiliate->id)], 201);
    }

    public function show(Affiliate $affiliate)
    {
        $referrals = AffiliateReferral::query()->where('affiliate_id', $affiliate->id)->orderByDesc('attributed_at')->limit(300)->get();
        $tenants = Tenant::query()->whereIn('id', $referrals->pluck('tenant_id'))->with(['profile' => fn ($q) => $q->withoutGlobalScope('tenant')])->get()->keyBy('id');

        return view('admin.affiliate', [
            'affiliate' => $affiliate->load('user'), 'totals' => $this->affiliates->totals($affiliate), 'referrals' => $referrals, 'tenants' => $tenants,
            'commissions' => AffiliateCommission::query()->where('affiliate_id', $affiliate->id)->with('referral')->orderByDesc('id')->limit(300)->get(),
            'payouts' => AffiliatePayout::query()->where('affiliate_id', $affiliate->id)->orderByDesc('id')->get(),
            'orders' => BillingOrder::withoutGlobalScope('tenant')->whereIn('id', AffiliateCommission::query()->where('affiliate_id', $affiliate->id)->pluck('order_id'))->pluck('public_ref', 'id'),
        ]);
    }

    public function update(Request $request, Affiliate $affiliate)
    {
        $this->affiliates->update($affiliate, $request->validate(['commission_percent' => ['required', 'string', 'max:6'], 'commission_mode' => ['required', 'string'],
            'discount_percent' => ['nullable', 'string', 'max:6'], 'include_sms_credit' => ['nullable', 'boolean'], 'code' => ['nullable', 'string', 'max:20'],
            'status' => ['required', 'in:active,paused'], 'note' => ['nullable', 'string', 'max:250']]));

        return response()->json(['ok' => true]);
    }

    public function payout(Request $request, Affiliate $affiliate)
    {
        $data = $request->validate(['reference' => ['required', 'string', 'min:4', 'max:80'], 'note' => ['nullable', 'string', 'max:250']]);
        $payout = $this->affiliates->payout($affiliate, $data['reference'], Auth::guard('staff')->id(), $data['note'] ?? null);

        return response()->json(['ok' => true, 'amount_irr' => $payout->amount_irr]);
    }

    public function void(Request $request, AffiliateCommission $commission)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:250']]);
        $this->affiliates->void($commission, $data['reason']);

        return response()->json(['ok' => true]);
    }
}
