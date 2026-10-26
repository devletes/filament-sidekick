<?php

namespace Devletes\Sidekick\Contracts;

/** Per-turn context sent with the user's message, so the system prompt stays stable for prompt caching. */
interface ProvidesTurnContext
{
    public function turnContext(): ?string;
}
