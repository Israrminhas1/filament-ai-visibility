<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets\Concerns\InteractsWithReportFilters;

class VisibilityStats extends StatsOverviewWidget
{
    use InteractsWithReportFilters;

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected function getColumns(): int | array
    {
        return ['md' => 3, 'xl' => 6];
    }

    protected function getStats(): array
    {
        $filters = $this->reportFilters();

        if (! $filters) {
            return [Stat::make('Visibility', '—')->description('Add a brand to start tracking.')];
        }

        $now = $this->metrics()->summary($filters);
        $before = $this->metrics()->summary($filters->previous());

        return [
            $this->percent('Visibility', $now['visibility'], $before['visibility'], 'Answers that mention ' . $filters->brand->name),
            $this->percent('Share of voice', $now['share_of_voice'], $before['share_of_voice'], 'Of answers naming any tracked brand'),
            $this->percent('Cited as a source', $now['citation_rate'], $before['citation_rate'], 'Answers citing your site'),
            Stat::make('Average position', $now['avg_position'] !== null ? '#' . $now['avg_position'] : '—')
                ->description($this->positionChange($now['avg_position'], $before['avg_position']))
                ->descriptionIcon($this->positionIcon($now['avg_position'], $before['avg_position']))
                ->color($this->positionColor($now['avg_position'], $before['avg_position'])),
            Stat::make('Answers', number_format($now['answers']))
                ->description('$' . number_format($now['spend'], 2) . ' spent')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('gray'),
            ...$this->reachStat($filters),
        ];
    }

    /**
     * Only shown when prompts are linked to keywords with search demand.
     *
     * @return array<Stat>
     */
    protected function reachStat(\IsrarMinhas\FilamentAiVisibility\Reports\ReportFilters $filters): array
    {
        $reach = $this->metrics()->reach($filters);

        if ($reach === null) {
            return [];
        }

        return [
            Stat::make('Search-weighted reach', $reach['reach'] . '%')
                ->description('Visibility weighted by the search demand of ' . $reach['prompts'] . ' keyword-linked prompts')
                ->descriptionIcon('heroicon-m-magnifying-glass')
                ->color('info'),
        ];
    }

    protected function percent(string $label, ?float $now, ?float $before, string $help): Stat
    {
        $stat = Stat::make($label, $now === null ? '—' : $now . '%');

        if ($now === null || $before === null) {
            return $stat->description($now === null ? 'No answers yet' : $help);
        }

        $change = round($now - $before, 1);

        return $stat
            ->description(($change >= 0 ? '+' : '') . $change . ' pts vs previous period')
            ->descriptionIcon($change >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($change > 0 ? 'success' : ($change < 0 ? 'danger' : 'gray'));
    }

    protected function positionChange(?float $now, ?float $before): string
    {
        if ($now === null) {
            return 'Not mentioned yet';
        }

        if ($before === null) {
            return 'When mentioned (1 = named first)';
        }

        $change = round($before - $now, 1);

        return ($change >= 0 ? '+' : '') . $change . ' places vs previous period';
    }

    protected function positionIcon(?float $now, ?float $before): ?string
    {
        if ($now === null || $before === null) {
            return null;
        }

        return $now <= $before ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down';
    }

    protected function positionColor(?float $now, ?float $before): string
    {
        if ($now === null || $before === null || $now === $before) {
            return 'gray';
        }

        // A lower position number is better.
        return $now < $before ? 'success' : 'danger';
    }
}
