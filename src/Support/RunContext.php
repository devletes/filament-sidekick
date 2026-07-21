<?php

namespace Devletes\Sidekick\Support;

/**
 * Per-run scratch state shared between tools and the run job. The job binds a
 * fresh instance before streaming; tools write into it; the job persists the
 * outcome onto the run row when the turn finishes.
 */
class RunContext
{
    public ?string $navigateTo = null;

    public ?string $conversationId = null;

    public ?string $runId = null;
}
