<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\ChatTool;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Ai\Tools\ToolNameResolver;

class ToolRegistry
{
    public function __construct(protected SidekickManager $manager) {}

    /** @return ChatTool[] */
    public function authorizedFor(?Authenticatable $user): array
    {
        if (! $user) {
            return [];
        }

        $panelId = $this->currentPanelId();

        return collect($this->manager->toolInstances())
            ->filter(fn (ChatTool $tool): bool => $this->offeredIn($tool->panels(), $panelId))
            ->map(fn (ChatTool $tool): ChatTool => $tool->forUser($user))
            ->filter(fn (ChatTool $tool): bool => $tool->authorize($user))
            ->values()
            ->all();
    }

    /** Standing guidance from the tools offered to this user; fragments are deduplicated and joined in stable registry order. */
    public function instructionsFor(?Authenticatable $user): string
    {
        $fragments = collect($this->authorizedFor($user))
            ->map(fn (ChatTool $tool): string => trim((string) $tool->instructions()))
            ->filter()
            ->unique()
            ->values();

        return $fragments->isEmpty()
            ? ''
            : "Tool guidance:\n".$fragments->join("\n\n");
    }

    /** Status line for a tool by its model-facing name (as recorded in run activity). */
    public function labelFor(string $toolName): ?string
    {
        foreach ($this->manager->toolInstances() as $tool) {
            if (ToolNameResolver::resolve($tool) === $toolName) {
                return $tool->label();
            }
        }

        return null;
    }

    /** @param  array<int, string>  $panels */
    protected function offeredIn(array $panels, ?string $panelId): bool
    {
        return in_array('*', $panels, true)
            || ($panelId !== null && in_array($panelId, $panels, true));
    }

    /** The serving panel (web) or the restored dispatch panel (queued turns — see PanelContext). */
    protected function currentPanelId(): ?string
    {
        try {
            return Filament::getCurrentPanel()?->getId();
        } catch (\Throwable) {
            return null;
        }
    }
}
