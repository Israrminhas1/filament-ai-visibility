<?php

namespace IsrarMinhas\FilamentAiVisibility\Runs;

use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Enums\EngineStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\Alert;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\AlertNotifier;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Spend;

/**
 * Stops spending when the monthly budget (tenant or brand) is used up,
 * and lets it start again once there is budget left (e.g. a new month).
 */
class BudgetGuard
{
    public function __construct(
        protected Spend $spend,
        protected Settings $settings,
        protected EngineManager $engines,
        protected AlertNotifier $alerts,
    ) {}

    /**
     * Whether a new call for this brand must not be made.
     */
    public function blocks(Brand $brand): bool
    {
        return $this->settings->get('budget.stop_at_budget', true) && $this->spend->overBudget($brand);
    }

    /**
     * After spending: pause engines (tenant budget) or the brand (brand budget) once a budget is used up.
     */
    public function enforce(Brand $brand): void
    {
        if (! $this->settings->get('budget.stop_at_budget', true)) {
            return;
        }

        $tenantBudget = $this->spend->monthlyBudget();

        if ($tenantBudget !== null && $this->spend->thisMonth() >= $tenantBudget) {
            foreach ($this->engines->enabled() as $engine) {
                $this->engines->pause($engine, PauseReason::Budget, sprintf('The monthly budget of $%s is used up.', number_format($tenantBudget, 2)));
            }
        }

        $brandBudget = $this->spend->brandBudget($brand);

        if ($brandBudget !== null && $brand->paused_reason !== 'budget' && $this->spend->thisMonth($brand) >= $brandBudget) {
            $brand->forceFill(['paused_reason' => 'budget'])->save();

            $this->alerts->send(new Alert(
                title: "{$brand->name} paused: budget reached",
                body: sprintf('The monthly budget of $%s for %s is used up. Tracking resumes next month, or raise the brand\'s budget.', number_format($brandBudget, 2), $brand->name),
                level: 'danger',
                type: 'brand_budget',
                brandId: $brand->getKey(),
            ));
        }
    }

    /**
     * Resume engines and brands paused for budget when there is budget again.
     */
    public function release(): void
    {
        if (! $this->spend->overBudget()) {
            foreach (array_keys(app(EngineRegistry::class)->all()) as $engine) {
                $state = $this->engines->state($engine);

                if ($state->status === EngineStatus::Paused && $state->reason === PauseReason::Budget) {
                    $this->engines->resume($engine);
                }
            }
        }

        Brand::query()->where('paused_reason', 'budget')->get()
            ->reject(fn (Brand $brand) => $this->spend->overBudget($brand))
            ->each(fn (Brand $brand) => $brand->forceFill(['paused_reason' => null])->save());
    }
}
