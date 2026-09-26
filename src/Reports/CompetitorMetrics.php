<?php

namespace IsrarMinhas\FilamentAiVisibility\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use IsrarMinhas\FilamentAiVisibility\Enums\Recommendation;
use IsrarMinhas\FilamentAiVisibility\Enums\Sentiment;
use IsrarMinhas\FilamentAiVisibility\Models\Citation;
use IsrarMinhas\FilamentAiVisibility\Models\Competitor;
use IsrarMinhas\FilamentAiVisibility\Models\Model;
use IsrarMinhas\FilamentAiVisibility\Models\Prompt;
use IsrarMinhas\FilamentAiVisibility\Models\ResultMention;

/**
 * Brand-vs-competitor numbers: leaderboard, engine heatmap, sentiment,
 * head-to-head and opportunities. Only the brand and its active competitors
 * count, so every view agrees with the others and with share of voice.
 */
class CompetitorMetrics
{
    /**
     * @var array<string, array>
     */
    protected array $cache = [];

    public function __construct(
        protected Metrics $metrics,
    ) {}

    /**
     * @return Collection<int, array{key: string, type: string, id: int, name: string, color: string}>
     */
    public function subjects(ReportFilters $filters): Collection
    {
        return collect([[
            'key' => 'brand:' . $filters->brand->getKey(),
            'type' => 'brand',
            'id' => (int) $filters->brand->getKey(),
            'name' => $filters->brand->name,
            'color' => '#10b981',
        ]])->merge($filters->brand->competitors()->where('is_active', true)->orderBy('name')->get()->map(fn (Competitor $c) => [
            'key' => 'competitor:' . $c->getKey(),
            'type' => 'competitor',
            'id' => (int) $c->getKey(),
            'name' => $c->name,
            'color' => $c->color ?? '#9ca3af',
        ]));
    }

    /**
     * One row per brand and competitor, strongest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function leaderboard(ReportFilters $filters): Collection
    {
        $data = $this->data($filters);
        $previous = $this->data($filters->previous());
        $total = max(1, $data['answers']);
        $allMentions = max(1, collect($data['subjects'])->sum('answers'));

        return $this->subjects($filters)
            ->map(function (array $subject) use ($data, $previous, $total, $allMentions) {
                $stats = $data['subjects'][$subject['key']] ?? null;
                $answers = $stats['answers'] ?? 0;
                $before = $previous['answers'] ? ($previous['subjects'][$subject['key']]['answers'] ?? 0) / $previous['answers'] * 100 : null;
                $analysed = $stats ? max(1, $stats['analysed']) : 1;
                $visibility = round($answers / $total * 100, 1);

                return $subject + [
                    'answers' => $answers,
                    'visibility' => $visibility,
                    'change' => $before === null ? null : round($visibility - $before, 1),
                    'share_of_voice' => round($answers / $allMentions * 100, 1),
                    'avg_position' => $stats && $stats['mentions'] ? round($stats['position_sum'] / $stats['mentions'], 1) : null,
                    'win_rate' => $answers ? round(($stats['first'] ?? 0) / $answers * 100, 1) : null,
                    'citation_rate' => round(($data['cited'][$subject['key']] ?? 0) / $total * 100, 1),
                    'net_sentiment' => $stats && $stats['analysed'] ? round((($stats['sentiment']['positive'] ?? 0) - ($stats['sentiment']['negative'] ?? 0)) / $analysed * 100) : null,
                    'top_pick_rate' => $stats && $stats['analysed'] ? round(($stats['recommendation'][Recommendation::TopPick->value] ?? 0) / $analysed * 100, 1) : null,
                ];
            })
            ->sortByDesc('visibility')
            ->values();
    }

    /**
     * Visibility % per engine for each brand/competitor.
     *
     * @return array{engines: array<string>, rows: Collection<int, array{name: string, type: string, color: string, cells: array<string, ?float>}>}
     */
    public function heatmap(ReportFilters $filters): array
    {
        $data = $this->data($filters);
        $engines = array_keys($data['engineAnswers']);
        sort($engines);

        $rows = $this->subjects($filters)->map(function (array $subject) use ($data, $engines) {
            $cells = [];

            foreach ($engines as $engine) {
                $answers = $data['engineAnswers'][$engine];
                $mentioned = $data['subjects'][$subject['key']]['engines'][$engine] ?? 0;
                $cells[$engine] = $answers ? round($mentioned / $answers * 100) : null;
            }

            return ['name' => $subject['name'], 'type' => $subject['type'], 'color' => $subject['color'], 'cells' => $cells];
        });

        return ['engines' => $engines, 'rows' => $rows];
    }

    /**
     * Sentiment and recommendation mix for the brand (or a competitor).
     *
     * @return array{analysed: int, sentiment: array<string, int>, recommendation: array<string, int>, descriptors: array<string, int>}
     */
    public function perception(ReportFilters $filters, ?int $competitorId = null): array
    {
        $include = $this->untracked($filters, $competitorId);
        $data = $this->data($filters, $include);
        $key = $competitorId ? "competitor:{$competitorId}" : 'brand:' . $filters->brand->getKey();
        $stats = $data['subjects'][$key] ?? null;

        $descriptors = $stats ? $this->descriptors($filters, $competitorId, $include) : [];
        arsort($descriptors);

        return [
            'analysed' => $stats['analysed'] ?? 0,
            'sentiment' => array_merge(array_fill_keys(array_column(Sentiment::cases(), 'value'), 0), $stats['sentiment'] ?? []),
            'recommendation' => array_merge(array_fill_keys(array_column(Recommendation::cases(), 'value'), 0), $stats['recommendation'] ?? []),
            'descriptors' => array_slice($descriptors, 0, 12, true),
        ];
    }

    /**
     * The brand against one competitor.
     *
     * @return array<string, mixed>
     */
    public function headToHead(ReportFilters $filters, Competitor $competitor): array
    {
        $data = $this->data($filters);
        $mentions = Model::prefixedTable('mentions');
        $results = Model::prefixedTable('results');

        $brandAnswers = [];
        $rivalAnswers = [];
        $promptOf = [];

        // Only the answers naming either side are read; a paused competitor
        // picked here is still compared.
        $rows = $this->mentionRows($filters, $this->untracked($filters, (int) $competitor->getKey()))
            ->where(fn (Builder $query) => $query
                ->where("{$mentions}.subject_type", 'brand')
                ->orWhere(fn (Builder $query) => $query->where("{$mentions}.subject_type", 'competitor')->where("{$mentions}.subject_id", $competitor->getKey())))
            ->get(["{$results}.id as result_id", "{$results}.prompt_id", "{$mentions}.subject_type", "{$mentions}.position"]);

        foreach ($rows as $row) {
            $resultId = (int) $row->result_id;
            $promptOf[$resultId] = (int) $row->prompt_id;

            if ($row->subject_type === 'brand') {
                $brandAnswers[$resultId] = (int) $row->position;
            } else {
                $rivalAnswers[$resultId] = (int) $row->position;
            }
        }

        $both = array_intersect_key($brandAnswers, $rivalAnswers);
        $either = $brandAnswers + $rivalAnswers;

        $prompts = [];
        $brandWins = 0;
        $rivalWins = 0;

        foreach ($either as $resultId => $_) {
            $brandPosition = $brandAnswers[$resultId] ?? null;
            $rivalPosition = $rivalAnswers[$resultId] ?? null;

            $winner = match (true) {
                $rivalPosition === null => 'brand',
                $brandPosition === null => 'rival',
                default => $brandPosition < $rivalPosition ? 'brand' : 'rival',
            };

            $winner === 'brand' ? $brandWins++ : $rivalWins++;
            $prompts[$promptOf[$resultId]][$winner] = ($prompts[$promptOf[$resultId]][$winner] ?? 0) + 1;
        }

        $texts = Prompt::query()->whereKey(array_keys($prompts))->pluck('text', 'id');

        $promptRows = collect($prompts)->map(fn ($wins, $promptId) => [
            'prompt_id' => $promptId,
            'text' => $texts[$promptId] ?? '',
            'brand' => $wins['brand'] ?? 0,
            'rival' => $wins['rival'] ?? 0,
        ]);

        [$brandSources, $rivalSources] = [$this->domainsFor(array_keys($brandAnswers)), $this->domainsFor(array_keys($rivalAnswers))];

        return [
            'total' => $data['answers'],
            'brand' => ['name' => $filters->brand->name, 'answers' => count($brandAnswers), 'visibility' => $data['answers'] ? round(count($brandAnswers) / $data['answers'] * 100, 1) : 0, 'wins' => $brandWins, 'perception' => $this->perception($filters)],
            'rival' => ['name' => $competitor->name, 'answers' => count($rivalAnswers), 'visibility' => $data['answers'] ? round(count($rivalAnswers) / $data['answers'] * 100, 1) : 0, 'wins' => $rivalWins, 'perception' => $this->perception($filters, $competitor->getKey())],
            'co_mention_rate' => $either ? round(count($both) / count($either) * 100, 1) : 0,
            'brand_prompts' => $promptRows->filter(fn ($row) => $row['brand'] > $row['rival'])->sortByDesc(fn ($row) => $row['brand'] - $row['rival'])->values(),
            'rival_prompts' => $promptRows->filter(fn ($row) => $row['rival'] > $row['brand'])->sortByDesc(fn ($row) => $row['rival'] - $row['brand'])->values(),
            // Sites that cite the competitor in answers where the brand is missing.
            'rival_only_sources' => collect($rivalSources)->diffKeys($brandSources)->sortDesc()->take(10)->all(),
            'brand_only_sources' => collect($brandSources)->diffKeys($rivalSources)->sortDesc()->take(10)->all(),
        ];
    }

    /**
     * Where competitors show up but the brand does not.
     *
     * @return array{prompts: Collection<int, array<string, mixed>>, sources: Collection<int, array{domain: string, category: ?string, answers: int}>, missed_answers: int}
     */
    public function opportunities(ReportFilters $filters): array
    {
        $mentions = Model::prefixedTable('mentions');
        $results = Model::prefixedTable('results');
        $names = $this->subjects($filters)->pluck('name', 'key');

        // Tracked competitors named in answers that do not name the brand.
        $missed = fn () => $this->mentionRows($filters)
            ->where("{$mentions}.subject_type", 'competitor')
            ->whereNotIn("{$results}.id", ResultMention::query()
                ->select('result_id')
                ->where('subject_type', 'brand')
                ->whereIn('result_id', $this->metrics->results($filters)->select("{$results}.id")));

        $byPrompt = [];

        foreach ($missed()
            ->selectRaw("{$results}.prompt_id as prompt_id, {$results}.engine as engine, {$mentions}.subject_id as subject_id, COUNT(DISTINCT {$results}.id) as answers")
            ->groupBy("{$results}.prompt_id", "{$results}.engine", "{$mentions}.subject_id")
            ->orderBy("{$results}.prompt_id")
            ->get() as $row) {
            $entry = $byPrompt[(int) $row->prompt_id] ?? ['answers' => 0, 'engines' => [], 'rivals' => []];
            $entry['engines'][$row->engine] = true;
            $entry['rivals']["competitor:{$row->subject_id}"] = ($entry['rivals']["competitor:{$row->subject_id}"] ?? 0) + (int) $row->answers;
            $byPrompt[(int) $row->prompt_id] = $entry;
        }

        // Answers per prompt, each counted once however many competitors it names.
        $answers = $missed()
            ->selectRaw("{$results}.prompt_id as prompt_id, COUNT(DISTINCT {$results}.id) as answers")
            ->groupBy("{$results}.prompt_id")
            ->get()
            ->pluck('answers', 'prompt_id');

        foreach ($answers as $promptId => $count) {
            if (isset($byPrompt[(int) $promptId])) {
                $byPrompt[(int) $promptId]['answers'] = (int) $count;
            }
        }

        $texts = Prompt::query()->whereKey(array_keys($byPrompt))->pluck('text', 'id');

        $prompts = collect($byPrompt)->map(function ($row, $promptId) use ($texts, $names) {
            arsort($row['rivals']);

            return [
                'prompt_id' => $promptId,
                'text' => $texts[$promptId] ?? '',
                'answers' => $row['answers'],
                'engines' => array_keys($row['engines']),
                'competitors' => collect($row['rivals'])->keys()->map(fn ($key) => $names[$key] ?? $key)->all(),
                'score' => count($row['rivals']) * count($row['engines']),
            ];
        })->sortByDesc('score')->values();

        $sources = Citation::query()
            ->toBase()
            ->whereIn('result_id', $missed()->select("{$results}.id"))
            ->where('is_brand', false)
            ->selectRaw('domain, MIN(category) as category, COUNT(DISTINCT result_id) as answers')
            ->groupBy('domain')
            ->orderByDesc('answers')
            ->orderBy('domain')
            ->limit(15)
            ->get()
            ->map(fn ($row) => ['domain' => $row->domain, 'category' => $row->category, 'answers' => (int) $row->answers]);

        return [
            'prompts' => $prompts,
            'sources' => $sources,
            'missed_answers' => (int) $answers->sum(),
        ];
    }

    /**
     * Cited domains (not the brand's own) in the given answers.
     *
     * @param  array<int>  $resultIds
     * @return array<string, int>
     */
    protected function domainsFor(array $resultIds): array
    {
        $domains = [];

        foreach (array_chunk($resultIds, 500) as $chunk) {
            $rows = Citation::query()
                ->toBase()
                ->whereIn('result_id', $chunk)
                ->where('is_brand', false)
                ->selectRaw('domain, COUNT(DISTINCT result_id) as answers')
                ->groupBy('domain')
                ->get();

            foreach ($rows as $row) {
                $domains[$row->domain] = ($domains[$row->domain] ?? 0) + (int) $row->answers;
            }
        }

        return $domains;
    }

    /**
     * Mentions of the brand and its active competitors, joined to the period's answers.
     */
    protected function mentionRows(ReportFilters $filters, ?int $include = null): Builder
    {
        $mentions = Model::prefixedTable('mentions');
        $results = Model::prefixedTable('results');

        $query = $this->metrics->results($filters)
            ->toBase()
            ->join($mentions, "{$mentions}.result_id", '=', "{$results}.id");

        $this->metrics->whereTracked($query, $filters, $include);

        return $query;
    }

    /**
     * The competitor's ID when it is not tracked (paused), so it can be read
     * when picked explicitly; null when the tracked set already covers it.
     */
    protected function untracked(ReportFilters $filters, ?int $competitorId): ?int
    {
        return $competitorId !== null && ! in_array($competitorId, $this->metrics->trackedCompetitorIds($filters), true)
            ? $competitorId
            : null;
    }

    /**
     * How often each descriptor is used for the brand or a competitor.
     *
     * @return array<string, int>
     */
    protected function descriptors(ReportFilters $filters, ?int $competitorId, ?int $include = null): array
    {
        $mentions = Model::prefixedTable('mentions');
        $counts = [];

        $this->mentionRows($filters, $include)
            ->where("{$mentions}.subject_type", $competitorId ? 'competitor' : 'brand')
            ->when($competitorId, fn (Builder $query) => $query->where("{$mentions}.subject_id", $competitorId))
            ->whereNotNull("{$mentions}.descriptors")
            ->select("{$mentions}.id", "{$mentions}.descriptors")
            ->orderBy("{$mentions}.id")
            ->chunk(1000, function ($rows) use (&$counts) {
                foreach ($rows as $row) {
                    foreach ((array) json_decode((string) $row->descriptors, true) as $descriptor) {
                        $descriptor = mb_strtolower((string) $descriptor);
                        $counts[$descriptor] = ($counts[$descriptor] ?? 0) + 1;
                    }
                }
            });

        return $counts;
    }

    /**
     * Counts for one period, aggregated in the database.
     *
     * @return array{answers: int, engineAnswers: array<string, int>, subjects: array<string, array{answers: int, engines: array<string, int>, mentions: int, position_sum: int, first: int, analysed: int, sentiment: array<string, int>, recommendation: array<string, int>}>, cited: array<string, int>}
     */
    protected function data(ReportFilters $filters, ?int $include = null): array
    {
        $cacheKey = implode('|', [$filters->brand->getKey(), $filters->from, $filters->until, $filters->engine, $filters->topicId, $include]);

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $results = Model::prefixedTable('results');
        $mentions = Model::prefixedTable('mentions');
        $brandKey = 'brand:' . $filters->brand->getKey();

        $engineRows = $this->metrics->results($filters)
            ->toBase()
            ->selectRaw("{$results}.engine as engine, COUNT(*) as answers, SUM(CASE WHEN {$results}.brand_cited = ? THEN 1 ELSE 0 END) as cited", [true])
            ->groupBy("{$results}.engine")
            ->get();

        $data = [
            'answers' => (int) $engineRows->sum('answers'),
            'engineAnswers' => $engineRows->mapWithKeys(fn ($row) => [$row->engine => (int) $row->answers])->all(),
            'subjects' => [],
            'cited' => [$brandKey => (int) $engineRows->sum('cited')],
        ];

        $sentiments = array_column(Sentiment::cases(), 'value');
        $recommendations = array_column(Recommendation::cases(), 'value');

        $query = $this->mentionRows($filters, $include)
            ->selectRaw("{$mentions}.subject_type as subject_type, {$mentions}.subject_id as subject_id, {$results}.engine as engine")
            ->selectRaw("COUNT(DISTINCT {$results}.id) as answers, COUNT(*) as mentions, SUM({$mentions}.position) as position_sum")
            ->selectRaw("SUM(CASE WHEN {$mentions}.position = 1 THEN 1 ELSE 0 END) as firsts")
            ->selectRaw("SUM(CASE WHEN {$mentions}.sentiment IS NOT NULL AND {$mentions}.sentiment <> '' THEN 1 ELSE 0 END) as analysed")
            ->groupBy("{$mentions}.subject_type", "{$mentions}.subject_id", "{$results}.engine");

        foreach ($sentiments as $i => $value) {
            $query->selectRaw("SUM(CASE WHEN {$mentions}.sentiment = ? THEN 1 ELSE 0 END) as s{$i}", [$value]);
        }

        foreach ($recommendations as $i => $value) {
            $query->selectRaw("SUM(CASE WHEN {$mentions}.recommendation = ? THEN 1 ELSE 0 END) as r{$i}", [$value]);
        }

        foreach ($query->get() as $row) {
            // Brand mentions always belong to the report's brand.
            $key = $row->subject_type === 'brand' ? $brandKey : "competitor:{$row->subject_id}";
            $subject = $data['subjects'][$key] ?? ['answers' => 0, 'engines' => [], 'mentions' => 0, 'position_sum' => 0, 'first' => 0, 'analysed' => 0, 'sentiment' => [], 'recommendation' => []];

            // An answer has one engine, so per-engine counts add up.
            $subject['answers'] += (int) $row->answers;
            $subject['engines'][$row->engine] = ($subject['engines'][$row->engine] ?? 0) + (int) $row->answers;
            $subject['mentions'] += (int) $row->mentions;
            $subject['position_sum'] += (int) $row->position_sum;
            $subject['first'] += (int) $row->firsts;
            $subject['analysed'] += (int) $row->analysed;

            foreach ($sentiments as $i => $value) {
                if ($count = (int) $row->{"s{$i}"}) {
                    $subject['sentiment'][$value] = ($subject['sentiment'][$value] ?? 0) + $count;
                }
            }

            foreach ($recommendations as $i => $value) {
                if ($count = (int) $row->{"r{$i}"}) {
                    $subject['recommendation'][$value] = ($subject['recommendation'][$value] ?? 0) + $count;
                }
            }

            $data['subjects'][$key] = $subject;
        }

        // Competitor sites cited per answer.
        foreach (Citation::query()
            ->whereIn('result_id', $this->metrics->results($filters)->select("{$results}.id"))
            ->whereNotNull('competitor_id')
            ->toBase()
            ->selectRaw('competitor_id, COUNT(DISTINCT result_id) as answers')
            ->groupBy('competitor_id')
            ->get() as $row) {
            $data['cited']["competitor:{$row->competitor_id}"] = (int) $row->answers;
        }

        return $this->cache[$cacheKey] = $data;
    }
}
