<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminActions;
use App\Domain\DomainError;
use App\Domain\Plans\CommercialConfig;
use App\Domain\Tax\TaxRuleAdmin;
use App\Models\TaxRule;
use App\Support\Digits;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Tax rules (docs/handoff/04_SCREENS_ADMIN.md A-08): versioned, future-dated changes only. */
class TaxController extends AdminController
{
    public const STATE_FA = ['active' => ['فعال', 'ok'], 'scheduled' => ['زمان‌بندی‌شده', 'info'], 'superseded' => ['جایگزین‌شده', 'off'], 'disabled' => ['غیرفعال', 'off']];

    public function index(CommercialConfig $config)
    {
        $rules = TaxRule::query()->orderBy('category')->orderByDesc('version')->get();
        $current = [];
        foreach ($rules->groupBy('category') as $category => $list) {
            $current[$category] = $list->filter(fn ($r) => $r->status === 'active' && $r->effective_from->lte(now()))->sortByDesc('effective_from')->first()?->id;
        }
        // Issued (and voided) invoices per rule, from their snapshots.
        $usage = DB::table('invoices')->whereIn('status', ['issued', 'void'])->whereNotNull('snapshot')
            ->selectRaw("snapshot->'tax'->>'rule_id' as rid, count(*) as c")->groupBy('rid')->pluck('c', 'rid');

        return view('admin.tax', [
            'rules' => $rules, 'current' => $current, 'usage' => $usage,
            'categories' => TaxRuleAdmin::CATEGORIES_FA, 'bases' => TaxRuleAdmin::BASES_FA, 'schedulable' => TaxRuleAdmin::SCHEDULABLE,
            'purchaseVat' => $config->vatRatePercent(),
            'minDate' => (function () {
                $d = now(config('talata.timezone'))->addDay();
                [$y, $m, $day] = Jalali::fromGregorian($d->year, $d->month, $d->day);

                return sprintf('%04d/%02d/%02d', $y, $m, $day);
            })(),
            'canManage' => $this->staff()->allows('tax.manage'),
        ]);
    }

    public function store(Request $request, TaxRuleAdmin $admin, AdminActions $actions): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:30'], 'rate_percent' => ['required', 'string', 'max:12'],
            'effective_from' => ['required', 'string', 'max:20'], 'reference' => ['required', 'string', 'min:5', 'max:250'],
            'expert_confirmed' => ['nullable', 'boolean'], 'idempotency_key' => ['required', 'string'],
        ]);
        $from = Jalali::parse(Digits::toLatin($data['effective_from']), config('talata.timezone'));
        if (! $from) {
            throw new DomainError('TAX_DATE_INVALID', 'تاریخ شروع را از تقویم انتخاب کنید.', 422, ['errors' => ['effective_from' => ['تاریخ شروع را انتخاب کنید.']]]);
        }
        $reference = trim(strip_tags($data['reference']));
        $result = $actions->once($this->staff(), 'tax.rule_scheduled', $data['idempotency_key'], null, function () use ($admin, $data, $from, $reference) {
            $rule = $admin->schedule($this->staff(), $data['category'], Digits::toLatin($data['rate_percent']), $from->startOfDay(), $reference, (bool) ($data['expert_confirmed'] ?? false));

            return ['id' => $rule->id, 'version' => $rule->version];
        });

        return response()->json($result + ['message_fa' => 'نسخه '.Digits::toPersian((string) $result['version']).' زمان‌بندی شد.'], 201);
    }

    public function disable(Request $request, int $rule, TaxRuleAdmin $admin): JsonResponse
    {
        $admin->disable($this->staff(), TaxRule::query()->findOrFail($rule), $this->reason($request));

        return response()->json(['message_fa' => 'این نسخه غیرفعال شد و اجرا نمی‌شود.']);
    }
}
