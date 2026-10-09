<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminActions;
use App\Domain\Billing\PromoCodes;
use App\Models\PromoCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Staff discount codes (e.g. a 100% code to test purchases before the bank is connected). */
class PromoCodesController extends AdminController
{
    public function store(Request $request, PromoCodes $promos, AdminActions $actions): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'], 'percent' => ['required', 'numeric'], 'products' => ['nullable', 'array'],
            'product_plan' => ['nullable', 'boolean'], 'product_sms' => ['nullable', 'boolean'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:100000'], 'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'note' => ['nullable', 'string', 'max:200'], 'idempotency_key' => ['required', 'string'],
        ]);
        $data['products'] ??= array_keys(array_filter(['PLAN' => $data['product_plan'] ?? false, 'SMS_CREDIT' => $data['product_sms'] ?? false]));
        $reason = $this->reason($request);
        $result = $actions->once($this->staff(), 'billing.promo_created', $data['idempotency_key'], null, fn () => ['code' => $promos->create($this->staff(), $data, $reason)->code]);

        return response()->json($result + ['message_fa' => 'کد تخفیف ساخته شد.'], 201);
    }

    public function deactivate(Request $request, PromoCode $promo, PromoCodes $promos): JsonResponse
    {
        $promos->deactivate($promo, $this->reason($request));

        return response()->json(['message_fa' => 'کد غیرفعال شد.']);
    }
}
