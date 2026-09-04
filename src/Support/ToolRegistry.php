<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\AlwaysOffered;
use Devletes\Sidekick\Contracts\ChatTool;
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
     * What is actually handed to the model. Direct mode offers every authorized tool; catalog mode offers
     * ListTools + RunTool instead, so the request carries two definitions rather than sixty.
     *
     * @return ChatTool[]
     */
    public function offeredTo(?Authenticatable $user): array
    {
        $tools = $this->authorizedFor($user);

        if (! $this->usesCatalog($tools)) {
            return $tools;
        }

        // Built-ins stay direct: their calls are read back from storage afterwards, which only works while
        // they are recorded under their own name.
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
     * The tools RunTool may dispatch — everything the user is authorized for that is not offered directly.
     *
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
     * The catalog ListTools returns: name, description and parameters per tool.
     *
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
     * Whether this turn runs in catalog mode: switched on outright, or past the size where the trade pays off.
     *
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
     * A tool's parameters as JSON Schema, with `required` folded onto each property so the catalog reads as
     * one flat block per tool.
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

    /**
     * The tool a recorded call actually ran: itself in direct mode, or the inner tool when it went through
     * RunTool. Everything that reads back a recorded call — activity lines, message buttons — goes through
     * this, so catalog mode stays invisible outside the registry.
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

    /** Status line for a recorded call, seeing through a RunTool call to the tool it actually ran. */
    public function labelForCall(?string $name, mixed $arguments = []): ?string
    {
        return $this->labelFor(static::ranTool($name, $arguments));
    }

    /** Status line for a tool by its model-facing name (as recorded in run activity). */
    public function labelFor(string $toolName): ?string
    {
        // The catalog pair is constructed per turn rather than registered, so it is searched alongside.
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
