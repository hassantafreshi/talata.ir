<?php

namespace App\Http\Controllers\App;

use App\Domain\Market\QuoteService;
use App\Domain\Tax\TaxRules;
use Brick\Math\BigDecimal;

class CalculatorController extends BaseController
{
    public function __invoke(QuoteService $quotes, TaxRules $tax)
    {
        $rule = $tax->for('GOLD_SERVICES', now());

        return view('app.calculator', [
            'quote' => $quotes->latestDto($this->tenant()->timezone),
            'vat' => (string) BigDecimal::of($rule->rate_percent)->strippedOfTrailingZeros(),
            'vatSample' => $rule->is_sample,
            'canInvoice' => $this->membership()->can('invoice.issue') && $this->ent()->can($this->tenant(), 'invoice.finalize'),
        ]);
    }
}
