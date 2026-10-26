<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\AlwaysOffered;
use Devletes\Sidekick\Contracts\ChatTool;
use Devletes\Sidekick\Contracts\ProvidesTurnContext;
use Devletes\Sidekick\Tools\ListTools;
use Devletes\Sidekick\Tools\RunTool;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Serializer;
use Illuminate\JsonSchema\Types\ObjectType;
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

    /**
     * Catalog mode swaps the authorized tools for ListTools + RunTool to keep the request small.
     *
     * @return ChatTool[]
     */
    public function offeredTo(?Authenticatable $user): array
    {
        $tools = $this->authorizedFor($user);

        if (! $this->usesCatalog($tools)) {
            return $tools;
        }

        // Built-ins stay direct: their calls are read back from storage by their own name.
        $direct = array_values(array_filter(
            $tools,
            fn (ChatTool $tool): bool => $tool instanceof AlwaysOffered,
        ));

        return [
            (new ListTools)->forUser($user),
            (new RunTool)->forUser($user),
            ...$direct,
        ];
    }

    /**
     * @return ChatTool[]
     */
    public function catalogueableFor(?Authenticatable $user): array
    {
        return array_values(array_filter(
            $this->authorizedFor($user),
            fn (ChatTool $tool): bool => ! $tool instanceof AlwaysOffered,
        ));
    }

    /**
     * @return array<int, array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public function catalogFor(?Authenticatable $user): array
    {
        return array_map(fn (ChatTool $tool): array => [
            'name' => ToolNameResolver::resolve($tool),
            'description' => (string) $tool->description(),
            'parameters' => $this->parametersOf($tool),
        ], $this->catalogueableFor($user));
    }

    /**
     * @param  ChatTool[]  $tools
     */
    protected function usesCatalog(array $tools): bool
    {
        if ($tools === []) {
            return false;
        }

        if (config('sidekick.tool_catalog.enabled', false)) {
            return true;
        }

        $above = config('sidekick.tool_catalog.above');

        return $above !== null && count($tools) > max(0, (int) $above);
    }

    /**
     * `required` is folded onto each property so the catalog reads as one flat block per tool.
     *
     * @return array<string, mixed>
     */
    protected function parametersOf(ChatTool $tool): array
    {
        $properties = $tool->schema(new JsonSchemaTypeFactory);

        if ($properties === []) {
            return [];
        }

        $serialized = Serializer::serialize(new ObjectType($properties));
        $required = $serialized['required'] ?? [];

        $parameters = [];

        foreach ($serialized['properties'] ?? [] as $name => $property) {
            $parameters[$name] = in_array($name, $required, true)
                ? [...$property, 'required' => true]
                : $property;
        }

        return $parameters;
    }

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

    public function turnContextFor(?Authenticatable $user): string
    {
        return collect($this->authorizedFor($user))
            ->filter(fn (ChatTool $tool): bool => $tool instanceof ProvidesTurnContext)
            ->map(fn (ProvidesTurnContext $tool): string => trim((string) $tool->turnContext()))
            ->filter()
            ->unique()
            ->join("\n\n");
    }

    /**
     * Sees through RunTool to the inner tool; all call read-back goes through here so catalog mode stays invisible.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function ranTool(?string $name, mixed $arguments = []): string
    {
        $name = (string) $name;

        if ($name !== RunTool::NAME) {
            return $name;
        }

        if (is_string($arguments)) {
            $arguments = json_decode($arguments, true) ?: [];
        }

        $inner = is_array($arguments) ? ($arguments['tool'] ?? null) : null;

        return is_string($inner) && trim($inner) !== '' ? trim($inner) : $name;
    }

    public function labelForCall(?string $name, mixed $arguments = []): ?string
    {
        return $this->labelFor(static::ranTool($name, $arguments));
    }

    public function labelFor(string $toolName): ?string
    {
        // The catalog pair is built per turn rather than registered, so it is searched alongside.
        foreach ([...$this->manager->toolInstances(), new ListTools, new RunTool] as $tool) {
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

    /** On queued turns this is the dispatch panel restored by PanelContext. */
    protected function currentPanelId(): ?string
    {
        try {
            return Filament::getCurrentPanel()?->getId();
        } catch (\Throwable) {
            return null;
        }
    }
}
