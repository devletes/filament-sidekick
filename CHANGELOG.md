# Changelog

All notable changes to `devletes/filament-sidekick` will be documented in this file.

## [Unreleased]

### Added

- Translations: every user-facing string now resolves through `sidekick::messages.*`, with English shipped and publishable via `php artisan vendor:publish --tag=sidekick-translations`. Missing keys in a locale fall back to the packaged English, so partial translations are safe.
- `sidekick.history_token_budget` bounds rehydrated history by approximate token size rather than row count alone, dropping oldest-first and always keeping the newest message. Tune the estimate with `history_bytes_per_token`.
- Opt-in conversation history: `SidekickPlugin::make()->enableHistory()` adds a dropdown beside New conversation listing the ten most recent chats. Ownership and profile scope are re-proven on open.
- Opt-in tool catalog mode (`sidekick.tool_catalog`): the model is offered `ListTools` + `RunTool` instead of every tool definition, keeping the prompt flat on large panels. `above` switches automatically once a user's authorized set passes a given size. Existing tools need no changes, `RunTool` re-proves authorization rather than trusting the catalog, and confirmable actions still only ever produce a card.
- `Contracts\AlwaysOffered` marks a tool as handed to the model directly even in catalog mode; the built-in `Navigate` and `PresentActions` use it because their calls are read back from storage.
- A working default limiter (`Support\MeteredUsage`, now the default `UsageLimiter` binding) enforcing allowances at two levels: the platform caps each tenant, the tenant caps its own people, and a user allowance is clamped to its tenant's so it can be stricter but never more generous. Requests and tokens, per day and per month. Inert until `sidekick.limits.enabled`. Bind `Contracts\LimitProvider` to read allowances from your own tables.
- An opt-in insights page (`SidekickPlugin::make()->enableInsights()`) with turns, tokens, failure rate, a 30-day chart and recent activity. Tenant-scoped, and shows nothing rather than everything if tenancy is on but no tenant resolved. Prompts are hidden unless `sidekick.insights.show_prompts` is set.
- `sidekick_runs` now carries `tenant_id` and a denormalised `tokens` count, so limits and insights scope and sum without joining conversations or decoding JSON.

### Fixed

- `Conversation` and `ConversationMessage` declare `$incrementing = false` explicitly. laravel/ai marks its own models with a `#[WithoutIncrementing]` attribute that does not exist in every supported framework release; where it is missing the attribute is ignored, the model auto-increments, and the assigned uuid is discarded on save.

### Changed

- Widened `laravel/ai` to `^0.7 || ^0.8 || ^0.9 || ^0.10 || ^0.11`. The suite passes against both ends of that range.

- `dependsOn()` on tools and actions: name the resources and models a class cannot work without, and deleting one withholds it from the assistant instead of fataling mid-turn.
- `sidekick:check` reports every tool and action with a missing dependency and exits non-zero for CI. It reads declared `dependsOn()` entries *and* each file's imports, so a deleted resource is caught even where nothing was declared. `--uses="App\..."` lists what depends on a class before you delete it.
- Discovery roots are now scanned recursively, so tools and actions can be grouped by domain rather than by kind — any layout under `app/Sidekick` works.

### Changed

- **Breaking:** `sidekick.discover.tools` and `sidekick.discover.actions` are replaced by a single `sidekick.discover.paths`, which takes a path or an array of them and defaults to `app/Sidekick`. Classes are matched by contract, so the existing `Tools/` and `Actions/` folders keep working as subfolders of that root with no changes.

## [1.0.0] - 2026-08-17

### Added

- Push-aside assistant panel for Filament 5 panels: the layout gives way to the panel instead of being covered by it.
- Queued chat turns with streamed replies, live tool activity, and optional Reverb/Pusher broadcasting.
- One-class read tools (`Support\ChatToolBase`) and confirmable write actions (`Support\SidekickAction`), auto-discovered from `app/Sidekick/{Tools,Actions}`.
- Every action is exposed to the model as a `Propose{Name}` tool; execution happens only on the user's Confirm click.
- Built-in `Navigate` and `PresentActions` tools that activate once an `ActionResolver` is bound.
- Per-panel scoping via `panels()`, per-panel assistants via profiles, and multi-tenant support — the dispatching panel and tenant are restored inside the queue worker.
- Standing per-tool system prompt guidance via `instructions()`.
- `Contracts\UsageLimiter` seam for per-user or per-tenant limits (no policy shipped).
- Chat attachments whose contents never reach the model — only name, type, and size.
- Artisan commands: `sidekick:install`, `sidekick:tool`, `sidekick:action`, `sidekick:scaffold`.
- CSS custom property theming surface (`--sidekick-*`), configurable Heroicons, and a custom-SVG toggle icon via the plugin API.
