<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only (enforced by a PostgreSQL trigger). */
class AuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'actor_user_id', 'actor_type', 'event', 'subject_type', 'subject_id', 'data', 'ip', 'created_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }
}
