<?php

namespace App\Domain;

use RuntimeException;

/**
 * A business rule refusal with a stable machine code and a Persian message for the merchant.
 * Rendered as {code, message_fa, trace_id, ...context} for AJAX and as a flash message for pages.
 */
class DomainError extends RuntimeException
{
    public function __construct(
        public readonly string $codeName,
        public readonly string $messageFa,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($codeName);
    }
}
