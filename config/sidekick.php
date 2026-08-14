<?php

return [

    'enabled' => env('SIDEKICK_ENABLED', true),

    // The laravel/ai agent class that answers chat turns. Must use the
    // Promptable + RemembersConversations traits (see ChatAgent).
    'agent' => \Devletes\Sidekick\Agents\ChatAgent::class,

    'assistant' => [
        'name' => 'Assistant',
        'description' => 'Ask me anything about your workspace.',
    ],

    // Appended verbatim to the agent's system instructions when set.
    'instructions' => null,

    'provider' => null,
    'model' => null,
    'timeout' => 120,
    'max_output_tokens' => 2048,

    // Conversation messages (rows) loaded back into model context per turn.
    // null → no cap: the entire conversation is rehydrated every turn.
    'history_limit' => 10,

    // Strip past tool calls/results from context — the model re-calls tools
    // for fresh data; keeps prompt tokens flat as conversations grow.
    'lean_history' => true,

    // Messages rendered in the panel (display only, not model context).
    'display_limit' => 60,

    'max_prompt_length' => 4000,

    // Chat tools (class names implementing Contracts\ChatTool). Usually
    // unnecessary: classes in app/Sidekick/Tools are discovered automatically,
    // and packages can register via the Sidekick facade. List classes here to
    // add ones living elsewhere — or to give a profile its own tool set.
    'tools' => [],

    // Confirmable write actions (class names implementing
    // Contracts\ActionHandler — extend Support\SidekickAction). Same deal:
    // app/Sidekick/Actions is discovered automatically.
    'actions' => [],

    // Zero-registration discovery. Every non-abstract class in these
    // directories implementing the right contract joins the assistant.
    // null paths → app/Sidekick/Tools and app/Sidekick/Actions.
    'discover' => [
        'enabled' => true,
        'tools' => null,
        'actions' => null,
    ],

    // Built-in tools. Navigate + PresentActions wake automatically once an
    // ActionResolver with targets is bound; set false to keep one off anyway.
    'builtin_tools' => [
        'navigate' => true,
        'present_actions' => true,
    ],

    // Minutes before an unconfirmed action card expires.
    'actions_expire_after' => 15,

    // Chat file uploads. The attachment area is TEMPORARY: files live here
    // only long enough to be consumed by a tool/action (which copies them
    // into the host's own storage); chat history keeps name/type/size
    // metadata only, and the model only ever sees that metadata — file
    // contents are never sent to the LLM.
    'attachments' => [
        'enabled' => false,

        // null → filesystems.default. Use a PRIVATE disk — the package never
        // serves these files, but a public disk would expose them by URL.
        'disk' => null,

        'directory' => 'sidekick-attachments',

        // Mime patterns (exact, or wildcards like image/*). Empty = any type.
        'accept' => ['image/*', 'application/pdf'],

        // Per-file limit in KB. Livewire's own temporary-upload rule (12 MB
        // by default) applies first — raise both to go higher.
        'max_size' => 12288,

        // Max files staged per message (and per confirm card).
        'max_files' => 4,

        // sidekick:prune-attachments deletes ALL attachment files (and rows)
        // older than this many hours — sent or not. Keep it comfortably above
        // the action-card expiry so referenced files exist at confirm time.
        'prune_after_hours' => 24,
    ],

    'jobs' => [
        // Swap for a subclass to add app concerns (tenancy, metering).
        'run' => \Devletes\Sidekick\Jobs\RunChatTurn::class,
        'queue' => null,
    ],

    // Reverb/Pusher nudges for instant updates. Polling stays on while a
    // run is active regardless, so this is an upgrade, not a requirement.
    'broadcasting' => [
        'enabled' => env('SIDEKICK_BROADCASTING', false),
    ],

    'polling' => [
        'interval' => '2s',
        // Used instead of `interval` while broadcasting is enabled — echo
        // nudges do the real-time work and polling is just the safety net.
        'while_broadcasting' => '10s',
    ],

    // A queued/running run older than this (seconds since last update) is
    // treated as dead — the worker crashed or was never running.
    'stale_after' => 240,

    'panel' => [
        'width' => '23rem',
        // Pull the panel to full viewport height and squeeze the topbar
        // aside with it (ARP-style). Off = panel starts below the topbar.
        'full_height' => false,
    ],

    'tables' => [
        'runs' => 'sidekick_runs',
        'attachments' => 'sidekick_attachments',
    ],

    // Panel-specific assistants. Each entry is a named set of TOP-LEVEL
    // overrides of this config (assistant, instructions, tools, actions,
    // model, history_limit, attachments, ...) — an overridden key replaces
    // the base value wholesale. Attach a profile to a panel with
    // SidekickPlugin::make()->profile('name'); panels without one run the
    // base config. Conversations are stamped with their profile: each
    // panel's assistant only sees its own history, and queued turns run
    // under the profile the chat started in.
    'profiles' => [],

];
