<?php

namespace Devletes\Sidekick\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** `name` is an accessor, not a column, so selecting it in SQL fails. */
class Person extends Model
{
    protected $table = 'people';

    protected $guarded = [];

    public function getNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
