<?php

namespace App\Http\Controllers\Public;

use App\Domain\Invoices\InvoicePresenter;
use App\Domain\Invoices\Qr;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceShare;
use App\Models\InvoiceVerificationRevocation;
use App\Models\Tenant;
use App\Support\Mobile;
use App\Support\Tokens;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            // A token the shop revoked for security reads «لغوشده» — and reveals nothing about the invoice.
            $revoked = Tokens::isWellFormed($token) && InvoiceVerificationRevocation::withoutGlobalScope('tenant')->where('token_hash', Tokens::hash($token))->exists();

            return $this->headers(response()->view('public.verify-invalid', ['revoked' => $revoked], $revoked ? 410 : 404));
        }

        return $this->headers(response()->view('public.verify', $this->verifyData($invoice)));
    }

    /**
     * Reveal the buyer's name only to someone who proves they are the buyer by entering the buyer's mobile.
     * At most a few DISTINCT numbers may be tried per invoice (config talata.public.reveal_max_numbers) before
     * it locks, so the page can never be used to probe which number is on an invoice.
     */
    public function reveal(Request $request, string $token)
    {
        $invoice = Tokens::isWellFormed($token) ? Invoice::withoutGlobalScope('tenant')->where('verify_token_hash', Tokens::hash($token))->whereIn('status', ['issued', 'void'])->first() : null;
        if (! $invoice) {
            return $this->headers(response()->view('public.verify-invalid', ['revoked' => false], 404));
        }
        $onFile = $invoice->buyer_mobile;
        $entered = Mobile::normalize((string) $request->input('buyer_mobile'));
        $max = (int) config('talata.public.reveal_max_numbers');
        $cacheKey = 'verify_reveal:'.$invoice->verify_token_hash;
        $tried = Cache::get($cacheKey, []);

        if (! $onFile) {
            // Nothing to prove against; the view shows a neutral "no buyer on file" note.
            return $this->headers(response()->view('public.verify', $this->verifyData($invoice)));
        }
        if (count($tried) >= $max) {
            return $this->headers(response()->view('public.verify', $this->verifyData($invoice, gate: ['locked' => true, 'remaining' => 0, 'error' => null])));
        }
        if ($entered && hash_equals($onFile, $entered)) {
            return $this->headers(response()->view('public.verify', $this->verifyData($invoice, revealed: true)));
        }
        // A wrong (or malformed) number: remember distinct normalised wrong numbers so retyping the same one
        // is not punished, and lock once enough different numbers have been tried.
        $probe = $entered ?: 'bad:'.substr(hash('sha256', (string) $request->input('buyer_mobile')), 0, 12);
        if (! in_array($probe, $tried, true)) {
            $tried[] = $probe;
            Cache::put($cacheKey, $tried, now()->addMinutes((int) config('talata.public.reveal_window_minutes')));
        }
        $remaining = max(0, $max - count($tried));
        $error = $entered ? 'این شماره با شماره خریدارِ ثبت‌شده هم‌خوان نیست.' : 'شماره موبایل را درست وارد کنید.';

        return $this->headers(response()->view('public.verify', $this->verifyData($invoice, gate: ['locked' => $remaining === 0, 'remaining' => $remaining, 'error' => $error])));
    }

    /** View data for the verification page; buyer details are gated behind the mobile check (see reveal()). */
    private function verifyData(Invoice $invoice, bool $revealed = false, ?array $gate = null): array
    {
        $replacement = Invoice::withoutGlobalScope('tenant')->where('replaces_invoice_id', $invoice->id)->where('status', '!=', 'draft')->exists();
        // Verification answers «is this invoice genuine?»: the buyer's name/mobile are not part of that answer,
        // so the presenter output never carries them — they are revealed only on a correct mobile match.
        $v = InvoicePresenter::present($invoice, true);
        $v['buyer_name'] = null;
        $v['buyer_mobile'] = null;
        $max = (int) config('talata.public.reveal_max_numbers');
        $tried = count(Cache::get('verify_reveal:'.$invoice->verify_token_hash, []));

        return [
            'v' => $v,
            'replaced' => $replacement,
            'buyerOnFile' => (bool) $invoice->buyer_mobile,
            'revealed' => $revealed,
            'buyerName' => $revealed ? ($invoice->buyer_name ?: 'بدون نام') : null,
            'buyerMobileMasked' => $revealed ? Mobile::mask($invoice->buyer_mobile) : null,
            'gate' => $gate ?? ['locked' => $tried >= $max, 'remaining' => max(0, $max - $tried), 'error' => null],
        ];
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
