<?php

namespace IsrarMinhas\FilamentAiVisibility\Competitors;

use Illuminate\Support\Collection;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Usage;
use IsrarMinhas\FilamentAiVisibility\Support\HelperAi;
use IsrarMinhas\FilamentAiVisibility\Support\Instructions;
use IsrarMinhas\FilamentAiVisibility\Support\Text;

/**
 * Finds company and product names in answers, so competitors are discovered
 * even when an answer names them without linking to their site. Stored as
 * mentions with subject_type "entity".
 */
class NameExtractor
{
    public function __construct(
        protected HelperAi $helper,
        protected Instructions $instructions,
    ) {}

    /**
     * Extract names from answers that have not been processed yet.
     *
     * @return int Number of answers processed.
     *
     * @throws \IsrarMinhas\FilamentAiVisibility\Exceptions\HelperUnavailable
     */
    public function extract(Brand $brand, ?int $runId = null, int $limit = 200): int
    {
        $results = Result::query()
            ->where('brand_id', $brand->getKey())
            ->where('status', ResultStatus::Success)
            ->whereNull('entities_extracted_at')
            ->when($runId, fn ($query) => $query->where('run_id', $runId))
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'answer', 'brand_id']);

        $known = $this->knownNames($brand);

        foreach ($results->chunk((int) config('ai-visibility.discovery.extraction_batch', 8)) as $batch) {
            $this->extractBatch($brand, $batch, $known);
        }

        return $results->count();
    }

    /**
     * @param  Collection<int, Result>  $batch
     * @param  array<string, true>  $known
     */
    protected function extractBatch(Brand $brand, Collection $batch, array $known): void
    {
        $answers = $batch->map(fn (Result $result) => "ANSWER id={$result->id}\n" . mb_substr((string) $result->answer, 0, 3000))->implode("\n\n");

        $data = $this->helper->json(
            Usage::PURPOSE_ANALYSIS,
            $this->instructions->render(Instructions::EXTRACTION, ['brand' => $brand->name, 'answers' => $answers]),
            $brand,
            maxTokens: 2000,
        );

        $namesById = collect($data['answers'] ?? [])->mapWithKeys(fn ($row) => [(int) ($row['id'] ?? 0) => (array) ($row['names'] ?? [])]);

        foreach ($batch as $result) {
            $seen = [];
            $position = 0;

            foreach ($namesById->get($result->id, []) as $name) {
                $name = Text::squish(is_string($name) ? $name : '');
                $key = Text::normalize($name);

                if ($name === '' || mb_strlen($name) > 80 || isset($known[$key]) || isset($seen[$key])) {
                    continue;
                }

                // Only keep names that really appear in the answer.
                if (! Text::mentionsAny((string) $result->answer, [$name])) {
                    continue;
                }

                $seen[$key] = true;

                $result->mentions()->create([
                    'subject_type' => 'entity',
                    'subject_id' => null,
                    'name_matched' => $name,
                    'position' => ++$position,
                    'count' => 1,
                    'snippet' => $this->snippet((string) $result->answer, $name),
                ]);
            }

            $result->forceFill(['entities_extracted_at' => now()])->save();
        }
    }

    /**
     * Normalised brand and competitor names, which are tracked already.
     *
     * @return array<string, true>
     */
    protected function knownNames(Brand $brand): array
    {
        $names = $brand->names();

        foreach ($brand->competitors()->get() as $competitor) {
            array_push($names, ...$competitor->names());
        }

        return collect($names)->mapWithKeys(fn ($name) => [Text::normalize($name) => true])->all();
    }

    protected function snippet(string $answer, string $name): ?string
    {
        if (! preg_match('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu', $answer, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $offset = $match[0][1];
        $start = max(0, (int) strrpos(substr($answer, 0, $offset), "\n"), (int) strrpos(substr($answer, 0, $offset), '. '));
        $sentence = Text::squish(substr($answer, $start, 400));

        return mb_strlen($sentence) > 300 ? mb_substr($sentence, 0, 297) . '…' : ltrim($sentence, '. ');
    }
}
