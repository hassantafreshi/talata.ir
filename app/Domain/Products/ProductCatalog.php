<?php

namespace App\Domain\Products;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ShopProduct;
use App\Support\Digits;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Product-name suggestions for invoice rows: the shop's own sold products first (with their last weight, purity,
 * wage and profit), then the shared Persian dataset (resources/data/product-names-fa.json, same for every shop).
 */
class ProductCatalog
{
    /** Per-shop cap; the least recently sold products drop off beyond it. */
    public const MAX_PER_SHOP = 2000;

    /** Row defaults that are not a product the merchant named. */
    private const GENERIC = ['طلای ۱۸ عیار', 'طلا'];

    public const DATASET = 'data/product-names-fa.json';

    /** Matching key: Persian letters unified, ZWNJ/extra spaces folded, digits Latin, lower case. */
    public static function key(string $name): string
    {
        $s = strtr($name, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', "\u{200C}" => ' ', "\u{200F}" => '', "\u{0640}" => '']);
        $s = Digits::toLatin($s);

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
    }

    /** After issuance: remember each sold GOLD/MISC product with its latest values. Idempotent per invoice. */
    public function remember(Invoice $invoice): void
    {
        $now = now();
        $rows = InvoiceItem::withoutGlobalScope('tenant')->where('invoice_id', $invoice->id)->whereIn('item_type', ['GOLD', 'MISC'])->orderBy('position')->get();
        $records = [];
        foreach ($rows as $r) {
            $name = trim((string) $r->name);
            if ($name === '' || in_array($name, self::GENERIC, true)) {
                continue;
            }
            $key = self::key($name);
            $gold = $r->item_type === 'GOLD';
            $records[$r->item_type.'|'.$key] = [
                'public_id' => strtolower((string) Str::ulid()), 'tenant_id' => $invoice->tenant_id, 'item_type' => $r->item_type,
                'name' => $name, 'name_key' => mb_substr($key, 0, 160),
                'kind' => $gold ? ($r->item_attributes['kind'] ?? 'JEWELRY') : null,
                'net_weight_g' => $gold ? $r->net_weight_g : null, 'purity_ppt' => $gold ? $r->purity_ppt : null,
                'wage_percent' => $gold ? $r->wage_percent : null, 'profit_percent' => $gold ? $r->profit_percent : null,
                'manual_total_irr' => $gold ? null : $r->manual_total_irr,
                'use_count' => 1, 'last_used_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        if (! $records) {
            return;
        }
        DB::table('shop_products')->upsert(array_values($records), ['tenant_id', 'item_type', 'name_key'], [
            'name', 'kind', 'net_weight_g', 'purity_ppt', 'wage_percent', 'profit_percent', 'manual_total_irr', 'last_used_at', 'updated_at',
            'use_count' => DB::raw('shop_products.use_count + 1'),
        ]);

        $count = DB::table('shop_products')->where('tenant_id', $invoice->tenant_id)->count();
        if ($count > self::MAX_PER_SHOP) {
            $drop = DB::table('shop_products')->where('tenant_id', $invoice->tenant_id)->orderBy('last_used_at')->orderBy('id')->limit($count - self::MAX_PER_SHOP)->pluck('id');
            DB::table('shop_products')->whereIn('id', $drop)->delete();
        }
    }

    /** The shop's products for the row picker (current tenant), most used and most recent first. */
    public function forShop(int $limit = 600): array
    {
        $num = fn ($v) => $v === null ? '' : (string) BigDecimal::of($v)->strippedOfTrailingZeros();

        return ShopProduct::query()->orderByDesc('last_used_at')->limit($limit)->get()
            ->map(fn (ShopProduct $p) => [
                'id' => $p->public_id, 't' => $p->item_type, 'n' => $p->name, 'k' => $p->kind,
                'w' => $num($p->net_weight_g), 'p' => $num($p->purity_ppt), 'wg' => $num($p->wage_percent), 'pr' => $num($p->profit_percent),
                'm' => $p->manual_total_irr !== null ? Money::irrToToman((string) $p->manual_total_irr) : '',
                'c' => $p->use_count,
            ])->all();
    }

    /** Shared dataset: [[term, weight], …] in popularity order, plus its version for client caching. */
    public function dataset(): array
    {
        $path = resource_path(self::DATASET);
        if (! is_file($path)) {
            return ['version' => '0', 'terms' => []];
        }

        return cache()->remember('product_names:'.filemtime($path), now()->addDay(), function () use ($path) {
            $data = json_decode((string) file_get_contents($path), true) ?: [];
            $terms = [];
            foreach ($data['terms'] ?? [] as $t) {
                if (is_array($t) && is_string($t['t'] ?? null) && trim($t['t']) !== '') {
                    $terms[] = [trim($t['t']), (int) ($t['w'] ?? 1)];
                }
            }

            return ['version' => (string) ($data['version'] ?? '1').'-'.substr(md5_file($path), 0, 8), 'terms' => $terms];
        });
    }
}
