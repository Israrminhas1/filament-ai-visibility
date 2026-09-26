<?php

namespace IsrarMinhas\FilamentAiVisibility\Keywords;

use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Enums\KeywordSource as KeywordSourceEnum;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ConnectionResource;
use IsrarMinhas\FilamentAiVisibility\Models\Connection;
use IsrarMinhas\FilamentAiVisibility\Models\Keyword;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\Alert;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\AlertNotifier;
use IsrarMinhas\FilamentAiVisibility\Support\Limits;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Text;
use Throwable;

/**
 * Pulls keywords from a connection into the brand's keyword list. New
 * keywords stop at the brand's keyword limit; existing ones get fresh metrics.
 */
class KeywordSync
{
    public function __construct(
        protected KeywordSourceRegistry $sources,
        protected Limits $limits,
        protected Settings $settings,
        protected AlertNotifier $alerts,
    ) {}

    /**
     * @return array{created: int, updated: int, skipped: int}
     *
     * @throws SourceFailed
     */
    public function sync(Connection $connection): array
    {
        $brand = $connection->brand;
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        try {
            $source = $this->sources->get($connection->type);
            $existing = $brand->keywords()->get()->keyBy('keyword_hash');
            $max = $this->limits->maxKeywords($brand);
            $room = $max === null ? PHP_INT_MAX : max(0, $max - $existing->count());
            $sourceValue = (KeywordSourceEnum::tryFrom($connection->type === 'serpapi' ? 'serpapi_paa' : $connection->type) ?? KeywordSourceEnum::Custom)->value;

            foreach ($source->fetch($connection) as $data) {
                $keyword = Text::squish($data->keyword);

                if ($keyword === '' || mb_strlen($keyword) > 255) {
                    continue;
                }

                $hash = Text::hash($keyword);
                $metrics = array_filter([
                    'search_volume' => $data->searchVolume,
                    'clicks' => $data->clicks,
                    'impressions' => $data->impressions,
                    'avg_position' => $data->position,
                    'intent' => $data->intent,
                ], fn ($value) => $value !== null);

                if ($record = $existing->get($hash)) {
                    $record->fill($metrics + ['last_synced_at' => now()])->save();
                    $counts['updated']++;

                    continue;
                }

                if ($room <= 0) {
                    $counts['skipped']++;

                    continue;
                }

                $existing->put($hash, $brand->keywords()->create($metrics + [
                    'keyword' => $keyword,
                    'source' => $sourceValue,
                    'connection_id' => $connection->getKey(),
                    'metadata' => $data->metadata ?: null,
                    'last_synced_at' => now(),
                ]));

                $room--;
                $counts['created']++;
            }
        } catch (SourceFailed $e) {
            $this->markFailed($connection, $e);

            throw $e;
        } catch (Throwable $e) {
            // Anything unexpected: log the details, store only a generic message.
            report($e);
            $failure = static::unexpected($e);
            $this->markFailed($connection, $failure);

            throw $failure;
        }

        $connection->forceFill([
            'status' => Connection::CONNECTED,
            'last_error' => null,
            'last_sync_count' => $counts['created'] + $counts['updated'],
            'last_synced_at' => now(),
            'next_sync_at' => now()->addDays((int) $this->settings->get('keywords.sync_days', 30)),
        ])->save();

        return $counts;
    }

    /**
     * A safe, generic failure for an unexpected error (the original is kept as the previous exception).
     */
    public static function unexpected(Throwable $e): SourceFailed
    {
        return new SourceFailed('The sync failed unexpectedly. Details are in the application log.', previous: $e);
    }

    public function markFailed(Connection $connection, SourceFailed $e): void
    {
        $status = $e->credentialsRejected ? Connection::NEEDS_REAUTH : Connection::ERROR;
        $changed = $connection->status !== $status;

        $connection->forceFill([
            'status' => $status,
            'last_error' => $e->getMessage(),
            // Try again tomorrow rather than on every scheduler tick.
            'next_sync_at' => now()->addDay(),
        ])->save();

        // One alert per problem, not one per retry.
        if ($changed) {
            $this->alerts->send(new Alert(
                title: "Keyword source \"{$connection->name}\" " . ($e->credentialsRejected ? 'needs new credentials' : 'failed'),
                body: $e->getMessage(),
                level: 'danger',
                url: AiVisibilityPlugin::pageUrl(ConnectionResource::class),
                urlLabel: 'Open keyword sources',
                type: 'connection_failed',
                brandId: $connection->brand_id,
            ));
        }
    }
}
