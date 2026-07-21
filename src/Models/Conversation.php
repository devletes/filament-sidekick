<?php

namespace Devletes\Sidekick\Models;

use Devletes\Sidekick\Support\SidekickContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Ai\Models\Conversation as BaseConversation;

class Conversation extends BaseConversation
{
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class, 'conversation_id');
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class, 'conversation_id');
    }

    /** Conversations owned by the user within the host app's context (tenant etc.). */
    public function scopeForParticipant(Builder $query, Authenticatable $user): Builder
    {
        $query->where('user_id', $user->getAuthIdentifier());

        return app(SidekickContext::class)->scope($query, $user);
    }
}
