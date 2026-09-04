# Filament Sidekick

[![Latest Version on Packagist](https://img.shields.io/packagist/v/devletes/filament-sidekick.svg?style=flat-square)](https://packagist.org/packages/devletes/filament-sidekick)
[![Total Downloads](https://img.shields.io/packagist/dt/devletes/filament-sidekick.svg?style=flat-square)](https://packagist.org/packages/devletes/filament-sidekick)
[![License](https://img.shields.io/packagist/l/devletes/filament-sidekick.svg?style=flat-square)](https://packagist.org/packages/devletes/filament-sidekick)
[![GitHub Stars](https://img.shields.io/github/stars/devletes/filament-sidekick?style=flat-square)](https://github.com/devletes/filament-sidekick/stargazers)

An AI assistant panel for Filament 5 **that cannot write to your database on its own.**

The model may only ever *propose* a change. It arrives as a confirmation card showing exactly what will happen, and the write runs on the user's Confirm click, under their session, claimed atomically so two tabs cannot fire it twice. Answered cards leave a system-verified outcome in the timeline, and that outcome is the only thing the model is told about what happened — so it can never claim success on its own.

That is the whole design, not a setting:

- **Reads and writes are different classes.** A `ChatTool` answers; a `SidekickAction` proposes. There is no code path from the model to `execute()`
- **Generated code is inert until you finish it.** Scaffolded actions throw rather than guess, and every file carries an `authorize()` TODO
- **Unauthorized tools are never offered**, so a tool the user cannot use is not in the prompt to be talked into
- **Multi-tenant by construction** — panel, tenant and guard are captured at dispatch and restored in the worker; a turn that cannot restore its tenant refuses to run rather than running unscoped
- **Attachment contents never reach the model** — only name, type and size

<table><tr>
<td width="50%"><img src="docs/images/confirm_card_light.png" alt="A confirmation card awaiting the user (light)"></td>
<td width="50%"><img src="docs/images/confirm_card_dark.png" alt="A confirmation card awaiting the user (dark)"></td>
</tr></table>

Around that: a panel that pushes the page aside instead of covering it, queued turns that survive navigation, per-panel assistants, usage limits with a tenant→user hierarchy, an operator insights page, translations, and a `--sidekick-*` CSS theming surface.

<table><tr>
<td width="50%"><img src="docs/images/layout_light.png" alt="The panel open beside a leave requests table (light)"></td>
<td width="50%"><img src="docs/images/layout_dark.png" alt="The panel open beside a leave requests table (dark)"></td>
</tr></table>

## Requirements

- PHP `^8.3` — every `laravel/ai` release we support requires it
- Filament `^5.0` on Laravel 12 or 13
- `laravel/ai` `^0.7` through `^0.11`, with a configured provider
- A queue worker — turns are queued jobs

## Installation

```bash
composer require devletes/filament-sidekick
```

```bash
php artisan sidekick:install
```

The installer publishes the config and runs the migrations. Register the plugin on your panel:

```php
use Devletes\Sidekick\SidekickPlugin;

return $panel->plugins([
    SidekickPlugin::make(),
]);
```

Set a `laravel/ai` provider key in `.env` and run `php artisan queue:work`. That's a working assistant — it just has no tools yet.

<table><tr>
<td width="50%"><img src="docs/images/empty_state_light.png" alt="A fresh conversation (light)"></td>
<td width="50%"><img src="docs/images/empty_state_dark.png" alt="A fresh conversation (dark)"></td>
</tr></table>

> A turn's job timeout is `sidekick.timeout` + 60s. Your queue connection's `retry_after` must be larger, or a slow turn gets redelivered mid-stream.

## Tools (reads)

```bash
php artisan sidekick:tool LeaveBalances
```

```php
namespace App\Sidekick\Tools;

use Devletes\Sidekick\Support\ChatToolBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class LeaveBalances extends ChatToolBase
{
    public function description(): string
    {
        return "The signed-in employee's leave balance per policy: entitled, taken, remaining.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function label(): string
    {
        return 'Checking your leave balance';
    }

    public function handle(Request $request): string
    {
        // $this->user is the chatting user — scope every query to them.
        return $this->respond(['balances' => LeaveBalance::for($this->user)]);
    }
}
```

Classes under `app/Sidekick` are auto-discovered — there is no registration step. The panel shows each tool's `label()` while it runs, then renders the reply as markdown.

<table><tr>
<td width="50%"><img src="docs/images/panel_light.png" alt="Tool activity and a markdown reply (light)"></td>
<td width="50%"><img src="docs/images/panel_dark.png" alt="Tool activity and a markdown reply (dark)"></td>
</tr></table>

Optional overrides:

| Method | Notes |
|---|---|
| `authorize($user)` | Unauthorized users are never offered the tool. Defaults to everyone |
| `label()` | Status line in the panel. Defaults to the class name |
| `instructions()` | System-prompt guidance added while this tool is offered. Hard-coded text only — never interpolate user or record data |
| `panels()` | Panel ids that offer this tool. Defaults to `['*']` |
| `dependsOn()` | Classes this tool cannot work without. Delete one and the tool is withheld instead of fataling; `sidekick:check` reports it |

## Actions (writes)

Writes never run from the model. An action is one class extending `Support\SidekickAction`, and Sidekick offers it to the model as a `Propose{ClassName}` tool. `prepare()` validates the payload into a confirmation card; `execute()` runs only on the user's Confirm click, under their session.

```bash
php artisan sidekick:action RequestTimeOff
```

```php
class RequestTimeOff extends SidekickAction
{
    public function description(): string
    {
        return 'Book time off for the signed-in employee.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'policy' => $schema->string()->required(),
            'from' => $schema->string()->required(),
            'to' => $schema->string(),
        ];
    }

    public function prepare(array $payload, Authenticatable $user): array
    {
        // Throw InvalidArgumentException — the message goes back to the model to self-correct.
        $days = $this->workingDays($payload['from'], $payload['to'] ?? $payload['from']);

        return [
            'payload' => [...$payload, 'days' => $days],
            'summary' => "Book {$days} days of annual leave",
            'preview' => [
                ['label' => 'Working days', 'value' => (string) $days],
                ['label' => 'Balance after', 'value' => '7 of 22 days'],
            ],
        ];
    }

    public function execute(array $payload, Authenticatable $user): string
    {
        LeaveRequest::create([...$payload, 'user_id' => $user->getAuthIdentifier()]);

        return 'Submitted for approval.';
    }
}
```

The card replaces the composer until answered. Confirming is claimed atomically, so a second tab cannot execute the same action twice.

<table><tr>
<td width="50%"><img src="docs/images/confirm_card_light.png" alt="A confirmation card awaiting the user (light)"></td>
<td width="50%"><img src="docs/images/confirm_card_dark.png" alt="A confirmation card awaiting the user (dark)"></td>
</tr></table>

Answered cards leave a system-verified outcome in the timeline. Those outcomes are the only thing the model is told about what happened, so it can never claim success on its own.

<table><tr>
<td width="50%"><img src="docs/images/action_outcome_light.png" alt="A confirmed action's outcome (light)"></td>
<td width="50%"><img src="docs/images/action_outcome_dark.png" alt="A confirmed action's outcome (dark)"></td>
</tr></table>

Overridable conventions: `type()` (defaults to the snake_cased class name), plus `authorize()`, `label()`, `instructions()`, `panels()`, and `dependsOn()` as above. An action whose dependencies are missing stops being proposable *and* confirmable.

### Inline or modal

Confirmations render inline by default. When a preview is too tall or wide for the panel, return `ConfirmationMode::Modal` and the card opens in a modal over the page instead:

```php
use Devletes\Sidekick\Enums\ConfirmationMode;

public function confirmation(): ConfirmationMode
{
    return ConfirmationMode::Modal;
}
```

<table><tr>
<td width="50%"><img src="docs/images/modal_card_light.png" alt="A modal confirmation over the page (light)"></td>
<td width="50%"><img src="docs/images/modal_card_dark.png" alt="A modal confirmation over the page (dark)"></td>
</tr></table>

The modal can't be clicked, escaped, or closed away — answering it is the only way out. A page reload still escapes it, so the panel keeps a link back in until the card is confirmed or cancelled. For the same reason modals never spring open on page load; they open only when the proposal arrives while you are looking at the panel.

<table><tr>
<td width="50%"><img src="docs/images/modal_dock_light.png" alt="The panel's link back into a modal confirmation (light)"></td>
<td width="50%"><img src="docs/images/modal_dock_dark.png" alt="The panel's link back into a modal confirmation (dark)"></td>
</tr></table>

## Registering tools & actions

Three composable sources, deduplicated:

1. **Discovery** *(default)* — any class anywhere under `app/Sidekick`. Roots and kill switch under `sidekick.discover`.
2. **Config** — class arrays in `sidekick.tools` / `sidekick.actions`. Also how a profile gets its own tool set.
3. **Runtime** — `Sidekick::tools([...])` from any service provider, for packages contributing tools. Works in web requests and queue workers alike.

### Layout is yours

The discovery root is scanned recursively and classes are matched by contract, not by folder — so group by kind, by domain, or not at all:

```
app/Sidekick/
├── Leave/
│   ├── LeaveBalances.php      # ChatTool
│   └── RequestTimeOff.php     # SidekickAction
├── Payroll/
│   └── Payslips.php
└── ResourceResolver.php       # neither — ignored
```

Anything in the tree that isn't a `ChatTool` or `ActionHandler` is skipped, so support classes and the resolver can live alongside. `sidekick.discover.paths` takes a path or an array of them; point roots at trees that hold only Sidekick classes, since discovery autoloads what it finds.

### Knowing what breaks

A tool outlives the resource it queries. `dependsOn()` names what a class cannot work without:

```php
public function dependsOn(): array
{
    return [EmployeeResource::class, Employee::class];
}
```

Delete one of those and the tool is **withheld** from the assistant — chat keeps working, minus that capability — instead of fataling mid-turn. A warning is logged once per class.

```bash
php artisan sidekick:check
```

reports every tool and action with a missing dependency and exits non-zero, so CI catches it. It checks declared `dependsOn()` entries *and* each file's own imports, so a deleted resource is reported even in tools that never declared anything.

Before deleting something, ask what depends on it:

```bash
php artisan sidekick:check --uses="App\Filament\Resources\EmployeeResource"
```

## Tool catalog

By default every authorized tool's definition rides along in every request. Past a few dozen that costs real tokens on every turn and blunts the model's choice. Catalog mode offers two tools instead — `ListTools` (names, descriptions, parameters) and `RunTool` (dispatch by name) — so the prompt stays flat whether the assistant has 6 tools or 60:

```php
// config/sidekick.php
'tool_catalog' => [
    'enabled' => false,
    'above' => 30,
],
```

`above` flips it automatically once a user's **authorized** set passes that many, which is usually better than a flat on/off — the right mode depends on how much any one person can actually see, not how much is registered.

Nothing changes in your tools. The catalog is built from the same `ChatTool` interface, so `description()`, `schema()`, `authorize()` and `panels()` all still apply, and confirmable actions are catalogued under their `Propose{Type}` name like anything else. A write reached through `RunTool` still only ever produces a card.

What does change:

- **`description()` becomes the discovery surface.** The model's first pass sees names and descriptions only, so a vague description means a tool never gets fetched. Descriptions stop being documentation and start being routing.
- **You trade a round trip for a flatter prompt**, and lose provider-side schema validation — arguments arrive as JSON that Sidekick parses and hands back correctable errors for. Below ~15 tools that trade is a loss.
- **Try scoping first.** `panels()` and profiles often keep any one assistant small enough that you never need this.

`RunTool` resolves names against the caller's own authorized set, so naming a tool the user cannot use fails the same way an unknown name does. `Navigate` and `PresentActions` stay direct — their calls are read back from storage afterwards, which only works while they are recorded under their own name. Mark your own tool `Contracts\AlwaysOffered` to keep it direct too.

Per-tool `instructions()` stay in the system prompt in both modes: guidance has to reach the model before it chooses, not after it fetches the catalog.

## Navigation

`Navigate` (redirect when the reply finishes) and `PresentActions` (buttons under a reply) ship with the package. Both wake once you point at an `ActionResolver`, which maps named targets to authorized URLs:

```php
// config/sidekick.php
'action_resolver' => \App\Sidekick\ResourceResolver::class,
```

Opt out per tool under `sidekick.builtin_tools`. Model-authored button URLs are restricted to `http(s)`.

## Scaffolding

```bash
php artisan sidekick:scaffold
```

Generates a `Search{Models}` tool per Filament resource plus one `ResourceResolver` (tenant-aware when the panel has tenancy). `--actions` also scaffolds `Create{Model}` stubs, which throw until you implement `execute()`. `--dry-run` previews.

Output is a starting point, not finished code — every file carries TODOs, notably `authorize()`. Re-runs never overwrite existing files. Scope with `sidekick.scaffold.only` and `sidekick.scaffold.ignore`.

## Context size

Two bounds keep prompts flat as a conversation grows. `sidekick.history_limit` caps how many past messages are rehydrated (10), and `lean_history` strips old tool calls and results — the model re-calls tools for fresh data instead of re-reading stale answers.

A row cap says nothing about size, though: ten pasted stack traces cost far more than ten "thanks". Set a token budget to bound that directly:

```php
'history_token_budget' => 2000,
```

Messages are then dropped oldest-first until the estimate fits, and the newest is always kept so a single huge turn shrinks history rather than erasing it. The estimate is byte-based (`history_bytes_per_token`, default 4) because no PHP tokeniser matches every provider — budget with headroom rather than to the exact context window.

## Streaming

The panel shows each tool as it is called and reveals the reply as it streams. Turns survive full page navigations — progress lives on the run row, not in component state.

<table><tr>
<td width="50%"><img src="docs/images/streaming_light.png" alt="A turn mid-stream (light)"></td>
<td width="50%"><img src="docs/images/streaming_dark.png" alt="A turn mid-stream (dark)"></td>
</tr></table>

Polling (`2s`, only while a turn is active) is the default and needs nothing installed. Set `SIDEKICK_BROADCASTING=true` to push updates over Reverb/Pusher instead; polling stays on as a safety net at `polling.while_broadcasting` (`10s`), and a broadcast failure never fails a turn.

## Conversation history

The panel opens on a fresh chat by design — a panel assistant is usually a place to ask one thing, not an archive. Turn on a history dropdown beside **New conversation** when yours is used daily:

```php
SidekickPlugin::make()->enableHistory()
```

It lists the ten most recent conversations (`sidekick.history.limit`), newest first. Ownership and profile scope are re-proven on every open, so a tampered id cannot reach another user's chat, and each profile only ever lists its own. Set `sidekick.history.enabled` to turn it on everywhere, and `->enableHistory(false)` to keep one panel out.

## Translations

Every user-facing string goes through `sidekick::messages.*`. English ships with the package; publish it to translate or reword:

```bash
php artisan vendor:publish --tag=sidekick-translations
```

That writes `lang/vendor/sidekick/en/messages.php`. Copy it to `lang/vendor/sidekick/{locale}/messages.php` for each locale you support — anything you leave out falls back to the packaged English, so a partial translation is safe to ship.

The assistant's own replies are not translated files: the system prompt tells the model to answer in the language the user writes in, so replies follow the conversation rather than the app locale.

## Panels & multi-tenancy

`panels()` scopes any tool or action to specific panels — `['admin']` keeps it out of every other assistant.

Chat turns run in a worker, where Filament has no idea which panel or tenant the message came from. Sidekick captures the serving panel, tenant, and auth guard at dispatch and restores all three, so `Filament::getTenant()` and tenant-scoped queries behave as they do in the panel. Context is always rewritten — including cleared — so a long-lived worker never inherits the previous job's tenant.

## Profiles

Different panels can run different assistants off one install. Define named override sets in `sidekick.profiles` — each entry's top-level keys replace the base config — and attach one per panel:

```php
SidekickPlugin::make()->profile('boss')
```

Conversations are stamped with their profile, so each panel keeps its own history and a queued turn runs under the assistant it started with.

## Attachments

Set `sidekick.attachments.enabled` to let users attach files in the composer and on confirm cards. Files are validated (`accept`, `max_size`, `max_files`) and stored on a private disk.

The model only ever sees metadata — name, mime, size, and an `attachment_id` — so attachments cost a handful of tokens regardless of file size. Accept `attachment_ids` in a schema and resolve with `Attachment::query()->forUser($user)`, always re-proving ownership. An action's `prepare()` may return an `upload` spec (`['required' => true, 'label' => 'Receipt']`) to put a file field on the card.

Storage is temporary: schedule `sidekick:prune-attachments` daily and it deletes every upload older than `prune_after_hours`. A confirmed action should copy what it needs into your own storage.

## Insights

An operator page — turns, tokens, failure rate, a 30-day chart and recent activity — off until a panel asks for it:

```php
SidekickPlugin::make()->enableInsights(fn ($user) => $user->is_admin)
```

Pass a closure to say who may open it. Without one it is visible to anyone who can reach the panel, which is rarely right for a page that totals other people's usage.

**It is tenant-scoped, and fails closed.** On a tenant panel it shows that tenant's runs and nothing else. If tenancy is on but no tenant resolved, it shows nothing rather than everything. Prompts are hidden by default — they are the person's own words — and `sidekick.insights.show_prompts` opts back in.

## Usage limits

Sidekick meters every turn and ships a limiter that enforces allowances at two levels, because that is how a multi-tenant product actually sells: the platform caps each tenant, and the tenant divides its cap among its people.

```php
// config/sidekick.php
'limits' => [
    'enabled' => true,
    'tenant' => ['requests_per_day' => 2000, 'tokens_per_month' => 5_000_000],
    'user' => ['requests_per_day' => 50],
],
```

Both are enforced and whichever runs out first is the one the person is told about, with the tenant reported before the user — "your organisation is out" is more useful than "you are out" when both are true. Turns refused by the limiter never count against the allowance, so being refused once cannot help refuse you again.

Once tenants need their own numbers, bind `Contracts\LimitProvider` and read them from your tables:

```php
class PlanLimits implements LimitProvider
{
    public function forTenant(int|string|null $tenant): Limits
    {
        return Limits::fromArray(Plan::for($tenant)->limits);
    }

    public function forUser(Authenticatable $user, int|string|null $tenant): Limits
    {
        return Limits::fromArray($user->assistant_limits);
    }
}
```

A user allowance is always clamped to its tenant's, so a tenant admin can be **stricter** than their plan but can never hand out more of it than the platform sold them. Fields are clamped one by one, and anything the tenant left unset inherits the platform's figure rather than staying unlimited.

For something the two levels cannot express, replace the limiter outright:

```php
class TenantPlanLimiter implements UsageLimiter
{
    public function check(Authenticatable $user, ?string $conversationId): ?string
    {
        return $this->overBudget($user)
            ? 'Daily assistant limit reached — try again tomorrow.'
            : null;
    }
}
```

```php
// config/sidekick.php
'usage_limiter' => \App\Sidekick\TenantPlanLimiter::class,
```

Return `null` to allow, or a message to deny — shown to the user verbatim, with Retry suppressed. The check runs in the panel before a turn is created and again in the job, before any tokens are spent.

## Theming

Chrome comes from Filament's own Blade components, so the panel follows your panel's theme with no configuration. Everything else is a CSS custom property — declare any `--sidekick-*` on `body` in your theme stylesheet and it wins over the defaults. No PHP API needed.

```css
body {
    --sidekick-width: 26rem;
    --sidekick-bubble-user-bg: #e0e7ff;
    --sidekick-bubble-radius: 1.25rem;
}
```

| Variable | Notes |
|---|---|
| `--sidekick-width` / `--sidekick-top` / `--sidekick-height` | Panel geometry. Width is also settable via `sidekick.panel.width` |
| `--sidekick-edge-offset` | How far the aside climbs the layout's vertical padding in full-height mode |
| `--sidekick-bg` / `--sidekick-border` / `--sidekick-text` / `--sidekick-muted` | Panel surfaces |
| `--sidekick-bubble-user-bg` / `--sidekick-bubble-assistant-bg` / `--sidekick-bubble-radius` | Message bubbles |
| `--sidekick-composer-field-min` / `--sidekick-composer-btn-px` / `--sidekick-composer-btn-min` | Composer textarea and icon buttons |

Panel state is mirrored onto `<body>` as `sidekick-open`, `sidekick-full-height`, and `sidekick-ready` — use them to move your own chrome out of the way:

```css
body.sidekick-open .my-floating-button { right: calc(var(--sidekick-width) + 1rem); }
```

Set `sidekick.panel.full_height` to run the panel edge-to-edge instead of below the topbar.

<table><tr>
<td width="50%"><img src="docs/images/closed_light.png" alt="The panel collapsed (light)"></td>
<td width="50%"><img src="docs/images/closed_dark.png" alt="The panel collapsed (dark)"></td>
</tr></table>

### Icons

Every icon is configurable under `sidekick.icons` and defaults to a Heroicon: `assistant`, `new_conversation`, `close`, `attach`, `send`, `remove`, `tool_done`.

The toggle button can also be set per panel — an icon name, or raw SVG for a custom logo:

```php
SidekickPlugin::make()->icon('heroicon-o-chat-bubble-left-right')
SidekickPlugin::make()->icon(view('icons.my-logo'))
```

## Extension points

| Seam | Purpose |
|---|---|
| `config/sidekick.php` | Agent class, assistant identity, instructions, model, queue, broadcasting, geometry |
| `Contracts\ChatTool` / `ProposableAction` | Read tools and confirmable writes — extend the base classes instead |
| `Contracts\ActionResolver` | Named navigation targets → authorized URLs |
| `Contracts\LimitProvider` | Where allowances come from — a tenant's plan, a user's settings |
| `Contracts\UsageLimiter` | Replace the shipped limiter outright |
| `Contracts\AlwaysOffered` | Keep a tool out of the catalog and directly in the prompt |
| `Support\SidekickContext` | Stamp extra columns onto conversations and scope conversation queries |
| `sidekick.jobs.run` | Subclass `RunChatTurn` for app concerns like metering |

## Need something custom?

We build production Filament panels and plugins for teams that want to ship fast without compromising on polish. If you need a custom feature, an extended variant of this package, or a fully bespoke component built for your stack, we can help.

- **Browse the rest of our Filament work:** [filament.devletes.com](https://filament.devletes.com)
- **Get in touch:** [salman@devletes.com](mailto:salman@devletes.com)

Typical engagements: new Filament plugins, custom resources/widgets/actions, theme + UX work, integrations with your existing services, and one-off tailored forks of our open-source packages.

## Credits

- [Salman Hijazi](https://www.linkedin.com/in/syedsalmanhijazi/)

## License

MIT. See [LICENSE.md](LICENSE.md).
