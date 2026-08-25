# Changelog

All notable changes to `devletes/filament-sidekick` will be documented in this file.

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
