<?php

namespace Tests\Feature;

use App\Domain\Market\QuoteService;
use App\Domain\Products\ProductCatalog;
use App\Models\ShopProduct;
use Tests\TestCase;

/** Product-name suggestions: the shop's sold products (with last values) and the shared dataset. */
class ProductSuggestTest extends TestCase
{
    private function issue($user, array $rows): void
    {
        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $res = $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate])->assertCreated();
        $id = $res->json('draft_id');
        $state = $this->api('PUT', "/api/invoices/drafts/{$id}", ['version' => $res->json('version'), 'rows' => $rows, 'buyer' => ['name' => '', 'mobile' => '']])->assertOk();
        $this->api('POST', "/api/invoices/drafts/{$id}/issue", ['mode' => 'ISSUE_ONLY', 'version' => $state->json('version'), 'idempotency_key' => 'k-'.bin2hex(random_bytes(8))])->assertCreated();
    }

    public function test_sold_products_are_remembered_with_their_last_values_after_issuance(): void
    {
        $user = $this->merchant();
        $this->issue($user, [
            ['item_type' => 'GOLD', 'name' => 'انگشتر رینگی کارتیه', 'net_weight_g' => '3.25', 'purity_ppt' => '750', 'wage_percent' => '18', 'profit_percent' => '7'],
            ['item_type' => 'GOLD', 'name' => 'طلای ۱۸ عیار', 'net_weight_g' => '1', 'purity_ppt' => '750', 'wage_percent' => '0', 'profit_percent' => '0'],
            ['item_type' => 'MISC', 'name' => 'جعبه هدیه', 'manual_total_toman' => '50000'],
        ]);
        $items = collect($this->api('GET', '/api/products')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('items'));
        $this->assertCount(2, $items, 'the default row name is not a product');
        $ring = $items->firstWhere('n', 'انگشتر رینگی کارتیه');
        $this->assertSame(['GOLD', '3.25', '750', '18', '7', 1], [$ring['t'], $ring['w'], $ring['p'], $ring['wg'], $ring['pr'], $ring['c']]);
        $this->assertSame('50000', $items->firstWhere('n', 'جعبه هدیه')['m']);

        // Sold again (ZWNJ/Arabic-letter variant of the same name): one product, latest values, counted twice.
        $this->issue($user, [['item_type' => 'GOLD', 'name' => 'انگشتر رينگي كارتيه', 'net_weight_g' => '4.1', 'purity_ppt' => '750', 'wage_percent' => '20', 'profit_percent' => '7']]);
        $ring = collect($this->api('GET', '/api/products')->json('items'))->firstWhere('t', 'GOLD');
        $this->assertSame(['4.1', '20', 2], [$ring['w'], $ring['wg'], $ring['c']]);
        $this->assertSame(1, ShopProduct::query()->where('item_type', 'GOLD')->count());
    }

    public function test_products_are_private_to_the_shop_and_can_be_removed(): void
    {
        $a = $this->merchant();
        $this->issue($a, [['item_type' => 'GOLD', 'name' => 'النگو بافت', 'net_weight_g' => '12', 'purity_ppt' => '750', 'wage_percent' => '12', 'profit_percent' => '7']]);
        $id = $this->api('GET', '/api/products')->json('items.0.id');

        $b = $this->merchant();
        $this->actingAs($b)->api('GET', '/api/products')->assertOk()->assertJsonCount(0, 'items');
        $this->api('DELETE', "/api/products/{$id}")->assertNotFound();

        $this->actingAs($a)->api('DELETE', "/api/products/{$id}")->assertOk();
        $this->api('GET', '/api/products')->assertJsonCount(0, 'items');
    }

    public function test_shared_dataset_is_served_versioned_and_cacheable(): void
    {
        $this->actingAs($this->merchant());
        $data = app(ProductCatalog::class)->dataset();
        $res = $this->api('GET', '/api/products/names?v='.$data['version'])->assertOk();
        $this->assertStringContainsString('max-age=604800', $res->headers->get('Cache-Control'));
        $this->assertSame($data['version'], $res->json('version'));
        $this->assertGreaterThan(900, count($res->json('terms')), 'about 1000 Persian product names');
        $this->assertContains('انگشتر طلا', array_column($res->json('terms'), 0));
    }

    public function test_the_key_folds_spelling_variants(): void
    {
        $this->assertSame(ProductCatalog::key('گل‌سر طلا'), ProductCatalog::key('گل سر  طلا'));
        $this->assertSame(ProductCatalog::key('انگشتر ۱۸'), ProductCatalog::key('انگشتر 18'));
        $this->assertSame(ProductCatalog::key('كيف'), 'کیف');
    }
}
