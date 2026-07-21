<?php

namespace Devletes\Sidekick\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A confirmable write action. The model can only PROPOSE (prepare); execution
 * happens exclusively from the user's Confirm click in the panel, where the
 * handler re-validates everything against live data.
 *
 * prepare() and execute() throw \InvalidArgumentException with user-readable
 * messages for any validation failure.
 */
interface ActionHandler
{
    public function type(): string;

    /**
     * Validate + normalize the payload and describe the action for the card.
     *
     * The optional `upload` key makes the confirm card render its
     * "Attachments" block (`['required' => bool, 'multiple' => bool]`).
     * Payload `attachment_ids` render as removable chips there alongside
     * files the user uploads on the card; uploaded ids are merged into the
     * payload's `attachment_ids` before execute() runs, and when `required`
     * is true the card blocks Confirm until the payload holds at least one
     * id.
     *
     * @return array{payload: array, summary: string, preview: array<int, array{label: string, value: string}>, upload?: array{required?: bool, label?: string, multiple?: bool}}
     */
    public function prepare(array $payload, Authenticatable $user): array;

    /**
     * Perform the action. Returns a short outcome line for the card.
     *
     * `$payload['attachment_ids']` (when present) references chat uploads —
     * resolve them via the Attachment model, re-proving ownership; file
     * contents never went to the model.
     */
    public function execute(array $payload, Authenticatable $user): string;
}
