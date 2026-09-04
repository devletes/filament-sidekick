<?php

namespace Devletes\Sidekick\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/** A confirmable write action: the model only ever proposes; execute() runs solely from the user's Confirm click. prepare()/execute() throw \InvalidArgumentException with user-readable messages. */
interface ActionHandler
{
    public function type(): string;

    /**
     * Validate + normalize the payload and describe the action for the card; an `upload` key makes the card render a file block and gate Confirm when required.
     *
     * @return array{payload: array, summary: string, preview: array<int, array{label: string, value: string}>, upload?: array{required?: bool, label?: string, multiple?: bool}}
     */
    public function prepare(array $payload, Authenticatable $user): array;

    /** Perform the action and return a short outcome line; resolve any payload `attachment_ids` via the Attachment model, re-proving ownership. */
    public function execute(array $payload, Authenticatable $user): string;

    /**
     * Classes this action cannot work without. Delete one and the action stops being offered — and stops being
     * confirmable — rather than failing at execute() time; `sidekick:check` names the file. See ChatTool::dependsOn().
     *
     * @return array<int, class-string>
     */
    public function dependsOn(): array;
}
