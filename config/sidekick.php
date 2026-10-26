<?php

use Devletes\Sidekick\Agents\ChatAgent;
use Devletes\Sidekick\Jobs\RunChatTurn;

return [

    'enabled' => env('SIDEKICK_ENABLED', true),

    // laravel/ai agent class; must use Promptable + RemembersConversations.
    'agent' => ChatAgent::class,

    'assistant' => [
        'name' => 'Assistant',
        'description' => 'Ask me anything about your workspace.',
    ],

    // Any registered icon name; override the toggle per panel via SidekickPlugin::make()->icon().
    'icons' => [
        'assistant' => 'heroicon-o-sparkles',
        'new_conversation' => 'heroicon-m-plus',
        'close' => 'heroicon-m-x-mark',
        'attach' => 'heroicon-m-paper-clip',
        'send' => 'heroicon-m-arrow-up',
        'remove' => 'heroicon-m-x-mark',
        'tool_done' => 'heroicon-m-check',
        'history' => 'heroicon-m-clock',
    ],

    // Appended verbatim to the agent's system instructions.
    'instructions' => null,

    'provider' => null,
    'model' => null,
    'timeout' => 120,
    'max_output_tokens' => 2048,

    // Messages loaded back into model context per turn; null = whole conversation.
    'history_limit' => 10,

    // Approximate token ceiling on that history; null = row limit only.
    'history_token_budget' => null,

    // Anthropic only: also cache the conversation, not just tools and instructions.
    'cache_history' => true,

    // Bytes per token used for the history estimate.
    'history_bytes_per_token' => 4,

    // Replay past tool calls with long results stubbed to keep prompts flat.
    'lean_history' => true,

    // Messages rendered in the panel (display only, not model context).
    'display_limit' => 60,

    // null = detect from panels (any tenancy means yes).
    'tenancy' => [
        'multi_tenant' => null,
    ],

    // Operator usage page; enable per panel with SidekickPlugin::make()->enableInsights().
    'insights' => [
        'enabled' => false,

        'show_prompts' => false,

        // null = guessed from panel tenancy / guard; label falls back to the id if missing.
        'tenant_model' => null,
        'tenant_label_attribute' => 'name',

        'user_model' => null,
        'user_label_attribute' => 'name',
    ],

    // Past-conversations dropdown; enable per panel with SidekickPlugin::make()->enableHistory().
    'history' => [
        'enabled' => false,
        'limit' => 10,
    ],

    'max_prompt_length' => 4000,

    // Extra Contracts\ChatTool classes; app/Sidekick is discovered automatically.
    'tools' => [],

    // Extra Contracts\ActionHandler classes; app/Sidekick is discovered automatically.
    'actions' => [],

    // Recursive scan roots (string or array); null = app/Sidekick.
    'discover' => [
        'enabled' => true,
        'paths' => null,
    ],

    // Offer ListTools/RunTool instead of every tool; `above` enables it past that many tools.
    'tool_catalog' => [
        'enabled' => false,
        'above' => null,
    ],

    // Navigate and PresentActions activate once an ActionResolver is bound; false keeps one off.
    'builtin_tools' => [
        'navigate' => true,
        'present_actions' => true,
    ],

    // Contracts\ActionResolver class; a container binding wins.
    'action_resolver' => null,

    // php artisan sidekick:scaffold
    'scaffold' => [
        // Empty = every panel resource; class => panel id pins the panel.
        'only' => [],

        // Re-runs never overwrite, so list deleted scaffolds here to keep them gone.
        'ignore' => [],
    ],

    // Contracts\UsageLimiter class; null = Support\MeteredUsage. A container binding wins.
    'usage_limiter' => null,

    // null = unlimited for that window.
    'limits' => [
        'enabled' => false,

        // Per tenant, or per install without tenancy.
        'tenant' => [
            'requests_per_day' => null,
            'requests_per_month' => null,
            'tokens_per_day' => null,
            'tokens_per_month' => null,
        ],

        // Per user; always clamped to the tenant limits.
        'user' => [
            'requests_per_day' => null,
            'requests_per_month' => null,
            'tokens_per_day' => null,
            'tokens_per_month' => null,
        ],

        // Contracts\LimitProvider class; null = the arrays above.
        'provider' => null,

        // Contracts\UsageExemptions class; null = nothing exempt.
        'exemptions' => null,
    ],

    // Minutes before an unconfirmed action card expires.
    'actions_expire_after' => 15,

    // Temporary storage until a tool/action copies the file; the model only sees metadata.
    'attachments' => [
        'enabled' => false,

        // null = filesystems.default; use a private disk.
        'disk' => null,

        'directory' => 'sidekick-attachments',

        // Mime patterns, wildcards allowed; empty = any type.
        'accept' => ['image/*', 'application/pdf'],

        // KB per file; Livewire's upload limit (12 MB default) applies first.
        'max_size' => 12288,

        // Per message (and per confirm card).
        'max_files' => 4,

        // Keep above actions_expire_after so files exist at confirm time.
        'prune_after_hours' => 24,
    ],

    'jobs' => [
        'run' => RunChatTurn::class,
        'queue' => null,
    ],

    // Reverb/Pusher nudges; polling still runs while a turn is active.
    'broadcasting' => [
        'enabled' => env('SIDEKICK_BROADCASTING', false),
    ],

    'polling' => [
        'interval' => '2s',
        // Used instead of `interval` while broadcasting is enabled.
        'while_broadcasting' => '10s',
    ],

    // Seconds without an update before a queued/running run is treated as dead.
    'stale_after' => 240,

    'panel' => [
        'width' => '23rem',
        // Full viewport height, pushing the topbar aside.
        'full_height' => false,
    ],

    'tables' => [
        'runs' => 'sidekick_runs',
        'attachments' => 'sidekick_attachments',
    ],

    // Named top-level config overrides; attach with SidekickPlugin::make()->profile('name').
    'profiles' => [],

];
