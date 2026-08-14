# Filament Sidekick

A collapsible AI assistant panel for Filament — and a small framework for teaching it your app. The panel slides in from the right and **pushes the layout aside** (a real flex sibling of the page content — no overlay), runs each chat turn as a queued job backed by [laravel/ai](https://github.com/laravel/ai), and updates the UI via broadcasting (Reverb/Pusher) with polling as the always-on fallback.

## Quick start

```bash
composer require devletes/filament-sidekick
php artisan sidekick:install
```

Register the plugin on your panel:

```php
use Devletes\Sidekick\SidekickPlugin;

$panel->plugins([
    SidekickPlugin::make(),
]);
```

Point laravel/ai at a provider (e.g. `ANTHROPIC_API_KEY` in `.env`), run a queue worker, and the assistant is live. Then teach it your app:

```bash
php artisan sidekick:tool SearchProjects     # a read tool
php artisan sidekick:action CreateTask       # a confirmable write action
```

Generated classes land in `app/Sidekick/Tools` and `app/Sidekick/Actions`, which are **auto-discovered — no registration step**. Fill in `description()`, `schema()`, and the body, and the model can use them on the next message.

## Creating tools (reads)

A tool is one class: tell the model what it does, declare its arguments, do the work. Extend `Support\ChatToolBase`:

```php
class SearchProjects extends ChatToolBase
{
    public function description(): string
    {
        return 'Search the user\'s projects by name.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['query' => $schema->string()->required()];
    }

    public function label(): string
    {
        return 'Searching your projects';
    }

    public function handle(Request $request): string
    {
        return $this->respond(
            Project::query()
                ->whereBelongsTo($this->user)          // always scope to the chatting user
                ->where('name', 'like', '%'.$request['query'].'%')
                ->limit(5)
                ->get(['id', 'name']),
        );
    }
}
```

Optional overrides: `authorize($user)` (unauthorized users are never offered the tool) and `label()` (the status line in the panel; defaults to the class name).

## Creating actions (writes)

Writes never run from the model. An action is also one class — extend `Support\SidekickAction` — and Sidekick automatically offers it to the model as a `Propose{ClassName}` tool. The model can only **propose**: `prepare()` validates the payload into a confirmation card in the panel, and `execute()` runs exclusively when the user clicks Confirm, under their real session, re-validating against live data.

```php
class CreateTask extends SidekickAction
{
    public function description(): string
    {
        return 'Create a task in a project the user owns.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
        ];
    }

    public function prepare(array $payload, Authenticatable $user): array
    {
        $project = Project::query()->whereBelongsTo($user)->find($payload['project_id'] ?? null)
            ?? throw new InvalidArgumentException('That project does not exist.');

        return [
            'payload' => ['project_id' => $project->id, 'title' => trim($payload['title'])],
            'summary' => "Create \"{$payload['title']}\" in {$project->name}",
            'preview' => [
                ['label' => 'Project', 'value' => $project->name],
                ['label' => 'Title', 'value' => $payload['title']],
            ],
        ];
    }

    public function execute(array $payload, Authenticatable $user): string
    {
        Project::query()->whereBelongsTo($user)->findOrFail($payload['project_id'])
            ->tasks()->create(['title' => $payload['title']]);

        return 'Task created.';
    }
}
```

Conventions you can override: `type()` (defaults to the snake_cased class name), `authorize($user)`, `label()`. Throw `InvalidArgumentException` with user-readable messages anywhere — on propose it goes back to the model to self-correct; on execute it lands on the card.

## Registering tools & actions

Three ways, all composable — everything is deduplicated:

1. **Discovery (default)**: any non-abstract class in `app/Sidekick/Tools` / `app/Sidekick/Actions` implementing the right contract. Paths and kill switch under `sidekick.discover`.
2. **Config**: class-name arrays in `sidekick.tools` / `sidekick.actions` — also how a profile gets its own tool set, since profiles override config keys.
3. **Runtime**: `Sidekick::tools([...])` / `Sidekick::actions([...])` (the `Devletes\Sidekick\Facades\Sidekick` facade) from any service provider — how other packages contribute tools, and the registration that works identically in web requests and queue workers.

## Built-in tools

`Navigate` (redirect the user when the reply finishes) and `PresentActions` (clickable buttons under a reply) ship with the package and wake automatically once you bind an `ActionResolver` — the seam that turns named targets into authorized URLs:

```php
$this->app->singleton(\Devletes\Sidekick\Contracts\ActionResolver::class, AppActionResolver::class);
```

Per-tool opt-out under `sidekick.builtin_tools`.

## How it fits together

- `SidekickPlugin` registers two render hooks on a panel: the toggle button (right of the user menu) and the panel itself (`LAYOUT_END`, inside `.fi-layout`). Filament v5 renders the topbar inside `.fi-main-ctn`, so the whole column — topbar included — shrinks naturally when the panel opens.
- `ChatPanel` (Livewire) renders conversation history from the database and dispatches a `Run` per user message.
- `RunChatTurn` (queued) streams the agent server-side, flushing text chunks and tool events onto the run row as they arrive. The panel re-renders on every nudge — an Echo event when broadcasting is on, `wire:poll` otherwise — so a turn survives full page navigations.
- Conversation persistence is laravel/ai's own conversation store; the package pre-creates conversation rows so hosts can stamp extra columns (see `SidekickContext`).

## Host integration points

- **Config** (`config/sidekick.php`): agent class, assistant identity, instructions, model, queue, broadcasting, panel geometry.
- **`SidekickContext` binding**: stamp extra columns onto conversations (e.g. `tenant_id`) and scope conversation queries per context.
- **`sidekick.jobs.run`**: subclass `RunChatTurn` to add app concerns (tenant context, usage metering).
- **Tools & actions**: see the authoring sections above. The underlying contracts are `Contracts\ChatTool` (laravel/ai's `Tool` plus `authorize()` + `label()`) and `Contracts\ActionHandler` / `Contracts\ProposableAction`; the base classes exist so you rarely touch them directly.
- **CSS variables**: `--sidekick-width`, `--sidekick-top`, `--sidekick-height`, `--sidekick-edge-offset` (how far the aside climbs the layout's vertical padding on both edges in full-height mode) — plus `panel.full_height` to run the panel flush edge-to-edge (top 0 → 100dvh) instead of below the topbar line. Composer geometry: `--sidekick-composer-btn-px` / `--sidekick-composer-btn-min` (side padding + height of the icon-only attach/send buttons; the defaults pin a 2.25rem circle even against high-specificity theme button rules) and `--sidekick-composer-field-min` (the textarea's resting height — set it to your two-button stack).
- **Body-class hooks**: panel state is mirrored onto `<body>` as `sidekick-open`, `sidekick-full-height`, and `sidekick-ready` — use them to move host chrome (floating buttons, toasts) out of the panel's way, e.g. `body.sidekick-open .my-fab { right: calc(var(--sidekick-width) + 1rem); }`.

## Attachments (files never reach the LLM)

Enable `sidekick.attachments` to let users attach files from the composer (and on confirm cards). Files are validated against host rules (`accept` mime patterns, `max_size` KB, `max_files`), stored on a **private** disk (`disk` — never a public one; the package never serves these files), and recorded as `Attachment` rows.

**The model only ever sees metadata** — name, mime, size, and an `attachment_id` — appended to the prompt as a bracketed note (with an instruction to ask what to do when the user gave no context). File contents are never uploaded to the provider, so attachments cost a handful of tokens regardless of file size.

Wiring files into real work happens host-side by id:

- A tool/action can accept `attachment_ids` in its schema; the model passes ids from the note. Resolve them via `Attachment::query()->forUser($user)` — always re-prove ownership.
- `ActionHandler::prepare()` may return an `upload` spec — `['required' => bool, 'label' => 'Receipt', 'multiple' => bool]` — which renders a file field on the confirm card. Ids uploaded there are merged into the payload's `attachment_ids` before `execute()`; `required` blocks Confirm until a file is attached (unless the payload already references one from chat).

**The attachment area is temporary.** Chat history records only metadata (each message row carries name/type/size, which is what the chips and the model's context notes read); a confirmed action copies the file into the host's own storage (e.g. a media collection on the created record); and `sidekick:prune-attachments` (schedule it daily) deletes **every** upload — file and row — older than `prune_after_hours` (default 24h, kept comfortably above the confirm-card expiry). A file that was never consumed by then is simply irrelevant. Referencing a pruned `attachment_id` in a handler fails with a normal validation message ("ask the user to re-attach").

## Profiles (per-panel assistants)

Different panels can run different assistants off one install. Define named override sets in `sidekick.profiles` — each entry's top-level keys (identity, `instructions`, `tools`, `actions`, `model`, `attachments`, …) replace the base config wholesale — and attach one per panel:

```php
$panel->plugins([
    SidekickPlugin::make()->profile('boss'),
]);
```

Panels without a profile run the base config. Conversations are stamped with their profile, so each panel's assistant keeps its own history, and the queued turn re-applies the profile from the conversation — a chat always runs under the assistant it started with, whichever worker picks it up.

## Under the hood

The service provider auto-loads migrations (conversations, messages, runs) and registers a private broadcast channel `sidekick.user.{id}` (owner-only). Run updates broadcast per **user**, not per conversation — the panel mounts on a fresh conversation, so the user id is the only stable subscription key. The subscription itself lives in `sidekick.js` (waits for `window.Echo` / Filament's `EchoLoaded` event, then forwards each event as a `sidekick-echo-nudge` Livewire dispatch) because Livewire's native `echo-` listeners silently no-op when Echo loads late. While broadcasting is enabled the poll interval relaxes to `polling.while_broadcasting` (default `10s`); echo nudges carry the stream and polling stays on as the safety net.
