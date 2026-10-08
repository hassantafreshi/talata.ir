<?php

namespace App\Http\Controllers\Public;

use App\Domain\DomainError;
use App\Domain\Invoices\ProformaService;
use App\Domain\Invoices\SharePreview;
use App\Domain\Plans\Entitlements;
use App\Http\Controllers\App\ProformaController;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Proforma;
use App\Models\Tenant;
use App\Support\Digits;
use App\Support\Mobile;
use App\Support\Tokens;
use Illuminate\Http\Request;

/**
 * The customer's پیش‌فاکتور page (/p/{code}). Plain forms, no script needed: open → mobile → SMS code → confirmed
 * (the sales invoice is then issued). Token lookups bypass tenant scope by design; only the public DTO is shown.
 */
class PublicProformaController extends Controller
{
    public function __construct(private readonly ProformaService $proformas) {}

    private function find(string $token): ?Proforma
    {
        return Tokens::isShareToken($token) ? Proforma::withoutGlobalScope('tenant')->where('token_hash', Tokens::hash($token))->first() : null;
    }

    private function page(Proforma $p, string $token, array $extra = [], int $status = 200)
    {
        $invoice = $p->issued_at ? Invoice::withoutGlobalScope('tenant')->whereKey($p->invoice_id)->where('status', '!=', 'draft')->first() : null;
        $data = [
            'p' => $p, 'v' => ProformaController::present($p, true), 'token' => $token, 'step' => 'start', 'error' => null,
            'mobileMasked' => Mobile::mask($p->buyer_mobile),
            'invoiceNumber' => $invoice?->number, 'verifyUrl' => $invoice?->isIssued() || $invoice?->status === 'void' ? config('talata.public_url').'/v/'.$invoice->verify_token : null,
            'og' => $this->og($p, $token),
        ];
        $data = array_merge($data, $extra);

        return response()->view('public.proforma', $data, $status)
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function show(string $token)
    {
        $p = $this->find($token);
        if (! $p) {
            return response()->view('public.verify-invalid', ['share' => true], 404)->header('Cache-Control', 'no-store');
        }

        return $this->page($p, $token);
    }

    public function code(Request $request, string $token)
    {
        $p = $this->find($token);
        abort_unless($p, 404);
        $raw = (string) $request->input('mobile', '');
        try {
            $c = $this->proformas->requestCode($p, $raw, $request->ip());

            return $this->page($p, $token, ['step' => 'code', 'challenge' => $c['challenge_id'], 'mobileTyped' => $raw]);
        } catch (DomainError $e) {
            return $this->page($p->refresh(), $token, ['step' => in_array($e->codeName, ['PROFORMA_MOBILE_MISMATCH', 'OTP_COOLDOWN', 'OTP_RATE_LIMITED', 'OTP_BUDGET', 'OTP_LOCKED'], true) ? 'mobile' : 'start', 'error' => $e->messageFa, 'mobileTyped' => $raw], $e->status >= 500 ? 503 : 422);
        }
    }

    public function confirm(Request $request, string $token)
    {
        $p = $this->find($token);
        abort_unless($p, 404);
        $challenge = (string) $request->input('challenge', '');
        try {
            $p = $this->proformas->confirm($p, $challenge, (string) $request->input('code', ''), $request->ip());

            return $this->page($p, $token, ['step' => 'done']);
        } catch (DomainError $e) {
            $again = in_array($e->codeName, ['OTP_WRONG'], true);

            return $this->page($p->refresh(), $token, ['step' => $again ? 'code' : 'mobile', 'challenge' => $again ? $challenge : null, 'error' => $e->messageFa], 422);
        }
    }

    /** Link preview: «پیش‌فاکتور · shop» and the validity, never buyer data. */
    private function og(Proforma $p, string $token): array
    {
        $tenant = Tenant::query()->find($p->tenant_id);
        $shop = $p->snapshot['shop'] ?? [];
        $branded = $tenant && app(Entitlements::class)->can($tenant, 'invoice.shop_logo');
        try {
            $contact = $shop['landline'] ?: Mobile::display($shop['business_mobile'] ?? '');
            $image = app(SharePreview::class)->urlFor($tenant->public_id, ['name' => $shop['name'] ?? '', 'contact_primary' => Digits::toPersian((string) $contact), 'logo' => $shop['logo'] ?? null], $branded);
        } catch (\Throwable $e) {
            report($e);
            $image = rtrim((string) config('talata.public_url'), '/').SharePreview::STATIC_CARD;
        }
        $tz = $p->snapshot['timezone'] ?? config('talata.timezone');

        return [
            'title' => 'پیش‌فاکتور · '.($shop['name'] ?? ''),
            'description' => 'پیش‌فاکتور خرید شما از '.($shop['name'] ?? '').'. مهلت تأیید: '.ProformaService::until($p, $tz).'.',
            'image' => $image, 'url' => ProformaService::link($p),
        ];
    }
}
