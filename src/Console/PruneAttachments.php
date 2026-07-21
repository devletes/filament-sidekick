<?php

namespace Devletes\Sidekick\Console;

use Devletes\Sidekick\Models\Attachment;
use Illuminate\Console\Command;

/**
 * The attachment area is temporary: every upload (sent or not) is deleted —
 * file and row — once older than the retention window. Chat history is
 * unaffected (messages carry their own name/size metadata), and files a
 * confirmed action consumed already live in the host's own storage.
 */
class PruneAttachments extends Command
{
    protected $signature = 'sidekick:prune-attachments {--hours= : Override sidekick.attachments.prune_after_hours}';

    protected $description = 'Delete Sidekick chat uploads older than the retention window';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?? config('sidekick.attachments.prune_after_hours', 24));
        $hours = max(1, $hours);

        $pruned = 0;

        Attachment::query()
            ->where('created_at', '<', now()->subHours($hours))
            ->orderBy('id')
            ->chunkById(100, function ($attachments) use (&$pruned): void {
                foreach ($attachments as $attachment) {
                    $attachment->deleteWithFile();
                    $pruned++;
                }
            });

        $this->info("Pruned {$pruned} attachment(s) older than {$hours}h.");

        return self::SUCCESS;
    }
}
