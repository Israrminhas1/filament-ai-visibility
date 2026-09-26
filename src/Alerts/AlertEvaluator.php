<?php

namespace IsrarMinhas\FilamentAiVisibility\Alerts;

use Carbon\CarbonImmutable;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Enums\CompetitorLabel;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\RunStatus;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\CompetitorsReport;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Overview;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\CandidateResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\RunResource;
use IsrarMinhas\FilamentAiVisibility\Models\AlertRule;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Candidate;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Reports\CompetitorMetrics;
use IsrarMinhas\FilamentAiVisibility\Reports\Metrics;
use IsrarMinhas\FilamentAiVisibility\Reports\ReportFilters;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\Alert;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\AlertNotifier;
use IsrarMinhas\FilamentAiVisibility\Support\Spend;

/**
 * Checks alert rules and sends what they find. A rule fires once per
 * episode: the same finding (fingerprint) is never repeated, and nothing is
 * sent again within the rule's cooldown.
 */
class AlertEvaluator
{
    public function __construct(
        protected Metrics $metrics,
        protected AlertNotifier $notifier,
        protected Spend $spend,
    ) {}

    /**
     * Evaluate the rules that apply to a brand (and account-wide rules).
     *
     * @param  array<AlertType>|null  $only  Limit to these types.
     * @return int Alerts sent.
     */
    public function evaluate(Brand $brand, ?Run $run = null, ?array $only = null): int
    {
        $rules = AlertRule::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('brand_id')->orWhere('brand_id', $brand->getKey()))
            ->get()
            ->filter(fn (AlertRule $rule) => $only === null || in_array($rule->type, $only, true));

        $sent = 0;

        foreach ($rules as $rule) {
            $finding = $this->check($rule, $brand, $run);

            if ($finding && $this->fire($rule, $brand, $finding)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @return array{fingerprint: string, title: string, body: string, level?: string, url?: ?string, payload?: array}|null
     */
    public function check(AlertRule $rule, Brand $brand, ?Run $run = null): ?array
    {
        return match ($rule->type) {
            AlertType::VisibilityDrop => $this->visibilityDrop($rule, $brand),
            AlertType::CompetitorOvertakes => $this->competitorOvertakes($rule, $brand),
            AlertType::NewCompetitor => $this->newCompetitor($rule, $brand),
            AlertType::PromptLost => $this->promptLost($rule, $brand),
            AlertType::NegativeSentiment => $this->negativeSentiment($rule, $brand),
            AlertType::BudgetThreshold => $this->budgetThreshold($rule),
            AlertType::RunFailed => $run ? $this->runFailed($rule, $run) : null,
        };
    }

    protected function fire(AlertRule $rule, Brand $brand, array $finding): bool
    {
        $inCooldown = $rule->last_triggered_at?->gt(now()->subHours($rule->cooldown_hours));

        if ($rule->last_fingerprint === $finding['fingerprint'] || $inCooldown) {
            return false;
        }

        $this->notifier->send(new Alert(
            title: $finding['title'],
            body: $finding['body'],
            level: $finding['level'] ?? 'warning',
            url: $finding['url'] ?? null,
            urlLabel: 'Open',
            type: $rule->type->value,
            brandId: $rule->type->isBrandLevel() ? $brand->getKey() : null,
            ruleId: $rule->getKey(),
            payload: $finding['payload'] ?? [],
        ), $rule->channels ?: null);

        $rule->forceFill(['last_fingerprint' => $finding['fingerprint'], 'last_triggered_at' => now()])->save();

        return true;
    }

    protected function filters(Brand $brand, int $days, ?string $engine = null): ReportFilters
    {
        return new ReportFilters($brand, CarbonImmutable::now()->subDays($days - 1)->startOfDay(), CarbonImmutable::now(), $engine);
    }

    protected function visibilityDrop(AlertRule $rule, Brand $brand): ?array
    {
        $filters = $this->filters($brand, (int) $rule->option('days'), $rule->option('engine'));
        $now = $this->metrics->summary($filters)['visibility'];
        $before = $this->metrics->summary($filters->previous())['visibility'];

        if ($now === null || $before === null || $before - $now < (float) $rule->option('points')) {
            return null;
        }

        return [
            'fingerprint' => 'drop:' . now()->toDateString(),
            'title' => "{$brand->name}: visibility dropped to {$now}%",
            'body' => sprintf('Over the last %d days, %s was mentioned in %s%% of answers, down from %s%% in the %d days before (−%s points).', $rule->option('days'), $brand->name, $now, $before, $rule->option('days'), round($before - $now, 1)),
            'level' => 'danger',
            'url' => AiVisibilityPlugin::pageUrl(Overview::class),
            'payload' => ['now' => $now, 'before' => $before],
        ];
    }

    protected function competitorOvertakes(AlertRule $rule, Brand $brand): ?array
    {
        $leaderboard = app(CompetitorMetrics::class)->leaderboard($this->filters($brand, (int) $rule->option('days')));
        $ours = $leaderboard->firstWhere('type', 'brand');
        $only = $rule->option('competitor_id');

        $ahead = $leaderboard
            ->where('type', 'competitor')
            ->filter(fn ($row) => $row['answers'] > 0 && $row['visibility'] > ($ours['visibility'] ?? 0))
            ->filter(fn ($row) => ! $only || (int) $row['id'] === (int) $only);

        if ($ahead->isEmpty()) {
            return null;
        }

        return [
            'fingerprint' => 'ahead:' . $ahead->pluck('id')->sort()->implode(','),
            'title' => "{$brand->name}: " . $ahead->pluck('name')->implode(', ') . ' ' . ($ahead->count() > 1 ? 'are' : 'is') . ' ahead',
            'body' => $ahead->map(fn ($row) => "{$row['name']}: {$row['visibility']}%")->implode(', ') . " vs {$brand->name}: " . ($ours['visibility'] ?? 0) . '% of answers over the last ' . $rule->option('days') . ' days.',
            'url' => AiVisibilityPlugin::pageUrl(CompetitorsReport::class),
            'payload' => ['competitors' => $ahead->pluck('id')->values()->all()],
        ];
    }

    protected function newCompetitor(AlertRule $rule, Brand $brand): ?array
    {
        $candidates = Candidate::query()
            ->where('brand_id', $brand->getKey())
            ->where('status', Candidate::STATUS_CLASSIFIED)
            ->where('label', CompetitorLabel::DirectCompetitor)
            ->where('classified_at', '>', $rule->last_triggered_at ?? $rule->created_at)
            ->orderByDesc('score')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        return [
            'fingerprint' => 'candidates:' . $candidates->max('id'),
            'title' => "{$brand->name}: " . $candidates->count() . ' new direct competitor' . ($candidates->count() > 1 ? 's' : '') . ' found',
            'body' => $candidates->take(5)->map(fn (Candidate $c) => "{$c->name} (in {$c->answers} answers)")->implode(', ') . '. Review them on the Discovered screen.',
            'url' => AiVisibilityPlugin::pageUrl(CandidateResource::class),
            'payload' => ['candidates' => $candidates->pluck('id')->all()],
        ];
    }

    protected function promptLost(AlertRule $rule, Brand $brand): ?array
    {
        $previous = max(1, (int) $rule->option('previous'));
        $lost = [];

        $results = Result::query()
            ->where('brand_id', $brand->getKey())
            ->where('status', ResultStatus::Success)
            ->where('ran_at', '>=', now()->subDays(60))
            ->orderByDesc('ran_at')
            ->with('prompt:id,text')
            ->get(['id', 'prompt_id', 'engine', 'brand_mentioned', 'ran_at'])
            ->groupBy(fn (Result $r) => $r->prompt_id . '|' . $r->engine);

        foreach ($results as $answers) {
            $latest = $answers->first();
            $before = $answers->slice(1, $previous);

            if (! $latest->brand_mentioned && $before->count() === $previous && $before->every(fn ($r) => $r->brand_mentioned)) {
                $lost[] = $latest;
            }
        }

        if ($lost === []) {
            return null;
        }

        return [
            'fingerprint' => 'lost:' . collect($lost)->pluck('id')->sort()->implode(','),
            'title' => "{$brand->name}: no longer mentioned for " . count($lost) . ' prompt' . (count($lost) > 1 ? 's' : ''),
            'body' => collect($lost)->take(5)->map(fn (Result $r) => '"' . $r->prompt?->text . '" on ' . \IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource::engineLabel($r->engine))->implode('; '),
            'level' => 'danger',
            'url' => AiVisibilityPlugin::pageUrl(Overview::class),
            'payload' => ['results' => collect($lost)->pluck('id')->all()],
        ];
    }

    protected function negativeSentiment(AlertRule $rule, Brand $brand): ?array
    {
        $perception = app(CompetitorMetrics::class)->perception($this->filters($brand, (int) $rule->option('days')));

        // Too few analysed mentions to judge.
        if ($perception['analysed'] < 5) {
            return null;
        }

        $share = round($perception['sentiment']['negative'] / $perception['analysed'] * 100);

        if ($share < (float) $rule->option('percent')) {
            return null;
        }

        return [
            'fingerprint' => 'negative:' . now()->format('o-W'),
            'title' => "{$brand->name}: {$share}% of mentions are negative",
            'body' => "{$perception['sentiment']['negative']} of {$perception['analysed']} analysed mentions over the last {$rule->option('days')} days were negative.",
            'level' => 'danger',
            'url' => AiVisibilityPlugin::pageUrl(CompetitorsReport::class),
        ];
    }

    protected function budgetThreshold(AlertRule $rule): ?array
    {
        $budget = $this->spend->monthlyBudget();

        if ($budget === null) {
            return null;
        }

        $spent = $this->spend->thisMonth();
        $percent = round($spent / $budget * 100);

        if ($percent < (float) $rule->option('percent')) {
            return null;
        }

        return [
            'fingerprint' => 'budget:' . now()->format('Y-m'),
            'title' => "{$percent}% of the monthly AI Visibility budget used",
            'body' => sprintf('$%s of $%s spent this month.', number_format($spent, 2), number_format($budget, 2)),
        ];
    }

    protected function runFailed(AlertRule $rule, Run $run): ?array
    {
        $bad = $run->results_failed + $run->results_skipped;
        $share = $run->results_total ? round($bad / $run->results_total * 100) : 0;

        if ($run->status !== RunStatus::Failed && $share < (float) $rule->option('percent')) {
            return null;
        }

        return [
            'fingerprint' => 'run:' . $run->getKey(),
            'title' => "Run for {$run->brand?->name}: {$run->status->getLabel()}",
            'body' => "{$run->results_done} answers collected, {$run->results_failed} failed and {$run->results_skipped} skipped of {$run->results_total}.",
            'level' => 'danger',
            'url' => AiVisibilityPlugin::pageUrl(RunResource::class, 'view', ['record' => $run]),
        ];
    }
}
