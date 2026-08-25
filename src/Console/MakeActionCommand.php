<?php

namespace Devletes\Sidekick\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

class MakeActionCommand extends GeneratorCommand
{
    protected $name = 'sidekick:action';

    protected $description = 'Create a new Sidekick confirmable action (auto-discovered, no registration needed)';

    protected $type = 'Sidekick action';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/action.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Sidekick\Actions';
    }

    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the action if it already exists'],
        ];
    }
}
