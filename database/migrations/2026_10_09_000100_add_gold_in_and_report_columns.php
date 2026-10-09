<?php

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gold received from the customer (GOLD_IN rows) and denormalized report columns for the sales dashboard.
 * The snapshot stays the legal record; these columns are written once at issuance and only read by reports.
 * See docs/GOLD_RECEIVED_AND_DASHBOARD.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->decimal('accepted_buy_rate_irr', 24, 0)->nullable()->after('accepted_rate_irr'); // market 18K buy rate captured at Start
            $t->decimal('sales_total_irr', 24, 0)->default(0)->after('misc_total_irr');         // gold + misc sold
            $t->decimal('gold_in_total_irr', 24, 0)->default(0)->after('sales_total_irr');      // value of gold received
            $t->decimal('wage_irr', 24, 0)->default(0)->after('gold_in_total_irr');
            $t->decimal('profit_irr', 24, 0)->default(0)->after('wage_irr');
            $t->decimal('vat_irr', 24, 0)->default(0)->after('profit_irr');
            $t->decimal('gold_out_weight_750', 18, 3)->default(0)->after('vat_irr');           // 750-equivalent grams sold
            $t->decimal('gold_in_weight_750', 18, 3)->default(0)->after('gold_out_weight_750'); // 750-equivalent grams received
        });

        DB::table('invoices')->whereIn('status', ['issued', 'void'])->whereNotNull('snapshot')->orderBy('id')
            ->chunkById(200, function ($invoices) {
                foreach ($invoices as $inv) {
                    $s = json_decode($inv->snapshot, true);
                    $g = $s['totals']['gold_components'] ?? [];
                    $out = BigDecimal::zero();
                    foreach ($s['rows'] ?? [] as $r) {
                        if (($r['item_type'] ?? '') === 'GOLD' && $r['net_weight_g'] !== null && $r['purity_ppt'] !== null) {
                            $out = $out->plus(BigDecimal::of($r['net_weight_g'])->multipliedBy($r['purity_ppt'])->dividedBy(750, 3, RoundingMode::HalfUp));
                        }
                    }
                    DB::table('invoices')->where('id', $inv->id)->update([
                        'sales_total_irr' => (string) BigDecimal::of($inv->gold_total_irr)->plus($inv->misc_total_irr),
                        'wage_irr' => $g['W'] ?? '0', 'profit_irr' => $g['P'] ?? '0', 'vat_irr' => $g['V'] ?? '0',
                        'gold_out_weight_750' => (string) $out,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn(['accepted_buy_rate_irr', 'sales_total_irr', 'gold_in_total_irr', 'wage_irr', 'profit_irr', 'vat_irr', 'gold_out_weight_750', 'gold_in_weight_750']);
        });
    }
};
