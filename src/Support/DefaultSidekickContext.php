<?php

namespace Devletes\Sidekick\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

class DefaultSidekickContext implements SidekickContext
{
    public function attributes(Authenticatable $user): array
    {
        return ['channel' => 'web'];
    }

    public function scope(Builder $query, Authenticatable $user): Builder
    {
        return $query;
    }
}
