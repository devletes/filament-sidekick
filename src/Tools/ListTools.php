<?php

namespace Devletes\Sidekick\Tools;

use Devletes\Sidekick\Contracts\AlwaysOffered;
use Devletes\Sidekick\Support\ChatToolBase;
use Devletes\Sidekick\Support\ToolRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/** Catalog mode: the model asks what it can do, instead of carrying every tool definition in every request. */
class ListTools extends ChatToolBase implements AlwaysOffered
{
    public function description(): string
    {
        return 'List the tools you can run, with their descriptions and parameters.'
            .' Call this once before your first RunTool call in a conversation turn,'
            .' then use RunTool with the exact name of the tool you need.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function label(): string
    {
        return __('sidekick::messages.activity.catalog');
    }

    public function handle(Request $request): string
    {
        if (! $this->user) {
            return $this->respond(['error' => 'No authenticated user.']);
        }

        $tools = app(ToolRegistry::class)->catalogFor($this->user);

        if ($tools === []) {
            return $this->respond(['tools' => [], 'note' => 'No tools are available to you.']);
        }

        // Cast at the JSON boundary so a tool with no parameters reads as {} rather than an empty list.
        return $this->respond(['tools' => array_map(
            fn (array $tool): array => [...$tool, 'parameters' => (object) $tool['parameters']],
            $tools,
        )]);
    }
}
