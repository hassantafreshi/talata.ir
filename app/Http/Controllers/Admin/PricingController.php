<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Plans\CommercialConfig;
use App\Domain\Plans\PricingAdmin;
use App\Http\Controllers\Controller;
use App\Models\PricingVersion;
use App\Models\StaffUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Plan and SMS prices (docs/ADMIN_PRICING.md). Support staff can view; only admins publish. */
class PricingController extends Controller
{
    public function __construct(private readonly PricingAdmin $pricing) {}

    public function index()
    {
        $history = PricingVersion::query()->orderByDesc('version')->limit(30)->get();

        return view('admin.pricing', [
            'current' => $this->pricing->current(),
            'history' => $history,
            'authors' => StaffUser::query()->whereIn('id', $history->pluck('created_by_staff')->filter())->pluck('name', 'id'),
            'vat' => app(CommercialConfig::class)->vatRatePercent(),
            'canEdit' => (bool) Auth::guard('staff')->user()?->allows('pricing.manage'),
        ]);
    }

    public function publish(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plans' => ['required', 'array'], 'plans.*' => ['array'], 'plans.*.*' => ['nullable', 'string', 'max:20'],
            'sms' => ['required', 'array'], 'sms.*' => ['nullable', 'string', 'max:10'],
            'note' => ['nullable', 'string', 'max:250'], 'confirm_large' => ['nullable', 'boolean'],
        ]);
        $result = $this->pricing->publish($data, (int) Auth::guard('staff')->id());

        return response()->json(['version' => $result['version']->version, 'changes' => $result['changes']], 201);
    }

    public function restore(PricingVersion $version): JsonResponse
    {
        $result = $this->pricing->restore($version, (int) Auth::guard('staff')->id());

        return response()->json(['version' => $result['version']->version, 'changes' => $result['changes']], 201);
    }
}
