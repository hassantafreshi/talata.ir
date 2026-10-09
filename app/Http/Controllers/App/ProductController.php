<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Audit;
use App\Domain\Products\ProductCatalog;
use App\Models\ShopProduct;

/** Product-name suggestions for invoice rows (docs/INVOICE_PRODUCT_SUGGESTIONS.md). */
class ProductController extends BaseController
{
    /** The shop's own products (private, never cached by shared caches). */
    public function index(ProductCatalog $catalog)
    {
        return response()->json(['items' => $catalog->forShop()])->header('Cache-Control', 'private, no-store');
    }

    /** Shared Persian product-name dataset; versioned URL, so the browser keeps it for a week. */
    public function names(ProductCatalog $catalog)
    {
        $data = $catalog->dataset();

        return response()->json($data)->header('Cache-Control', 'private, max-age=604800')->header('ETag', '"'.$data['version'].'"');
    }

    /** «حذف از پیشنهادها»: a mistyped or discontinued product. */
    public function destroy(ShopProduct $product)
    {
        $product->delete();
        Audit::record('product.suggestion_removed', $product, ['name' => $product->name]);

        return response()->json(['ok' => true]);
    }
}
