<?php

namespace App\Domain\Billing;

use Illuminate\Http\Request;

/**
 * Bank payment gateway (PSP) adapter. One class per PSP, registered by code in config('talata.payments.gateways');
 * switching PSP is configuration only (docs/PAYMENTS_AND_SMS_CREDIT.md §PSP adapters). Rules every adapter keeps:
 * amounts are IRR integers; a callback is never trusted (BillingService always calls verify() with the STORED
 * amount); verify() must be idempotent (a second call for a paid authority still answers OK); network or PSP
 * errors return UNKNOWN (reconciled later), never FAILED.
 */
interface PaymentGateway
{
    /** Stable short code stored on every PaymentAttempt and used in the callback URL. */
    public function code(): string;

    public function isMock(): bool;

    /**
     * Opens a payment at the PSP.
     *
     * @return array{authority:string,redirect_url:string,method:'GET'|'POST',fields:array<string,string>}
     *
     * @throws PaymentGatewayError when the PSP refuses or cannot be reached (no order is created)
     */
    public function request(string $orderRef, string $amountIrr, string $callbackUrl, ?string $mobile): array;

    /** Where to send the payer again for an existing, still-open authority. @return array{redirect_url:string,method:'GET'|'POST',fields:array<string,string>} */
    public function redirectFor(string $authority): array;

    /** @return array{authority:?string,status:'OK'|'CANCELLED'|'FAILED',bank_code:?string,raw:array} */
    public function parseCallback(Request $request): array;

    /** Server-to-server verification with the stored amount. @return array{status:'OK'|'FAILED'|'UNKNOWN',ref_id:?string,card_mask:?string,bank_code:?string,amount_irr:?string} */
    public function verify(string $authority, string $amountIrr): array;

    /** Hosts our pages may POST a form to (CSP form-action) — only for PSPs that need a POST redirect. @return list<string> */
    public function formActionHosts(): array;
}
