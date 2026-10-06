<?php

namespace App\Domain\Billing;

use Illuminate\Http\Request;

/** Bank payment gateway adapter. The concrete Iranian PSP is pending owner selection. */
interface PaymentGateway
{
    public function code(): string;

    public function isMock(): bool;

    /** @return array{authority:string,redirect_url:string,method:'GET'|'POST',fields:array<string,string>} */
    public function request(string $orderRef, string $amountIrr, string $callbackUrl, ?string $mobile): array;

    /** @return array{authority:?string,status:'OK'|'CANCELLED'|'FAILED',bank_code:?string,raw:array} */
    public function parseCallback(Request $request): array;

    /** Server-to-server verification with the stored amount. @return array{status:'OK'|'FAILED'|'UNKNOWN',ref_id:?string,card_mask:?string,bank_code:?string,amount_irr:?string} */
    public function verify(string $authority, string $amountIrr): array;
}
