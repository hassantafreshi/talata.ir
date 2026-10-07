<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminActions;
use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Market\EmergencyRates;
use App\Domain\Market\QuoteService;
use App\Models\EmergencyRate;
use App\Models\StaffUser;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Quotes and مظنه (docs/handoff/04_SCREENS_ADMIN.md A-07): feed status, fetch log, emergency 18K rate. */
class QuotesController extends AdminController
{
    public const ASSETS_FA = [
        'GOLD_18_SELL' => 'طلای ۱۸ عیار · فروش', 'GOLD_18_BUY' => 'طلای ۱۸ عیار · خرید', 'GOLD_24' => 'طلای ۲۴ عیار',
        'USD_IRR' => 'دلار بازار آزاد', 'XAU_USD' => 'انس جهانی',
    ];

    public function index(QuoteService $quotes)
    {
        $rows = [];
        foreach (QuoteService::ASSETS as $asset) {
            $q = $quotes->feed($asset);
            $rows[$asset] = ['q' => $q, 'freshness' => $quotes->freshness($q)];
        }
        $history = EmergencyRate::query()->orderByDesc('id')->limit(15)->get();

        return view('admin.quotes', [
            'rows' => $rows,
            'emergency' => $quotes->emergency(),
            'history' => $history,
            'staffNames' => StaffUser::query()->whereIn('id', $history->pluck('created_by_staff')->merge($history->pluck('cancelled_by_staff'))->filter())->pluck('name', 'id'),
            'fetches' => DB::connection(config('database.log_connection'))->table('system_logs')->where('service', 'quotes')->orderByDesc('id')->limit(15)->get(['created_at', 'level', 'message', 'context']),
            'lastError' => Cache::get('talata.quotes.last_error'),
            'config' => config('talata.quotes'),
            'driver' => config('talata.drivers.quotes'),
            'validity' => EmergencyRates::VALIDITY_FA,
            'canManage' => $this->staff()->allows('quotes.manage'),
        ]);
    }

    public function announce(Request $request, EmergencyRates $rates, AdminActions $actions): JsonResponse
    {
        $data = $request->validate([
            'value_toman' => ['required', 'string', 'max:20'], 'validity' => ['required', 'string', 'max:20'],
            'confirm_large' => ['nullable', 'boolean'], 'idempotency_key' => ['required', 'string'],
        ]);
        $reason = $this->reason($request);
        $irr = Money::parseTomanToIrr($data['value_toman']);
        if (! $irr || BigDecimal::of($irr)->isGreaterThan((string) config('talata.invoices.max_amount_irr'))) {
            throw new DomainError('RATE_INVALID', 'نرخ را درست وارد کنید.', 422, ['errors' => ['value_toman' => ['نرخ هر گرم را به تومان وارد کنید.']]]);
        }
        $result = $actions->once($this->staff(), 'quotes.emergency_set', $data['idempotency_key'], null, function () use ($rates, $irr, $data, $reason) {
            $rate = $rates->announce($this->staff(), $irr, $data['validity'], $reason, (bool) ($data['confirm_large'] ?? false));

            return ['id' => $rate->id, 'value_irr' => $rate->value_irr];
        });

        return response()->json($result + ['message_fa' => 'نرخ اعلامی منتشر شد؛ فروشگاه‌ها از همین حالا آن را می‌بینند.'], 201);
    }

    public function cancel(Request $request, EmergencyRates $rates): JsonResponse
    {
        $rates->cancel($this->staff(), $this->reason($request));

        return response()->json(['message_fa' => 'نرخ اعلامی لغو شد؛ نرخ سرویس دوباره نمایش داده می‌شود.']);
    }

    /** «دریافت دوباره الان»: one central fetch (single-flight lock; never a per-merchant call). */
    public function refresh(QuoteService $quotes): JsonResponse
    {
        $ok = $quotes->refresh();
        Audit::record('quotes.refresh_now', null, ['ok' => $ok], null, 'staff');

        return response()->json(['ok' => $ok, 'message_fa' => $ok ? 'نرخ‌ها دوباره دریافت شد.' : 'دریافت انجام نشد (در حال دریافت یا خطای ارائه‌دهنده). لاگ را ببینید.'], $ok ? 200 : 502);
    }
}
