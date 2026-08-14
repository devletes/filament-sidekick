<?php

namespace Devletes\Sidekick\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * An ActionHandler the model can propose directly: Sidekick auto-exposes one
 * proposal tool per registered ProposableAction (see ActionProposalTool), so
 * one class carries the whole write path — the model calls the generated tool
 * with a payload matching schema(), prepare() validates it into a confirm
 * card, and execute() runs on the user's Confirm click.
 *
 * Extend Support\SidekickAction rather than implementing this by hand.
 */
interface ProposableAction extends ActionHandler
{
    /** Tells the model what this action does and when to propose it. */
    public function description(): string;

    /**
     * The payload the model must supply, as named schema types
     * (e.g. ['amount' => $schema->number()->required()]).
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array;

    /** Users who may not propose this action never see its tool. */
    public function authorize(Authenticatable $user): bool;

    /** Status line shown while the proposal is being prepared. */
    public function label(): string;
}
