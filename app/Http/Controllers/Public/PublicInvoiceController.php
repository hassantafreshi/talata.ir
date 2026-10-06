<?php

namespace App\Http\Controllers\Public;

use App\Domain\Invoices\InvoicePresenter;
use App\Domain\Invoices\Qr;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceShare;
use App\Models\Tenant;
use App\Support\Tokens;
use Illuminate\Support\Facades\Storage;

/** Public pages: token lookups bypass tenant scope by design and expose only the public DTO. */
class PublicInvoiceController extends Controller
{
    private function headers($response)
    {
        // Token URLs must never leak through the Referer header, caches or search engines.
        return $response->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function verify(string $token)
    {
        $invoice = Tokens::isWellFormed($token) ? Invoice::withoutGlobalScope('tenant')->where('verify_token_hash', Tokens::hash($token))->whereIn('status', ['issued', 'void'])->first() : null;
        if (! $invoice) {
            return $this->headers(response()->view('public.verify-invalid', [], 404));
        }
        $replacement = Invoice::withoutGlobalScope('tenant')->where('replaces_invoice_id', $invoice->id)->where('status', '!=', 'draft')->exists();

        // Verification answers «is this invoice genuine?»: the buyer's name and (masked) mobile are not part of
        // that answer, so they are not even handed to the view.
        $v = InvoicePresenter::present($invoice, true);
        $v['buyer_name'] = null;
        $v['buyer_mobile'] = null;

        return $this->headers(response()->view('public.verify', ['v' => $v, 'replaced' => $replacement]));
    }

    private function shared(string $token): ?Invoice
    {
        if (! Tokens::isWellFormed($token)) {
            return null;
        }
        $share = InvoiceShare::withoutGlobalScope('tenant')->where('token_hash', Tokens::hash($token))->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();

        return $share ? Invoice::withoutGlobalScope('tenant')->whereKey($share->invoice_id)->whereIn('status', ['issued', 'void'])->first() : null;
    }

    public function show(string $token)
    {
        $invoice = $this->shared($token);
        if (! $invoice) {
            return $this->headers(response()->view('public.verify-invalid', ['share' => true], 404));
        }

        return $this->headers(response()->view('public.invoice', [
            'v' => InvoicePresenter::present($invoice, true), 'verifyUrl' => config('talata.public_url').'/v/'.$invoice->verify_token, 'token' => $token,
        ]));
    }

    public function print(string $token)
    {
        $invoice = $this->shared($token);
        abort_unless($invoice, 404);

        return $this->headers(response()->view('print.invoice', [
            'v' => InvoicePresenter::present($invoice, true), 'qr' => Qr::svg(config('talata.public_url').'/v/'.$invoice->verify_token),
            'verifyShort' => preg_replace('#^https?://#', '', config('talata.public_url')).'/v/…',
        ]));
    }

    public function logo(string $tenant, int $version)
    {
        abort_unless(preg_match('/^[0-9a-z]{26}$/', $tenant), 404);
        $path = "logos/{$tenant}/v{$version}.png";
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
