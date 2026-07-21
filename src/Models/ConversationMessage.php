<?php

namespace Devletes\Sidekick\Models;

use Laravel\Ai\Models\ConversationMessage as BaseConversationMessage;

class ConversationMessage extends BaseConversationMessage
{
    /** The base model already casts tool_calls to array; this just guards null. */
    public function decodedToolCalls(): array
    {
        return $this->tool_calls ?? [];
    }
}
