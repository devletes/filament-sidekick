<?php

namespace Devletes\Sidekick\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'sidekick:tool', aliases: ['make:sidekick-tool'])]
class MakeToolCommand extends GeneratorCommand
{
    protected $name = 'sidekick:tool';

    protected $description = 'Create a new Sidekick chat tool (auto-discovered, no registration needed)';

    protected $type = 'Sidekick tool';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/tool.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Sidekick\Tools';
    }

    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the tool if it already exists'],
        ];
    }
}
