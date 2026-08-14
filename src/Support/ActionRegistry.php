<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\ActionHandler;

class ActionRegistry
{
    public function __construct(protected SidekickManager $manager) {}

    public function handler(string $type): ?ActionHandler
    {
        return $this->manager->actionHandler($type);
    }
}
