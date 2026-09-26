<?php

namespace IsrarMinhas\FilamentAiVisibility\Commands;

use Illuminate\Console\Command;
use IsrarMinhas\FilamentAiVisibility\Keywords\KeywordSync;
use IsrarMinhas\FilamentAiVisibility\Keywords\SourceFailed;
use IsrarMinhas\FilamentAiVisibility\Models\Connection;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;
use Throwable;

class SyncKeywordsCommand extends Command
{
    protected $signature = 'ai-visibility:sync-keywords
        {--brand= : Only this brand ID}
        {--all : Sync every connection now, not only those due}';

    protected $description = 'Pull keywords from connected keyword sources';

    public function handle(KeywordSync $sync): int
    {
        $connections = Connection::query()->withoutGlobalScopes()
            ->where('status', '!=', Connection::DISABLED)
            ->when($this->option('brand'), fn ($query, $id) => $query->where('brand_id', $id))
            ->when(! $this->option('all'), fn ($query) => $query->where(fn ($q) => $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now())))
            ->get();

        // One failing connection never stops the others.
        foreach ($connections as $connection) {
            try {
                Tenancy::as($connection->tenant_id, function () use ($sync, $connection) {
                    $counts = $sync->sync($connection);
                    $this->components->info("{$connection->name}: {$counts['created']} new, {$counts['updated']} updated.");
                });
            } catch (SourceFailed $e) {
                $this->components->error("{$connection->name}: {$e->getMessage()}");
            } catch (Throwable $e) {
                $this->markUnexpectedFailure($sync, $connection, $e);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Marks the connection errored (retried tomorrow) with a generic message.
     */
    protected function markUnexpectedFailure(KeywordSync $sync, Connection $connection, Throwable $e): void
    {
        report($e);
        $failure = KeywordSync::unexpected($e);

        try {
            $sync->markFailed($connection, $failure);
        } catch (Throwable $markError) {
            report($markError);
        }

        $this->components->error("{$connection->name}: {$failure->getMessage()}");
    }
}
