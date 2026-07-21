# Filament Sidekick

A collapsible AI assistant panel for Filament. The panel slides in from the right and **pushes the layout aside** (a real flex sibling of the page content — no overlay), runs each chat turn as a queued job backed by [laravel/ai](https://github.com/laravel/ai), and updates the UI via broadcasting (Reverb/Pusher) with polling as the always-on fallback.

## How it fits together

- `SidekickPlugin` registers two render hooks on a panel: the toggle button (right of the user menu) and the panel itself (`LAYOUT_END`, inside `.fi-layout`). Filament v5 renders the topbar inside `.fi-main-ctn`, so the whole column — topbar included — shrinks naturally when the panel opens.
- `ChatPanel` (Livewire) renders conversation history from the database and dispatches a `Run` per user message.
- `RunChatTurn` (queued) streams the agent server-side, flushing text chunks and tool events onto the run row as they arrive. The panel re-renders on every nudge — an Echo event when broadcasting is on, `wire:poll` otherwise — so a turn survives full page navigations.
- Conversation persistence is laravel/ai's own conversation store; the package pre-creates conversation rows so hosts can stamp extra columns (see `SidekickContext`).

## Host integration points

- **Config** (`config/sidekick.php`): agent class, assistant identity, instructions, model, queue, broadcasting, panel geometry.
- **`SidekickContext` binding**: stamp extra columns onto conversations (e.g. `tenant_id`) and scope conversation queries per context.
- **`sidekick.jobs.run`**: subclass `RunChatTurn` to add app concerns (tenant context, usage metering).
- **Tools**: implement `Contracts\ChatTool` (extends laravel/ai's `Tool` with `authorize()`, `label()`, `needsConfirmation()`) and list the classes in `sidekick.tools`.
- **Actions**: implement `Contracts\ActionHandler` (`prepare()` proposes a card, `execute()` runs on the user's Confirm click) and list the classes in `sidekick.actions`.
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

## Registering

```php
use Devletes\Sidekick\SidekickPlugin;

$panel->plugins([
    SidekickPlugin::make(),
]);
```

The service provider auto-loads migrations (conversations, messages, runs) and registers a private broadcast channel `sidekick.user.{id}` (owner-only). Run updates broadcast per **user**, not per conversation — the panel mounts on a fresh conversation, so the user id is the only stable subscription key. The subscription itself lives in `sidekick.js` (waits for `window.Echo` / Filament's `EchoLoaded` event, then forwards each event as a `sidekick-echo-nudge` Livewire dispatch) because Livewire's native `echo-` listeners silently no-op when Echo loads late. While broadcasting is enabled the poll interval relaxes to `polling.while_broadcasting` (default `10s`); echo nudges carry the stream and polling stays on as the safety net.
