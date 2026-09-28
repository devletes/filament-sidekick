<?php

namespace Devletes\Sidekick\Contracts;

/**
 * A tool with something to say that changes from turn to turn: where a checklist stands, what the user is
 * looking at. Unlike ChatTool::instructions(), which is standing guidance in the system prompt, this rides
 * with the user's message, so the system prompt stays the same between turns and the conversation before it
 * can be read from the provider's prompt cache. Returned only while the tool is offered to the user.
 */
interface ProvidesTurnContext
{
    public function turnContext(): ?string;
}
