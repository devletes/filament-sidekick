<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\ActionResolver;
use Illuminate\Contracts\Auth\Authenticatable;

class NullActionResolver implements ActionResolver
{
    public function resolve(string $target, ?string $record, Authenticatable $user): ?string
    {
        return null;
    }

    public function targets(): array
    {
        return [];
    }
}
