<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Integer primary keys stay internal; URLs and APIs only expose an unguessable ULID public_id. */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            if (! $model->public_id) {
                $model->public_id = strtolower((string) Str::ulid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
