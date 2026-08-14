<?php

namespace Devletes\Sidekick\Tools;

use Devletes\Sidekick\Contracts\ActionResolver;
use Devletes\Sidekick\Support\ChatToolBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/**
 * Built-in: renders clickable buttons under the assistant's message. The
 * panel derives the buttons from this tool call as persisted on the message
 * (re-resolving and re-authorizing targets at render time — see
 * ChatPanel::messageActions), so handle() only validates and reports back.
 *
 * The class name is the wire contract: ChatPanel matches tool calls named
 * "PresentActions".
 */
class PresentActions extends ChatToolBase
{
    public function __construct(protected ActionResolver $resolver) {}

    public function description(): string
    {
        return 'Show up to 4 clickable buttons under your reply so the user can jump to a page.'
            .' Each button needs a short label plus either a named in-app target (preferred) or an absolute URL.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'actions' => $schema->array()
                ->items($schema->object([
                    'label' => $schema->string()
                        ->description('Short button label, e.g. "Open expenses".')
                        ->required(),
                    'target' => $schema->string()
                        ->enum($this->resolver->targets())
                        ->description('Named in-app destination.')
                        ->nullable(),
                    'record' => $schema->string()
                        ->description('Identifier of a specific record at the target, when applicable.')
                        ->nullable(),
                    'url' => $schema->string()
                        ->description('Absolute URL — only when no named target fits.')
                        ->nullable(),
                ]))
                ->description('The buttons to render, in order.')
                ->required(),
        ];
    }

    public function label(): string
    {
        return 'Preparing shortcuts';
    }

    public function handle(Request $request): string
    {
        if (! $this->user) {
            return $this->respond(['error' => 'No authenticated user.']);
        }

        $presented = [];
        $rejected = [];

        foreach (array_slice((array) $request['actions'], 0, 4) as $action) {
            $label = trim((string) ($action['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            if (filled($action['url'] ?? null)) {
                $presented[] = $label;
            } elseif (filled($action['target'] ?? null)
                && $this->resolver->resolve((string) $action['target'], $action['record'] ?? null, $this->user) !== null) {
                $presented[] = $label;
            } else {
                $rejected[] = $label;
            }
        }

        if ($presented === []) {
            return $this->respond([
                'error' => 'No valid actions to present.',
                'targets' => $this->resolver->targets(),
            ]);
        }

        return $this->respond([
            'presented' => $presented,
            ...($rejected !== [] ? ['not_shown' => $rejected] : []),
            'note' => 'The buttons render under this reply — no need to repeat the links as text.',
        ]);
    }
}
