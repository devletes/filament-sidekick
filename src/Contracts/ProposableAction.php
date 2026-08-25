<?php

namespace Devletes\Sidekick\Contracts;

use Devletes\Sidekick\Enums\ConfirmationMode;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/** An ActionHandler auto-exposed to the model as a proposal tool; extend Support\SidekickAction rather than implementing this by hand. */
interface ProposableAction extends ActionHandler
{
    /** Tells the model what this action does and when to propose it. */
    public function description(): string;

    /**
     * The payload the model must supply, as named schema types (e.g. ['amount' => $schema->number()->required()]).
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array;

    /** Users who may not propose this action never see its tool. */
    public function authorize(Authenticatable $user): bool;

    /** Status line shown while the proposal is being prepared. */
    public function label(): string;

    /** Where the confirmation card is presented: inline in the panel, or in a non-dismissible modal. */
    public function confirmation(): ConfirmationMode;

    /** Standing system prompt guidance while this action's proposal tool is offered (see ChatTool::instructions()). */
    public function instructions(): ?string;

    /**
     * Panel ids whose assistant offers this action's proposal tool; ['*'] = every panel (see ChatTool::panels()).
     *
     * @return array<int, string>
     */
    public function panels(): array;
}
