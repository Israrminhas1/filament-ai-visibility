<?php

namespace IsrarMinhas\FilamentAiVisibility\Reports;

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
 * head-to-head and opportunities. Built from the period's mentions in one
 * pass, so every view agrees with the others.
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
        $allMentions = max(1, collect($data['subjects'])->sum(fn ($s) => count($s['answers'])));

        return $this->subjects($filters)
            ->map(function (array $subject) use ($data, $previous, $total, $allMentions) {
                $stats = $data['subjects'][$subject['key']] ?? null;
                $answers = $stats ? count($stats['answers']) : 0;
                $before = $previous['answers'] ? count($previous['subjects'][$subject['key']]['answers'] ?? []) / $previous['answers'] * 100 : null;
                $analysed = $stats ? max(1, $stats['analysed']) : 1;
                $visibility = round($answers / $total * 100, 1);

                return $subject + [
                    'answers' => $answers,
                    'visibility' => $visibility,
                    'change' => $before === null ? null : round($visibility - $before, 1),
                    'share_of_voice' => round($answers / $allMentions * 100, 1),
                    'avg_position' => $stats && $stats['positions'] ? round(array_sum($stats['positions']) / count($stats['positions']), 1) : null,
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
                $mentioned = count($data['subjects'][$subject['key']]['engines'][$engine] ?? []);
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
        $data = $this->data($filters);
        $key = $competitorId ? "competitor:{$competitorId}" : 'brand:' . $filters->brand->getKey();
        $stats = $data['subjects'][$key] ?? null;

        $descriptors = $stats['descriptors'] ?? [];
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
        $brandKey = 'brand:' . $filters->brand->getKey();
        $rivalKey = "competitor:{$competitor->getKey()}";

        $brandAnswers = $data['subjects'][$brandKey]['answers'] ?? [];
        $rivalAnswers = $data['subjects'][$rivalKey]['answers'] ?? [];
        $both = array_intersect_key($brandAnswers, $rivalAnswers);
        $either = $brandAnswers + $rivalAnswers;

        $prompts = [];
        $brandWins = 0;
        $rivalWins = 0;

        foreach ($either as $resultId => $_) {
            $promptId = $data['results'][$resultId]['prompt_id'];
            $brandPosition = $brandAnswers[$resultId] ?? null;
            $rivalPosition = $rivalAnswers[$resultId] ?? null;

            $winner = match (true) {
                $rivalPosition === null => 'brand',
                $brandPosition === null => 'rival',
                default => $brandPosition < $rivalPosition ? 'brand' : 'rival',
            };

            $winner === 'brand' ? $brandWins++ : $rivalWins++;
            $prompts[$promptId][$winner] = ($prompts[$promptId][$winner] ?? 0) + 1;
        }

        $texts = Prompt::query()->whereKey(array_keys($prompts))->pluck('text', 'id');

        $promptRows = collect($prompts)->map(fn ($wins, $promptId) => [
            'prompt_id' => $promptId,
            'text' => $texts[$promptId] ?? '',
            'brand' => $wins['brand'] ?? 0,
            'rival' => $wins['rival'] ?? 0,
        ]);

        [$brandSources, $rivalSources] = [$this->domainsFor($filters, array_keys($brandAnswers)), $this->domainsFor($filters, array_keys($rivalAnswers))];

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
        $data = $this->data($filters);
        $brandKey = 'brand:' . $filters->brand->getKey();
        $names = $this->subjects($filters)->pluck('name', 'key');

        $missed = [];

        foreach ($data['results'] as $resultId => $result) {
            if (isset($data['subjects'][$brandKey]['answers'][$resultId])) {
                continue;
            }

            $rivals = array_keys(array_filter($data['subjects'], fn ($s, $key) => $key !== $brandKey && isset($s['answers'][$resultId]), ARRAY_FILTER_USE_BOTH));

            if ($rivals) {
                $missed[$resultId] = $rivals;
            }
        }

        $byPrompt = [];

        foreach ($missed as $resultId => $rivals) {
            $result = $data['results'][$resultId];
            $row = $byPrompt[$result['prompt_id']] ?? ['answers' => 0, 'engines' => [], 'rivals' => []];
            $row['answers']++;
            $row['engines'][$result['engine']] = true;

            foreach ($rivals as $rival) {
                $row['rivals'][$rival] = ($row['rivals'][$rival] ?? 0) + 1;
            }

            $byPrompt[$result['prompt_id']] = $row;
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

        $sources = $this->domainsFor($filters, array_keys($missed), withCategory: true);

        return [
            'prompts' => $prompts,
            'sources' => collect($sources)->values()->sortByDesc('answers')->values()->take(15),
            'missed_answers' => count($missed),
        ];
    }

    /**
     * Cited domains (not the brand's own) in the given answers.
     *
     * @param  array<int>  $resultIds
     * @return array<string, int>|array<string, array{domain: string, category: ?string, answers: int}>
     */
    protected function domainsFor(ReportFilters $filters, array $resultIds, bool $withCategory = false): array
    {
        if ($resultIds === []) {
            return [];
        }

        $rows = collect();

        foreach (array_chunk($resultIds, 500) as $chunk) {
            $rows = $rows->merge(Citation::query()
                ->whereIn('result_id', $chunk)
                ->where('is_brand', false)
                ->toBase()
                ->get(['domain', 'category', 'result_id']));
        }

        return $rows->groupBy('domain')->map(function (Collection $group, string $domain) use ($withCategory) {
            $answers = $group->pluck('result_id')->unique()->count();

            return $withCategory ? ['domain' => $domain, 'category' => $group->first()->category, 'answers' => $answers] : $answers;
        })->all();
    }

    /**
     * Everything needed for one period, read once.
     *
     * @return array{answers: int, results: array<int, array{prompt_id: int, engine: string}>, engineAnswers: array<string, int>, subjects: array<string, array>, cited: array<string, int>}
     */
    protected function data(ReportFilters $filters): array
    {
        $cacheKey = implode('|', [$filters->brand->getKey(), $filters->from, $filters->until, $filters->engine, $filters->topicId]);

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $results = Model::prefixedTable('results');
        $mentions = Model::prefixedTable('mentions');

        $resultRows = $this->metrics->results($filters)->toBase()->get(["{$results}.id", 'prompt_id', 'engine', 'brand_cited']);

        $data = [
            'answers' => $resultRows->count(),
            'results' => [],
            'engineAnswers' => [],
            'subjects' => [],
            'cited' => ['brand:' . $filters->brand->getKey() => $resultRows->where('brand_cited', true)->count()],
        ];

        foreach ($resultRows as $row) {
            $data['results'][(int) $row->id] = ['prompt_id' => (int) $row->prompt_id, 'engine' => $row->engine];
            $data['engineAnswers'][$row->engine] = ($data['engineAnswers'][$row->engine] ?? 0) + 1;
        }

        $mentionRows = ResultMention::query()
            ->whereIn("{$mentions}.result_id", $this->metrics->results($filters)->select("{$results}.id"))
            ->whereIn('subject_type', ['brand', 'competitor'])
            ->toBase()
            ->get(['result_id', 'subject_type', 'subject_id', 'position', 'sentiment', 'recommendation', 'descriptors']);

        foreach ($mentionRows as $row) {
            $key = "{$row->subject_type}:{$row->subject_id}";
            $resultId = (int) $row->result_id;
            $engine = $data['results'][$resultId]['engine'] ?? null;

            $subject = $data['subjects'][$key] ?? ['answers' => [], 'engines' => [], 'positions' => [], 'first' => 0, 'analysed' => 0, 'sentiment' => [], 'recommendation' => [], 'descriptors' => []];
            $subject['answers'][$resultId] = (int) $row->position;
            $subject['engines'][$engine][$resultId] = true;
            $subject['positions'][] = (int) $row->position;
            $subject['first'] += (int) $row->position === 1 ? 1 : 0;

            if ($row->sentiment) {
                $subject['analysed']++;
                $subject['sentiment'][$row->sentiment] = ($subject['sentiment'][$row->sentiment] ?? 0) + 1;
            }

            if ($row->recommendation) {
                $subject['recommendation'][$row->recommendation] = ($subject['recommendation'][$row->recommendation] ?? 0) + 1;
            }

            foreach ((array) json_decode((string) $row->descriptors, true) as $descriptor) {
                $descriptor = mb_strtolower((string) $descriptor);
                $subject['descriptors'][$descriptor] = ($subject['descriptors'][$descriptor] ?? 0) + 1;
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
