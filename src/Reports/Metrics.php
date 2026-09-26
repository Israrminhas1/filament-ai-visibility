<?php

namespace IsrarMinhas\FilamentAiVisibility\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Models\Citation;
use IsrarMinhas\FilamentAiVisibility\Models\Model;
use IsrarMinhas\FilamentAiVisibility\Models\Prompt;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\ResultMention;
use IsrarMinhas\FilamentAiVisibility\Models\Usage;

/**
 * Report numbers. Only successful answers count: skipped answers (paused
 * engines, budget) are excluded so an outage never looks like a drop.
 */
class Metrics
{
    /**
     * Successful answers in the filters' slice.
     */
    public function results(ReportFilters $filters): Builder
    {
        $table = Model::prefixedTable('results');

        return Result::query()
            ->where("{$table}.brand_id", $filters->brand->getKey())
            ->where("{$table}.status", ResultStatus::Success)
            ->whereBetween("{$table}.ran_at", [$filters->from, $filters->until])
            ->when($filters->engine, fn (Builder $query, string $engine) => $query->where("{$table}.engine", $engine))
            ->when($filters->topicId, fn (Builder $query, int $topic) => $query->whereIn(
                "{$table}.prompt_id",
                Prompt::query()->select('id')->where('topic_id', $topic),
            ));
    }

    /**
     * @return array{answers: int, visibility: ?float, citation_rate: ?float, avg_position: ?float, share_of_voice: ?float, spend: float}
     */
    public function summary(ReportFilters $filters): array
    {
        $row = $this->results($filters)
            ->toBase()
            ->selectRaw('COUNT(*) as answers')
            ->selectRaw('SUM(CASE WHEN brand_mentioned = ? THEN 1 ELSE 0 END) as mentioned', [true])
            ->selectRaw('SUM(CASE WHEN brand_cited = ? THEN 1 ELSE 0 END) as cited', [true])
            ->selectRaw('AVG(brand_position) as avg_position')
            ->first();

        $answers = (int) ($row->answers ?? 0);
        $sov = $this->shareOfVoice($filters);

        return [
            'answers' => $answers,
            'visibility' => $answers ? round((int) $row->mentioned / $answers * 100, 1) : null,
            'citation_rate' => $answers ? round((int) $row->cited / $answers * 100, 1) : null,
            'avg_position' => $row->avg_position !== null ? round((float) $row->avg_position, 1) : null,
            'share_of_voice' => $sov->firstWhere('type', 'brand')['share'] ?? ($answers ? 0.0 : null),
            'spend' => $this->spend($filters),
        ];
    }

    /**
     * Spend for the brand and engine in the period. Usage is not recorded per
     * prompt, so this is never narrowed to a topic.
     */
    public function spend(ReportFilters $filters): float
    {
        return (float) Usage::query()
            ->where('brand_id', $filters->brand->getKey())
            ->whereBetween('created_at', [$filters->from, $filters->until])
            ->when($filters->engine, fn ($query, $engine) => $query->where('engine', $engine))
            ->sum('cost_usd');
    }

    /**
     * IDs of the brand's active competitors: only they count in share of voice
     * and the leaderboard.
     *
     * @return array<int>
     */
    public function trackedCompetitorIds(ReportFilters $filters): array
    {
        return $filters->brand->competitors()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Limit a mentions query to the brand and its active competitors, leaving
     * out untracked names (entities) and paused competitors. A competitor
     * picked explicitly ($alsoCompetitor) is kept even while paused.
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query
     */
    public function whereTracked($query, ReportFilters $filters, ?int $alsoCompetitor = null): void
    {
        $mentions = Model::prefixedTable('mentions');
        $competitors = $this->trackedCompetitorIds($filters);

        if ($alsoCompetitor !== null && ! in_array($alsoCompetitor, $competitors, true)) {
            $competitors[] = $alsoCompetitor;
        }

        $query->where(fn ($query) => $query
            ->where("{$mentions}.subject_type", 'brand')
            ->when($competitors !== [], fn ($query) => $query->orWhere(fn ($query) => $query
                ->where("{$mentions}.subject_type", 'competitor')
                ->whereIn("{$mentions}.subject_id", $competitors))));
    }

    /**
     * Share of voice: of all mentions of the brand and its active competitors,
     * how many are each one's. Matches the competitor leaderboard.
     *
     * @return Collection<int, array{type: string, id: ?int, name: string, color: string, answers: int, share: float}>
     */
    public function shareOfVoice(ReportFilters $filters): Collection
    {
        $mentions = Model::prefixedTable('mentions');
        $results = Model::prefixedTable('results');

        $query = ResultMention::query()
            ->whereIn("{$mentions}.result_id", $this->results($filters)->select("{$results}.id"))
            ->groupBy("{$mentions}.subject_type", "{$mentions}.subject_id")
            ->selectRaw("{$mentions}.subject_type as type, {$mentions}.subject_id as subject_id, COUNT(DISTINCT {$mentions}.result_id) as answers");

        $this->whereTracked($query, $filters);

        $rows = $query->toBase()->get();
        $total = max(1, (int) $rows->sum('answers'));
        $competitors = $filters->brand->competitors()->get()->keyBy('id');

        return $rows
            ->map(function ($row) use ($total, $competitors, $filters) {
                $isBrand = $row->type === 'brand';
                $competitor = $isBrand ? null : $competitors->get($row->subject_id);

                return [
                    'type' => $row->type,
                    'id' => $row->subject_id ? (int) $row->subject_id : null,
                    'name' => $isBrand ? $filters->brand->name : ($competitor?->name ?? 'Unknown'),
                    'color' => $isBrand ? '#10b981' : ($competitor?->color ?? '#9ca3af'),
                    'answers' => (int) $row->answers,
                    'share' => round((int) $row->answers / $total * 100, 1),
                ];
            })
            ->sortByDesc('answers')
            ->values();
    }

    /**
     * Visibility % per engine per day (or week for long periods). Days without
     * answers are null, so charts show a gap instead of a false zero.
     *
     * @return array{labels: array<string>, series: array<string, array<?float>>}
     */
    public function trend(ReportFilters $filters): array
    {
        $weekly = $filters->days() > 90;
        $date = $this->dateExpression();

        $rows = $this->results($filters)
            ->toBase()
            ->selectRaw("{$date} as day, engine, COUNT(*) as answers, SUM(CASE WHEN brand_mentioned = ? THEN 1 ELSE 0 END) as mentioned", [true])
            ->groupBy(DB::raw($date), 'engine')
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $day = \Carbon\CarbonImmutable::parse(substr((string) $row->day, 0, 10));
            $key = $weekly ? $day->startOfWeek()->toDateString() : $day->toDateString();
            $buckets[$row->engine][$key]['answers'] = ($buckets[$row->engine][$key]['answers'] ?? 0) + (int) $row->answers;
            $buckets[$row->engine][$key]['mentioned'] = ($buckets[$row->engine][$key]['mentioned'] ?? 0) + (int) $row->mentioned;
        }

        $labels = [];
        $cursor = $weekly ? $filters->from->startOfWeek() : $filters->from->startOfDay();

        while ($cursor->lte($filters->until)) {
            $labels[] = $cursor->toDateString();
            $cursor = $weekly ? $cursor->addWeek() : $cursor->addDay();
        }

        $series = [];

        foreach ($buckets as $engine => $days) {
            $series[$engine] = array_map(
                fn (string $label) => isset($days[$label]) ? round($days[$label]['mentioned'] / max(1, $days[$label]['answers']) * 100, 1) : null,
                $labels,
            );
        }

        ksort($series);

        return ['labels' => $labels, 'series' => $series];
    }

    /**
     * Visibility per engine for the period.
     *
     * @return Collection<string, array{answers: int, visibility: float}>
     */
    public function byEngine(ReportFilters $filters): Collection
    {
        return $this->results($filters)
            ->toBase()
            ->selectRaw('engine, COUNT(*) as answers, SUM(CASE WHEN brand_mentioned = ? THEN 1 ELSE 0 END) as mentioned', [true])
            ->groupBy('engine')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->engine => [
                'answers' => (int) $row->answers,
                'visibility' => round((int) $row->mentioned / max(1, (int) $row->answers) * 100, 1),
            ]]);
    }

    /**
     * @return Collection<int, object{domain: string, category: ?string, answers: int, is_brand: bool}>
     */
    public function topSources(ReportFilters $filters, int $limit = 10): Collection
    {
        $citations = Model::prefixedTable('citations');
        $results = Model::prefixedTable('results');

        return Citation::query()
            ->whereIn("{$citations}.result_id", $this->results($filters)->select("{$results}.id"))
            ->groupBy('domain', 'category')
            ->selectRaw('domain, category, COUNT(DISTINCT result_id) as answers, MAX(CASE WHEN is_brand = ? THEN 1 ELSE 0 END) as is_brand', [true])
            ->orderByDesc('answers')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(function ($row) {
                $row->answers = (int) $row->answers;
                $row->is_brand = (bool) $row->is_brand;

                return $row;
            });
    }

    /**
     * @return Collection<string, int> category => answers citing it
     */
    public function sourceCategories(ReportFilters $filters): Collection
    {
        $citations = Model::prefixedTable('citations');
        $results = Model::prefixedTable('results');

        return Citation::query()
            ->whereIn("{$citations}.result_id", $this->results($filters)->select("{$results}.id"))
            ->groupBy('category')
            ->selectRaw('category, COUNT(*) as citations')
            ->orderByDesc('citations')
            ->toBase()
            ->pluck('citations', 'category')
            ->map(fn ($count) => (int) $count);
    }

    /**
     * The brand's own pages cited most often.
     *
     * @return Collection<int, object{url: string, answers: int}>
     */
    public function ownPagesCited(ReportFilters $filters, int $limit = 10): Collection
    {
        $citations = Model::prefixedTable('citations');
        $results = Model::prefixedTable('results');

        return Citation::query()
            ->whereIn("{$citations}.result_id", $this->results($filters)->select("{$results}.id"))
            ->where('is_brand', true)
            ->groupBy('url')
            ->selectRaw('url, COUNT(DISTINCT result_id) as answers')
            ->orderByDesc('answers')
            ->limit($limit)
            ->toBase()
            ->get();
    }

    /**
     * Visibility per prompt this period and the previous one.
     *
     * @return Collection<int, array{prompt_id: int, text: string, answers: int, visibility: float, previous: ?float, change: ?float}>
     */
    public function prompts(ReportFilters $filters): Collection
    {
        $current = $this->promptVisibility($filters);
        $previous = $this->promptVisibility($filters->previous());
        $texts = Prompt::query()->whereKey($current->keys())->pluck('text', 'id');

        return $current
            ->map(function (array $row, int $promptId) use ($previous, $texts) {
                $before = $previous->get($promptId)['visibility'] ?? null;

                return [
                    'prompt_id' => $promptId,
                    'text' => $texts[$promptId] ?? '',
                    'answers' => $row['answers'],
                    'visibility' => $row['visibility'],
                    'previous' => $before,
                    'change' => $before === null ? null : round($row['visibility'] - $before, 1),
                ];
            })
            ->values();
    }

    /**
     * @return Collection<int, array{answers: int, visibility: float}>
     */
    protected function promptVisibility(ReportFilters $filters): Collection
    {
        return $this->results($filters)
            ->toBase()
            ->selectRaw('prompt_id, COUNT(*) as answers, SUM(CASE WHEN brand_mentioned = ? THEN 1 ELSE 0 END) as mentioned', [true])
            ->groupBy('prompt_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->prompt_id => [
                'answers' => (int) $row->answers,
                'visibility' => round((int) $row->mentioned / max(1, (int) $row->answers) * 100, 1),
            ]]);
    }

    /**
     * Visibility weighted by the search demand behind each prompt (its keywords'
     * monthly searches, or Search Console impressions), so winning a popular
     * question counts more than winning a rare one. Null without keyword data.
     *
     * @return array{reach: float, demand: int, prompts: int}|null
     */
    public function reach(ReportFilters $filters): ?array
    {
        $visibility = $this->promptVisibility($filters);

        if ($visibility->isEmpty()) {
            return null;
        }

        $demand = DB::table(Model::prefixedTable('keyword_prompt') . ' as kp')
            ->join(Model::prefixedTable('keywords') . ' as k', 'k.id', '=', 'kp.keyword_id')
            ->whereIn('kp.prompt_id', $visibility->keys())
            ->groupBy('kp.prompt_id')
            ->selectRaw('kp.prompt_id, SUM(COALESCE(k.search_volume, k.impressions, 0)) as demand')
            ->pluck('demand', 'prompt_id')
            ->map(fn ($value) => (int) $value)
            ->filter();

        $total = $demand->sum();

        if ($total === 0) {
            return null;
        }

        $weighted = $demand->reduce(fn ($carry, $value, $promptId) => $carry + $value * ($visibility[$promptId]['visibility'] ?? 0), 0);

        return ['reach' => round($weighted / $total, 1), 'demand' => $total, 'prompts' => $demand->count()];
    }

    /**
     * Visibility and change per topic, weakest first. Read in three grouped
     * queries for all topics at once.
     *
     * @return Collection<int, array{topic_id: ?int, name: string, prompts: int, answers: int, visibility: ?float, change: ?float, share_of_voice: ?float}>
     */
    public function topics(ReportFilters $filters): Collection
    {
        $topics = $filters->brand->topics()->withCount('prompts')->orderBy('name')->get();

        if ($topics->isEmpty()) {
            return collect();
        }

        // Every topic gets its own numbers, whatever topic the page is filtered to.
        $filters = new ReportFilters($filters->brand, $filters->from, $filters->until, $filters->engine);

        $now = $this->visibilityByTopic($filters);
        $before = $this->visibilityByTopic($filters->previous());
        $sov = $this->shareOfVoiceByTopic($filters);

        return $topics
            ->map(function ($topic) use ($now, $before, $sov) {
                $id = (int) $topic->getKey();
                $answers = $now[$id]['answers'] ?? 0;
                $visibility = $now[$id]['visibility'] ?? null;
                $previous = $before[$id]['visibility'] ?? null;

                return [
                    'topic_id' => $topic->getKey(),
                    'name' => $topic->name,
                    'prompts' => $topic->prompts_count,
                    'answers' => $answers,
                    'visibility' => $visibility,
                    'change' => $visibility !== null && $previous !== null ? round($visibility - $previous, 1) : null,
                    'share_of_voice' => $sov[$id] ?? ($answers ? 0.0 : null),
                ];
            })
            ->sortBy(fn ($row) => $row['visibility'] ?? 101)
            ->values();
    }

    /**
     * @return Collection<int, array{answers: int, visibility: float}> keyed by topic ID
     */
    protected function visibilityByTopic(ReportFilters $filters): Collection
    {
        $results = Model::prefixedTable('results');
        $prompts = Model::prefixedTable('prompts');

        return $this->results($filters)
            ->toBase()
            ->join($prompts, "{$prompts}.id", '=', "{$results}.prompt_id")
            ->whereNotNull("{$prompts}.topic_id")
            ->selectRaw("{$prompts}.topic_id as topic_id, COUNT(*) as answers, SUM(CASE WHEN {$results}.brand_mentioned = ? THEN 1 ELSE 0 END) as mentioned", [true])
            ->groupBy("{$prompts}.topic_id")
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->topic_id => [
                'answers' => (int) $row->answers,
                'visibility' => round((int) $row->mentioned / max(1, (int) $row->answers) * 100, 1),
            ]]);
    }

    /**
     * The brand's share of voice per topic.
     *
     * @return Collection<int, float> keyed by topic ID
     */
    protected function shareOfVoiceByTopic(ReportFilters $filters): Collection
    {
        $results = Model::prefixedTable('results');
        $mentions = Model::prefixedTable('mentions');
        $prompts = Model::prefixedTable('prompts');

        $query = $this->results($filters)
            ->toBase()
            ->join($mentions, "{$mentions}.result_id", '=', "{$results}.id")
            ->join($prompts, "{$prompts}.id", '=', "{$results}.prompt_id")
            ->whereNotNull("{$prompts}.topic_id")
            ->selectRaw("{$prompts}.topic_id as topic_id, {$mentions}.subject_type as type, COUNT(DISTINCT {$mentions}.result_id) as answers")
            ->groupBy("{$prompts}.topic_id", "{$mentions}.subject_type", "{$mentions}.subject_id");

        $this->whereTracked($query, $filters);

        return $query->get()
            ->groupBy('topic_id')
            ->mapWithKeys(function (Collection $rows, $topicId) {
                $brand = (int) $rows->where('type', 'brand')->sum('answers');

                return [(int) $topicId => round($brand / max(1, (int) $rows->sum('answers')) * 100, 1)];
            });
    }

    protected function dateExpression(): string
    {
        return DB::connection((new Result)->getConnectionName())->getDriverName() === 'sqlsrv'
            ? 'CAST(ran_at AS date)'
            : 'DATE(ran_at)';
    }
}
