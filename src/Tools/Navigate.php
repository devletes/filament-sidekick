<?php

namespace Devletes\Sidekick\Tools;

use Devletes\Sidekick\Contracts\ActionResolver;
use Devletes\Sidekick\Support\ChatToolBase;
use Devletes\Sidekick\Support\RunContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/** Built-in: sends the browser to a named in-app destination when the turn completes; the panel consumes the stored URL exactly once. */
class Navigate extends ChatToolBase
{
    public function __construct(protected ActionResolver $resolver) {}

    public function description(): string
    {
        return 'Navigate the user to a page in the app when your reply is done.'
            .' Only use it when the user asked to go somewhere or clearly wants to continue there.'
            .' Prefer PresentActions (buttons the user clicks) when merely suggesting a destination.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'target' => $schema->string()
                ->enum($this->resolver->targets())
                ->description('The named destination to open.')
                ->required(),
            'record' => $schema->string()
                ->description('Identifier of a specific record at the target, when applicable.')
                ->nullable(),
        ];
    }

    public function label(): string
    {
        return 'Opening the page';
    }

    public function handle(Request $request): string
    {
        if (! $this->user) {
            return $this->respond(['error' => 'No authenticated user.']);
        }

        $target = (string) $request['target'];
        $record = isset($request['record']) ? (string) $request['record'] : null;

        $url = $this->resolver->resolve($target, $record, $this->user);

        if ($url === null) {
            return $this->respond([
                'error' => "Unknown or unauthorized target [{$target}].",
                'targets' => $this->resolver->targets(),
            ]);
        }

        app(RunContext::class)->navigateTo = $url;

        return $this->respond([
            'navigating' => true,
            'note' => 'The user will be taken there when your reply finishes.',
        ]);
    }
}
