<?php

namespace Devletes\Sidekick\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/** Host-app seam: stamps extra columns onto new conversations and constrains conversation queries (e.g. tenant scoping). */
interface SidekickContext
{
    /** Extra attributes merged into new conversation rows. */
    public function attributes(Authenticatable $user): array;

    /** Constrain which conversations the user may see/resume in the current context. */
    public function scope(Builder $query, Authenticatable $user): Builder;
}
