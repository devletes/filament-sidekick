<?php

namespace Devletes\Sidekick\Tools;

use Devletes\Sidekick\Contracts\AlwaysOffered;
use Devletes\Sidekick\Contracts\ChatTool;
use Devletes\Sidekick\Support\ChatToolBase;
use Devletes\Sidekick\Support\ToolRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Throwable;

/** Catalog mode: dispatches a tool the model found via ListTools, re-proving authorization rather than trusting the name. */
class RunTool extends ChatToolBase implements AlwaysOffered
{
    public const NAME = 'RunTool';

    public function description(): string
    {
        return 'Run one of the tools returned by ListTools.'
            .' Pass the tool\'s exact name and its arguments as a JSON object.'
            .' Call ListTools first if you have not already — never guess a tool name.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tool' => $schema->string()
                ->description('Exact tool name as returned by ListTools.')
                ->required(),
            'arguments' => $schema->string()
                ->description('The tool\'s arguments as a JSON object, e.g. {"query":"ann"}. Pass {} when it takes none.')
                ->required(),
        ];
    }

    public function label(): string
    {
        // Only ever seen if the inner tool cannot be resolved; the panel normally shows the inner tool's own label.
        return __('sidekick::messages.activity.running');
    }

    public function handle(Request $request): string
    {
        if (! $this->user) {
            return $this->respond(['error' => 'No authenticated user.']);
        }

        $name = trim((string) $request['tool']);
        $tool = $this->resolve($name);

        if (! $tool instanceof ChatTool) {
            return $this->respond([
                'error' => "Unknown or unavailable tool [{$name}].",
                'available' => array_column(app(ToolRegistry::class)->catalogFor($this->user), 'name'),
            ]);
        }

        $arguments = $this->decode($request['arguments'] ?? null);

        if (! is_array($arguments)) {
            return $this->respond([
                'error' => 'The `arguments` value must be a JSON object, e.g. {"query":"ann"}.',
                'tool' => $name,
            ]);
        }

        try {
            return (string) $tool->handle(new Request($arguments));
        } catch (InvalidArgumentException $e) {
            // Author-thrown and user-readable: hand it back so the model can correct itself.
            return $this->respond(['error' => $e->getMessage(), 'tool' => $name]);
        } catch (Throwable $e) {
            report($e);

            return $this->respond(['error' => "The tool [{$name}] failed.", 'tool' => $name]);
        }
    }

    /**
     * Resolve by name from the caller's own authorized set. The model can name anything, so the catalog is
     * never the authority — this lookup re-applies panels() and authorize() for the acting user.
     */
    protected function resolve(string $name): ?ChatTool
    {
        foreach (app(ToolRegistry::class)->catalogueableFor($this->user) as $tool) {
            if (ToolNameResolver::resolve($tool) === $name) {
                return $tool;
            }
        }

        return null;
    }

    /** Providers send the argument bag as a JSON string, but some hand back a decoded object; accept both. */
    protected function decode(mixed $arguments): ?array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        $raw = trim((string) $arguments);

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
