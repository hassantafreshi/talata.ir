<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A dangerous staff action applied once per idempotency key (App\Domain\Admin\AdminActions). */
class AdminAction extends Model
{
    public $timestamps = false;

    protected $fillable = ['idempotency_key', 'action', 'staff_id', 'tenant_id', 'result', 'created_at'];

    protected function casts(): array
    {
        return ['result' => 'array', 'created_at' => 'datetime'];
    }
}
