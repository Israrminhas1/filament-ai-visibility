<?php

namespace IsrarMinhas\FilamentAiVisibility\Commands;

use Illuminate\Console\Command;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\ResultMention;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

/**
 * Removes the full text of old answers (Settings → Safety & data → "Keep
 * full answer text for"). Everything reports use is kept: mentions,
 * positions, sources, sentiment and costs. Runs daily from the scheduler.
 */
class PruneAnswersCommand extends Command
{
    protected $signature = 'ai-visibility:prune {--dry-run : Only count what would be removed}';

    protected $description = 'Remove the text of AI Visibility answers older than the configured number of days';

    public function handle(): int
    {
        $tenants = Brand::query()->withoutGlobalScopes()->distinct()->pluck('tenant_id');

        foreach ($tenants as $tenantId) {
            Tenancy::as($tenantId, fn () => $this->pruneTenant($tenantId));
        }

        return self::SUCCESS;
    }

    protected function pruneTenant(int | string | null $tenantId): void
    {
        $days = (int) app(Settings::class)->get('data.keep_answers_days', 365);
        $label = $tenantId === null ? 'Answers' : "Tenant {$tenantId}";

        // 0 or empty keeps answers forever.
        if ($days <= 0) {
            $this->components->twoColumnDetail($label, 'kept (no limit)');

            return;
        }

        $old = Result::query()
            ->whereNotNull('answer')
            ->where('ran_at', '<', now()->subDays($days));

        if ($this->option('dry-run')) {
            $this->components->twoColumnDetail($label, $old->count() . " answers older than {$days} days would be removed");

            return;
        }

        $removed = 0;

        $old->select('id')->chunkById(500, function ($results) use (&$removed) {
            $ids = $results->modelKeys();

            // Snippets quote the answer, so they go too; who was mentioned and where stays.
            ResultMention::query()->whereIn('result_id', $ids)->update(['snippet' => null]);
            $removed += Result::query()->whereKey($ids)->update(['answer' => null]);
        });

        $this->components->twoColumnDetail($label, "{$removed} answers older than {$days} days removed");
    }
}
