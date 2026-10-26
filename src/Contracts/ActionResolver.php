<?php

namespace Devletes\Sidekick\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/** Turns a named target (+ optional record) into an authorized URL; return null, never throw, when unknown or unauthorized. */
interface ActionResolver
{
    public function resolve(string $target, ?string $record, Authenticatable $user): ?string;

    /** @return array<int, string> Valid target names (schema enums, instructions). */
    public function targets(): array;
}
