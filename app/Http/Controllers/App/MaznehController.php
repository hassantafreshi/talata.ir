<?php

namespace App\Http\Controllers\App;

use App\Domain\Market\QuoteService;

class MaznehController extends BaseController
{
    public function __invoke(QuoteService $quotes)
    {
        return view('app.mazneh', ['board' => $quotes->board($this->tenant()->timezone)]);
    }
}
