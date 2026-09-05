<?php

use Devletes\Sidekick\Agents\ChatAgent;
use Devletes\Sidekick\Jobs\RunChatTurn;

return [

    'enabled' => env('SIDEKICK_ENABLED', true),

    // The laravel/ai agent class that answers chat turns. Must use the
    // Promptable + RemembersConversations traits (see ChatAgent).
    'agent' => ChatAgent::class,

    'assistant' => [
        'name' => 'Assistant',
        'description' => 'Ask me anything about your workspace.',
    ],

    // Any icon your app has registered (Heroicons ship with Filament). The
    // toggle icon can also be overridden per panel — including with raw SVG —
    // via SidekickPlugin::make()->icon(...).
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

    // Appended verbatim to the agent's system instructions when set.
    'instructions' => null,

    'provider' => null,
    'model' => null,
    'timeout' => 120,
    'max_output_tokens' => 2048,

    // Conversation messages (rows) loaded back into model context per turn.
    // null → no cap: the entire conversation is rehydrated every turn.
    'history_limit' => 10,

    // Optional ceiling on the SIZE of that history, in approximate tokens.
    // A row cap says nothing about prompt size — ten pasted stack traces cost
    // far more than ten "thanks" — so set this to bound spend predictably.
    // Rows are dropped oldest-first until the estimate fits; the newest
    // message is always kept. null → rows are the only limit.
    'history_token_budget' => null,

    // Bytes per token for that estimate. No PHP tokeniser matches every
    // provider, so this is deliberately approximate — budget with headroom
    // rather than to the exact context window. 4 suits Latin scripts; CJK
    // lands nearer 3 bytes (~1 token) per character and is already counted
    // by byte length.
    'history_bytes_per_token' => 4,

    // Strip past tool calls/results from context — the model re-calls tools
    // for fresh data; keeps prompt tokens flat as conversations grow.
    'lean_history' => true,

    // Messages rendered in the panel (display only, not model context).
    'display_limit' => 60,

    // Whether this installation serves more than one tenant. Decides whether
    // the insights page offers a per-tenant breakdown on panels that are not
    // themselves tenant-scoped — a single-tenant app has nothing to break down.
    //
    // null detects it from your panels: any panel with tenancy means yes. Set
    // true or false to decide it yourself.
    'tenancy' => [
        'multi_tenant' => null,
    ],

    // Operator insights page: turns, tokens, failures and recent activity,
    // scoped to the panel's tenant. Off by default. Turn it on per panel with
    // SidekickPlugin::make()->enableInsights(), ideally passing a closure that
    // says who may open it — it totals other people's usage.
    'insights' => [
        'enabled' => false,

        // Presentation is not configured here on purpose. Navigation label,
        // icon, sort, group, sidebar visibility, slug, heading and the widget
        // list are ordinary members of the page and its widgets, so extend
        // them and hand the subclass to the panel:
        //
        //   class NyraInsights extends \Devletes\Sidekick\Pages\SidekickInsights {
        //       protected static ?string $navigationLabel = 'Nyra Insights';
        //   }
        //
        //   SidekickPlugin::make()->insightsPage(NyraInsights::class)
        //
        // The widgets take the same treatment — RecentRuns::userColumn() and
        // tenantColumn(), TenantUsage::tenantColumn() — so rendering a person
        // with your own component is a short subclass, not a rewrite.

        // Prompts are the person's own words; an operator dashboard is not
        // automatically the right place to read them back.
        'show_prompts' => false,

        // On a panel WITHOUT tenancy — a platform console — the page adds a
        // per-tenant breakdown and a tenant column, since its totals span every
        // tenant. Point these at your tenant model so rows read as customer
        // names instead of ids; without it they fall back to the raw id.
        // Both tenants and users show a name rather than an id, and both find
        // their model on their own: the tenant model comes from whichever panel
        // declares tenancy, the user model from the panel guard. Set either
        // explicitly only when that guess is wrong — an app whose runs carry a
        // tenant but whose panels have no tenancy, say.
        //
        // The attribute is the column read as the name. If it does not exist,
        // the lookup degrades to showing the id rather than erroring.
        'tenant_model' => null,
        'tenant_label_attribute' => 'name',

        'user_model' => null,
        'user_label_attribute' => 'name',
    ],

    // Past conversations, reachable from a dropdown next to New conversation.
    // Off by default: a panel assistant is usually a place to ask one thing,
    // not an archive. Turn it on per panel with SidekickPlugin::make()
    // ->enableHistory(), or globally here.
    'history' => [
        'enabled' => false,
        'limit' => 10,
    ],

    'max_prompt_length' => 4000,

    // Chat tools (class names implementing Contracts\ChatTool). Usually
    // unnecessary: classes under app/Sidekick are discovered automatically,
    // and packages can register via the Sidekick facade. List classes here to
    // add ones living elsewhere — or to give a profile its own tool set.
    'tools' => [],

    // Confirmable write actions (class names implementing
    // Contracts\ActionHandler — extend Support\SidekickAction). Same deal:
    // app/Sidekick is discovered automatically.
    'actions' => [],

    // Zero-registration discovery. Roots are scanned RECURSIVELY and every
    // non-abstract class implementing ChatTool or ActionHandler joins the
    // assistant — so app/Sidekick/Tools/… and app/Sidekick/Leave/… work
    // equally well, and anything else in the tree (support classes, the
    // resolver) is simply ignored. null → app/Sidekick.
    //
    // A string or an array of paths. Point roots at trees that hold only
    // Sidekick classes: discovery autoloads what it finds, so aiming one at a
    // shared tree like app/Filament costs boot time for nothing.
    'discover' => [
        'enabled' => true,
        'paths' => null,
    ],

    // Tool catalog. Normally every authorized tool's definition rides along in
    // every request; past a few dozen that costs real tokens and blunts the
    // model's choice. Turn this on and the model is offered two tools instead:
    // ListTools (names, descriptions and parameters) and RunTool (dispatch by
    // name). The prompt then stays flat whether you have 6 tools or 60.
    //
    // It buys that with an extra round trip per turn and the loss of
    // provider-side schema validation, so it is a poor trade for a small
    // assistant. Prefer scoping with panels() and profiles first; reach for
    // this when one assistant genuinely needs everything.
    //
    // `above` flips it automatically once a user's authorized set passes that
    // many tools, which is usually better than a flat yes/no — the right mode
    // depends on how much any one user can actually see.
    'tool_catalog' => [
        'enabled' => false,
        'above' => null,
    ],

    // Built-in tools. Navigate + PresentActions wake automatically once an
    // ActionResolver with targets is bound; set false to keep one off anyway.
    'builtin_tools' => [
        'navigate' => true,
        'present_actions' => true,
    ],

    // Maps named navigation targets to URLs (Contracts\ActionResolver) — the
    // wiring that wakes the built-in tools above. Set a class here, or bind
    // the contract yourself in a service provider (a binding wins over this).
    // sidekick:scaffold generates an implementation from your Filament resources.
    'action_resolver' => null,

    // php artisan sidekick:scaffold — generates baseline search tools, a
    // resolver, and (with --actions) action stubs from your Filament resources.
    'scaffold' => [
        // Only scaffold these resources (empty = every panel resource).
        // A plain class list, or class => panel id to pin which panel's
        // URLs the resolver generates.
        'only' => [],

        // Resources the scaffolder always skips. Re-runs never overwrite
        // existing files, so list deleted scaffolds here to keep them gone.
        'ignore' => [],
    ],

    // Usage limits. The shipped limiter (Support\MeteredUsage) counts turns and
    // tokens off the runs table and enforces the allowances below; it does
    // nothing at all while `limits.enabled` is false. Point this at your own
    // Contracts\UsageLimiter to replace it outright. A container binding wins.
    'usage_limiter' => null,

    'limits' => [
        'enabled' => false,

        // The platform's cap on a whole tenant — or on the whole install when
        // the panel has no tenancy. null anywhere means unlimited for that
        // window, so a partial set constrains only what it names.
        'tenant' => [
            'requests_per_day' => null,
            'requests_per_month' => null,
            'tokens_per_day' => null,
            'tokens_per_month' => null,
        ],

        // The cap on one person. Always clamped to the tenant's above, so a
        // tenant admin can be stricter than their plan but can never hand out
        // more of it than the platform sold them.
        'user' => [
            'requests_per_day' => null,
            'requests_per_month' => null,
            'tokens_per_day' => null,
            'tokens_per_month' => null,
        ],

        // Contracts\LimitProvider — where those numbers come from. The default
        // reads the two arrays above. Point this at your own class to read a
        // tenant's plan and its per-user settings from your tables; the
        // clamping still applies, so you cannot accidentally oversell.
        'provider' => null,
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
        'run' => RunChatTurn::class,
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
