<?php

namespace IsrarMinhas\FilamentAiVisibility\Competitors;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use IsrarMinhas\FilamentAiVisibility\Detection\Domains;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Candidate;
use IsrarMinhas\FilamentAiVisibility\Models\Citation;
use IsrarMinhas\FilamentAiVisibility\Models\Model;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\ResultMention;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Text;

/**
 * Builds the list of possible competitors from recent answers: cited domains
 * and extracted names (merged when a name matches a domain), each scored
 * 0–100 by how often, where and on how many engines it appears.
 */
class Discovery
{
    public function __construct(
        protected Settings $settings,
    ) {}

    /**
     * @return int Number of candidates found or updated.
     */
    public function discover(Brand $brand): int
    {
        $from = CarbonImmutable::now()->subDays((int) config('ai-visibility.discovery.window_days', 90));
        $totals = $this->totals($brand, $from);

        if ($totals['answers'] === 0) {
            return 0;
        }

        $groups = $this->groups($brand, $from);
        $maxAnswers = max(1, (int) collect($groups)->max(fn ($g) => count($g['answers'])));
        $maxPrompts = max(1, (int) collect($groups)->max(fn ($g) => count($g['prompts'])));

        $existing = Candidate::query()->where('brand_id', $brand->getKey())->get()->keyBy('key');

        foreach ($groups as $key => $group) {
            $candidate = $existing->get($key) ?? new Candidate([
                'brand_id' => $brand->getKey(),
                'tenant_id' => $brand->tenant_id,
                'key' => $key,
                'status' => Candidate::STATUS_NEW,
            ]);

            $candidate->fill([
                'kind' => $group['kind'],
                'name' => $group['name'],
                'domain' => $group['domain'],
                'answers' => count($group['answers']),
                'prompts' => count($group['prompts']),
                'engines' => array_keys($group['engines']),
                'avg_position' => $group['positions'] ? round(array_sum($group['positions']) / count($group['positions']), 2) : null,
                'first_seen_at' => $group['first'],
                'last_seen_at' => $group['last'],
                'score' => $this->score($group, $totals, $maxAnswers, $maxPrompts),
            ])->save();
        }

        // Open candidates no longer seen in the window drop to the bottom.
        Candidate::query()
            ->where('brand_id', $brand->getKey())
            ->whereIn('status', [Candidate::STATUS_NEW, Candidate::STATUS_CLASSIFIED])
            ->whereNotIn('key', array_keys($groups))
            ->update(['answers' => 0, 'prompts' => 0, 'score' => 0]);

        return count($groups);
    }

    /**
     * @return array{answers: int, prompts: int, engines: int}
     */
    protected function totals(Brand $brand, CarbonImmutable $from): array
    {
        $row = Result::query()
            ->where('brand_id', $brand->getKey())
            ->where('status', ResultStatus::Success)
            ->where('ran_at', '>=', $from)
            ->toBase()
            ->selectRaw('COUNT(*) as answers, COUNT(DISTINCT prompt_id) as prompts, COUNT(DISTINCT engine) as engines')
            ->first();

        return ['answers' => (int) $row->answers, 'prompts' => (int) $row->prompts, 'engines' => (int) $row->engines];
    }

    /**
     * Appearances grouped by candidate key.
     *
     * @return array<string, array{kind: string, name: string, domain: ?string, answers: array<int, true>, prompts: array<int, true>, engines: array<string, true>, positions: array<int>, first: string, last: string}>
     */
    protected function groups(Brand $brand, CarbonImmutable $from): array
    {
        $results = Model::prefixedTable('results');
        $citations = Model::prefixedTable('citations');
        $mentions = Model::prefixedTable('mentions');
        $excluded = $this->excludedDomains($brand);
        $knownNames = $this->knownNames($brand);

        $scope = fn ($query) => $query
            ->where("{$results}.brand_id", $brand->getKey())
            ->where("{$results}.status", ResultStatus::Success->value)
            ->where("{$results}.ran_at", '>=', $from);

        $groups = [];

        // Names first, so matching domains are merged into them.
        $names = ResultMention::query()
            ->join($results, "{$results}.id", '=', "{$mentions}.result_id")
            ->where("{$mentions}.subject_type", 'entity')
            ->tap($scope)
            ->toBase()
            ->get(["{$mentions}.name_matched as name", "{$mentions}.position", "{$results}.id as result_id", "{$results}.prompt_id", "{$results}.engine", "{$results}.ran_at"]);

        $nameKeys = [];

        foreach ($names as $row) {
            $normalized = Text::normalize($row->name);

            if ($normalized === '' || isset($knownNames[$normalized])) {
                continue;
            }

            $key = 'name:' . $normalized;
            $nameKeys[str_replace(' ', '', $normalized)] = $key;
            $this->add($groups, $key, 'name', $row->name, null, $row);
        }

        $domains = Citation::query()
            ->join($results, "{$results}.id", '=', "{$citations}.result_id")
            ->where("{$citations}.is_brand", false)
            ->whereNull("{$citations}.competitor_id")
            ->tap($scope)
            ->toBase()
            ->get(["{$citations}.domain", "{$citations}.position", "{$results}.id as result_id", "{$results}.prompt_id", "{$results}.engine", "{$results}.ran_at"]);

        foreach ($domains as $row) {
            $domain = strtolower((string) $row->domain);

            if ($domain === '' || Domains::matches('https://' . $domain, $excluded)) {
                continue;
            }

            // "globex.io" joins the "Globex" name candidate; "monday.com" joins "Monday.com".
            $label = explode('.', $domain)[0];
            $key = $nameKeys[$label] ?? $nameKeys[str_replace('.', '', $domain)] ?? 'domain:' . $domain;

            $this->add($groups, $key, str_starts_with($key, 'name:') ? 'name' : 'domain', $domain, $domain, $row);
        }

        return $groups;
    }

    protected function add(array &$groups, string $key, string $kind, string $name, ?string $domain, object $row): void
    {
        $group = $groups[$key] ?? [
            'kind' => $kind,
            'name' => $name,
            'domain' => null,
            'answers' => [],
            'prompts' => [],
            'engines' => [],
            'positions' => [],
            'first' => $row->ran_at,
            'last' => $row->ran_at,
        ];

        $group['domain'] ??= $domain;
        $group['answers'][$row->result_id] = true;
        $group['prompts'][$row->prompt_id] = true;
        $group['engines'][$row->engine] = true;
        $group['positions'][] = (int) $row->position;
        $group['first'] = min($group['first'], $row->ran_at);
        $group['last'] = max($group['last'], $row->ran_at);

        $groups[$key] = $group;
    }

    /**
     * @param  array{answers: array, prompts: array, engines: array, positions: array<int>, last: string}  $group
     * @param  array{answers: int, prompts: int, engines: int}  $totals
     */
    protected function score(array $group, array $totals, int $maxAnswers, int $maxPrompts): int
    {
        $weights = config('ai-visibility.discovery.weights');
        $avgPosition = $group['positions'] ? array_sum($group['positions']) / count($group['positions']) : 10;
        $daysAgo = CarbonImmutable::parse($group['last'])->diffInDays(now(), absolute: true);

        $values = [
            'answers' => count($group['answers']) / $maxAnswers,
            'prompts' => count($group['prompts']) / $maxPrompts,
            'engines' => count($group['engines']) / max(1, $totals['engines']),
            'position' => 1 / max(1, $avgPosition),
            'recency' => exp(-$daysAgo / 30),
        ];

        $total = array_sum($weights) ?: 1;
        $score = 0;

        foreach ($weights as $key => $weight) {
            $score += $weight * ($values[$key] ?? 0);
        }

        return (int) round(100 * $score / $total);
    }

    /**
     * @return array<string>
     */
    protected function excludedDomains(Brand $brand): array
    {
        $domains = [
            ...config('ai-visibility.discovery.ignored_domains', []),
            ...(array) $this->settings->get('discovery.ignored_domains', []),
            ...($brand->domains ?? []),
        ];

        foreach ($brand->competitors()->get() as $competitor) {
            array_push($domains, ...($competitor->domains ?? []));
        }

        return array_values(array_filter($domains));
    }

    /**
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
}
