<?php

namespace Devletes\Sidekick\Models;

use Laravel\Ai\Models\ConversationMessage as BaseConversationMessage;

class ConversationMessage extends BaseConversationMessage
{
    /** String keys, independent of whether this framework release supports laravel/ai's attribute. */
    public $incrementing = false;

    protected $keyType = 'string';

    /** Every step's calls, in order; the base model collapses them out of `steps`. */
    public function decodedToolCalls(): array
    {
        return $this->tool_calls;
    }
}
