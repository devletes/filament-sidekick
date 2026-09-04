<?php

namespace Devletes\Sidekick\Models;

use Laravel\Ai\Models\ConversationMessage as BaseConversationMessage;

class ConversationMessage extends BaseConversationMessage
{
    /** String keys, independent of whether this framework release supports laravel/ai's attribute. */
    public $incrementing = false;

    protected $keyType = 'string';

    /** The base model already casts tool_calls to array; this just guards null. */
    public function decodedToolCalls(): array
    {
        return $this->tool_calls ?? [];
    }
}
