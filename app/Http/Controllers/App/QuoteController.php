<?php

namespace App\Http\Controllers\App;

use App\Domain\Market\QuoteService;

class QuoteController extends BaseController
{
    public function latest(QuoteService $quotes)
    {
        return response()->json($quotes->latestDto($this->tenant()->timezone))->header('Cache-Control', 'no-store');
    }

    public function board(QuoteService $quotes)
    {
        return response()->json($quotes->board($this->tenant()->timezone))->header('Cache-Control', 'no-store');
    }
}
