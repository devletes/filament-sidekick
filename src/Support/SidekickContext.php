<?php

namespace Devletes\Sidekick\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/** Host-app seam: stamps extra columns onto new conversations and constrains conversation queries (e.g. tenant scoping). */
interface SidekickContext
{
    public function attributes(Authenticatable $user): array;

    public function scope(Builder $query, Authenticatable $user): Builder;
}
