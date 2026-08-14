<?php

namespace Devletes\Sidekick\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Devletes\Sidekick\Support\SidekickManager tools(array $classes)
 * @method static \Devletes\Sidekick\Support\SidekickManager actions(array $classes)
 * @method static array toolClasses()
 * @method static array actionClasses()
 * @method static array toolInstances()
 * @method static array actionInstances()
 * @method static \Devletes\Sidekick\Contracts\ActionHandler|null actionHandler(string $type)
 * @method static void flush()
 *
 * @see \Devletes\Sidekick\Support\SidekickManager
 */
class Sidekick extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Devletes\Sidekick\Support\SidekickManager::class;
    }
}
