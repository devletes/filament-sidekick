<?php

namespace Devletes\Sidekick\Console;

use Devletes\Sidekick\Models\Attachment;
use Illuminate\Console\Command;

/** Deletes every upload (file and row) past the retention window; chat history keeps its own metadata and consumed files live in host storage. */
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
