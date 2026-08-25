<?php

namespace Devletes\Sidekick\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/** Host-app seam: turns a named target (+ optional record) into an authorized URL; return null for unknown/unauthorized — never throw into the render path. */
interface ActionResolver
{
    public function resolve(string $target, ?string $record, Authenticatable $user): ?string;

    /** @return array<int, string> Valid target names (schema enums, instructions). */
    public function targets(): array;
}
