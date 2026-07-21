<?php

namespace Devletes\Sidekick\Assets;

use Filament\Support\Assets\Css;

/**
 * Filament versions asset URLs by the composer package version, which never
 * moves for a path-repo symlink (and only moves per release otherwise) — so
 * browsers cache stale CSS across edits. Version by content instead: every
 * change busts the cache, an unchanged file keeps it.
 */
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
