<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class OtpChallenge extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = ['mobile', 'purpose', 'code_hash', 'attempts', 'ip', 'expires_at', 'consumed_at', 'created_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
