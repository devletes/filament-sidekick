<?php

namespace Devletes\Sidekick\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/** A confirmable write action; prepare()/execute() throw \InvalidArgumentException with user-readable messages. */
interface ActionHandler
{
    public function type(): string;

    /**
     * An `upload` key makes the card render a file block and gate Confirm when required.
     *
     * @return array{payload: array, summary: string, preview: array<int, array{label: string, value: string}>, upload?: array{required?: bool, label?: string, multiple?: bool}}
     */
    public function prepare(array $payload, Authenticatable $user): array;

    /** Returns a short outcome line; re-prove ownership when resolving payload `attachment_ids`. */
    public function execute(array $payload, Authenticatable $user): string;

    /**
     * A missing dependency withholds the action instead of failing at execute() time.
     *
     * @return array<int, class-string>
     */
    public function dependsOn(): array;
}
