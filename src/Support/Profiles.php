<?php

namespace Devletes\Sidekick\Support;

/** Named `sidekick.profiles.{name}` config overrides; every apply() starts from a base-config snapshot, so long-lived workers switch profiles without bleed. */
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

        // Unknown names fall back to base — and stamp as base, so conversations never reference a profile that doesn't resolve.
        $this->current = $overrides !== null ? $name : null;
    }

    /** The active profile name (null = base config). */
    public function current(): ?string
    {
        return $this->current;
    }
}
