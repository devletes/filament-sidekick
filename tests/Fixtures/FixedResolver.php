<?php

namespace Devletes\Sidekick\Tests\Fixtures;

use Devletes\Sidekick\Contracts\ActionResolver;
use Illuminate\Contracts\Auth\Authenticatable;

class FixedResolver implements ActionResolver
{
    public function resolve(string $target, ?string $record, Authenticatable $user): ?string
    {
        return $target === 'notes'
            ? 'https://example.test/notes'.($record ? '/'.$record : '')
            : null;
    }

    public function targets(): array
    {
        return ['notes'];
    }
}
