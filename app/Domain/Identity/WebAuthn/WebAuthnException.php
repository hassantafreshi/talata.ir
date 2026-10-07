<?php

namespace App\Domain\Identity\WebAuthn;

final class WebAuthnException extends \RuntimeException
{
    /**
     * Short, non-sensitive code shown next to the Persian error so a screenshot from a real phone tells support
     * which check failed (e.g. ORIGIN = the page's address does not match TALATA_WEBAUTHN_ORIGINS / APP_URL).
     */
    public function reasonCode(): string
    {
        return match ($this->getMessage()) {
            'Challenge expired' => 'EXPIRED',
            'Challenge mismatch' => 'CHALLENGE',
            'Origin not allowed', 'Cross-origin ceremonies are not allowed' => 'ORIGIN',
            'RP ID mismatch' => 'RPID',
            'User presence and verification are required' => 'UV',
            'Credential already registered' => 'DUPLICATE',
            'Too many passkeys' => 'LIMIT',
            'Unknown credential' => 'UNKNOWN_KEY',
            'Bad signature' => 'SIGNATURE',
            'Signature counter did not increase (possible cloned credential)' => 'COUNTER',
            'Invalid P-256 key', 'RSA key too small', 'Unsupported credential algorithm', 'Unreadable public key' => 'KEY_TYPE',
            default => 'INVALID',
        };
    }
}
