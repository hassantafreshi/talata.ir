<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** WebAuthn credential (public key only; biometrics never leave the device). */
class Passkey extends Model
{
    protected $fillable = ['owner_type', 'owner_id', 'credential_id', 'public_key_pem', 'alg', 'sign_count', 'name', 'transports', 'backed_up', 'last_used_at'];

    protected $hidden = ['public_key_pem'];

    protected function casts(): array
    {
        return ['transports' => 'array', 'backed_up' => 'boolean', 'last_used_at' => 'datetime', 'sign_count' => 'integer', 'alg' => 'integer'];
    }
}
