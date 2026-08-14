<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\ChatTool;
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

        return collect($this->manager->toolInstances())
            ->map(function (ChatTool $tool) use ($user): ChatTool {
                if ($tool instanceof ChatToolBase) {
                    $tool->forUser($user);
                }

                return $tool;
            })
            ->filter(fn (ChatTool $tool): bool => $tool->authorize($user))
            ->values()
            ->all();
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
}
