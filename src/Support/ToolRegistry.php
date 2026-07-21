<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\ChatTool;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Ai\Tools\ToolNameResolver;

class ToolRegistry
{
    /** @return ChatTool[] */
    public function authorizedFor(?Authenticatable $user): array
    {
        if (! $user) {
            return [];
        }

        return collect(config('sidekick.tools', []))
            ->map(function (string $class) use ($user): ChatTool {
                $tool = app($class);

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
        foreach (config('sidekick.tools', []) as $class) {
            $tool = app($class);

            if (ToolNameResolver::resolve($tool) === $toolName) {
                return $tool->label();
            }
        }

        return null;
    }
}
