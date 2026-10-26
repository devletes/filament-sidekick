<?php

namespace Devletes\Sidekick\Support;

/** Per-run scratch state: bound fresh by the job, written by tools, persisted by the job. */
class RunContext
{
    public ?string $navigateTo = null;

    public ?string $conversationId = null;

    public ?string $runId = null;
}
