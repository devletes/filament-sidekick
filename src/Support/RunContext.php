<?php

namespace Devletes\Sidekick\Support;

/** Per-run scratch state: the job binds a fresh instance before streaming, tools write into it, the job persists the outcome. */
class RunContext
{
    public ?string $navigateTo = null;

    public ?string $conversationId = null;

    public ?string $runId = null;
}
