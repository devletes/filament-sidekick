<?php

namespace Devletes\Sidekick\Assets;

use Filament\Support\Assets\Css;

/** Filament versions by package version, which never moves for a path-repo symlink, so version by content. */
class HashedCss extends Css
{
    public function getVersion(): string
    {
        $path = $this->getPath();

        if (filled($path) && ! $this->isRemote() && is_file($path)) {
            return md5_file($path) ?: parent::getVersion();
        }

        return parent::getVersion();
    }
}
