<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One device of a user that accepts Web Push (App\Support\WebPush). Per user, not per shop. */
class PushSubscription extends Model
{
    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'device', 'last_success_at', 'failures'];

    protected $hidden = ['endpoint', 'p256dh', 'auth'];

    protected function casts(): array
    {
        return ['last_success_at' => 'datetime'];
    }
}
