<?php

namespace Devletes\Sidekick\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A user model shaped like a real one: `name` is computed from first_name/last_name and is not a column, so
 * asking SQL for it fails. Stands in for every app whose display name is an accessor.
 */
class Person extends Model
{
    protected $table = 'people';

    protected $guarded = [];

    public function getNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
