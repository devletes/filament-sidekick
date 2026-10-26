# Changelog

All notable changes to `devletes/filament-sidekick` will be documented in this file.

## [1.0.0] - 2026-10-25

First stable release. Requires laravel/ai 1.x; existing conversations are migrated on `php artisan migrate` — see **Changed**.

### Changed

- **Breaking:** requires `laravel/ai` `^1.0`; 0.x is no longer supported. laravel/ai 1.0 stores each assistant message as `steps` (each tool call carrying its own result) with a `status`, in place of the `tool_calls`, `tool_results` and `approval_state` columns. The suite passes on 1.0.0 and 1.3.0.
- A new migration rewrites existing conversation messages into the 1.x shape — calls paired with their results, `meta.reasoning` moved onto its step — then drops the old columns and rebuilds `participant_index` with `agent`. It runs only on a table that still has `tool_calls` and no `steps`, so **do not also add laravel/ai's own upgrade migration**; if you already ran that one, this one does nothing. It is one-way.
- Fresh installs create the conversation tables in the 1.x shape. Tables laravel/ai created on its own gain the `user_id` column the package's scopes, limits and insights read.
- Token accounting reads laravel/ai 1.x's `inputTokens` / `outputTokens`. `sidekick_runs.usage.prompt_tokens` remains fresh input only — 1.x's input count now includes cached and cache-written input, which are already logged separately — so `tokens` sums the same way for runs before and after the upgrade.

- The insights chart draws turns in the panel's primary colour and tokens in its warning colour, with one tooltip per day. Both lines were the same colour before, and the pale fill under turns washed out in dark mode; it is now a translucent wash that sits on either background.

### Fixed

- Confirming or cancelling an action failed with `Array to string conversion` on laravel/ai 1.x, which no longer casts `tool_calls` / `tool_results` on write. The acknowledgement is now stored as one plain step.
- On laravel/ai 1.x, lean history would have replayed no tool calls at all, since they now live in `steps`. Replay now rebuilds each reply step by step through laravel/ai's own store, with long results stubbed as before.
- laravel/ai 1.x stores a turn that threw as a `failed` message. The panel leaves it out — the failure is shown from the run, as before — while the model still sees it in history.
- Preliminary tool results, which laravel/ai 1.x streams while a sub-agent runs, are no longer logged as finished tool calls in the panel's activity.

## [0.2.3] - 2026-09-28

### Added

- `Contracts\ProvidesTurnContext`: a tool with something to say that changes from turn to turn — where a checklist stands, what the user is looking at — returns it from `turnContext()`, and it rides with the user's message instead of the system prompt. The instructions stay identical between turns, so everything before the newest message can be read from the provider's prompt cache. The context is stored in a new `context` column on `agent_conversation_messages` and replayed with the message it was sent with.
- `sidekick.cache_history` (default `true`, Anthropic only) caches the conversation as well as the tools and the instructions, so each step of a turn and the next turn read what was already sent instead of paying for it again.

### Changed

- With `sidekick.history_token_budget` set, the start of the rehydrated history now moves in steps of half the budget rather than a message at a time, so it stays put for several turns and keeps hitting the cache. The history runs between half the budget and all of it; the newest message is always kept.

## [0.2.2] - 2026-09-28

### Fixed

- The line left under a settled action card sorted before the turn that proposed it. It now sorts after it, and its `summary — outcome` separator is a translatable line (`sidekick::messages.outcome.line`).

## [0.2.1] - 2026-09-28

### Fixed

- Lean history dropped past tool calls entirely, so the model saw answers with no calls behind them and learned to answer without calling tools. Past calls are now replayed with their results; results longer than `LeanConversationStore::KEPT_RESULT_LENGTH` (400 characters) are stubbed so the prompt stays flat.

## [0.2.0] - 2026-09-26

### Added

- Translations: every user-facing string now resolves through `sidekick::messages.*`, with English shipped and publishable via `php artisan vendor:publish --tag=sidekick-translations`. Missing keys in a locale fall back to the packaged English, so partial translations are safe.
- `sidekick.history_token_budget` bounds rehydrated history by approximate token size rather than row count alone, dropping oldest-first and always keeping the newest message. Tune the estimate with `history_bytes_per_token`.
- Opt-in conversation history: `SidekickPlugin::make()->enableHistory()` adds a dropdown beside New conversation listing the ten most recent chats. Ownership and profile scope are re-proven on open.
- Opt-in tool catalog mode (`sidekick.tool_catalog`): the model is offered `ListTools` + `RunTool` instead of every tool definition, keeping the prompt flat on large panels. `above` switches automatically once a user's authorized set passes a given size. Existing tools need no changes, `RunTool` re-proves authorization rather than trusting the catalog, and confirmable actions still only ever produce a card.
- `Contracts\AlwaysOffered` marks a tool as handed to the model directly even in catalog mode; the built-in `Navigate` and `PresentActions` use it because their calls are read back from storage.
- `dependsOn()` on tools and actions: name the resources and models a class cannot work without, and deleting one withholds it from the assistant instead of fataling mid-turn.
- `sidekick:check` reports every tool and action with a missing dependency and exits non-zero for CI. It reads declared `dependsOn()` entries *and* each file's imports, so a deleted resource is caught even where nothing was declared. `--uses="App\..."` lists what depends on a class before you delete it.
- A working default limiter (`Support\MeteredUsage`, now the default `UsageLimiter` binding) enforcing allowances at two levels: the platform caps each tenant, the tenant caps its own people, and a user allowance is clamped to its tenant's so it can be stricter but never more generous. Requests and tokens, per day and per month. Inert until `sidekick.limits.enabled`. Bind `Contracts\LimitProvider` to read allowances from your own tables.
- `Contracts\UsageExemptions` (`sidekick.limits.exemptions`) marks turns as free: never refused, still logged with their tokens, never counted against an allowance — your onboarding, say. Recorded on `sidekick_runs.metered`. The default exempts nothing.
- Prompt caching for the chat agent: the tools and instructions are marked cacheable on providers that support it.
- An opt-in insights page (`SidekickPlugin::make()->enableInsights()`) with turns, tokens, failure rate, a 30-day chart and recent activity. Tenant-scoped, and shows nothing rather than everything if tenancy is on but no tenant resolved. Prompts are hidden unless `sidekick.insights.show_prompts` is set.
- `sidekick_runs` now carries `tenant_id` and a denormalised `tokens` count, so limits and insights scope and sum without joining conversations or decoding JSON.
- `SidekickPlugin::withoutChat()` registers everything except the chat panel, for a console that wants the insights page and no assistant. Previously the two render hooks were mounted unconditionally, so registering the plugin for insights alone also put a chat panel on a panel with no tenant — where a tenant-scoped `SidekickContext` has nothing to scope a conversation to.
- Per-tenant breakdown on the insights page: a usage-by-tenant table and a tenant column on recent runs, shown only on a cross-tenant panel of a multi-tenant install. `insights.tenant_model` resolves ids to customer names. Multi-tenancy is detected from your panels and can be forced either way with `tenancy.multi_tenant`.
- The insights page is customised by extending it: `SidekickPlugin::insightsPage(MyPage::class)` registers your subclass in place of the packaged one, so navigation label, icon, sort, group, sidebar visibility, slug, heading, header actions and the widget list are all reached as ordinary page members — per panel, since each panel registers its own. Both widget tables are ordinary Filament tables, and `RecentRuns::userColumn()`, `RecentRuns::tenantColumn()` and `TenantUsage::tenantColumn()` are protected seams, so rendering a person or tenant with your own component is a short subclass rather than a rewrite.
- Tenants and users both show a name rather than an id with no configuration: the tenant model is discovered from whichever panel declares tenancy, the user model from the panel's auth guard, and both read a `name` column. `insights.tenant_model` / `user_model` and the `*_label_attribute` keys override that, and a missing attribute degrades to the id rather than erroring.

### Changed

- **Breaking:** `sidekick.discover.tools` and `sidekick.discover.actions` are replaced by a single `sidekick.discover.paths`, which takes a path or an array of them and defaults to `app/Sidekick`. Roots are scanned recursively and classes are matched by contract, so tools and actions can be grouped by domain rather than by kind, and the existing `Tools/` and `Actions/` folders keep working as subfolders of that root with no changes.
- **Breaking:** `insightsNavigation()`, `insightsWidgets()` and the `insights.navigation` / `widgets` / `icon` / `slug` config keys are removed in favour of subclassing the page (see `insightsPage()` above). The config layer reached only what the package anticipated, and it overrode a subclass's own `$navigationLabel` and `$title`.
- Widened `laravel/ai` to `^0.7 || ^0.8 || ^0.9 || ^0.10 || ^0.11`. The suite passes against both ends of that range.
- Laravel 12 and 13 are both supported and both tested. CI now runs a PHP 8.3/8.4 × testbench 10/11 matrix; it previously tested PHP 8.2, which the `^8.3` floor no longer allows, and never exercised Laravel 13 at all.

### Fixed

- Every turn failed on laravel/ai 0.10 and later with `table agent_conversation_messages has no column named approval_state`. laravel/ai 0.10 made the conversation owner polymorphic (`participant_type` + `participant_id`) and added `approval_state` to messages, and its store writes those columns unconditionally — but a host whose tables came from this package's original migration never had them. A new migration adds the columns to both tables, backfills the participant pair from `user_id` using the host user model's morph class, and leaves `user_id` in place since the package's own scopes and insights read it. New conversations now record both.
- `Conversation` and `ConversationMessage` declare `$incrementing = false` explicitly. laravel/ai marks its own models with a `#[WithoutIncrementing]` attribute that does not exist in every supported framework release; where it is missing the attribute is ignored, the model auto-increments, and the assigned uuid is discarded on save.
- Tenant and user names resolved as database columns, so a `name` that is an accessor — computed from `first_name`/`last_name`, as most user models do — failed the lookup and fell back to showing the id. Labels are now read off the hydrated model, so accessors and casts apply, with Filament's `HasName` and then the key as fallbacks.
- Label lookups were primed from every run ever recorded. They now resolve only the ids on screen, one query per page.
- The per-tenant insights table failed on MySQL with `only_full_group_by`. Filament appends a primary-key tiebreaker to every sort (`order by turns desc, sidekick_runs.id desc`), which MySQL rejects against a `GROUP BY` since `id` is neither grouped nor aggregated. The aggregate now runs as a subquery, leaving the outer select ungrouped. Verified against MySQL with `ONLY_FULL_GROUP_BY` enabled — the package's own suite runs on SQLite, which does not enforce it.
- The chat log jumped back to the top on every `wire:navigate`. The panel is `@persist`ed across pages, and moving it reset its scroll; the position is now kept, and a message that lands during the swap is still followed to the end.

## [0.1.1] - 2026-08-25

### Added

- One-class read tools (`Support\ChatToolBase`) and confirmable write actions (`Support\SidekickAction`), auto-discovered from `app/Sidekick/{Tools,Actions}`, or registered at runtime through the `Sidekick` facade.
- Every action is exposed to the model as a `Propose{Name}` tool; execution happens only on the user's Confirm click, from a proposal card or a modal.
- Built-in `Navigate` and `PresentActions` tools that activate once an `ActionResolver` is bound.
- Standing per-tool system prompt guidance via `instructions()`.
- `Contracts\UsageLimiter` seam for per-user or per-tenant limits (no policy shipped).
- Artisan commands: `sidekick:install`, `sidekick:tool`, `sidekick:action`, `sidekick:scaffold`.
- CSS custom property theming surface (`--sidekick-*`), configurable Heroicons, and a custom-SVG toggle icon via the plugin API.

## [0.1.0] - 2026-07-21

### Added

- Push-aside assistant panel for Filament 5 panels: the layout gives way to the panel instead of being covered by it.
- Queued chat turns with streamed replies, live tool activity, and optional Reverb/Pusher broadcasting.
- Read tools and confirmable write actions registered through config.
- Per-panel scoping via `panels()`, per-panel assistants via profiles, and multi-tenant support — the dispatching panel and tenant are restored inside the queue worker.
- Chat attachments whose contents never reach the model — only name, type, and size — pruned with `sidekick:prune-attachments`.
