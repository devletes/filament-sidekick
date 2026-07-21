<?php

namespace Devletes\Sidekick\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Host-app seam: turns a named navigation target (+ optional record) into a
 * concrete, authorized URL. Used by navigation/action tools and by the panel
 * when rendering action buttons. Return null for unknown or unauthorized
 * targets — never throw into the render path.
 */
interface ActionResolver
{
    public function resolve(string $target, ?string $record, Authenticatable $user): ?string;

    /** @return array<int, string> Valid target names (schema enums, instructions). */
    public function targets(): array;
}
