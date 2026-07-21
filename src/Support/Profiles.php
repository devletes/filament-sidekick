<?php

namespace Devletes\Sidekick\Support;

/**
 * Panel-specific assistant profiles. A profile is a named set of overrides in
 * `sidekick.profiles.{name}` whose top-level keys REPLACE the base config for
 * the duration of the request (identity, instructions, tools, actions, model,
 * attachments — anything). The active panel applies its profile via
 * SidekickPlugin::profile(); queued turns re-apply it from the profile stamped
 * on the conversation, so a chat always runs under the profile it started in.
 *
 * Registered as a singleton. The base config is snapshotted on first use and
 * every apply() starts from it — long-lived queue workers switch profiles
 * between jobs without bleed.
 */
class Profiles
{
    protected ?array $base = null;

    protected ?string $current = null;

    public function apply(?string $name): void
    {
        $this->base ??= (array) config('sidekick');

        $config = $this->base;
        $overrides = $name !== null ? ($config['profiles'][$name] ?? null) : null;

        foreach ($overrides ?? [] as $key => $value) {
            $config[$key] = $value;
        }

        config(['sidekick' => $config]);

        // Unknown names fall back to the base profile — and stamp as base, so
        // conversations never reference a profile that doesn't resolve.
        $this->current = $overrides !== null ? $name : null;
    }

    /** The active profile name (null = base config). */
    public function current(): ?string
    {
        return $this->current;
    }
}
