<?php

namespace App\Domain\Billing;

use RuntimeException;

/** The PSP refused or could not be reached while opening a payment. $pspCode is the PSP's own code (for logs). */
final class PaymentGatewayError extends RuntimeException
{
    public function __construct(public readonly string $pspCode, string $message = 'payment gateway error')
    {
        parent::__construct($message);
    }
}
