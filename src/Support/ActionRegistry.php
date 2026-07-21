<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\ActionHandler;

class ActionRegistry
{
    public function handler(string $type): ?ActionHandler
    {
        foreach (config('sidekick.actions', []) as $class) {
            $handler = app($class);

            if ($handler instanceof ActionHandler && $handler->type() === $type) {
                return $handler;
            }
        }

        return null;
    }
}
